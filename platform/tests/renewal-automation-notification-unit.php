<?php
namespace Delnavazan\Platform\Core\Application {
    /** Unit harness: the production guard is exercised by the runtime/canonical suites. */
    final class NotificationSuppressionGuard {
        public function suppressed(string $audience,int $recipientId,string $eventKey):bool{return false;}
    }
}
/** Renewal-automation notifications: catalogue declaration, idempotency, fact gating, clock-driven due window, recipient resolution and the WhatsApp leg. */
namespace {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
    function wp_date(string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null): string {
        return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone($timezone ?? new DateTimeZone('UTC'))->format($format);
    }
    function wp_mail(...$args): bool { return false; }
    function home_url(string $path = ''): string { return 'https://example.invalid' . $path; }
    function add_action(...$args): void {}
    function do_action(...$args): void {}
    function wp_schedule_single_event(...$args): void {}
    function wp_next_scheduled(...$args) { return false; }

    require_once dirname(__DIR__, 2) . '/platform/src/Integrations/Notifications/NotificationCatalogue.php';
    require_once dirname(__DIR__, 2) . '/platform/src/Integrations/Notifications/PhoneNumberNormalizer.php';
    require_once dirname(__DIR__, 2) . '/platform/src/Integrations/Notifications/TelnyxTemplateRegistry.php';
    require_once dirname(__DIR__, 2) . '/platform/src/Core/Application/Finance/Pricing/PricingMoney.php';
    require_once dirname(__DIR__, 2) . '/platform/src/Core/Application/OperationalNotificationReceipt.php';

    require_once dirname(__DIR__, 2) . '/platform/src/Core/Application/RenewalAutomationNotificationService.php';
    require_once dirname(__DIR__, 2) . '/platform/src/Integrations/RenewalAutomationWorker.php';

    use Delnavazan\Platform\Core\Application\RenewalAutomationNotificationService;
    use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
    use Delnavazan\Platform\Integrations\RenewalAutomationWorker;

    $fail = static function (string $name, bool $ok): void {
        if (!$ok) throw new RuntimeException('Renewal-automation notification unit failed: ' . $name);
    };

    /* (i) Each of the six events is declared with the expected channels, copy, producer and authority. */
    foreach (array(
        'renewal.automatic_charged.student' => array('label' => 'Automatic Renewal Charged', 'variables' => array('name', 'course', 'amount', 'paid_date', 'portal_url')),
        'renewal.automatic_failed.student' => array('label' => 'Automatic Renewal Failed', 'variables' => array('name', 'course', 'amount', 'due_date', 'portal_url')),
        'renewal.automatic_upcoming.student' => array('label' => 'Automatic Renewal Upcoming', 'variables' => array('name', 'course', 'due_date', 'amount', 'portal_url')),
        'renewal.guarantee_deadline_approaching.student' => array('label' => 'Renewal Deadline Approaching', 'variables' => array('name', 'course', 'due_date', 'amount', 'portal_url')),
        'renewal.manual_payment_required.student' => array('label' => 'Renewal Payment Required', 'variables' => array('name', 'course', 'amount', 'due_date', 'portal_url')),
        'renewal.term_lapsed.student' => array('label' => 'Term Lapsed', 'variables' => array('name', 'course', 'portal_url')),
    ) as $key => $spec) {
        $event = NotificationCatalogue::get($key);
        $fail($key . ' is declared', is_array($event));
        $fail($key . ' targets the student', $event['audience'] === 'student');
        $fail($key . ' is a payment event', $event['category'] === 'payment');
        $fail($key . ' offers every delivery method', $event['channels'] === NotificationCatalogue::CHANNELS);
        $fail($key . ' is financial and mandatory', $event['consent'] === 'financial' && $event['mandatory'] === true);
        $fail($key . ' has a registered producer', $event['implementation'] === 'implemented' && $event['producer_registered'] === true);
        $fail($key . ' is produced by the dedicated renewal service', $event['producer'] === 'RenewalAutomationNotificationService');
        $fail($key . ' is owned by an event-owned queue', $event['authority'] === 'queue');
        $fail($key . ' carries no one-time-secret review hold', $event['review'] === array());
        $fail($key . ' declares the producer-supplyable variables', $event['variables'] === $spec['variables']);
        $fail($key . ' labels the notice', $event['label'] === $spec['label']);
        foreach (array('email', 'website', 'whatsapp') as $channel) {
            foreach (NotificationCatalogue::SUPPORTED_LOCALES as $locale) {
                $copy = NotificationCatalogue::exactCopy($key, $channel, $locale);
                $fail($key . '/' . $channel . '/' . $locale . ' has copy', is_array($copy) && trim((string) ($copy['body'] ?? '')) !== '');
            }
        }
        $fa = NotificationCatalogue::exactCopy($key, 'email', 'fa-IR');
        $en = NotificationCatalogue::exactCopy($key, 'email', 'en');
        $fail($key . ' Persian email greets with درود', str_contains($fa['body'], 'درود') && !str_contains($fa['body'], 'سلام'));
        $fail($key . ' English email greets with Hello', str_contains($en['body'], 'Hello'));
        $fail($key . ' email links the student portal', str_contains($fa['body'], '{{portal_url}}') && str_contains($en['body'], '{{portal_url}}'));
    }
    $fail('the retired greeting stays retired across renewal copy', !str_contains(NotificationCatalogue::exactCopy('renewal.term_lapsed.student', 'email', 'fa-IR')['body'], 'سلام'));

