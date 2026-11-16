<?php
/**
 * Notification template readiness contract.
 *
 * Editorial and completeness guard for the closed notification catalogue. It proves that every
 * supported event/channel/language pair has its own valid copy, that English is a translation rather
 * than a Persian fallback, that both languages drive the same placeholder contract, and that no
 * channel is given copy that belongs to another channel or another event.
 *
 * Pure PHP: no WordPress, no database, no provider call.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Integrations/Notifications/NotificationTemplateValidationException.php';
require_once $root . '/src/Integrations/Notifications/NotificationCatalogue.php';
require_once $root . '/src/Integrations/Notifications/NotificationTemplateSanitizer.php';

use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateSanitizer;

$fail = static function (string $name, bool $ok): void {
    if (!$ok) throw new RuntimeException('Notification template readiness contract failed: ' . $name);
};
$persian = static fn(string $text): bool => preg_match('/[\x{0600}-\x{06FF}]/u', $text) === 1;
$placeholders = static function (string $text): array {
    $found = array();
    if (preg_match_all('/\{\{([a-z][a-z0-9_]{0,63})\}\}/', $text, $matches)) $found = array_values(array_unique($matches[1]));
    sort($found);
    return $found;
};

/* 1. Coverage: every supported pair has its own copy in both languages. */
$coverage = NotificationCatalogue::coverage();
$fail('the catalogue declares supported notifications', $coverage['events'] >= 70 && $coverage['channel_pairs'] > 0);
$fail('every required template is defined', $coverage['required'] === $coverage['present'] && $coverage['missing'] === array());
$derivedRequired = 0;
foreach (NotificationCatalogue::events() as $event) $derivedRequired += count((array) $event['channels']) * count(NotificationCatalogue::SUPPORTED_LOCALES);
$fail('the required count equals events x channels x languages', $coverage['required'] === $derivedRequired);

/* 2. Every template is valid, language-correct and placeholder-complete. */
$bodies = array();
$subjects = array();
foreach (NotificationCatalogue::events() as $eventKey => $event) {
    foreach ((array) $event['channels'] as $channel) {
        foreach (NotificationCatalogue::SUPPORTED_LOCALES as $locale) {
            $templateKey = NotificationCatalogue::templateKey($eventKey, (string) $channel, $locale);
            $copy = NotificationCatalogue::exactCopy($eventKey, (string) $channel, $locale);
            $fail('exact copy exists: ' . $templateKey, is_array($copy) && trim((string) ($copy['body'] ?? '')) !== '');
            $validated = NotificationTemplateSanitizer::validate($eventKey, (string) $channel, (string) ($copy['subject'] ?? ''), (string) $copy['body']);
            $fail('copy validates: ' . $templateKey . ' — ' . implode(' ', $validated['errors']), $validated['ok']);

            if ($locale === 'en') {
                $fail('English is translated, not a Persian fallback: ' . $templateKey, !$persian((string) $copy['body']) && !$persian((string) ($copy['subject'] ?? '')));
            } else {
                $fail('Persian copy does not use the retired greeting: ' . $templateKey, !str_contains((string) $copy['body'], 'سلام'));
            }
            if ((string) $channel === 'email') {
                $fail('Persian email greets with درود: ' . $templateKey, $locale !== 'fa-IR' || str_contains((string) $copy['body'], 'درود'));
                $fail('English email greets the recipient: ' . $templateKey, $locale !== 'en' || str_contains((string) $copy['body'], 'Hello'));
                $fail('email subject stays short: ' . $templateKey, mb_strlen((string) ($copy['subject'] ?? '')) <= 60);
                $subjects[(string) $copy['subject']][(string) $event['category']] = $eventKey;
            }
            if ((string) $channel === 'sms') {
                $segments = NotificationTemplateSanitizer::smsSegmentation((string) $copy['body']);
                $fail('SMS copy fits two segments: ' . $templateKey, $segments['segments'] <= 2);
            }
            if ((string) $channel === 'whatsapp') {
                $fail('WhatsApp copy stays short: ' . $templateKey, mb_strlen((string) $copy['body']) <= 700);
                $fail('WhatsApp copy is conversational, not an email: ' . $templateKey, substr_count((string) $copy['body'], "\n\n") <= 4);
                $fail('WhatsApp copy is channel-specific: ' . $templateKey, (string) $copy['body'] !== (string) (NotificationCatalogue::exactCopy($eventKey, 'email', $locale)['body'] ?? ''));
            }

            $body = (string) $copy['body'];
            $fail('copy is never a generic placeholder: ' . $templateKey, !in_array(trim($body), array('Your notification is ready', 'Notification', 'پیام شما آماده است'), true));
            $fail('copy never repeats the raw event key: ' . $templateKey, !str_contains($body, $eventKey));
            $fail('no body is shared between two templates: ' . $templateKey, !isset($bodies[$body]));
            $bodies[$body] = $templateKey;
        }
        $fa = NotificationCatalogue::exactCopy($eventKey, (string) $channel, 'fa-IR');
        $en = NotificationCatalogue::exactCopy($eventKey, (string) $channel, 'en');
        $fail('translation keeps the same placeholders: ' . $eventKey . '/' . $channel, $placeholders((string) $fa['body']) === $placeholders((string) $en['body']));
    }
}

