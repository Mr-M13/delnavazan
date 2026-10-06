<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/** Editable, validated copy for the bounded one-time invitation delivery adapter. */
final class TeacherInvitationPresentation {
    public const OPTION = 'dzn_teacher_invitation_presentation_v1';
    public const SUBJECT = 'دعوت به پنل مدرس دلنوازان';
    public const BODY = "سلام،\n\nبرای ساخت یا اتصال حساب مدرس دلنوازان، صفحه امن دعوت را باز کنید:\n{{claim_url}}\n\nکد یک‌بارمصرف دعوت: {{invitation_code}}\n\nاین کد را با دیگران به اشتراک نگذارید. اگر این پیام را انتظار نداشتید، آن را نادیده بگیرید.\n\nبا احترام،\nگروه دلنوازان";
    private const ALLOWED = array('claim_url', 'invitation_code');

    public static function values(): array {
        $saved = (array) get_option(self::OPTION, array());
        try { return self::validate((string) ($saved['subject'] ?? self::SUBJECT), (string) ($saved['body'] ?? self::BODY)); }
        catch (\Throwable) { return array('subject' => self::SUBJECT, 'body' => self::BODY); }
    }
    public static function validate(string $subject, string $body): array {
        $subject = trim(sanitize_text_field($subject));
        $body = trim(str_replace("\r\n", "\n", sanitize_textarea_field($body)));
        if ($subject === '' || strlen($subject) > 180 || $body === '' || strlen($body) > 5000 || str_contains($body, '<')) throw new \InvalidArgumentException('notification_copy_invalid');
        foreach (array($subject, $body) as $text) {
            preg_match_all('/\{\{([a-z][a-z0-9_]*)\}\}/', $text, $matches);
            foreach ($matches[1] as $code) if (!in_array($code, self::ALLOWED, true)) throw new \InvalidArgumentException('notification_placeholder_invalid');
            if (preg_match('/\{\{[^}]*\}\}/', preg_replace('/\{\{([a-z][a-z0-9_]*)\}\}/', '', $text))) throw new \InvalidArgumentException('notification_placeholder_invalid');
        }
        if (preg_match('~https?://[^\s]*\{\{invitation_code\}\}|\{\{invitation_code\}\}[^\s]*https?://~i', $body)) throw new \InvalidArgumentException('notification_secret_in_url');
        foreach (self::ALLOWED as $required) if (!str_contains($body, '{{'.$required.'}}')) throw new \InvalidArgumentException('notification_required_field_missing');
        return array('subject' => $subject, 'body' => $body);
    }
    public static function render(string $text, string $claimUrl, string $secret): string {
        return strtr($text, array('{{claim_url}}' => $claimUrl, '{{invitation_code}}' => $secret));
    }
}
