<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Closed vocabulary for the post-intro follow-up survey.
 *
 * Feedback is not a continuation decision. The student separately chooses whether to request
 * contact, decline continuation, or record feedback only. Only the existing canonical continuation
 * authority may record a decision.
 */
final class PostIntroFeedbackRule {
    public const RULE_VERSION = 'post_intro_feedback_v1';

    /** Feedback reasons reuse the canonical continuation vocabulary. */
    public const REASONS = CanonicalContinuationRule::FEEDBACK_REASONS;

    public const CHOICE_CONTACT = 'contact_me';
    public const CHOICE_DECLINE = 'not_continuing';
    public const CHOICE_FEEDBACK_ONLY = 'feedback_only';
    public const CHOICES = array(self::CHOICE_CONTACT, self::CHOICE_DECLINE, self::CHOICE_FEEDBACK_ONLY);

    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_AWAITING = 'awaiting_response';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';
    public const FOLLOW_UP_STATUSES = array(self::STATUS_NEW, self::STATUS_CONTACTED, self::STATUS_AWAITING, self::STATUS_RESOLVED, self::STATUS_CLOSED);

    public const COMMENT_MAX = 1000;
    public const NOTES_MAX = 5000;
    public const OUTCOME_MAX = 255;

    public static function reason(string $value): bool { return in_array($value, self::REASONS, true); }
    public static function choice(string $value): bool { return in_array($value, self::CHOICES, true); }
    public static function status(string $value): bool { return in_array($value, self::FOLLOW_UP_STATUSES, true); }
}
