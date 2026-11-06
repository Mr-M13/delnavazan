<?php
/**
 * One concurrent submitter for the post-intro feedback survey runtime proof.
 */
if (getenv('DZN_POST_INTRO_FEEDBACK_CONCURRENT') !== 'authority' || !defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "Post-intro feedback concurrent runtime refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\PostIntroFeedbackService;

$uid = trim((string) getenv('DZN_FEEDBACK_UID'));
$user = (int) getenv('DZN_FEEDBACK_USER');
if ($uid === '' || $user < 1) {
    fwrite(STDERR, "Post-intro feedback concurrent runtime needs a preparation UID and user.\n");
    exit(1);
}
wp_set_current_user($user);
$result = (new PostIntroFeedbackService())->submit($uid, 'schedule', 'concurrent submission', 'feedback_only');
echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
