<?php
/**
 * Source contract for the unified notification management surface.
 *
 * Proves the security boundary and the architectural invariants that cannot be exercised without a
 * WordPress request: capability and nonce checks on every mutation, escaped output, the immutable
 * versioned template authority, contact-free inbox storage and ownership-scoped read state.
 */
$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/src/Admin/Controller/NotificationManagementController.php');
$library = (string) file_get_contents($root . '/src/Integrations/Notifications/NotificationTemplateLibrary.php');
$catalogue = (string) file_get_contents($root . '/src/Integrations/Notifications/NotificationCatalogue.php');
$authority = (string) file_get_contents($root . '/src/Integrations/Notifications/TelnyxTemplateAuthority.php');
$adapter = (string) file_get_contents($root . '/src/Integrations/TelnyxWhatsAppAdapter.php');
$inbox = (string) file_get_contents($root . '/src/Portals/PortalNotificationInbox.php');
$migration = (string) file_get_contents($root . '/src/Core/Infrastructure/Migration/Migrator.php');
$studentShell = (string) file_get_contents($root . '/../theme/template-parts/portal/shell.php');
$teacherShell = (string) file_get_contents($root . '/../theme/template-parts/teacher-portal/shell.php');
$studentInbox = (string) file_get_contents($root . '/../theme/template-parts/portal/notifications.php');
$submission = (string) file_get_contents($root . '/src/Core/Application/BookingRequestSubmissionService.php');
$fail = static function (string $name, bool $ok): void { if (!$ok) throw new RuntimeException('Notification management contract failed: ' . $name); };

/* 1. Every mutation is capability- and nonce-gated and every redirect is same-site. */
foreach (array(
    'dzn_notification_copy_save_draft', 'dzn_notification_copy_publish', 'dzn_notification_copy_discard',
    'dzn_notification_copy_restore', 'dzn_notification_copy_restore_default', 'dzn_notification_provider_mapping',
    'dzn_notification_event_toggle', 'dzn_notification_event_channel_toggle', 'dzn_notification_inbox_prune', 'dzn_notification_inbox_test',
    'dzn_notification_recover_leases', 'dzn_notification_prepare_drafts', 'dzn_notification_publish_drafts',
    'dzn_notification_disable_blocked_mobile',
    'dzn_notification_approve_draft', 'dzn_notification_revoke_approval', 'dzn_notification_approve_operational',
    'dzn_notification_approve_all_drafts', 'dzn_notification_manual_send',
) as $action) {
    $fail('registered action ' . $action, str_contains($controller, "admin_post_" . $action));}
$fail('editing uses the canonical template capability', str_contains($controller, "CAP_EDIT='dzn_manage_notification_templates'"));
$fail('a manual send is idempotent by content, not by clock', str_contains($controller, "hash('sha256',wp_json_encode(\$input))") && !str_contains($controller, "'manual-send:'.gmdate"));
$fail('a manual send requires the dispatch capability', str_contains((string) file_get_contents(dirname(__DIR__) . '/src/Integrations/Notifications/NotificationManualSendService.php'), "current_user_can(self::CAPABILITY)"));
$fail('channel toggles have an AJAX no-reload path', str_contains($controller, 'admin_post_dzn_notification_channel_toggle_ajax') && str_contains($controller, 'ajaxToggleEventChannel') && str_contains($controller, 'admin_post_dzn_notification_event_channel_toggle'));
$fail('the AJAX channel toggle reuses capability, nonce and the audited save path', str_contains($controller, "wp_verify_nonce(isset(\$_POST['_wpnonce'])?(string)wp_unslash(\$_POST['_wpnonce']):'','dzn_notification_event_channel_toggle')") && str_contains($controller, 'wp_send_json_success(self::applyChannelToggle') && str_contains($controller, 'saveEventChannel($eventKey,$channel,$enabled)'));
$fail('the channel toggle is progressive enhancement, not a replacement', str_contains($controller, 'liveToggleScript') && str_contains($controller, 'dzn_notification_channel_toggle') && str_contains($controller, 'preventDefault') && str_contains($controller, 'new FormData(form)') && str_contains($controller, 'dzn-notif-live-status') && !str_contains($controller, 'location.reload'));
$fail('every handler checks a nonce', substr_count($controller, 'check_admin_referer(') >= 2);
$fail('redirects are validated', str_contains($controller, 'wp_safe_redirect'));
$fail('no eval or request superglobal', !str_contains($controller, 'eval(') && !str_contains($controller, '$_REQUEST'));
$fail('no unfiltered HTML authority', !str_contains($controller, 'unfiltered_html'));
$fail('output is escaped', str_contains($controller, 'esc_html(') && str_contains($controller, 'esc_attr(') && str_contains($controller, 'esc_url('));
$fail('the recorder is imported from its real namespace', str_contains($controller, 'use Delnavazan\\Platform\\Core\\Application\\PortalNotificationRecorder;'));
$fail('the safe inbox test uses a catalogue event', str_contains($controller, "system.inbox_check.admin"));

