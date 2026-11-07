<?php
/** Unit contract for the default-on Google Meet recording policy. */
final class DznDefaultOnEligibilityDb {
    public string $prefix = 'wp_';
    public ?object $lesson;
    public ?object $studentDecision = null;
    public ?object $guardianDecision = null;

    public function __construct() {
        $this->lesson = (object) array('id' => 9, 'student_id' => 21, 'teacher_id' => 34);
    }
    public function prepare(string $query, mixed ...$args): array { return array($query, $args); }
    public function get_row(array $query): ?object {
        [$sql] = $query;
        if (str_contains($sql, 'FROM wp_dzn_lessons')) return $this->lesson;
        return str_contains($sql, 'guardian_actor_user_id IS NOT NULL') ? $this->guardianDecision : $this->studentDecision;
    }
}

require_once dirname(__DIR__) . '/src/Core/Application/ConsentPurposeRegistry.php';
require_once dirname(__DIR__) . '/src/Core/Application/RecordingDecisionAuthorityService.php';

use Delnavazan\Platform\Core\Application\RecordingDecisionAuthorityService;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$wpdb = new DznDefaultOnEligibilityDb();
$service = new RecordingDecisionAuthorityService();

$default = $service->lessonRecordingEligibility(9);
$assert(($default['eligible'] ?? false) === true && ($default['reason'] ?? '') === 'student_recording_default_on', 'No Student decision must retain automatic recording.');

$wpdb->studentDecision = (object) array('id' => 1, 'decision_code' => 'decline', 'decided_at' => '2026-11-07 00:00:00', 'notice_uid' => 'NOTICE-ONE', 'notice_version_number' => 1);
$declined = $service->lessonRecordingEligibility(9);
$assert(($declined['eligible'] ?? true) === false && ($declined['reason'] ?? '') === 'student_recording_declined', 'An explicit Student decline must disable automatic recording.');

$wpdb->guardianDecision = (object) array('id' => 2, 'decision_code' => 'allow', 'decided_at' => '2026-11-07 01:00:00', 'notice_uid' => 'NOTICE-TWO', 'notice_version_number' => 1);
$reallowed = $service->lessonRecordingEligibility(9);
$assert(($reallowed['eligible'] ?? false) === true && ($reallowed['reason'] ?? '') === 'student_recording_default_on', 'A newer guardian choice must replace an earlier Student decline.');

echo "Recording default-on eligibility unit passed\n";
