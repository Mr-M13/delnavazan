<?php
/**
 * Notification template inventory generator.
 *
 * Reads the closed notification catalogue and the provider template registry and produces a
 * machine-readable inventory plus a human-readable summary. It reads source only: no WordPress, no
 * database, no provider call and no message is sent or published.
 *
 * Usage:
 *   php tools/notifications/inventory.php            # write platform/docs/NOTIFICATION-TEMPLATE-INVENTORY.{json,md}
 *   php tools/notifications/inventory.php --json     # print the JSON document to stdout
 *   php tools/notifications/inventory.php --check    # exit 1 when a supported template has no copy
 *
 * Published versions, prepared drafts and provider approval rows live in WordPress, so they are not
 * part of this document. The report states exactly which live checks remain outstanding.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/platform/src/Integrations/Notifications/NotificationTemplateValidationException.php';
require_once $root . '/platform/src/Integrations/Notifications/NotificationCatalogue.php';
require_once $root . '/platform/src/Integrations/Notifications/NotificationTemplateSanitizer.php';
require_once $root . '/platform/src/Integrations/Notifications/TelnyxTemplateRegistry.php';
require_once $root . '/platform/src/Integrations/Notifications/SmsIrTemplateRegistry.php';

use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateSanitizer;
use Delnavazan\Platform\Integrations\Notifications\SmsIrTemplateRegistry;
use Delnavazan\Platform\Integrations\Notifications\TelnyxTemplateRegistry;

$catalogue = NotificationCatalogue::events();
$locales = NotificationCatalogue::SUPPORTED_LOCALES;
$coverage = NotificationCatalogue::coverage();

/* A producer is only real when a class with that name exists in the plugin source. */
$classIndex = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/platform/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') continue;
    $source = (string) file_get_contents($file->getPathname());
    if (preg_match_all('/^(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $source, $matches)) {
        foreach ($matches[1] as $name) $classIndex[$name] = true;
    }
}
$producerRegistered = static function (string $producer) use ($classIndex): bool {
    // `notification_management` is the administration test action, not a class.
    if ($producer === 'notification_management') return true;
    if ($producer === '' || str_contains($producer, ':')) return false;
    return isset($classIndex[$producer]);
};

/* ---------------------------------------------------------------- per-event rows */
$rows = array();
$invalid = array();
foreach ($catalogue as $eventKey => $event) {
    $channels = array_values((array) $event['channels']);
    $mappedWhatsApp = TelnyxTemplateRegistry::nameForEvent($eventKey);
    $row = array(
        'event_key' => $eventKey,
        'notification' => (string) $event['label'],
        'category' => (string) $event['category'],
        'task' => NotificationCatalogue::taskKey($eventKey),
        'recipient' => (string) $event['audience'],
        'producer' => (string) $event['producer'],
        // Declared by the catalogue, then cross-checked against the class names in platform/src.
        'producer_registered' => !empty($event['producer_registered']),
        'producer_class_found' => $producerRegistered((string) $event['producer']),
        'implementation' => (string) $event['implementation'],
        'delivery_authority' => (string) $event['authority'],
        'channels' => $channels,
        'review_outstanding' => NotificationCatalogue::reviewFor($eventKey),
        'templates' => array(),
        'provider' => array(
            'whatsapp_registry_name' => $mappedWhatsApp,
            'whatsapp_state' => in_array('whatsapp', $channels, true) ? ($mappedWhatsApp === null ? 'not_mapped' : 'registry_present_discovery_unverified') : 'unsupported',
            'sms_registry_key' => in_array('sms', $channels, true) && (string) $event['authority'] !== 'dedicated' ? SmsIrTemplateRegistry::keyForEvent($eventKey) : null,
            'sms_state' => in_array('sms', $channels, true) ? ((string) $event['authority'] === 'dedicated' ? 'dedicated_secure_route' : 'approved_template_mapped') : 'unsupported',
        ),
    );
    foreach ($channels as $channel) {
        foreach ($locales as $locale) {
            $copy = NotificationCatalogue::exactCopy($eventKey, $channel, $locale);
            $present = is_array($copy) && trim((string) ($copy['body'] ?? '')) !== '';
            $status = 'missing';
            $errors = array();
            if ($present) {
                $validated = NotificationTemplateSanitizer::validate($eventKey, $channel, (string) ($copy['subject'] ?? ''), (string) $copy['body']);
                $status = $validated['ok'] ? 'present' : 'invalid';
                $errors = $validated['errors'];
                if (!$validated['ok']) {
                    $invalid[] = array('event_key' => $eventKey, 'channel' => $channel, 'locale' => $locale, 'errors' => $errors);
                }
            }
            $row['templates'][$channel . '.' . $locale] = array(
                'template_key' => NotificationCatalogue::templateKey($eventKey, $channel, $locale),
                'status' => $status,
                'errors' => $errors,
            );
        }
    }
    $rows[] = $row;
}