    /* (ii) Every WhatsApp route is registry-mapped and its variable order is a subset of the declared
     *       variables, so the dispatch-time provider mapping can never invent a parameter. */
    foreach (array(
        'renewal.automatic_charged.student' => 'delnavazan_renewal_automatic_charged_student_v2_fa',
        'renewal.automatic_failed.student' => 'delnavazan_renewal_automatic_failed_student_v2_fa',
        'renewal.automatic_upcoming.student' => 'delnavazan_renewal_reminder_v2_fa',
        'renewal.guarantee_deadline_approaching.student' => 'delnavazan_renewal_guarantee_deadline_approaching_student_v2_fa',
        'renewal.manual_payment_required.student' => 'delnavazan_payment_due_v2_fa',
        'renewal.term_lapsed.student' => 'delnavazan_renewal_term_lapsed_student_v2_fa',
    ) as $key => $templateName) {
        $definition = \Delnavazan\Platform\Integrations\Notifications\TelnyxTemplateRegistry::definitionForName($templateName);
        $fail($key . ' is registry-mapped', $definition !== null && $definition['event_key'] === $key);
        $order = array_values((array) $definition['variable_order']);
        $declared = NotificationCatalogue::variables($key);
        $fail($key . ' registry order stays within the declared variables', array_diff($order, $declared) === array());
    }

    /* A scenario builder with injectable email/WhatsApp collaborators and a shared durable inbox state. */
    $makeRow = static function (string $state, string $kind, int $studentId = 7, string $email = 'student@example.invalid', string $whatsapp = '09123456789', string $country = 'IR', array $overrides = array()): array {
        return array_merge(array(
            'student_id' => $studentId,
            'state' => $state,
            'kind' => $kind,
            'collection_mode' => $kind === 'automatic_charge' ? 'automatic' : 'manual',
            'student_email' => $email,
            'student_phone' => '09123456789',
            'student_whatsapp' => $whatsapp,
            'student_country_code' => $country,
            'student_name' => 'باران',
            'course_name' => 'تار',
            'amount_minor' => 2500000,
            'currency' => 'TOM',
            'automatic_charge_at' => '2026-11-20 09:00:00',
            'guarantee_deadline_at' => '2026-11-15 09:00:00',
            'paid_date' => '2026-11-12 09:00:00',
            'student_portal_url' => 'https://example.invalid/student-portal/',
        ), $overrides);
    };

