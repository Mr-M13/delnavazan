<?php
/**
 * WordPress-free behavioural proof for the introductory Lesson completion authority.
 *
 * A booking-linked introductory Lesson must continue through the post-intro workflow, while a
 * standalone introductory Lesson can be completed by the authorised Lesson command. Both paths
 * use the same atomic Lesson transition and neither may touch a canonical Term Lesson.
 */
namespace {
    final class DznIntroOutcomeFakeWpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public ?object $row = null;
        public array $updates = array();
        public array $queries = array();
        public function prepare(string $sql, mixed ...$args): string { return $sql; }
        public function get_row(string $sql): ?object { return $this->row; }
        public function update(string $table, array $data, array $where): int { $this->updates[] = array($table, $data, $where); return 1; }
        public function query(string $sql): bool { $this->queries[] = $sql; return true; }
    }

    $GLOBALS['dzn_intro_outcome_can_manage'] = true;
    function current_user_can(string $capability): bool { return (bool) ($GLOBALS['dzn_intro_outcome_can_manage'] && $capability === 'dzn_manage_lessons'); }
    function get_current_user_id(): int { return 7; }

    require_once dirname(__DIR__) . '/src/Core/Application/LessonService.php';

    $assert = static function (bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    };
    $refresh = static function (array $overrides = array()): object {
        $row = (object) array_merge(array(
            'id' => 41,
            'record_model' => 'legacy_phase1',
            'lesson_type' => 'introductory',
            'booking_request_id' => null,
            'enrolment_id' => null,
            'term_id' => null,
            'archived_at' => null,
            'current_schedule_version_id' => 73,
            'status' => 'scheduled',
        ), $overrides);
        $GLOBALS['wpdb']->row = $row;
        return $row;
    };

    $GLOBALS['wpdb'] = new DznIntroOutcomeFakeWpdb();
    $service = new \Delnavazan\Platform\Core\Application\LessonService();

    // A standalone introductory Lesson completes atomically through Lesson authority.
    $refresh();
    $service->completeScheduledIntroductory(41, 73);
    $assert(count($GLOBALS['wpdb']->updates) === 1, 'standalone completion must perform exactly one Lesson update');
    $update = $GLOBALS['wpdb']->updates[0];
    $assert($update[1]['status'] === 'completed', 'standalone completion must record completed status');
    $assert(isset($update[1]['completed_at']) && (int) $update[1]['updated_by'] === 7, 'completion must stamp the completion instant and actor');
    $assert($update[2] === array('id' => 41, 'current_schedule_version_id' => 73, 'status' => 'scheduled'), 'completion must be fenced by the expected occurrence and prior state');

    // A booking-linked Lesson may not bypass the post-intro authority.
    $refresh(array('booking_request_id' => 91));
    $blocked = false;
    try { $service->completeScheduledIntroductory(41, 73); }
    catch (\InvalidArgumentException $error) { $blocked = $error->getMessage() === 'booking_linked_intro_requires_post_intro_outcome'; }
    $assert($blocked && count($GLOBALS['wpdb']->updates) === 1, 'a booking-linked introductory Lesson must not bypass the post-intro outcome workflow');

    // The confirmed-booking path still requires its booking provenance.
    $refresh();
    $blocked = false;
    try { $service->completeIntroductory(41, 73); }
    catch (\InvalidArgumentException $error) { $blocked = $error->getMessage() === 'confirmed_intro_lesson_required'; }
    $assert($blocked && count($GLOBALS['wpdb']->updates) === 1, 'the booking-confirmed completion path must refuse an unlinked introductory Lesson');

    // A canonical Term Lesson is never completed by this legacy command.
    $refresh(array('record_model' => 'canonical_term_lesson_v1'));
    $blocked = false;
    try { $service->completeScheduledIntroductory(41, 73); }
    catch (\InvalidArgumentException $error) { $blocked = $error->getMessage() === 'confirmed_intro_lesson_required'; }
    $assert($blocked && count($GLOBALS['wpdb']->updates) === 1, 'the legacy introductory command must not mutate a canonical Term Lesson');

    echo "PASS introductory Lesson completion separates booking and standalone authority\n";
}
