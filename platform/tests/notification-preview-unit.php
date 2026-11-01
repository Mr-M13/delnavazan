<?php
/**
 * Focused notification preview regression test.
 *
 * Opens a real preview for every catalogue event/channel with synthetic data. The previous preview
 * fatal (a missing locale argument to ctaFor) is therefore exercised for email, portal, WhatsApp and
 * SMS without WordPress, a database, or any transport. It also proves that a caller cannot inject a
 * one-time secret into a preview and that preview never calls wp_mail.
 */
namespace {
    if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__, 2) . '/');
    if (!function_exists('get_option')) { function get_option($key, $default = false) { return $GLOBALS['dzn_preview_options'][$key] ?? $default; } }
    if (!function_exists('update_option')) { function update_option($key, $value, $autoload = null) { $GLOBALS['dzn_preview_options'][$key] = $value; return true; } }
    if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 7; } }
    if (!function_exists('wp_salt')) { function wp_salt($scheme = 'auth') { return 'preview-salt:' . $scheme; } }
    if (!function_exists('do_action')) { function do_action(...$args) {} }
    if (!function_exists('add_action')) { function add_action(...$args) { return true; } }
    if (!function_exists('remove_action')) { function remove_action(...$args) { return true; } }
    if (!function_exists('wp_mail')) {
        function wp_mail($to, $subject, $body, $headers = array()) {
            $GLOBALS['dzn_preview_mail'][] = compact('to', 'subject', 'body', 'headers');
            return true;
        }
    }
    $GLOBALS['dzn_preview_options'] = array();
    $GLOBALS['dzn_preview_mail'] = array();
    $GLOBALS['wpdb'] = (object) array('prefix' => 'wp_');
}

namespace {
    require_once dirname(__DIR__) . '/src/Core/Support/Identifier.php';
    require_once dirname(__DIR__) . '/src/Core/Application/NotificationRule.php';
    require_once dirname(__DIR__) . '/src/Core/Application/NotificationSupport.php';
    require_once dirname(__DIR__) . '/src/Core/Application/NotificationIntegrity.php';
    require_once dirname(__DIR__) . '/src/Core/Application/NotificationCopyPort.php';
    require_once dirname(__DIR__) . '/src/Core/Infrastructure/Repository/NotificationTemplateRepository.php';
    require_once dirname(__DIR__) . '/src/Core/Application/NotificationTemplateService.php';
    require_once dirname(__DIR__) . '/src/Core/Support/BrandIdentity.php';
    require_once dirname(__DIR__) . '/src/Core/Support/CommunicationDesignTokens.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationCatalogue.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationTemplateSanitizer.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationTemplateValidationException.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationTemplateLibrary.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/DelnavazanEmailRenderer.php';

    use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
    use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary;

    $fail = static function (string $name, bool $ok): void { if (!$ok) throw new RuntimeException('Notification preview unit failed: ' . $name); };
    $library = new NotificationTemplateLibrary();
    $channelsSeen = array();

    foreach (NotificationCatalogue::events() as $eventKey => $event) {
        foreach ((array) $event['channels'] as $channel) {
            $copy = NotificationCatalogue::defaultCopy($eventKey, $channel, NotificationCatalogue::PRIMARY_LOCALE);
            if (!is_array($copy)) continue;
            $channelsSeen[$channel] = true;
            $preview = $library->preview($eventKey, $channel, (string) ($copy['subject'] ?? ''), (string) ($copy['body'] ?? ''), NotificationCatalogue::PRIMARY_LOCALE);
            $fail('preview returns an array ' . $eventKey . '/' . $channel, is_array($preview));
            $fail('preview has text ' . $eventKey . '/' . $channel, trim((string) ($preview['text'] ?? '')) !== '');
            if ($channel === 'email') {
                $fail('email preview renders branded HTML ' . $eventKey, str_contains((string) ($preview['html'] ?? ''), 'dir="rtl"'));
            } else {
                $fail('non-email preview has no HTML ' . $eventKey . '/' . $channel, (string) ($preview['html'] ?? '') === '');
            }
            if ($channel === 'sms') {
                $fail('SMS preview reports segmentation ' . $eventKey, is_array($preview['segments'] ?? null) && isset($preview['segments']['segments']));
            }
        }
    }

    $fail('email preview was exercised', isset($channelsSeen['email']));
    $fail('portal preview was exercised', isset($channelsSeen['website']));
    $fail('WhatsApp preview was exercised', isset($channelsSeen['whatsapp']));
    $fail('SMS preview was exercised', isset($channelsSeen['sms']));

    // The confirmation email has a portal URL, so it must render the locale-aware primary action.
    $confirmed = NotificationCatalogue::defaultCopy('booking.confirmed.student', 'email', 'fa-IR');
    $emailPreview = $library->preview('booking.confirmed.student', 'email', (string) $confirmed['subject'], (string) $confirmed['body'], 'fa-IR');
    $fail('email CTA uses the synthetic portal URL', str_contains((string) $emailPreview['html'], 'https://example.invalid/student-portal/'));
    $fail('email CTA is localised', str_contains((string) $emailPreview['html'], 'رفتن به پرتال هنرجو'));

    // Never render a real one-time secret, even if a caller passes one directly.
    $claim = NotificationCatalogue::defaultCopy('account.claim_issued.student', 'email', 'fa-IR');
    $secret = 'REAL-ONE-TIME-SECRET-123';
    $claimPreview = $library->preview('account.claim_issued.student', 'email', (string) $claim['subject'], (string) $claim['body'], 'fa-IR', array('claim_code' => $secret));
    $fail('claim preview never exposes a supplied secret', !str_contains((string) $claimPreview['html'], $secret) && !str_contains((string) $claimPreview['text'], $secret));
    $fail('claim preview shows a synthetic code marker', str_contains((string) $claimPreview['text'], '[one-time code]'));
    $invitation = NotificationCatalogue::defaultCopy('teacher.invitation_issued.teacher', 'email', 'fa-IR');
    $invitePreview = $library->preview('teacher.invitation_issued.teacher', 'email', (string) $invitation['subject'], (string) $invitation['body'], 'fa-IR', array('invitation_code' => $secret, 'claim_url' => 'https://example.invalid/teacher-invitation/'));
    $fail('invitation preview never exposes a supplied secret', !str_contains((string) $invitePreview['html'], $secret) && !str_contains((string) $invitePreview['text'], $secret));

    $fail('preview never sends mail', $GLOBALS['dzn_preview_mail'] === array());

    echo "Notification preview unit passed\n";
}