foreach ($subjects as $subject => $categories) {
    $fail('no email subject is reused across categories: ' . $subject, count($categories) === 1);
}

/* 3. One-time secrets are handled by their authority, never by general editable copy. */
$claim = NotificationCatalogue::get('account.claim_issued.student');
$fail('the account claim keeps its dedicated secure authority contract', $claim['authority'] === 'canonical' && in_array('security', NotificationCatalogue::reviewFor('account.claim_issued.student'), true));
$fail('the account claim preview never shows a real code', NotificationCatalogue::previewVariables('account.claim_issued.student')['claim_code'] === '[one-time code]');
$invitation = NotificationCatalogue::get('teacher.invitation_issued.teacher');
$fail('the teacher invitation is owned by a dedicated authority', $invitation['authority'] === 'dedicated' && in_array('dedicated_authority', NotificationCatalogue::reviewFor('teacher.invitation_issued.teacher'), true));
$fail('a dedicated event never offers a general editor path', $invitation['channels'] === array('email','sms'));
$fail('the teacher invitation SMS copy declares the one-time code and claim link in both locales', NotificationCatalogue::exactCopy('teacher.invitation_issued.teacher','sms','fa-IR')['body']!==null && NotificationCatalogue::exactCopy('teacher.invitation_issued.teacher','sms','en')['body']!==null && $placeholders((string) NotificationCatalogue::exactCopy('teacher.invitation_issued.teacher','sms','fa-IR')['body']) === array('claim_url','invitation_code') && $placeholders((string) NotificationCatalogue::exactCopy('teacher.invitation_issued.teacher','sms','en')['body']) === array('claim_url','invitation_code'));
foreach (NotificationCatalogue::events() as $eventKey => $event) {
    foreach ((array) $event['channels'] as $channel) {
        foreach (NotificationCatalogue::SUPPORTED_LOCALES as $locale) {
            $copy = NotificationCatalogue::exactCopy($eventKey, (string) $channel, $locale);
            $fail('a one-time secret is never placed in a subject: ' . $eventKey, !str_contains((string) ($copy['subject'] ?? ''), '{{claim_code}}') && !str_contains((string) ($copy['subject'] ?? ''), '{{invitation_code}}'));
        }
    }
}

/* 4. Review metadata is closed, meaningful and never rendered as copy. */
$known = array_keys(NotificationCatalogue::REVIEW);
foreach (NotificationCatalogue::events() as $eventKey => $event) {
    $review = NotificationCatalogue::reviewFor($eventKey);
    $fail('review codes are known: ' . $eventKey, array_diff($review, $known) === array());
    if ((string) $event['category'] === 'payment' && (string) $event['implementation'] !== 'implemented') {
        $fail('financial copy carries no separate finance or legal hold: ' . $eventKey, !in_array('financial', $review, true) && !in_array('legal', $review, true));
    }
    if ((string) $event['category'] === 'payment' && (string) $event['implementation'] === 'implemented') {
        $fail('an active financial reminder carries reviewed copy, not a placeholder: ' . $eventKey, !str_contains((string) NotificationCatalogue::exactCopy($eventKey, 'email', 'fa-IR')['body'], 'بازبینی'));
    }
    if ((string) $event['category'] === 'security') $fail('security copy is flagged: ' . $eventKey, in_array('security', $review, true));
    foreach (NotificationCatalogue::reviewReasons($eventKey) as $reason) {
        foreach ((array) $event['channels'] as $channel) {
            $copy = NotificationCatalogue::exactCopy($eventKey, (string) $channel, 'en');
            $fail('review metadata is never customer copy: ' . $eventKey, !str_contains((string) $copy['body'], $reason));
        }
    }
}