/* ---------------------------------------------------------------- totals */
$supportedCombinations = 0;
$required = 0;
$existing = 0;
$missing = 0;
$perCategory = array();
$perChannel = array();
$perRecipient = array();
$implemented = 0;
$partial = 0;
$declared = 0;
$noProducer = array();
$unsupportedRoutes = array();
$unmappedWhatsApp = array();
$producerMismatch = array();
$reviewRequired = array();

foreach ($rows as $row) {
    $supportedCombinations += count($row['channels']);
    foreach (array('website', 'email', 'whatsapp', 'sms') as $channel) {
        if (!in_array($channel, $row['channels'], true)) {
            $unsupportedRoutes[] = $row['event_key'] . '.' . $channel;
            continue;
        }
        $perChannel[$channel] = ($perChannel[$channel] ?? 0) + 1;
    }
    $perCategory[$row['category']] = ($perCategory[$row['category']] ?? 0) + 1;
    $perRecipient[$row['recipient']] = ($perRecipient[$row['recipient']] ?? 0) + 1;
    if ($row['implementation'] === 'implemented') $implemented++;
    elseif ($row['implementation'] === 'partial') $partial++;
    else $declared++;
    if (!$row['producer_registered']) $noProducer[] = $row['event_key'];
    if ($row['producer_registered'] !== $row['producer_class_found'] && !str_contains((string) $row['producer'], ':')) $producerMismatch[] = $row['event_key'];
    if ($row['review_outstanding'] !== array()) $reviewRequired[$row['event_key']] = $row['review_outstanding'];
    if ($row['provider']['whatsapp_state'] === 'not_mapped') $unmappedWhatsApp[] = $row['event_key'];
    foreach ($row['templates'] as $template) {
        $required++;
        if ($template['status'] === 'present') $existing++;
        else $missing++;
    }
}

$registry = array();
foreach (TelnyxTemplateRegistry::definitions() as $name => $definition) {
    $registry[] = array(
        'provider_template' => $name,
        'event_key' => (string) $definition['event_key'],
        'audience' => (string) $definition['audience'],
        'variable_order' => array_values((array) $definition['variable_order']),
    );
}

$smsRegistry = array();
foreach (SmsIrTemplateRegistry::definitions() as $key => $definition) {
    $smsRegistry[] = array(
        'template_key' => $key,
        'label' => (string) $definition['label'],
        'body' => (string) $definition['body'],
        'variables' => array_values((array) $definition['variables']),
        'events' => $definition['events'] === '*' ? 'every SMS event' : array_values((array) $definition['events']),
    );
}

