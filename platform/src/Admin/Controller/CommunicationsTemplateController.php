<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Integrations\Notifications\TeacherInvitationPresentation;

/** Small communications copy editor for the notification channel the invitation adapter actually supports. */
final class CommunicationsTemplateController {
    public static function register(): void { add_action('admin_post_dzn_communications_copy_save', array(__CLASS__, 'save')); }
    public static function save(): never {
        self::authorize(); check_admin_referer('dzn_communications_copy_save');
        try {
            $input = (array) wp_unslash($_POST['copy'] ?? array());
            $copy = TeacherInvitationPresentation::validate((string)($input['subject'] ?? ''), (string)($input['body'] ?? ''));
            update_option(TeacherInvitationPresentation::OPTION, $copy, false);
            $result = 'saved';
        } catch (\Throwable) { $result = 'invalid'; }
        wp_safe_redirect(add_query_arg(array('page'=>'dzn-communications-templates','copy_result'=>$result),admin_url('admin.php'))); exit;
    }
    public static function screen(): void {
        self::authorize(); $copy = TeacherInvitationPresentation::values();
        echo '<div class="wrap"><h1>Notification templates</h1><p>Edit the human-facing email sent when an administrator issues a teacher invitation. A queued invitation creates delivery intent; transport acceptance does not confirm mailbox delivery.</p>';
        if(isset($_GET['copy_result'])) echo '<div class="notice '.($_GET['copy_result']==='saved'?'notice-success':'notice-error').'"><p>'.($_GET['copy_result']==='saved'?'Template saved.':'Template was not saved. Check the required fields and placeholders.').'</p></div>';
        echo '<h2>Teacher invitation · Email</h2><p>Supported channel: email. Required placeholders: <code>{{claim_url}}</code> and <code>{{invitation_code}}</code>. The code remains a one-time secret and must be requested in the message body; do not add other placeholders or HTML.</p>';
        echo '<p><strong>Preview</strong></p><div class="notice notice-info"><p><strong>'.esc_html($copy['subject']).'</strong></p><pre style="white-space:pre-wrap">'.esc_html(TeacherInvitationPresentation::render($copy['body'],'https://example.invalid/teacher-invitation/','[one-time code]')).'</pre></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dzn_communications_copy_save">'; wp_nonce_field('dzn_communications_copy_save');
        echo '<table class="form-table"><tbody><tr><th><label for="dzn-copy-subject">Subject</label></th><td><input id="dzn-copy-subject" class="large-text" maxlength="180" name="copy[subject]" value="'.esc_attr($copy['subject']).'" required></td></tr><tr><th><label for="dzn-copy-body">Email body</label></th><td><textarea id="dzn-copy-body" class="large-text" rows="14" maxlength="5000" name="copy[body]" required>'.esc_textarea($copy['body']).'</textarea></td></tr></tbody></table><p><button class="button button-primary">Save invitation template</button></p></form>';
        $transport = function_exists('wp_mail') && (bool) apply_filters('dzn_teacher_invitation_email_transport_configured', false) ? 'Configured, unverified' : 'Not configured';
        echo '<h2>Sender and delivery readiness</h2><p>Invitation transport: <strong>'.esc_html($transport).'</strong>. Set sender name/address and SMTP settings under <a href="'.esc_url(admin_url('admin.php?page=dzn-integration-email')).'">Settings → Email</a>. Defaults are Delnavazan and no-reply@delnavazan.com. A configured transport or accepted mail handoff does not confirm recipient delivery. SPF, DKIM and DMARC are not verified by this application; ask the domain administrator to publish and verify those DNS records.</p><p>Test-send is unavailable here: invitation delivery requires a live one-time secret and the existing delivery authority has no safe preview-send command. Use the Email integration test for a clearly marked transport check.</p></div>';
    }
    private static function authorize(): void { if(!current_user_can('manage_options')&&!current_user_can('dzn_manage_provider_integrations'))wp_die('Forbidden',403); }
}
