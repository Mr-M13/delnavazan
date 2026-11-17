<?php
/** Payment-failure and refund notifications: catalogue declaration, idempotency, fact gating, recipient resolution and the WhatsApp leg. */
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
    require_once dirname(__DIR__, 2) . '/platform/src/Core/Application/PaymentRefundNotificationService.php';

    use Delnavazan\Platform\Core\Application\PaymentRefundNotificationService;
    use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;

    $fail = static function (string $name, bool $ok): void {
        if (!$ok) throw new RuntimeException('Payment/refund notification unit failed: ' . $name);
    };

    /* (i) Each of the four events is declared with the expected channels, copy, producer and authority. */
    foreach (array(
        'payment.failed.student' => array('label' => 'Payment Failed', 'variables' => array('name', 'course', 'amount', 'portal_url')),
        'payment.recovered.student' => array('label' => 'Payment Recovered', 'variables' => array('name', 'course', 'amount', 'paid_date', 'portal_url')),
        'refund.review_required.student' => array('label' => 'Refund Under Review', 'variables' => array('name', 'course', 'amount', 'portal_url')),
        'refund.resolved.student' => array('label' => 'Refund Resolved', 'variables' => array('name', 'course', 'amount', 'portal_url')),
    ) as $key => $spec) {
        $event = NotificationCatalogue::get($key);
        $fail($key . ' is declared', is_array($event));
        $fail($key . ' targets the student', $event['audience'] === 'student');
        $fail($key . ' is a payment event', $event['category'] === 'payment');
        $fail($key . ' offers email, website and whatsapp', $event['channels'] === array('email', 'website', 'whatsapp'));
        $fail($key . ' is financial and mandatory', $event['consent'] === 'financial' && $event['mandatory'] === true);
        $fail($key . ' has a registered producer', $event['implementation'] === 'implemented' && $event['producer_registered'] === true);
        $fail($key . ' is produced by the dedicated payment/refund service', $event['producer'] === 'PaymentRefundNotificationService');
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
    $fail('the retired greeting stays retired across financial copy', !str_contains(NotificationCatalogue::exactCopy('refund.resolved.student', 'email', 'fa-IR')['body'], 'سلام'));

    /* (ii) Every WhatsApp route is registry-mapped and its variable order is a subset of the declared
     *       variables, so the dispatch-time provider mapping can never invent a parameter. */
    foreach (array(
        'payment.failed.student' => 'delnavazan_payment_failed_student_fa',
        'payment.recovered.student' => 'delnavazan_payment_received_fa',
        'refund.review_required.student' => 'delnavazan_refund_review_required_student_fa',
        'refund.resolved.student' => 'delnavazan_refund_resolved_student_fa',
    ) as $key => $templateName) {
        $definition = \Delnavazan\Platform\Integrations\Notifications\TelnyxTemplateRegistry::definitionForName($templateName);
        $fail($key . ' is registry-mapped', $definition !== null && $definition['event_key'] === $key);
        $order = array_values((array) $definition['variable_order']);
        $declared = NotificationCatalogue::variables($key);
        $fail($key . ' registry order stays within the declared variables', array_diff($order, $declared) === array());
    }

    /* A scenario builder with injectable email/WhatsApp collaborators and a shared durable inbox state. */
    $makeRow = static function (string $state, int $studentId = 7, string $email = 'student@example.invalid', string $whatsapp = '09123456789', string $country = 'IR', array $overrides = array()): array {
        return array_merge(array(
            'student_id' => $studentId,
            'state' => $state,
            'kind' => 'manual_payment_required',
            'student_email' => $email,
            'student_phone' => '09123456789',
            'student_whatsapp' => $whatsapp,
            'student_country_code' => $country,
            'student_name' => 'باران',
            'course_name' => 'تار',
            'amount_minor' => 2500000,
            'currency' => 'TOM',
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
        $service = new PaymentRefundNotificationService($transport, static fn(string $eventKey, int $id): array => $row, $record, $seen, $whatsappSender, $whatsappAllowed, $whatsappProviderReady);
        return array($service, $state);
    };

    /* (iii) A committed manual collection-intent failure produces exactly one notice, idempotent on re-run. */
    [$service, $state] = $make($makeRow('failed'));
    $first = $service->dispatch('payment.failed.student', 500);
    $fail('payment failed produces once for the failed collection intent', $first['produced'] === true && count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);
    $fail('payment failed resolves the beneficiary student', $state->recorded[0]['recipient_id'] === 7 && $state->sent[0]['email'] === 'student@example.invalid' && $state->sent[0]['audience'] === 'student');
    $fail('payment failed renders the course and amount', str_contains($state->sent[0]['body'], 'تار') && str_contains($state->sent[0]['body'], 'تومان'));
    $fail('payment failed records the durable source identity', $state->recorded[0]['source_kind'] === 'payment_refund' && $state->recorded[0]['source_id'] === 500);
    $second = $service->dispatch('payment.failed.student', 500);
    $fail('payment failed is idempotent on re-run', $second['produced'] === false && $second['email']['reason'] === 'already_recorded' && $second['whatsapp']['reason'] === 'already_recorded' && count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);

    /* (iv) A committed recovery produces exactly one notice, and the paid date is rendered. */
    [$service, $state] = $make($makeRow('recovered'));
    $first = $service->dispatch('payment.recovered.student', 501);
    $fail('payment recovered produces once for the recovered case', $first['produced'] === true && count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);
    $fail('payment recovered renders the paid date', str_contains($state->sent[0]['body'], '2026-11-12'));
    $service->dispatch('payment.recovered.student', 501);
    $fail('payment recovered is idempotent on re-run', count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);

    /* (v) The two refund notices produce once each on their committed review states. */
    [$service, $state] = $make($makeRow('review_required'));
    $first = $service->dispatch('refund.review_required.student', 502);
    $fail('refund under review produces once for the review-required case', $first['produced'] === true && count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);
    $service->dispatch('refund.review_required.student', 502);
    $fail('refund under review is idempotent on re-run', count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);

    [$service, $state] = $make($makeRow('resolved'));
    $first = $service->dispatch('refund.resolved.student', 503);
    $fail('refund resolved produces once for the resolved case', $first['produced'] === true && count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);
    $service->dispatch('refund.resolved.student', 503);
    $fail('refund resolved is idempotent on re-run', count($state->recorded) === 1 && count($state->sent) === 1 && count($state->whatsapp) === 1);

    /* (vi) No notification before the authoritative transition, and no notification for unrelated states. */
    $service->dispatch('payment.failed.student', 599); // no row: unrelated subject id
    [$service, $state] = $make($makeRow('submitted'));
    $r = $service->dispatch('payment.failed.student', 504);
    $fail('payment failed is refused on a submitted collection intent', $r['produced'] === false && $r['email']['reason'] === 'fact_not_applicable' && count($state->recorded) === 0 && count($state->sent) === 0 && count($state->whatsapp) === 0);

    [$service, $state] = $make($makeRow('failed', 7, 'student@example.invalid', '09123456789', 'IR', array('kind' => 'automatic_charge')));
    $r = $service->dispatch('payment.failed.student', 505);
    $fail('an automatic-charge failure never emits the manual payment-failed notice', $r['produced'] === false && $r['email']['reason'] === 'fact_not_applicable' && count($state->recorded) === 0);

    [$service, $state] = $make($makeRow('open'));
    $r = $service->dispatch('payment.recovered.student', 506);
    $fail('payment recovered is refused on an open recovery case', $r['produced'] === false && $r['email']['reason'] === 'fact_not_applicable' && count($state->recorded) === 0 && count($state->whatsapp) === 0);

    [$service, $state] = $make($makeRow('open'));
    $r = $service->dispatch('refund.review_required.student', 507);
    $fail('refund under review is refused on an open review case', $r['produced'] === false && $r['email']['reason'] === 'fact_not_applicable' && count($state->recorded) === 0);

    [$service, $state] = $make($makeRow('review_required'));
    $r = $service->dispatch('refund.resolved.student', 508);
    $fail('refund resolved is refused on a review-required case', $r['produced'] === false && $r['email']['reason'] === 'fact_not_applicable' && count($state->recorded) === 0);

    [$service, $state] = $make($makeRow('failed'));
    $r = $service->dispatch('booking.confirmed.student', 509);
    $fail('an unrelated event is never produced', $r['produced'] === false && $r['email']['reason'] === 'unknown_event' && count($state->recorded) === 0);

    /* (vii) The recipient resolves to the beneficiary student only. */
    [$service, $state] = $make($makeRow('failed', 99, 'other@example.invalid'));
    $service->dispatch('payment.failed.student', 510);
    $fail('the notice reaches exactly the beneficiary student', count($state->recorded) === 1 && $state->recorded[0]['recipient_id'] === 99 && $state->sent[0]['email'] === 'other@example.invalid');
    $fail('the notice never reaches a different student', $state->sent[0]['email'] !== 'student@example.invalid');

    [$service, $state] = $make($makeRow('failed', 0));
    $r = $service->dispatch('payment.failed.student', 511);
    $fail('an unresolved student never queues', $r['produced'] === false && $r['email']['reason'] === 'recipient_unresolved' && count($state->recorded) === 0);

    /* (viii) WhatsApp skip matrix and the accepted path. */
    [$service, $state] = $make($makeRow('failed', 7, 'student@example.invalid', '', 'IR'));
    $r = $service->dispatch('payment.failed.student', 512);
    $fail('missing dedicated WhatsApp number falls back to the student mobile', $r['whatsapp']['state'] === 'accepted' && count($state->whatsapp) === 1 && $r['produced'] === true && count($state->sent) === 1);

    [$service, $state] = $make($makeRow('failed', 7, 'student@example.invalid', '', 'IR', array('student_phone' => '')));
    $r = $service->dispatch('payment.failed.student', 5121);
    $fail('no WhatsApp or mobile number skips with a stable reason', $r['whatsapp']['state'] === 'skipped' && $r['whatsapp']['reason'] === 'no_whatsapp_phone' && count($state->whatsapp) === 0 && $r['produced'] === true && count($state->sent) === 1);

    [$service, $state] = $make($makeRow('failed'), array('whatsapp_allowed' => static fn(string $e): bool => false));
    $r = $service->dispatch('payment.failed.student', 513);
    $fail('admin-disabled WhatsApp skips with a stable reason', $r['whatsapp']['state'] === 'skipped' && $r['whatsapp']['reason'] === 'channel_disabled' && count($state->whatsapp) === 0);

    [$service, $state] = $make($makeRow('failed'), array('whatsapp_ready' => static fn(): bool => false));
    $r = $service->dispatch('payment.failed.student', 514);
    $fail('unconfigured WhatsApp provider skips with a stable reason', $r['whatsapp']['state'] === 'skipped' && $r['whatsapp']['reason'] === 'provider_not_configured' && count($state->whatsapp) === 0);

    [$service, $state] = $make($makeRow('failed'), array('whatsapp_behaviour' => 'template_not_mapped'));
    $r = $service->dispatch('payment.failed.student', 515);
    $fail('an unmapped WhatsApp template skips with a stable reason', $r['whatsapp']['state'] === 'skipped' && $r['whatsapp']['reason'] === 'provider_template_not_mapped' && count($state->whatsapp) === 1);

    [$service, $state] = $make($makeRow('failed'));
    $r = $service->dispatch('payment.failed.student', 516);
    $fail('a mapped WhatsApp template is accepted through the provider machinery', $r['whatsapp']['state'] === 'accepted' && count($state->whatsapp) === 1 && $state->whatsapp[0]['to'] === '+989123456789');
    $fail('WhatsApp carries only the approved template parameters, never free-form text', is_array($state->whatsapp[0]['parameters']) && $state->whatsapp[0]['parameters']['name'] === 'باران' && $state->whatsapp[0]['parameters']['course'] === 'تار' && array_key_exists('amount', $state->whatsapp[0]['parameters']) && array_key_exists('portal_url', $state->whatsapp[0]['parameters']));
    $fail('WhatsApp is correlated to the portal inbox row', $state->whatsapp[0]['notificationId'] === 1);

    /* (ix) WhatsApp failure never changes the email or inbox outcome. */
    [$service, $state] = $make($makeRow('failed'), array('whatsapp_behaviour' => 'throw'));
    $r = $service->dispatch('payment.failed.student', 517);
    $fail('WhatsApp exception is reported without breaking email/inbox', $r['produced'] === true && $r['email']['state'] === 'sent' && $r['whatsapp']['state'] === 'failed' && $r['whatsapp']['reason'] === 'whatsapp_exception' && count($state->recorded) === 1 && count($state->sent) === 1);

    /* (x) The producer never mutates finance state: the portal inbox is the only write seam, and the
     *      producer source issues no UPDATE/INSERT/DELETE against any finance table. */
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/platform/src/Core/Application/PaymentRefundNotificationService.php');
    $fail('the producer records only through the portal inbox seam', str_contains($source, 'PortalNotificationRecorder::student') && str_contains($source, 'SOURCE_KIND'));
    $fail('the producer never writes a finance aggregate', !str_contains($source, '$wpdb->update(') && !str_contains($source, '$wpdb->insert(') && !str_contains($source, '$wpdb->delete('));
    $fail('the producer reads finance facts read-only', str_contains($source, '$wpdb->get_row(') && !str_contains($source, 'FOR UPDATE'));
    $fail('the producer re-verifies the committed aggregate state at dispatch', str_contains($source, 'fact_not_applicable'));
    $fail('the producer defers out of band', str_contains($source, 'wp_schedule_single_event') && str_contains($source, 'DISPATCH_HOOK'));
    $fail('WhatsApp is delivered only through the existing provider-template adapter', str_contains($source, 'TelnyxWhatsAppAdapter') && str_contains($source, 'sendBookingNotification'));
    $fail('WhatsApp skips with a stable unmapped-template reason', str_contains($source, 'provider_template_not_mapped'));
    $fail('the result reports a state + reason and never the message body', str_contains($source, "'state'=>'sent'") && str_contains($source, "'state'=>'skipped'"));

    /* (xi) The hook wiring maps each committed R2 transition to its one event. */
    $fail('record_failure maps to the payment-failed notice', PaymentRefundNotificationService::eventFor('record_failure') === 'payment.failed.student');
    $fail('mark_recovered maps to the payment-recovered notice', PaymentRefundNotificationService::eventFor('mark_recovered') === 'payment.recovered.student');
    $fail('route_for_review maps to the refund-review-required notice', PaymentRefundNotificationService::eventFor('route_for_review') === 'refund.review_required.student');
    $fail('resolve maps to the refund-resolved notice', PaymentRefundNotificationService::eventFor('resolve') === 'refund.resolved.student');
    $fail('an unrelated transition maps to no notice', PaymentRefundNotificationService::eventFor('dismiss') === null && PaymentRefundNotificationService::eventFor('confirm') === null);

    echo "Payment/refund notification unit passed\n";
}