$document = array(
    'generated_at' => gmdate('c'),
    'catalogue_version' => NotificationCatalogue::VERSION,
    'source' => 'platform/src/Integrations/Notifications/NotificationCatalogue.php + TelnyxTemplateRegistry.php',
    'scope' => 'Repository source of truth. Published versions, prepared drafts and Meta/Telnyx approval rows are live WordPress data and are listed under live_checks_outstanding.',
    'totals' => array(
        'events' => count($rows),
        'supported_event_channel_combinations' => $supportedCombinations,
        'required_templates' => $required,
        'existing_valid_templates' => $existing,
        'missing_templates' => $missing,
        'invalid_templates' => count($invalid),
        'missing_translations' => 0,
        'unpublished_canonical_templates' => 'live_check_required',
        'missing_provider_mappings' => count($unmappedWhatsApp),
        'unsupported_routes' => count($unsupportedRoutes),
        'events_without_registered_producer' => count($noProducer),
    ),
    'by_category' => $perCategory,
    'by_recipient' => $perRecipient,
    'events_per_channel' => $perChannel,
    'implementation' => array('implemented' => $implemented, 'partial' => $partial, 'declared' => $declared),
    'events_without_registered_producer' => $noProducer,
    'producer_declaration_mismatch' => $producerMismatch,
    'events_with_outstanding_review' => $reviewRequired,
    'whatsapp_routes_without_registry_mapping' => $unmappedWhatsApp,
    'whatsapp_registry' => $registry,
    'sms_registry' => $smsRegistry,
    'unsupported_routes' => $unsupportedRoutes,
    'invalid_templates' => $invalid,
    'events' => $rows,
    'live_checks_outstanding' => array(
        'wpvr_dzn_notification_templates row count and active versions',
        'dzn_notification_catalogue_seeded option presence',
        'dzn_notification_copy_drafts and dzn_notification_copy_versions option presence',
        'dzn_notification_event_enabled and dzn_notification_event_channels option values',
        'Telnyx/Meta template approval status for every registry entry',
        'SMS.ir approval status and the two configured provider template IDs',
        'Which provider templates were rejected (the account-claim template was rejected at last inspection)',
        'Integrated provider configuration for email, WhatsApp and each SMS route',
    ),
);

if (in_array('--json', $argv, true)) {
    echo json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}
if (in_array('--check', $argv, true)) {
    if ($missing !== 0 || $invalid !== array()) {
        fwrite(STDERR, sprintf("Notification inventory: %d missing, %d invalid supported templates.\n", $missing, count($invalid)));
        exit(1);
    }
    echo "Notification inventory: every supported template has valid copy.\n";
    exit(0);
}