/* 1b. The preview regression is fixed at the call site, not hidden behind a suppression. */
$fail('preview passes the locale into the primary action helper', str_contains($library, '$this->ctaFor($eventKey,$body,$variables,(string)(NotificationCatalogue::get($eventKey)[\'audience\']??\'student\'),$locale)'));
$fail('preview masks one-time secrets', str_contains($library, "foreach(array('claim_code','invitation_code') as \$secret)") && str_contains($library, "'[one-time code]'"));
$fail('preview test covers all four channels', is_file($root . '/tests/notification-preview-unit.php') && str_contains((string) file_get_contents($root . '/tests/notification-preview-unit.php'), "\$channelsSeen['website']"));
$fail('delivery status test exists', is_file($root . '/tests/notification-delivery-status-unit.php'));

/* 1c. Notifications opens on the single event catalogue; old tabs stay aliases or redirect to Diagnostics. */
$fail('events is the default workspace', str_contains($controller, "'events'=>'Events'") || str_contains($controller, "sanitize_key(wp_unslash((string)\$_GET['tab'])):'events'"));
$fail('legacy tab URLs are mapped', str_contains($controller, "'rules'=>'events'") && str_contains($controller, "'templates'=>'events'"));
$fail('legacy diagnostics redirects centrally', str_contains($controller, "page=dzn-system-health&component=notifications"));
$diagnostics = (string) file_get_contents($root . '/src/Admin/Controller/DiagnosticsController.php');
$overview = (string) file_get_contents($root . '/src/Admin/Controller/AcademyOperationsController.php');
$fail('the notifications component is honoured', str_contains($diagnostics, "\$component === 'notifications'") && str_contains($diagnostics, 'renderNotificationAttention()'));
$fail('the failure card explains every actionable class', str_contains($diagnostics, "'retry_exhausted'") && str_contains($diagnostics, "'stuck_lease'") && str_contains($diagnostics, "'orphan_outbox_row'") && str_contains($diagnostics, "'unregistered_intent'"));
$fail('the register links each failure to its owning record', str_contains($diagnostics, 'private static function notificationRecordUrl') && str_contains($diagnostics, "page=dzn-booking-request-coordination&booking_request_id=") && str_contains($diagnostics, "page=dzn-lesson&id="));
$fail('the retry stays bound to one exact notification', str_contains($diagnostics, 'from its owning record or from the row below') && str_contains($diagnostics, "ACTION_RETRY = 'dzn_notification_retry_exact'") && str_contains($diagnostics, 'NotificationManualDeliveryService') && str_contains($diagnostics, "current_user_can('dzn_operate_notification_dispatch')") && str_contains($diagnostics, 'check_admin_referer(self::ACTION_RETRY)') && str_contains($diagnostics, "'diagnostics-retry:' . \$notificationId"));
$fail('the retry surface accepts no recipient or template', !str_contains($diagnostics, 'name="recipient"') && !str_contains($diagnostics, 'name="template') && !str_contains($diagnostics, 'dzn_notification_manual_retry'));
$fail('the Overview card opens Diagnostics instead of the retired stub', str_contains($overview, 'notificationAttention(') && str_contains($overview, "'dzn-system-health'") && str_contains($overview, "'dzn-notifications'") && !str_contains($overview, 'dzn-communications'));
$fail('an unconfigured event route is not counted as a delivery failure', !str_contains(preg_replace('/\/\*\*.*?\*\//s', '', $overview), "(int) (\$report['unroutable_intent']"));
$fail('the shared outbox is scoped to notification aggregates', str_contains((string) file_get_contents($root . '/src/Core/Application/NotificationRule.php'), 'OUTBOX_AGGREGATES') && str_contains((string) file_get_contents($root . '/src/Core/Application/NotificationDiagnosticsReadService.php'), 'OUTBOX_AGGREGATES') && str_contains((string) file_get_contents($root . '/src/Core/Application/NotificationDiagnosticsReadService.php'), "notification_id IS NOT NULL AND status='leased'"));
$fail('one event table uses combined search and compact filters', str_contains($controller, 'name="search"') && str_contains($controller, 'name="category"') && str_contains($controller, 'name="recipient"') && str_contains($controller, 'name="status"'));
$fail('all four delivery methods are rendered together', str_contains($controller, "array('website','email','whatsapp','sms')"));
$fail('effective status replaces legacy readiness columns', str_contains($controller, 'name="status"') && str_contains($controller, 'dzn-notif-grid-cards') && !str_contains($controller, '<th>Template readiness</th>') && !str_contains($controller, '<th>Latest delivery</th>'));
$fail('the editor opens in the workspace without leaving it', str_contains($controller, 'self::renderEvents();') && str_contains($controller, 'id="dzn-notif-drawer"') && str_contains($controller, 'self::renderEditor(new NotificationTemplateLibrary()'));
$fail('table filters survive editor navigation', str_contains($controller, 'private static function editorUrl') && str_contains($controller, 'filter_context'));
$fail('the template editor opens as a drawer that preserves the list position', str_contains($controller, 'class="dzn-notif-drawer"') && str_contains($controller, 'dzn-notif-drawer-close') && str_contains($controller, '#event-') && str_contains($controller, 'scroll-margin-top'));
$fail('reschedule families are genuinely collapsible', str_contains($controller, '<details><summary><strong>') && str_contains($controller, 'private static function eventFamily'));
$fail('purpose copy is event-specific or family-specific', str_contains($catalogue, 'private static function purpose') && !str_contains($catalogue, 'This message tells the recipient what happened'));
$fail('global channel policy editor is removed', !str_contains($controller, 'dzn_notification_channel_policy') && !str_contains($library, 'OPTION_CHANNEL_POLICY'));
$fail('the retired category override is deleted during admin upgrade', str_contains($library, 'retireLegacyCategoryPolicy') && str_contains($library, "delete_option(\$key)"));
$fail('generic notification resend surface is removed', !str_contains($controller, 'dzn_notification_manual_retry') && !str_contains($controller, 'Review a specific notification for resend'));
$fail('provider mapping lives with the WhatsApp template editor', str_contains($controller, 'private static function renderProviderMapping') && str_contains($controller, "if(\$channel!=='whatsapp')return;"));
$fail('admin and transport share one provider mapping authority', str_contains($library, 'TelnyxTemplateAuthority') && str_contains($adapter, 'TelnyxTemplateAuthority') && str_contains($authority, "count(\$approved)>1") && str_contains($authority, 'CONTRACT_MISMATCH'));
$fail('discovered mappings do not expose a duplicate manual editor', str_contains($controller, "(\$mapping['source']??'')==='discovery'") && str_contains($controller, 'verified Telnyx template discovery record'));
$fail('Twilio is not presented as implemented', !str_contains($controller, 'Twilio') && str_contains((string) file_get_contents($root . '/src/Admin/Controller/IntegrationControlPlaneController.php'), 'Twilio WhatsApp is not implemented'));