/* 5. Editorial standard and the generated inventory stay aligned with the catalogue. */
$standard = (string) file_get_contents($root . '/docs/NOTIFICATION-EDITORIAL-STANDARD.md');
$fail('the editorial standard mandates the approved term for intro lessons', str_contains($standard, '| کلاس آشنایی |') && str_contains($standard, 'کلاس آشنایی «تار»'));
$fail('the editorial standard retires the old greeting', !str_contains($standard, '> سلام'));
$fail('the editorial standard separates marketing consent from admin control', str_contains($standard, 'Administrator delivery control'));
$inventoryPath = $root . '/docs/NOTIFICATION-TEMPLATE-INVENTORY.json';
$fail('the generated inventory exists', is_file($inventoryPath));
$inventory = json_decode((string) file_get_contents($inventoryPath), true);
$fail('the inventory is valid JSON', is_array($inventory));
$fail('the inventory matches the catalogue event count', (int) $inventory['totals']['events'] === count(NotificationCatalogue::events()));
$fail('the inventory reports no missing template', (int) $inventory['totals']['missing_templates'] === 0);
$fail('the inventory reports no invalid template', (int) $inventory['totals']['invalid_templates'] === 0);
$fail('the inventory required total matches the catalogue', (int) $inventory['totals']['required_templates'] === $coverage['required']);
$fail('the inventory is generated from the current catalogue version', (string) $inventory['catalogue_version'] === NotificationCatalogue::VERSION);

/* 6. The administration surface separates completeness, drafts, publication, approvals, delivery and
 *    producer state, and the bulk publish path is gated by an explicit editorial approval. */
$controller = (string) file_get_contents($root . '/src/Admin/Controller/NotificationManagementController.php');
$librarySource = (string) file_get_contents($root . '/src/Integrations/Notifications/NotificationTemplateLibrary.php');
foreach (array('Catalogue copy', 'Drafts', 'Publication', 'Approvals', 'Delivery', 'Producer') as $dimension) {
    $fail('the catalogue row reports ' . $dimension . ' separately', str_contains($controller, "'" . $dimension . "'"));
}
$fail('completeness is never merged with delivery readiness', !str_contains($controller, '<th>Template readiness</th>') && str_contains($controller, 'dzn-notif-lifecycle'));
$fail('a producer gap is visible as its own state', str_contains($controller, "return 'no_producer'") && str_contains($controller, '>No producer yet<'));
$fail('an approval is required before bulk publication', str_contains($librarySource, 'The draft has not been approved for publication.'));
$fail('editing the copy revokes the approval', str_contains($librarySource, 'private function forgetApproval') && substr_count($librarySource, 'forgetApproval(') >= 4);
$fail('the approval is bound to a content digest', str_contains($librarySource, 'public function contentDigest') && str_contains($librarySource, "'digest'=>"));
$fail('the provider gate is a positive whitelist', str_contains($librarySource, '$state!==TelnyxTemplateAuthority::MAPPED') && str_contains($librarySource, "default=>'The provider approval state is unknown, so publication is refused.'"));
$fail('the publish action is capability and nonce gated', substr_count($controller, 'check_admin_referer(') >= 2 && str_contains($controller, 'dzn_notification_approve_draft'));
$fail('the workflow exposes a bulk approval step', str_contains($controller, 'dzn_notification_approve_operational') && str_contains($librarySource, 'public function approveOperationalDrafts'));
$fail('the workflow reports each step persistently', str_contains($controller, 'dzn_notification_workflow_result') && str_contains($controller, 'private static function rememberWorkflowResult'));
$fail('a canonical event awaiting sign-off is not labelled as missing copy', str_contains($controller, "'awaiting_approval'") && str_contains($controller, 'Awaiting approval'));
$fail('a channel the event does not use is never shown as a fault', str_contains($controller, "'Not offered'") && str_contains($controller, 'if(!in_array($channel,(array)$row[') && str_contains($controller, 'continue;'));
$fail('a rejected edit is preserved instead of discarded', str_contains($controller, 'private static function rememberRejectedSubmission') && str_contains($controller, 'Your last change was not saved'));
$fail('the action link is a choice, not one fixed placeholder', str_contains((string) file_get_contents($root . '/src/Integrations/Notifications/NotificationTemplateSanitizer.php'), 'public static function requiredActionLinks'));

/* 7. Producer gaps and unmapped provider routes are reported as outstanding implementation work. */
$inventory = json_decode((string) file_get_contents($root . '/docs/NOTIFICATION-TEMPLATE-INVENTORY.json'), true);
$fail('the inventory lists the producer gaps', count((array) $inventory['events_without_registered_producer']) > 0);
$fail('the inventory lists the unmapped WhatsApp routes', count((array) $inventory['whatsapp_routes_without_registry_mapping']) > 0);
$fail('the inventory separates outstanding work from delivered functionality', str_contains((string) file_get_contents($root . '/docs/NOTIFICATION-TEMPLATE-INVENTORY.md'), 'Outstanding implementation work (not delivered functionality)'));
$fail('the catalogue declares producer registration per event', str_contains((string) file_get_contents($root . '/src/Integrations/Notifications/NotificationCatalogue.php'), "'producer_registered'=>"));

echo "Notification template readiness contract passed\n";
