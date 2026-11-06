<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

use Delnavazan\Platform\Core\Application\PostIntroFeedbackRule;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Durable post-intro feedback and staff follow-up records.
 *
 * The unique `preparation_id` makes one logical submission per preparation. Follow-up updates use
 * `feedback_version` as a compare-and-swap so a stale staff form cannot overwrite a newer note or
 * status. This repository never writes a continuation decision, reservation, enrolment or payment.
 */
final class PostIntroFeedbackRepository {
    private string $p;

    public function __construct() {
        global $wpdb;
        $this->p = $wpdb->prefix . 'dzn_';
    }

    /** @return array{feedback:?object,created:bool} */
    public function insert(array $data): array {
        global $wpdb;
        $existing = $this->byPreparation((int) ($data['preparation_id'] ?? 0));
        if ($existing) return array('feedback' => $existing, 'created' => false);
        $now = gmdate('Y-m-d H:i:s');
        $row = array(
            'uid' => Identifier::uid(),
            'preparation_id' => (int) $data['preparation_id'],
            'intro_lesson_id' => (int) $data['intro_lesson_id'],
            'student_id' => (int) $data['student_id'],
            'teacher_id' => (int) $data['teacher_id'],
            'course_id' => (int) $data['course_id'],
            'feedback_reason' => (string) $data['feedback_reason'],
            'feedback_comment' => $data['feedback_comment'] === null ? null : mb_substr((string) $data['feedback_comment'], 0, PostIntroFeedbackRule::COMMENT_MAX),
            'contact_requested' => !empty($data['contact_requested']) ? 1 : 0,
            'continuation_choice' => (string) $data['continuation_choice'],
            'continuation_decision' => null,
            'canonical_decision_id' => null,
            'follow_up_status' => PostIntroFeedbackRule::STATUS_NEW,
            'feedback_version' => 1,
            'assigned_staff_user_id' => null,
            'last_contact_at' => null,
            'follow_up_notes' => null,
            'outcome' => null,
            'submitted_at' => $now,
            'created_at' => $now,
            'created_by' => (int) ($data['created_by'] ?? 0),
            'updated_at' => $now,
            'updated_by' => (int) ($data['created_by'] ?? 0),
        );
        // The unique preparation key is the concurrency guard. Suppress the expected duplicate
        // database warning so a normal concurrent replay does not pollute the error log.
        $previous = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert($this->p . 'post_intro_feedback', $row);
        $wpdb->suppress_errors($previous);
        if ($inserted === false) {
            $winner = $this->byPreparation((int) $data['preparation_id']);
            if ($winner) return array('feedback' => $winner, 'created' => false);
            throw new \RuntimeException('post_intro_feedback_persistence_failed');
        }
        $feedback = $this->byId((int) $wpdb->insert_id);
        if (!$feedback) throw new \RuntimeException('post_intro_feedback_persistence_failed');
        return array('feedback' => $feedback, 'created' => true);
    }

    public function byPreparation(int $preparationId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}post_intro_feedback WHERE preparation_id=%d LIMIT 1", $preparationId)) ?: null;
    }

    public function byId(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}post_intro_feedback WHERE id=%d LIMIT 1", $id)) ?: null;
    }

    /**
     * Staff follow-up read. Contact facts are joined for the authorised admin surface only.
     *
     * @param array{status?:string,reason?:string,contact?:string} $filters
     * @return list<object>
     */
    public function recent(array $filters = array(), int $limit = 100): array {
        global $wpdb;
        $limit = max(1, min(200, $limit));
        $where = array('1=1');
        $args = array();
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status === 'unresolved') {
            $where[] = "feedback.follow_up_status NOT IN ('resolved','closed')";
        } elseif ($status !== '' && PostIntroFeedbackRule::status($status)) {
            $where[] = 'feedback.follow_up_status=%s';
            $args[] = $status;
        }
        $reason = trim((string) ($filters['reason'] ?? ''));
        if ($reason !== '' && PostIntroFeedbackRule::reason($reason)) {
            $where[] = 'feedback.feedback_reason=%s';
            $args[] = $reason;
        }
        $contact = trim((string) ($filters['contact'] ?? ''));
        if ($contact === '1' || $contact === '0') {
            $where[] = 'feedback.contact_requested=%d';
            $args[] = (int) $contact;
        }
        $args[] = $limit;
        $sql = "SELECT feedback.*,student.display_name student_name,student.email student_email,student.whatsapp_phone student_whatsapp,
                       teacher.display_name teacher_name,COALESCE(NULLIF(course.name_fa,''),course.name_en) course_name,
                       staff.display_name assigned_staff_name,continuation.current_decision canonical_current_decision
                  FROM {$this->p}post_intro_feedback feedback
                  LEFT JOIN {$this->p}students student ON student.id=feedback.student_id
                  LEFT JOIN {$this->p}teachers teacher ON teacher.id=feedback.teacher_id
                  LEFT JOIN {$this->p}courses course ON course.id=feedback.course_id
                  LEFT JOIN {$wpdb->users} staff ON staff.ID=feedback.assigned_staff_user_id
                  LEFT JOIN {$this->p}canonical_continuation_cases continuation ON continuation.intro_lesson_id=feedback.intro_lesson_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY feedback.id DESC LIMIT %d";
        return $wpdb->get_results($wpdb->prepare($sql, $args)) ?: array();
    }

   /** Record the canonical decision id without ever changing the student's feedback or choice. */
    public function attachDecision(int $id, int $version, string $decision, int $decisionId, int $actor): void {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $changed = $wpdb->update(
            $this->p . 'post_intro_feedback',
            array(
                'continuation_decision' => $decision,
                'canonical_decision_id' => $decisionId > 0 ? $decisionId : null,
                'feedback_version' => $version + 1,
                'updated_at' => $now,
                'updated_by' => $actor,
            ),
            array('id' => $id, 'feedback_version' => $version)
        );
        if ($changed !== 1) throw new \RuntimeException('post_intro_feedback_changed_concurrently');
    }

    /** Staff-only follow-up update with a compare-and-swap version. */
    public function updateFollowUp(int $id, int $version, array $data, int $actor): void {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $fields = array(
            'follow_up_status' => (string) $data['follow_up_status'],
            'assigned_staff_user_id' => $data['assigned_staff_user_id'] === null ? null : (int) $data['assigned_staff_user_id'],
            'last_contact_at' => $data['last_contact_at'] === null ? null : (string) $data['last_contact_at'],
            'follow_up_notes' => $data['follow_up_notes'] === null ? null : mb_substr((string) $data['follow_up_notes'], 0, PostIntroFeedbackRule::NOTES_MAX),
            'outcome' => $data['outcome'] === null ? null : mb_substr((string) $data['outcome'], 0, PostIntroFeedbackRule::OUTCOME_MAX),
            'feedback_version' => $version + 1,
            'updated_at' => $now,
            'updated_by' => $actor,
        );
        $changed = $wpdb->update($this->p . 'post_intro_feedback', $fields, array('id' => $id, 'feedback_version' => $version));
        if ($changed !== 1) throw new \RuntimeException('post_intro_feedback_changed_concurrently');
    }
}