/* 1d. Booking and class records show the shared delivery status vocabulary. */
$fail('booking records show notification status', str_contains((string) file_get_contents($root . '/src/Admin/Controller/BookingRequestController.php'), "renderRecordStatus('booking_request'"));
$fail('class records show notification status', str_contains((string) file_get_contents($root . '/src/Admin/Controller/ScreenController.php'), "renderRecordStatus('lesson'"));
$fail('the status reader keeps hand-off separate from confirmation', str_contains((string) file_get_contents($root . '/src/Core/Application/NotificationDeliveryStatusReadService.php'), 'HANDED_OFF') && str_contains((string) file_get_contents($root . '/src/Core/Application/NotificationDeliveryStatusReadService.php'), 'DELIVERY_CONFIRMED'));

/* 2. Publishing keeps the immutable canonical model and never rewrites a stored version body. */
$fail('publish uses the canonical service', str_contains($library, 'NotificationTemplateService') && str_contains($library, 'registerVersion(') && str_contains($library, 'activateVersion('));
$fail('version body is immutable', str_contains($library, 'A template version is immutable') && str_contains($library, 'if(isset($bodies[$versionId]))return;'));
$fail('copy is keyed by version id', str_contains($library, 'template_version_id') && str_contains($library, 'OPTION_COPY_VERSIONS'));
$fail('no provider credential is stored', !str_contains($library, 'api_key') && !str_contains($library, 'access_token') && !str_contains($library, 'client_secret'));