    $make = static function (array $row, array $opts = array()): array {
        $state = new \stdClass();
        $state->recorded = array();
        $state->sent = array();
        $state->whatsapp = array();
        $state->portal = array();
        $seen = static function (string $eventKey, int $subjectId, string $audience = 'student') use ($state): bool { return isset($state->portal[$eventKey . ':' . $subjectId . ':' . $audience]); };
        $record = static function (int $recipientId, string $eventKey, string $sourceKind, int $sourceId, array $variables, ?string $linkUrl) use ($state): ?int {
            $key = $eventKey . ':' . $sourceId . ':student';
            if (isset($state->portal[$key])) return $state->portal[$key];
            $id = count($state->portal) + 1;
            $state->portal[$key] = $id;
            $state->recorded[] = array('recipient_id' => $recipientId, 'audience' => 'student', 'event_key' => $eventKey, 'source_kind' => $sourceKind, 'source_id' => $sourceId, 'variables' => $variables, 'link_url' => $linkUrl);
            return $id;
        };
        $transport = static function (string $email, string $subject, string $body, string $audience = 'student') use ($state): bool {
            $state->sent[] = array('email' => $email, 'subject' => $subject, 'body' => $body, 'audience' => $audience);
            return true;
        };
        $whatsappSender = $opts['whatsapp_sender'] ?? static function (string $to, int $notificationId, string $eventKey, array $parameters) use ($state, $opts): array {
            $state->whatsapp[] = array('to' => $to, 'notificationId' => $notificationId, 'event_key' => $eventKey, 'parameters' => $parameters);
            $behaviour = $opts['whatsapp_behaviour'] ?? 'accepted';
            if ($behaviour === 'throw') throw new RuntimeException('whatsapp transport down');
            if ($behaviour === 'template_not_mapped') return array('acknowledged' => false, 'permanent_failure' => 'send_refused', 'reason' => 'template_not_mapped');
            if ($behaviour === 'sender_not_configured') return array('acknowledged' => false, 'permanent_failure' => 'send_refused', 'reason' => 'sender_not_configured');
            if ($behaviour === 'refused') return array('acknowledged' => false, 'permanent_failure' => 'send_refused');
            return array('acknowledged' => true, 'permanent_failure' => null);
        };
        $whatsappAllowed = $opts['whatsapp_allowed'] ?? static fn(string $eventKey): bool => true;
        $whatsappProviderReady = $opts['whatsapp_ready'] ?? static fn(): bool => true;
        $service = new RenewalAutomationNotificationService($transport, static fn(string $eventKey, int $id): array => $row, $record, $seen, $whatsappSender, $whatsappAllowed, $whatsappProviderReady);
        return array($service, $state);
    };

    /* (iii) A committed automatic-charge confirmation produces exactly one notice, idempotent on re-run. */
    [$service, $state] = $make($makeRow('confirmed', 'automatic_charge'));
    $first = $service->dispatch('renewal.automatic_charged.student', 500);
    $fail('automatic charged produces once for the confirmed intent', $first['produced'] === true && count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);
    $fail('automatic charged resolves the beneficiary student', $state->recorded[0]['recipient_id'] === 7 && $state->sent[0]['email'] === 'student@example.invalid' && $state->sent[0]['audience'] === 'student');
    $fail('automatic charged renders the course and amount', str_contains($state->sent[0]['body'], 'تار') && str_contains($state->sent[0]['body'], 'تومان'));
    $fail('automatic charged renders the paid date', str_contains($state->sent[0]['body'], '2026-11-12'));
    $fail('automatic charged records the durable source identity', $state->recorded[0]['source_kind'] === 'renewal_automation' && $state->recorded[0]['source_id'] === 500);
    $second = $service->dispatch('renewal.automatic_charged.student', 500);
    $fail('automatic charged is idempotent on re-run', $second['produced'] === false && $second['email']['reason'] === 'already_recorded' && $second['whatsapp']['reason'] === 'already_recorded' && count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);

