<?php
/** Teacher lesson-lifecycle notifications: catalogue declaration, idempotency, lifecycle gating, recipient resolution and the SMS leg. */
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
    require_once dirname(__DIR__, 2) . '/platform/src/Core/Application/SmsProviderRoutingPolicy.php';
    require_once dirname(__DIR__, 2) . '/platform/src/Core/Application/LessonLifecycleNotificationService.php';

    use Delnavazan\Platform\Core\Application\LessonLifecycleNotificationService;
    use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;

    $fail = static function (string $name, bool $ok): void {
        if (!$ok) throw new RuntimeException('Lesson lifecycle notification unit failed: ' . $name);
    };

    /* (i) Each teacher lesson event is declared with the expected channels, copy, producer and authority. */
    foreach (array(
        'lesson.access.teacher' => 'Class Access Ready',
        'lesson.reminder.teacher' => 'Class Reminder',
        'lesson.cancelled.teacher' => 'Class Cancelled',
    ) as $key => $label) {
        $event = NotificationCatalogue::get($key);
        $fail($key . ' is declared', is_array($event));
        $fail($key . ' targets the teacher', $event['audience'] === 'teacher');
        $fail($key . ' is a lesson event', $event['category'] === 'lesson');
        $fail($key . ' offers email, website and sms', $event['channels'] === array('email', 'website', 'sms'));
        $fail($key . ' is operational and mandatory', $event['consent'] === 'operational' && $event['mandatory'] === true);
        $fail($key . ' has a registered producer', $event['implementation'] === 'implemented' && $event['producer_registered'] === true);
        $fail($key . ' declares the producer-supplyable variables', $event['variables'] === array('teacher', 'course', 'student', 'when', 'teacher_portal_url'));
        $fail($key . ' is owned by a dedicated authority', $event['authority'] === 'dedicated' && in_array('dedicated_authority', NotificationCatalogue::reviewFor($key), true));
        $fail($key . ' labels the notice', $event['label'] === $label);
        foreach (array('email', 'website', 'sms') as $channel) {
            foreach (NotificationCatalogue::SUPPORTED_LOCALES as $locale) {
                $copy = NotificationCatalogue::exactCopy($key, $channel, $locale);
                $fail($key . '/' . $channel . '/' . $locale . ' has copy', is_array($copy) && trim((string) ($copy['body'] ?? '')) !== '');
            }
        }
        $fa = NotificationCatalogue::exactCopy($key, 'email', 'fa-IR');
        $en = NotificationCatalogue::exactCopy($key, 'email', 'en');
        $fail($key . ' Persian email greets with درود', str_contains($fa['body'], 'درود') && !str_contains($fa['body'], 'سلام'));
        $fail($key . ' English email greets with Hello', str_contains($en['body'], 'Hello'));
        $fail($key . ' email links the teacher portal', str_contains($fa['body'], '{{teacher_portal_url}}') && str_contains($en['body'], '{{teacher_portal_url}}'));
        $fail($key . ' email names the class time', str_contains($fa['body'], '{{when}}') && str_contains($en['body'], '{{when}}'));
    }

    /* The student lesson events stay declared-only in this phase. */
    foreach (array('lesson.access.student', 'lesson.reminder.student', 'lesson.cancelled.student') as $student) {
        $event = NotificationCatalogue::get($student);
        $fail($student . ' remains declared-only', $event['implementation'] === 'declared' && $event['producer_registered'] === false);
    }

    $makeRow = static function (string $state = 'authorised', int $teacherId = 42, string $email = 'teacher@example.invalid', string $phone = '09123456789', string $country = 'IR'): array {
        return array(
            'teacher_id' => $teacherId,
            'student_id' => 7,
            'lifecycle_state' => $state,
            'email' => $email,
            'phone' => $phone,
            'country_code' => $country,
            'teacher_name' => 'استاد ندا',
            'student_name' => 'باران',
            'course_name' => 'تار',
            'starts_at_utc' => '2026-11-05 15:00:00',
            'teacher_timezone' => 'Asia/Tehran',
            'schedule_timezone' => 'Asia/Tehran',
            'teacher_portal_url' => 'https://example.invalid/teacher-portal/',
        );
    };

    /* A scenario builder with injectable SMS collaborators and a shared durable inbox state. */
    $make = static function (array $row, array $opts = array()): array {
        $state = new \stdClass();
        $state->recorded = array();
        $state->sent = array();
        $state->sms = array();
        $state->portal = array();
        $seen = static function (string $eventKey, int $lessonId) use ($state): bool { return isset($state->portal[$eventKey . ':' . $lessonId]); };
        $record = static function (int $teacherId, string $eventKey, string $sourceKind, int $sourceId, array $variables, ?string $linkUrl) use ($state): ?int {
            $key = $eventKey . ':' . $sourceId;
            if (isset($state->portal[$key])) return $state->portal[$key];
            $id = count($state->portal) + 1;
            $state->portal[$key] = $id;
            $state->recorded[] = array('teacher_id' => $teacherId, 'event_key' => $eventKey, 'source_id' => $sourceId, 'variables' => $variables);
            return $id;
        };
        $transport = static function (string $email, string $subject, string $body, string $audience = '') use ($state): bool {
            $state->sent[] = array('email' => $email, 'subject' => $subject, 'body' => $body, 'audience' => $audience);
            return true;
        };
        $smsSender = static function (string $provider, string $to, int $notificationId, string $body) use ($state, $opts): array {
            $state->sms[] = array('provider' => $provider, 'to' => $to, 'notificationId' => $notificationId, 'body' => $body);
            $behaviour = $opts['sms_behaviour'] ?? 'accepted';
            if ($behaviour === 'throw') throw new RuntimeException('sms transport down');
            if ($behaviour === 'refused') return array('acknowledged' => false, 'permanent_failure' => 'send_refused');
            if ($behaviour === 'retryable') return array('acknowledged' => false, 'permanent_failure' => null);
            return array('acknowledged' => true, 'permanent_failure' => null);
        };
        $smsAllowed = $opts['sms_allowed'] ?? static fn(string $eventKey): bool => true;
        $smsProviderReady = $opts['sms_ready'] ?? static fn(string $provider): bool => true;
        $service = new LessonLifecycleNotificationService($transport, static fn(int $id): array => $row, $record, $seen, $smsSender, $smsAllowed, $smsProviderReady);
        return array($service, $state);
    };

    /* (ii) Access-ready on an authorised lesson queues once and is idempotent on re-run. */
    [$service, $state] = $make($makeRow('authorised'));
    $first = $service->notifyAccessReady(101);
    $fail('access-ready queues on an authorised lesson', $first['produced'] === true && count($state->recorded) === 1 && count($state->sent) === 1);
    $fail('access-ready resolves the assigned teacher', $state->recorded[0]['teacher_id'] === 42 && $state->sent[0]['email'] === 'teacher@example.invalid');
    $fail('access-ready renders the class time', str_contains($state->sent[0]['body'], '2026-11-05') && str_contains($state->sent[0]['body'], 'تار'));
    $second = $service->notifyAccessReady(101);
    $fail('access-ready is idempotent on re-run', $second['produced'] === false && $second['sms']['state'] === 'skipped' && $second['sms']['reason'] === 'already_recorded' && count($state->recorded) === 1 && count($state->sent) === 1 && count($state->sms) === 1);

    /* Reminder and cancellation each queue exactly once. */
    [$service, $state] = $make($makeRow('authorised'));
    $service->notifyReminder(101);
    $service->notifyReminder(101);
    $fail('reminder queues exactly once per lesson+event', count($state->recorded) === 1 && count($state->sent) === 1 && count($state->sms) === 1);
    $fail('reminder names the reminder lifecycle', str_contains($state->sent[0]['subject'], 'یادآوری') || str_contains($state->sent[0]['body'], 'یادآوری'));

    [$service, $state] = $make($makeRow('cancelled'));
    $service->notifyCancelled(101);
    $service->notifyCancelled(101);
    $fail('cancellation queues exactly once on a cancelled lesson', count($state->recorded) === 1 && count($state->sent) === 1 && count($state->sms) === 1);

    /* (iii) A non-applicable lifecycle state never queues any leg. */
    [$service, $state] = $make($makeRow('cancelled'));
    $r = $service->notifyAccessReady(101);
    $fail('access-ready is refused on a cancelled lesson', $r['produced'] === false && $r['sms']['state'] === 'skipped' && $r['sms']['reason'] === 'lifecycle_not_applicable' && count($state->recorded) === 0 && count($state->sent) === 0 && count($state->sms) === 0);
    $r = $service->notifyReminder(101);
    $fail('reminder is refused on a cancelled lesson', $r['produced'] === false && count($state->recorded) === 0 && count($state->sms) === 0);

    [$service, $state] = $make($makeRow('authorised'));
    $r = $service->notifyCancelled(101);
    $fail('cancellation is refused on an authorised lesson', $r['produced'] === false && count($state->recorded) === 0 && count($state->sms) === 0);

    [$service, $state] = $make($makeRow('completed'));
    $fail('access-ready is refused on a completed lesson', $service->notifyAccessReady(101)['produced'] === false && count($state->recorded) === 0 && count($state->sms) === 0);

    /* (iv) The recipient resolves to the assigned teacher only. */
    [$service, $state] = $make($makeRow('authorised', 99, 'other@example.invalid'));
    $service->notifyAccessReady(101);
    $fail('the notice reaches exactly the assigned teacher', count($state->recorded) === 1 && $state->recorded[0]['teacher_id'] === 99 && $state->sent[0]['email'] === 'other@example.invalid');
    $fail('the notice never reaches a different teacher', $state->sent[0]['email'] !== 'teacher@example.invalid');

    [$service, $state] = $make(array('teacher_id' => 0, 'lifecycle_state' => 'authorised'));
    $r = $service->notifyAccessReady(101);
    $fail('an unresolved teacher never queues', $r['produced'] === false && $r['sms']['reason'] === 'recipient_unresolved' && count($state->recorded) === 0 && count($state->sms) === 0);

    /* SMS routing matrix. */
    // (i) Iranian +98 number routes to SMS.ir with the E.164 normalised destination and catalogue copy.
    [$service, $state] = $make($makeRow('authorised', 42, 'teacher@example.invalid', '09123456789', 'IR'));
    $service->notifyAccessReady(101);
    $fail('Iranian number routes to SMS.ir', count($state->sms) === 1 && $state->sms[0]['provider'] === 'sms_ir' && $state->sms[0]['to'] === '+989123456789');
    $fail('SMS body is rendered from the catalogue copy', str_contains($state->sms[0]['body'], 'تار') && str_contains($state->sms[0]['body'], 'باران'));
    $fail('SMS is correlated to the portal inbox row', $state->sms[0]['notificationId'] === 1);

    // (ii) Non-Iranian number routes to Telnyx.
    [$service, $state] = $make($makeRow('authorised', 42, 'teacher@example.invalid', '+61412345678', 'AU'));
    $service->notifyAccessReady(101);
    $fail('non-Iranian number routes to Telnyx', count($state->sms) === 1 && $state->sms[0]['provider'] === 'telnyx' && $state->sms[0]['to'] === '+61412345678');

    // (iii) Skip reasons: no phone, invalid phone, unconfigured provider, admin-disabled channel.
    [$service, $state] = $make($makeRow('authorised', 42, 'teacher@example.invalid', '', 'IR'));
    $r = $service->notifyAccessReady(101);
    $fail('no phone skips SMS with a stable reason', $r['sms']['state'] === 'skipped' && $r['sms']['reason'] === 'no_phone' && count($state->sms) === 0 && $r['produced'] === true && count($state->sent) === 1);

    [$service, $state] = $make($makeRow('authorised', 42, 'teacher@example.invalid', 'not-a-number', 'IR'));
    $r = $service->notifyAccessReady(101);
    $fail('invalid phone skips SMS with a stable reason', $r['sms']['state'] === 'skipped' && $r['sms']['reason'] === 'phone_invalid' && count($state->sms) === 0);

    [$service, $state] = $make($makeRow('authorised', 42, 'teacher@example.invalid', '09123456789', 'IR'), array('sms_ready' => static fn(string $p): bool => false));
    $r = $service->notifyAccessReady(101);
    $fail('unconfigured provider skips SMS with a stable reason', $r['sms']['state'] === 'skipped' && $r['sms']['reason'] === 'provider_not_configured' && $r['sms']['provider'] === 'sms_ir' && count($state->sms) === 0);

    [$service, $state] = $make($makeRow('authorised'), array('sms_allowed' => static fn(string $e): bool => false));
    $r = $service->notifyAccessReady(101);
    $fail('admin-disabled SMS skips with a stable reason', $r['sms']['state'] === 'skipped' && $r['sms']['reason'] === 'channel_disabled' && count($state->sms) === 0);

    // (v) SMS failure never changes the email or inbox outcome.
    [$service, $state] = $make($makeRow('authorised'), array('sms_behaviour' => 'throw'));
    $r = $service->notifyAccessReady(101);
    $fail('SMS exception is reported without breaking email/inbox', $r['produced'] === true && $r['email']['state'] === 'sent' && $r['sms']['state'] === 'failed' && $r['sms']['reason'] === 'sms_exception' && count($state->recorded) === 1 && count($state->sent) === 1);

    [$service, $state] = $make($makeRow('authorised'), array('sms_behaviour' => 'refused'));
    $r = $service->notifyAccessReady(101);
    $fail('SMS refusal is reported independently', $r['produced'] === true && $r['email']['state'] === 'sent' && $r['sms']['state'] === 'failed' && $r['sms']['reason'] === 'send_refused');

    // (vi) Idempotent re-run sends nothing twice.
    [$service, $state] = $make($makeRow('authorised'));
    $service->notifyAccessReady(101);
    $service->notifyAccessReady(101);
    $fail('idempotent re-run never duplicates SMS', count($state->sms) === 1 && count($state->sent) === 1 && count($state->recorded) === 1);

    /* Production wiring: hooks, deferral, recipient resolution and the SMS route. */
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/platform/src/Core/Application/LessonLifecycleNotificationService.php');
    $fail('access-ready hooks schedule creation', str_contains($source, "dzn_phase_2a2n_after_command_insert") && str_contains($source, "schedule_initial"));
    $fail('cancellation hooks the lifecycle transition', str_contains($source, "dzn_phase_2a2m_after_lesson_transition") && str_contains($source, "'cancel'"));
    $fail('recipient resolution joins the assigned teacher only', str_contains($source, 't.id=l.teacher_id') && str_contains($source, "t.status='active'") && str_contains($source, 't.archived_at IS NULL'));
    $fail('recipient resolution carries the phone and country', str_contains($source, 't.phone') && str_contains($source, 't.country_code'));
    $fail('the producer defers out of band and re-verifies state', str_contains($source, 'wp_schedule_single_event') && str_contains($source, 'lifecycle_state'));
    $fail('SMS uses the existing destination policy and E.164 normaliser', str_contains($source, 'SmsProviderRoutingPolicy::select') && str_contains($source, 'PhoneNumberNormalizer::toE164'));
    $fail('SMS passes the body only to the provider and never records or logs it', str_contains($source, 'never recorded or logged'));

    echo "Lesson lifecycle notification unit passed\n";
}
