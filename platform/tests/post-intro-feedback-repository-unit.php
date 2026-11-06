<?php
/**
 * Pure persistence guard for post-intro feedback idempotency and follow-up CAS.
 *
 * The unique preparation row is the concurrent-submission guard; the feedback_version is the
 * compare-and-swap guard for staff follow-up edits. No WordPress or database is required.
 */
final class DznFeedbackFakeWpdb {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public string $last_error = '';
    private bool $suppress = false;
    private array $rows = array();
    private int $next = 1;

    public function suppress_errors(bool $suppress = true): bool {
        $previous = $this->suppress;
        $this->suppress = $suppress;
        return $previous;
    }

    public function prepare(string $sql, ...$args): string {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $index = 0;
        return (string) preg_replace_callback('/%[ds]/', static function (array $match) use (&$index, $args): string {
            $value = $args[$index++] ?? '';
            return $match[0] === '%d' ? (string) (int) $value : (string) $value;
        }, $sql);
    }

    public function get_row(string $sql): ?object {
        if (preg_match('/preparation_id=(\d+)/', $sql, $match)) {
            foreach ($this->rows as $row) {
                if ((int) ($row['preparation_id'] ?? 0) === (int) $match[1]) return (object) $row;
            }
            return null;
        }
        if (preg_match('/WHERE id=(\d+)/', $sql, $match)) {
            return isset($this->rows[(int) $match[1]]) ? (object) $this->rows[(int) $match[1]] : null;
        }
        return null;
    }

    public function insert(string $table, array $data): int|false {
        if (isset($data['preparation_id'])) {
            foreach ($this->rows as $row) {
                if ((int) ($row['preparation_id'] ?? 0) === (int) $data['preparation_id']) {
                    $this->last_error = 'Duplicate entry for key preparation_id';
                    return false;
                }
            }
        }
        $id = $this->next++;
        $data['id'] = $id;
        $this->rows[$id] = $data;
        $this->insert_id = $id;
        return 1;
    }

    public function update(string $table, array $data, array $where): int {
        foreach ($this->rows as $id => $row) {
            $matches = true;
            foreach ($where as $column => $expected) {
                if ((int) ($row[$column] ?? 0) !== (int) $expected) { $matches = false; break; }
            }
            if (!$matches) continue;
            $this->rows[$id] = array_merge($row, $data);
            return 1;
        }
        return 0;
    }
}

$root = dirname(__DIR__);
require_once $root . '/src/Core/Support/Identifier.php';
require_once $root . '/src/Core/Application/CanonicalContinuationRule.php';
require_once $root . '/src/Core/Application/PostIntroFeedbackRule.php';
require_once $root . '/src/Core/Infrastructure/Repository/PostIntroFeedbackRepository.php';

use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroFeedbackRepository;

$GLOBALS['wpdb'] = new DznFeedbackFakeWpdb();
$repository = new PostIntroFeedbackRepository();
$fail = static function (string $name, bool $ok): void { if (!$ok) throw new RuntimeException('Post-intro feedback repository unit failed: ' . $name); };

$base = array(
    'preparation_id' => 41,
    'intro_lesson_id' => 91,
    'student_id' => 7,
    'teacher_id' => 3,
    'course_id' => 5,
    'feedback_reason' => 'schedule',
    'feedback_comment' => ' evenings are difficult ',
    'contact_requested' => 1,
    'continuation_choice' => 'contact_me',
    'created_by' => 7,
);
$first = $repository->insert($base);
$fail('first submission creates one row', $first['created'] === true && (int) $first['feedback']->id > 0);
$fail('comment is bounded and preserved', (string) $first['feedback']->feedback_comment === ' evenings are difficult ');
$fail('new feedback starts in the new follow-up state', (string) $first['feedback']->follow_up_status === 'new');
$second = $repository->insert(array_merge($base, array('feedback_reason' => 'price')));
$fail('a concurrent or repeated submission replays the one row', $second['created'] === false && (int) $second['feedback']->id === (int) $first['feedback']->id);
$fail('the repeated submission does not overwrite the feedback reason', (string) $second['feedback']->feedback_reason === 'schedule');

$repository->attachDecision((int) $first['feedback']->id, 1, 'contact_me', 88, 7);
$attached = $repository->byId((int) $first['feedback']->id);
$fail('decision attachment advances the version', (int) $attached->feedback_version === 2 && (string) $attached->continuation_decision === 'contact_me');
$stale = false;
try { $repository->attachDecision((int) $first['feedback']->id, 1, 'not_continuing', 99, 7); } catch (RuntimeException $error) { $stale = str_contains($error->getMessage(), 'concurrently'); }
$fail('a stale decision attachment is refused', $stale);

$repository->updateFollowUp((int) $first['feedback']->id, 2, array(
    'follow_up_status' => 'contacted',
    'assigned_staff_user_id' => 4,
    'last_contact_at' => '2026-11-06 00:00:00',
    'follow_up_notes' => 'Called the student.',
    'outcome' => 'Scheduling alternative offered',
), 4);
$updated = $repository->byId((int) $first['feedback']->id);
$fail('staff follow-up is persisted', (string) $updated->follow_up_status === 'contacted' && (int) $updated->assigned_staff_user_id === 4 && (int) $updated->feedback_version === 3);
$staleFollowUp = false;
try {
    $repository->updateFollowUp((int) $first['feedback']->id, 2, array(
        'follow_up_status' => 'resolved', 'assigned_staff_user_id' => null,
        'last_contact_at' => null, 'follow_up_notes' => null, 'outcome' => null,
    ), 4);
} catch (RuntimeException $error) {
    $staleFollowUp = str_contains($error->getMessage(), 'concurrently');
}
$fail('a stale staff follow-up edit is refused', $staleFollowUp);

$other = $repository->insert(array_merge($base, array('preparation_id' => 42, 'intro_lesson_id' => 92)));
$fail('a different preparation gets its own row', $other['created'] === true && (int) $other['feedback']->id !== (int) $first['feedback']->id);

echo "Post-intro feedback repository unit passed\n";