    /* (iv) A committed automatic-charge failure produces exactly one notice. */
    [$service, $state] = $make($makeRow('failed', 'automatic_charge'));
    $first = $service->dispatch('renewal.automatic_failed.student', 501);
    $fail('automatic failed produces once for the failed intent', $first['produced'] === true && count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);
    $fail('automatic failed renders the action deadline', str_contains($state->sent[0]['body'], '2026-11-15'));
    $service->dispatch('renewal.automatic_failed.student', 501);
    $fail('automatic failed is idempotent on re-run', count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);

    /* (v) A committed lapse produces exactly one notice. */
    [$service, $state] = $make($makeRow('lapsed', 'manual'));
    $first = $service->dispatch('renewal.term_lapsed.student', 502);
    $fail('term lapsed produces once for the lapsed cycle', $first['produced'] === true && count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);
    $fail('term lapsed omits finance variables from the copy', !str_contains($state->sent[0]['body'], '{{amount}}') && !str_contains($state->sent[0]['body'], '{{due_date}}'));
    $service->dispatch('renewal.term_lapsed.student', 502);
    $fail('term lapsed is idempotent on re-run', count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);

    /* (vi) No notice before the authoritative transition, and no notice for unrelated states. */
    [$service, $state] = $make($makeRow('submitted', 'automatic_charge'));
    $r = $service->dispatch('renewal.automatic_charged.student', 503);
    $fail('automatic charged is refused on a submitted intent', $r['produced'] === false && $r['email']['reason'] === 'fact_not_applicable' && count($state->recorded) === 0 && count($state->sent) === 0 && count($state->whatsapp) === 0);

    [$service, $state] = $make($makeRow('confirmed', 'manual_payment_required'));
    $r = $service->dispatch('renewal.automatic_charged.student', 504);
    $fail('a manual confirmed intent never emits the automatic-charged notice', $r['produced'] === false && $r['email']['reason'] === 'fact_not_applicable' && count($state->recorded) === 0);

    [$service, $state] = $make($makeRow('failed', 'manual_payment_required'));
    $r = $service->dispatch('renewal.automatic_failed.student', 505);
    $fail('a manual failed intent never emits the automatic-failed notice', $r['produced'] === false && $r['email']['reason'] === 'fact_not_applicable' && count($state->recorded) === 0);

    [$service, $state] = $make($makeRow('pending', 'manual'));
    $r = $service->dispatch('renewal.automatic_upcoming.student', 506);
    $fail('a manual pending cycle never emits the automatic-upcoming notice', $r['produced'] === false && $r['email']['reason'] === 'fact_not_applicable' && count($state->recorded) === 0);

    [$service, $state] = $make($makeRow('payment_required', 'automatic_charge'));
    $r = $service->dispatch('renewal.manual_payment_required.student', 507);
    $fail('an automatic payment-required cycle never emits the manual-payment notice', $r['produced'] === false && $r['email']['reason'] === 'fact_not_applicable' && count($state->recorded) === 0);

    [$service, $state] = $make($makeRow('collected', 'manual'));
    $r = $service->dispatch('renewal.term_lapsed.student', 508);
    $fail('term lapsed is refused on a collected cycle', $r['produced'] === false && $r['email']['reason'] === 'fact_not_applicable' && count($state->recorded) === 0);

    [$service, $state] = $make($makeRow('confirmed', 'automatic_charge'));
    $r = $service->dispatch('booking.confirmed.student', 509);
    $fail('an unrelated event is never produced', $r['produced'] === false && $r['email']['reason'] === 'unknown_event' && count($state->recorded) === 0);

