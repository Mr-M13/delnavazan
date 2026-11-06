<?php
/**
 * Pure unit guard for the post-intro feedback vocabulary.
 *
 * Feedback is deliberately separate from a continuation decision: `feedback_only` records no
 * decision, while an explicit decline is the canonical `not_continuing` value.
 */
$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/CanonicalContinuationRule.php';
require_once $root . '/src/Core/Application/PostIntroFeedbackRule.php';

use Delnavazan\Platform\Core\Application\CanonicalContinuationRule;
use Delnavazan\Platform\Core\Application\PostIntroFeedbackRule;

$fail = static function (string $name, bool $ok): void { if (!$ok) throw new RuntimeException('Post-intro feedback unit failed: ' . $name); };

$fail('feedback reasons reuse the canonical closed vocabulary', PostIntroFeedbackRule::REASONS === CanonicalContinuationRule::FEEDBACK_REASONS);
$fail('feedback choices are exactly the three separate outcomes', PostIntroFeedbackRule::CHOICES === array('contact_me', 'not_continuing', 'feedback_only'));
$fail('feedback-only is not a continuation decision', !in_array('feedback_only', CanonicalContinuationRule::STUDENT_DECISIONS, true));
$fail('explicit decline shares the canonical decision name', PostIntroFeedbackRule::CHOICE_DECLINE === CanonicalContinuationRule::DECISION_NOT_CONTINUING);
$fail('contact choice is the canonical contact-request decision', PostIntroFeedbackRule::CHOICE_CONTACT === CanonicalContinuationRule::DECISION_CONTACT_ME);
$fail('follow-up statuses are the controlled lifecycle', PostIntroFeedbackRule::FOLLOW_UP_STATUSES === array('new', 'contacted', 'awaiting_response', 'resolved', 'closed'));
$fail('every controlled feedback reason is accepted', array_reduce(PostIntroFeedbackRule::REASONS, static fn(bool $ok, string $reason): bool => $ok && PostIntroFeedbackRule::reason($reason), true));
$fail('an unknown reason is refused', !PostIntroFeedbackRule::reason('unknown'));
$fail('an unknown continuation choice is refused', !PostIntroFeedbackRule::choice('decline'));
$fail('an unknown follow-up state is refused', !PostIntroFeedbackRule::status('pending'));

echo "Post-intro feedback unit passed\n";