/* 3. The catalogue is a single definition source with categories, authorities and channel policy. */
foreach (array('CATEGORIES', 'TASKS', 'taskKey', 'AUTHORITIES', 'IMPLEMENTATION', 'variables', 'defaultCopy', 'templateKey', 'isMandatory') as $needle) {
    $fail('catalogue exposes ' . $needle, str_contains($catalogue, $needle));
}
$fail('one-time secret events stay security-only', str_contains($catalogue, "'account.claim_issued.student'") && str_contains($catalogue, "'teacher.invitation_issued.teacher'"));
$fail('account-claim mobile routes are administrator-controlled and audited', !str_contains($library, 'notification_channel_secure_route') && str_contains($library, "audit(\$enabled?'enable_channel':'disable_channel'"));
$fail('only Utility provider templates can map transactional messages', str_contains($authority, "\$utility=strtoupper") && str_contains($authority, "not a Utility template"));
$fail('new intro bookings record an admin inbox alert', str_contains($catalogue, "'booking.submitted.admin'") && str_contains($submission, "PortalNotificationRecorder::admin('booking.submitted.admin'"));

/* 3b. Mobile activation is limited to methods a real producer sends. */
$fail('catalogue declares the mobile producer map', str_contains($catalogue, 'MOBILE_PRODUCER_CHANNELS') && str_contains($catalogue, 'producesMobileChannel'));
$fail('bulk mobile activation uses the producer map', str_contains($controller, 'producesMobileChannel') && !str_contains($controller, "(string)(\$event['category']??'')!=='operational'"));
$fail('a method without a producer is not switchable', str_contains($controller, "'no_producer'") && str_contains($controller, 'has no sender for that mobile method'));
$fail('WhatsApp readiness ignores events with no producer', str_contains((string) file_get_contents($root . '/src/Integrations/Notifications/WhatsAppProviderReadinessReadService.php'), "producesMobileChannel(\$eventKey,'whatsapp')"));
require_once $root . '/src/Integrations/Notifications/NotificationCatalogue.php';
$catalogueClass = 'Delnavazan\\Platform\\Integrations\\Notifications\\NotificationCatalogue';
$mobileMap = $catalogueClass::MOBILE_PRODUCER_CHANNELS;
$fail('mobile producer map is not empty', $mobileMap !== array());
foreach ($mobileMap as $mobileEvent => $mobileChannels) {
    $fail('mobile producer event exists: ' . $mobileEvent, $catalogueClass::get($mobileEvent) !== null);
    foreach ($mobileChannels as $mobileChannel) {
        $fail('mobile producer channel is known: ' . $mobileEvent, in_array($mobileChannel, array('whatsapp', 'sms'), true));
    }
}
$fail('the confirmed booking has a WhatsApp producer', $catalogueClass::producesMobileChannel('booking.confirmed.student', 'whatsapp'));
$fail('student booking is WhatsApp-only', $catalogueClass::producesMobileChannel('booking.confirmed.student', 'whatsapp') && !$catalogueClass::producesMobileChannel('booking.confirmed.student', 'sms'));
$fail('student reschedule has a WhatsApp producer', $catalogueClass::producesMobileChannel('reschedule.change.proposed.student', 'whatsapp') && !$catalogueClass::producesMobileChannel('reschedule.change.proposed.student', 'sms'));
$fail('the renewal notice has a WhatsApp producer', $catalogueClass::producesMobileChannel('renewal.automatic_upcoming.student', 'whatsapp'));
$fail('the teacher cancellation is SMS-only', $catalogueClass::producesMobileChannel('booking.cancelled.teacher', 'sms') && !$catalogueClass::producesMobileChannel('booking.cancelled.teacher', 'whatsapp'));
$fail('one-time account codes have no mobile producer', !$catalogueClass::producesMobileChannel('account.claim_issued.student', 'whatsapp') && !$catalogueClass::producesMobileChannel('account.claim_issued.student', 'sms'));
$fail('consent notices have no mobile producer', !$catalogueClass::producesMobileChannel('consent.student_required.student', 'whatsapp'));
$fail('only the new intro admin event has a mobile producer', (static function () use ($mobileMap): bool {
    $admin = array();
    foreach ($mobileMap as $eventKey => $channels) if (str_ends_with((string) $eventKey, '.admin')) $admin[$eventKey] = $channels;
    return array_keys($admin) === array('booking.submitted.admin') && in_array('whatsapp', $admin['booking.submitted.admin'], true);
})());
$notifier = (string) file_get_contents($root . '/src/Core/Application/AdminIntroWhatsAppNotifier.php');
$fail('admin WhatsApp is limited to the new intro event', str_contains($notifier, "EVENT_KEY='booking.submitted.admin'") && !str_contains($notifier, 'booking.escalation') && !str_contains($notifier, 'booking.referral'));
$fail('admin WhatsApp uses the operations number and the idempotent adapter', str_contains($notifier, "'admin_whatsapp_number'") && str_contains($notifier, 'sendBookingNotification') && str_contains($notifier, 'NotificationChannelPolicy::allows'));
$fail('admin WhatsApp is scheduled after the intake commit', str_contains((string) file_get_contents($root . '/src/Core/Application/BookingRequestSubmissionService.php'), 'AdminIntroWhatsAppNotifier::schedule') && str_contains((string) file_get_contents($root . '/src/Core/Application/BookingStudentAccountService.php'), 'AdminIntroWhatsAppNotifier::schedule'));
$receipt = (string) file_get_contents($root . '/src/Core/Application/OperationalNotificationReceipt.php');
$fail('operational receipts use the unique audit key', str_contains($receipt, 'platform_audit_events') && str_contains($receipt, 'idempotency_key') && str_contains($receipt, 'return $ok!==false;'));
$fail('lesson, renewal and payment sends claim a durable receipt', str_contains((string) file_get_contents($root . '/src/Core/Application/LessonLifecycleNotificationService.php'), "OperationalNotificationReceipt::claim(") && str_contains((string) file_get_contents($root . '/src/Core/Application/RenewalAutomationNotificationService.php'), "OperationalNotificationReceipt::claim(") && str_contains((string) file_get_contents($root . '/src/Core/Application/PaymentRefundNotificationService.php'), "OperationalNotificationReceipt::claim("));
$fail('mobile methods without a producer are not offered', str_contains($library, 'producesMobileChannel($eventKey,$channel)') && !str_contains($library, 'return $event!==null&&in_array($channel,(array)$event[\'channels\'],true);'));
$fail('the card renders only offered methods', str_contains($controller, "foreach((array)\$row['offered'] as \$channel)"));
$fail('the editor hides an unoffered method', str_contains($controller, 'if(!$library->channelOffered($eventKey,$channel))continue;'));
$fail('blocked-mobile cleanup is registered and gated', str_contains($controller, 'disableBlockedMobile') && str_contains($controller, 'dzn_notification_disable_blocked_mobile') && str_contains($controller, 'check_admin_referer(\'dzn_notification_disable_blocked_mobile\')'));
$fail('blocked-mobile cleanup only disables what cannot send', str_contains($controller, 'producesMobileChannel($eventKey,$channel)&&self::channelBlocker($library,$eventKey,$channel)===null') && str_contains($controller, 'saveEventChannel($eventKey,$channel,false)'));
$fail('WhatsApp readiness uses the Telnyx event transport', str_contains($controller, 'new TelnyxConfiguration($settings)') && str_contains($controller, 'whatsappReady()'));
$testRunner = (string) file_get_contents($root . '/src/Integrations/IntegrationTestRunner.php');
$fail('the WhatsApp test requires Telnyx event readiness', str_contains($testRunner, 'telnyx_whatsapp_configuration_incomplete') && str_contains($testRunner, 'whatsappReady()'));
$fail('the WhatsApp test cannot fall back to Meta direct', !str_contains($testRunner, 'graph.facebook.com'));