    /* (vii) The clock-driven due window: before, inside and after. */
    $now = strtotime('2026-11-10 12:00:00 UTC');
    $fail('the automatic-upcoming lead is a bounded window', RenewalAutomationWorker::leadSeconds('renewal.automatic_upcoming.student') === 172800);
    $fail('the guarantee-deadline lead is a bounded window', RenewalAutomationWorker::leadSeconds('renewal.guarantee_deadline_approaching.student') === 259200);
    $fail('the manual-payment lead is a bounded window', RenewalAutomationWorker::leadSeconds('renewal.manual_payment_required.student') === 259200);
    $fail('an event-driven event is not clock-driven', RenewalAutomationWorker::leadSeconds('renewal.term_lapsed.student') === 0);
    $fail('an instant beyond the lead window is not yet due', RenewalAutomationWorker::isDue('2026-12-01 00:00:00', $now, 'renewal.guarantee_deadline_approaching.student') === false);
    $fail('an instant inside the lead window is due', RenewalAutomationWorker::isDue('2026-11-13 00:00:00', $now, 'renewal.guarantee_deadline_approaching.student') === true);
    $fail('an instant at or before now is past due', RenewalAutomationWorker::isDue('2026-11-10 12:00:00', $now, 'renewal.guarantee_deadline_approaching.student') === false && RenewalAutomationWorker::isDue('2026-11-09 00:00:00', $now, 'renewal.guarantee_deadline_approaching.student') === false);
    $fail('the automatic-upcoming window keys off the charge instant', RenewalAutomationWorker::isDue('2026-11-12 00:00:00', $now, 'renewal.automatic_upcoming.student') === true);

    /* (viii) The clock-driven notices produce once each on their committed state through the same dispatch. */
    [$service, $state] = $make($makeRow('guarantee_protected', 'manual'));
    $first = $service->dispatch('renewal.guarantee_deadline_approaching.student', 600);
    $fail('guarantee deadline produces once for the protected cycle', $first['produced'] === true && count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);
    $service->dispatch('renewal.guarantee_deadline_approaching.student', 600);
    $fail('guarantee deadline is idempotent on re-run', count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);

    [$service, $state] = $make($makeRow('payment_required', 'manual'));
    $first = $service->dispatch('renewal.manual_payment_required.student', 601);
    $fail('manual payment required produces once for the payment-required cycle', $first['produced'] === true && count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);
    $service->dispatch('renewal.manual_payment_required.student', 601);
    $fail('manual payment required is idempotent on re-run', count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);

    [$service, $state] = $make($makeRow('pending', 'automatic_charge'));
    $first = $service->dispatch('renewal.automatic_upcoming.student', 602);
    $fail('automatic upcoming produces once for the pending automatic cycle', $first['produced'] === true && count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);
    $service->dispatch('renewal.automatic_upcoming.student', 602);
    $fail('automatic upcoming is idempotent on re-run', count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);

    [$service, $state] = $make($makeRow('pending', 'automatic_charge', 7, 'student@example.invalid', '09123456789', 'IR', array('automatic_charge_at' => '')));
    $r = $service->dispatch('renewal.automatic_upcoming.student', 603);
    $fail('automatic upcoming refuses a cycle with no announced charge instant', $r['produced'] === false && $r['email']['reason'] === 'fact_not_applicable' && count($state->recorded) === 0);

    /* (ix) The recipient resolves to the beneficiary student only. */
    [$service, $state] = $make($makeRow('confirmed', 'automatic_charge', 99, 'other@example.invalid'));
    $service->dispatch('renewal.automatic_charged.student', 510);
    $fail('the notice reaches exactly the beneficiary student', count($state->recorded) === 1 && $state->recorded[0]['recipient_id'] === 99 && $state->sent[0]['email'] === 'other@example.invalid');
    $fail('the notice never reaches a different student', $state->sent[0]['email'] !== 'student@example.invalid');