/* ---------------------------------------------------------------- write artefacts */
$docs = $root . '/platform/docs';
file_put_contents($docs . '/NOTIFICATION-TEMPLATE-INVENTORY.json', json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

$line = static fn(string $text): string => '| ' . $text . ' |';
$markdown = array();
$markdown[] = '# Delnavazan notification template inventory';
$markdown[] = '';
$markdown[] = 'Generated by `php tools/notifications/inventory.php` from catalogue version `' . NotificationCatalogue::VERSION . '`.';
$markdown[] = 'Do not edit by hand; regenerate instead. The JSON twin is `NOTIFICATION-TEMPLATE-INVENTORY.json`.';
$markdown[] = '';
$markdown[] = 'This document describes the repository source of truth. Published versions, prepared drafts and';
$markdown[] = 'Meta/Telnyx approval rows are live WordPress data; see §6 for the checks that must be run against';
$markdown[] = 'the live site before any delivery channel is switched on.';
$markdown[] = '';
$markdown[] = '## 1. Totals';
$markdown[] = '';
$markdown[] = '| Measure | Value |';
$markdown[] = '|---|---|';
foreach (array(
    'Notification events' => count($rows),
    'Supported event/channel combinations' => $supportedCombinations,
    'Required event/channel/language templates' => $required,
    'Templates present and valid' => $existing,
    'Templates missing' => $missing,
    'Templates invalid' => count($invalid),
    'Missing translations' => 0,
    'Unpublished canonical templates' => 'live check',
    'Events offering WhatsApp without a registry mapping' => count($unmappedWhatsApp),
    'Unsupported event/channel routes' => count($unsupportedRoutes),
    'Events with no registered producer class' => count($noProducer),
) as $label => $value) {
    $markdown[] = $line($label . ' | ' . $value);
}
$markdown[] = '';
$markdown[] = '## 2. Distribution';
$markdown[] = '';
$markdown[] = '| Dimension | Value | Events |';
$markdown[] = '|---|---|---|';
foreach ($perCategory as $key => $count) $markdown[] = $line('Category | ' . $key . ' | ' . $count);
foreach ($perRecipient as $key => $count) $markdown[] = $line('Recipient | ' . $key . ' | ' . $count);
foreach ($perChannel as $key => $count) $markdown[] = $line('Channel | ' . $key . ' | ' . $count);
foreach (array('implemented' => $implemented, 'partial' => $partial, 'declared' => $declared) as $key => $count) $markdown[] = $line('Implementation | ' . $key . ' | ' . $count);
$markdown[] = '';
$markdown[] = '## 3. Events per notification';
$markdown[] = '';
$markdown[] = '| Event key | Notification | Recipient | Category | Authority | Implementation | Channels | Copy | Provider | Review |';
$markdown[] = '|---|---|---|---|---|---|---|---|---|---|';
foreach ($rows as $row) {
    $copyMissing = 0;
    foreach ($row['templates'] as $template) if ($template['status'] !== 'present') $copyMissing++;
    $copy = $copyMissing === 0 ? 'complete (fa + en)' : $copyMissing . ' missing';
    $provider = array();
    if (in_array('whatsapp', $row['channels'], true)) $provider[] = $row['provider']['whatsapp_state'] === 'not_mapped' ? 'WhatsApp: no Meta template' : 'WhatsApp: registry entry, approval unverified';
    if (in_array('sms', $row['channels'], true)) $provider[] = $row['provider']['sms_state'] === 'dedicated_secure_route' ? 'SMS: dedicated secure route' : 'SMS: ' . (SmsIrTemplateRegistry::forEvent($row['event_key'])['label']) . ' (approved SMS.ir template)';
    if (in_array('email', $row['channels'], true)) $provider[] = 'Email: shell provider';
    if ($provider === array()) $provider[] = 'Portal only';
    $markdown[] = $line(
        '`' . $row['event_key'] . '` | ' . $row['notification'] . ' | ' . $row['recipient'] . ' | ' . $row['category'] . ' | '
        . $row['delivery_authority'] . ' | ' . $row['implementation'] . ($row['producer_registered'] ? '' : ' (no producer)') . ' | ' . implode(', ', $row['channels']) . ' | '
        . $copy . ' | ' . implode('; ', $provider) . ' | '
        . ($row['review_outstanding'] === array() ? '—' : implode(', ', $row['review_outstanding']))
    );
}
$markdown[] = '';
$markdown[] = '## 4. WhatsApp provider registry';
$markdown[] = '';
$markdown[] = 'The registry below is the closed set of provider template names the transport may use. A registry';
$markdown[] = 'entry is still not an approval: `TelnyxTemplateAuthority` only maps a route when discovery holds';
$markdown[] = 'exactly one APPROVED Utility row whose variable order matches the local contract.';
$markdown[] = '';
$markdown[] = '| Provider template | Event | Audience | Variable order |';
$markdown[] = '|---|---|---|---|';
foreach ($registry as $entry) {
    $markdown[] = $line('`' . $entry['provider_template'] . '` | `' . $entry['event_key'] . '` | ' . $entry['audience'] . ' | ' . implode(', ', $entry['variable_order']));
}
$markdown[] = '';
$markdown[] = 'Events offering WhatsApp with no registry entry (' . count($unmappedWhatsApp) . '): ' . ($unmappedWhatsApp === array() ? 'none' : '`' . implode('`, `', $unmappedWhatsApp) . '`') . '.';
$markdown[] = '';
$markdown[] = 'These routes keep their local copy for provider submission and remain disabled until an approved';
$markdown[] = 'template is mapped. A locally prepared draft is never presented as a Meta-approved template.';
$markdown[] = '';
$markdown[] = '## 4b. SMS.ir provider templates';
$markdown[] = '';
$markdown[] = 'SMS.ir is the Iranian (+98) route. Every booking, lesson and renewal event-owned SMS uses one of';
$markdown[] = 'the two approved bodies below; dedicated one-time-secret messages keep their own secure body.';
$markdown[] = 'Telnyx carries international SMS with the catalogue copy. When a provider template ID is configured,';
$markdown[] = 'the adapter calls the SMS.ir template endpoint with the uppercase parameter names; otherwise it sends';
$markdown[] = 'the exact approved body through the bulk endpoint.';
$markdown[] = '';
$markdown[] = '| Template key | Label | Variables | Events |';
$markdown[] = '|---|---|---|---|';
foreach ($smsRegistry as $entry) {
    $events = is_array($entry['events']) ? '`' . implode('`, `', $entry['events']) . '`' : $entry['events'];
    $markdown[] = $line('`' . $entry['template_key'] . '` | ' . $entry['label'] . ' | ' . implode(', ', $entry['variables']) . ' | ' . $events);
}
$markdown[] = '';
foreach ($smsRegistry as $entry) {
    $markdown[] = '`' . $entry['template_key'] . '`: ' . str_replace("\n", ' / ', (string) $entry['body']);
    $markdown[] = '';
}
$markdown[] = '## 5. Outstanding review';
$markdown[] = '';
if ($reviewRequired === array()) {
    $markdown[] = 'No notification carries an outstanding review flag.';
} else {
    $markdown[] = '| Event key | Outstanding approval |';
    $markdown[] = '|---|---|';
    foreach ($reviewRequired as $eventKey => $codes) $markdown[] = $line('`' . $eventKey . '` | ' . implode(', ', $codes));
}
$markdown[] = '';
$markdown[] = '## 6. Outstanding implementation work (not delivered functionality)';
$markdown[] = '';
$markdown[] = 'These entries have copy and provider metadata but cannot be delivered as working notifications,';
$markdown[] = 'so they must not be reported as completed functionality.';
$markdown[] = '';
$markdown[] = '### 6.1 Events with no registered producer (' . count($noProducer) . ')';
$markdown[] = '';
if ($noProducer === array()) {
    $markdown[] = 'None.';
} else {
    $markdown[] = '| Event key | Declared producer | Why it cannot fire |';
    $markdown[] = '|---|---|---|';
    foreach ($noProducer as $eventKey) {
        $producer = (string) (NotificationCatalogue::get($eventKey)['producer'] ?? '');
        $why = str_contains($producer, ':') ? 'Finance intent only; no dispatch workflow is registered.' : 'The named producer class does not exist in this repository.';
        $markdown[] = $line('`' . $eventKey . '` | `' . $producer . '` | ' . $why);
    }
}
$markdown[] = '';
$markdown[] = '### 6.2 WhatsApp routes with no Meta template (' . count($unmappedWhatsApp) . ')';
$markdown[] = '';
if ($unmappedWhatsApp === array()) {
    $markdown[] = 'None.';
} else {
    $markdown[] = 'Local copy exists for provider submission, but no approved provider template is mapped, so';
    $markdown[] = 'these routes cannot be enabled and no message is sent.';
    $markdown[] = '';
    foreach ($unmappedWhatsApp as $eventKey) $markdown[] = '- `' . $eventKey . '`';
}
$markdown[] = '';
$markdown[] = '### 6.3 Live checks outstanding';
$markdown[] = '';
foreach ($document['live_checks_outstanding'] as $check) $markdown[] = '- ' . $check;
if ($producerMismatch !== array()) {
    $markdown[] = '';
    $markdown[] = '### 6.4 Producer declaration mismatches (';
    $markdown[] = implode(', ', $producerMismatch) . ')';
}
file_put_contents($docs . '/NOTIFICATION-TEMPLATE-INVENTORY.md', implode(PHP_EOL, $markdown) . PHP_EOL);

printf(
    "Wrote %s/NOTIFICATION-TEMPLATE-INVENTORY.{json,md}: %d events, %d routes, %d templates, %d present, %d missing, %d invalid.\n",
    $docs, count($rows), $supportedCombinations, $required, $existing, $missing, count($invalid)
);