/* 4. Inbox storage is contact-free and read state is scoped to the linked WordPress user. */
$fail('migration creates the inbox table', str_contains($migration, 'portal_notifications') && str_contains($migration, 'UNIQUE KEY dedupe_key'));
$fail('inbox never stores contact or provider data', str_contains($migration, "'email','phone','whatsapp','secret','token','payload','provider'"));
$fail('mark read is owner-scoped in SQL', str_contains($inbox, 'WHERE id=%d AND wp_user_id=%d'));
$fail('inbox query is user-scoped', str_contains($inbox, 'WHERE wp_user_id=%d'));
$fail('dedupe is a keyed digest', str_contains($inbox, 'hash_hmac(') && str_contains($inbox, 'dedupe_key'));

/* 5. Both portals show a bell and an owned list, and a read action is never a business approval. */
$fail('student shell has a bell', str_contains($studentShell, 'dzn-appbar__bell') && str_contains($studentShell, 'dzn-appbar__badge'));
$fail('teacher shell has a bell', str_contains($teacherShell, 'dzn-tp-appbar__bell') && str_contains($teacherShell, 'dzn-tp-appbar__badge'));
$fail('student inbox has read state and nonce', str_contains($studentInbox, 'dzn_portal_notification_read') && str_contains($studentInbox, 'dzn_theme_nonce_field'));
$fail('student inbox never approves a business action', str_contains($studentInbox, 'هیچ اقدام کاری') || str_contains($studentInbox, 'تأیید یا تغییر کلاس'));

echo "Notification management contract passed\n";