    [$service, $state] = $make($makeRow('confirmed', 'automatic_charge', 0));
    $r = $service->dispatch('renewal.automatic_charged.student', 511);
    $fail('an unresolved student never queues', $r['produced'] === false && $r['email']['reason'] === 'recipient_unresolved' && count($state->recorded) === 0);

    /* (x) WhatsApp skip matrix and the accepted path. */
    [$service, $state] = $make($makeRow('confirmed', 'automatic_charge', 7, 'student@example.invalid', '', 'IR'));
    $r = $service->dispatch('renewal.automatic_charged.student', 512);
    $fail('missing dedicated WhatsApp number falls back to the student mobile', $r['whatsapp']['state'] === 'accepted' && count($state->whatsapp) === 1 && $r['produced'] === true && count($state->sent) === 1);

    [$service, $state] = $make($makeRow('confirmed', 'automatic_charge', 7, 'student@example.invalid', '', 'IR', array('student_phone' => '')));
    $r = $service->dispatch('renewal.automatic_charged.student', 5121);
    $fail('no WhatsApp or mobile number skips with a stable reason', $r['whatsapp']['state'] === 'skipped' && $r['whatsapp']['reason'] === 'no_whatsapp_phone' && count($state->whatsapp) === 0 && $r['produced'] === true && count($state->sent) === 1);

    [$service, $state] = $make($makeRow('confirmed', 'automatic_charge'), array('whatsapp_allowed' => static fn(string $e): bool => false));
    $r = $service->dispatch('renewal.automatic_charged.student', 513);
    $fail('admin-disabled WhatsApp skips with a stable reason', $r['whatsapp']['state'] === 'skipped' && $r['whatsapp']['reason'] === 'channel_disabled' && count($state->whatsapp) === 0);

    [$service, $state] = $make($makeRow('confirmed', 'automatic_charge'), array('whatsapp_ready' => static fn(): bool => false));
    $r = $service->dispatch('renewal.automatic_charged.student', 514);
    $fail('unconfigured WhatsApp provider skips with a stable reason', $r['whatsapp']['state'] === 'skipped' && $r['whatsapp']['reason'] === 'provider_not_configured' && count($state->whatsapp) === 0);

    [$service, $state] = $make($makeRow('confirmed', 'automatic_charge'), array('whatsapp_behaviour' => 'template_not_mapped'));
    $r = $service->dispatch('renewal.automatic_charged.student', 515);
    $fail('an unmapped WhatsApp template skips with a stable reason', $r['whatsapp']['state'] === 'skipped' && $r['whatsapp']['reason'] === 'provider_template_not_mapped' && count($state->whatsapp) === 1);

    [$service, $state] = $make($makeRow('confirmed', 'automatic_charge'));
    $r = $service->dispatch('renewal.automatic_charged.student', 516);
    $fail('a mapped WhatsApp template is accepted through the provider machinery', $r['whatsapp']['state'] === 'accepted' && count($state->whatsapp) === 1 && $state->whatsapp[0]['to'] === '+989123456789');
    $fail('WhatsApp carries only the approved template parameters, never free-form text', is_array($state->whatsapp[0]['parameters']) && $state->whatsapp[0]['parameters']['name'] === 'باران' && $state->whatsapp[0]['parameters']['course'] === 'تار' && array_key_exists('amount', $state->whatsapp[0]['parameters']) && array_key_exists('paid_date', $state->whatsapp[0]['parameters']) && array_key_exists('portal_url', $state->whatsapp[0]['parameters']));
    $fail('WhatsApp is correlated to the portal inbox row', $state->whatsapp[0]['notificationId'] === 1);

    /* (xi) WhatsApp failure never changes the email or inbox outcome. */
    [$service, $state] = $make($makeRow('confirmed', 'automatic_charge'), array('whatsapp_behaviour' => 'throw'));
    $r = $service->dispatch('renewal.automatic_charged.student', 517);
    $fail('WhatsApp exception is reported without breaking email/inbox', $r['produced'] === true && $r['email']['state'] === 'sent' && $r['whatsapp']['state'] === 'failed' && $r['whatsapp']['reason'] === 'whatsapp_exception' && count($state->recorded) === 1 && count($state->sent) === 1);

    /* (xii) The producer never mutates finance state: the portal inbox is the only write seam, and the
     *        producer source issues no UPDATE/INSERT/DELETE against any finance table. */
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/platform/src/Core/Application/RenewalAutomationNotificationService.php');
    $fail('the producer records only through the portal inbox seam', str_contains($source, 'PortalNotificationRecorder::student') && str_contains($source, 'SOURCE_KIND'));
    $fail('the producer never writes a finance aggregate', !str_contains($source, '$wpdb->update(') && !str_contains($source, '$wpdb->insert(') && !str_contains($source, '$wpdb->delete('));
    $fail('the producer reads finance facts read-only', str_contains($source, '$wpdb->get_row(') && !str_contains($source, 'FOR UPDATE'));
    $fail('the producer re-verifies the committed aggregate state at dispatch', str_contains($source, 'fact_not_applicable'));
    $fail('the producer defers out of band', str_contains($source, 'wp_schedule_single_event') && str_contains($source, 'DISPATCH_HOOK'));
    $fail('WhatsApp is delivered only through the existing provider-template adapter', str_contains($source, 'TelnyxWhatsAppAdapter') && str_contains($source, 'sendBookingNotification'));
    $fail('WhatsApp skips with a stable unmapped-template reason', str_contains($source, 'provider_template_not_mapped'));
    $fail('the result reports a state + reason and never the message body', str_contains($source, "'state'=>'sent'") && str_contains($source, "'state'=>'skipped'"));

    /* (xiii) The hook wiring maps each committed R2 transition to its one event. */
    $fail('confirm maps to the automatic-charged notice', RenewalAutomationNotificationService::eventFor('confirm') === 'renewal.automatic_charged.student');
    $fail('record_failure maps to the automatic-failed notice', RenewalAutomationNotificationService::eventFor('record_failure') === 'renewal.automatic_failed.student');
    $fail('lapse maps to the term-lapsed notice', RenewalAutomationNotificationService::eventFor('lapse') === 'renewal.term_lapsed.student');
    $fail('an unrelated transition maps to no notice', RenewalAutomationNotificationService::eventFor('dismiss') === null && RenewalAutomationNotificationService::eventFor('open_cycle') === null && RenewalAutomationNotificationService::eventFor('require_payment') === null);

    /* (xiv) The scheduler is bounded, SELECT-only and keys the clock-driven events off their instants. */
    $worker = (string) file_get_contents(dirname(__DIR__, 2) . '/platform/src/Integrations/RenewalAutomationWorker.php');
    $fail('the worker is bounded by a batch limit', str_contains($worker, 'LIMIT %d') && str_contains($worker, 'BATCH'));
    $fail('the worker schedules a recurring bounded cron', str_contains($worker, 'wp_schedule_event') && str_contains($worker, 'dzn_two_minutes'));
    $fail('the worker finds automatic-upcoming cycles by their charge instant', str_contains($worker, 'automatic_charge_at') && str_contains($worker, "state='pending'"));
    $fail('the worker finds guarantee-deadline cycles by their deadline instant', str_contains($worker, 'guarantee_deadline_at') && str_contains($worker, "state='guarantee_protected'"));
    $fail('the worker finds manual-payment cycles by their deadline instant', str_contains($worker, "state='payment_required'") && str_contains($worker, "collection_mode='manual'"));
    $fail('the worker never mutates a cycle', !str_contains($worker, '$wpdb->update(') && !str_contains($worker, '$wpdb->insert(') && !str_contains($worker, '$wpdb->delete('));

    echo "Renewal-automation notification unit passed\n";
}
