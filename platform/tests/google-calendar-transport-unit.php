<?php
/** Controlled Google Calendar/Meet transport contract: success, 409 convergence, retryable and permanent failures. */
namespace {
    if (!class_exists('WP_Error')) {
        class WP_Error { public function __construct(private string $code, private string $message) {} }
    }
    $GLOBALS['dzn_gcal_requests'] = array();
    $GLOBALS['dzn_gcal_responses'] = array();
    function wp_json_encode($value, int $flags = 0): string|false { return json_encode($value, $flags); }
    function is_wp_error($value): bool { return $value instanceof \WP_Error; }
    function wp_remote_request(string $uri, array $args = array()) {
        $GLOBALS['dzn_gcal_requests'][] = array('uri' => $uri, 'args' => $args);
        return array_shift($GLOBALS['dzn_gcal_responses']);
    }
    function wp_remote_retrieve_response_code($response): int { return (int) ($response['response']['code'] ?? 0); }
    function wp_remote_retrieve_body($response): string { return (string) ($response['body'] ?? ''); }
    require_once dirname(__DIR__) . '/src/Integrations/GoogleCalendarProjectionTransport.php';

    use Delnavazan\Platform\Integrations\GoogleCalendarProjectionTransport;

    $fail = static function (string $name, bool $ok): void { if (!$ok) throw new RuntimeException('Google Calendar transport unit failed: ' . $name); };
    $request = static function (string $eventId = 'abcdef123456'): array {
        return array(
            'origin' => 'https://www.googleapis.com',
            'path' => '/calendar/v3/calendars/primary/events',
            'method' => 'POST',
            'query' => array('conferenceDataVersion' => 1),
            'body' => array('id' => $eventId, 'summary' => 'Delnavazan replacement', 'start' => array('dateTime' => '2026-11-10T10:00:00Z'), 'end' => array('dateTime' => '2026-11-10T10:30:00Z')),
        );
    };
    $credential = static fn(): string => json_encode(array('access_token' => 'test-access-token'));
    $response = static fn(int $code, array $body = array()): array => array('response' => array('code' => $code), 'body' => json_encode($body));
    $reset = static function (array $responses): void { $GLOBALS['dzn_gcal_requests'] = array(); $GLOBALS['dzn_gcal_responses'] = $responses; };
    $throws = static function (callable $call, string $expected, string $name) use ($fail): void { $caught = null; try { $call(); } catch (\Throwable $e) { $caught = $e; } $fail($name, $caught !== null && $caught->getMessage() === $expected); };
    $transport = new GoogleCalendarProjectionTransport();

    $reset(array($response(200, array('id' => 'abcdef123456', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij'))));
    $result = $transport->dispatch($request(), $credential());
    $fail('successful creation returns the exact event and Meet link', $result['provider_object_reference'] === 'abcdef123456' && $result['join_uri_reference'] === 'https://meet.google.com/abc-defg-hij' && $result['conference_pending'] === false);
    $fail('successful creation sends exactly one POST', count($GLOBALS['dzn_gcal_requests']) === 1 && $GLOBALS['dzn_gcal_requests'][0]['args']['method'] === 'POST');
    $fail('the deterministic event id is sent to Google', json_decode((string) $GLOBALS['dzn_gcal_requests'][0]['args']['body'], true)['id'] === 'abcdef123456');

    $reset(array(
        $response(409),
        $response(200, array('id' => 'abcdef123456', 'conferenceData' => array('entryPoints' => array(array('entryPointType' => 'video', 'uri' => 'https://meet.google.com/xyz-1234-abc'))))),
    ));
    $result = $transport->dispatch($request(), $credential());
    $fail('HTTP 409 converges by reading the deterministic event', $result['provider_object_reference'] === 'abcdef123456' && $result['join_uri_reference'] === 'https://meet.google.com/xyz-1234-abc');
    $fail('HTTP 409 never issues a second event creation', count($GLOBALS['dzn_gcal_requests']) === 2 && $GLOBALS['dzn_gcal_requests'][0]['args']['method'] === 'POST' && $GLOBALS['dzn_gcal_requests'][1]['args']['method'] === 'GET');

    $reset(array($response(200, array('id' => 'abcdef123456'))));
    $result = $transport->dispatch($request(), $credential());
    $fail('asynchronous Meet creation is reported as pending', $result['join_uri_reference'] === null && $result['conference_pending'] === true);

    $reset(array(new \WP_Error('http_request_failed', 'timeout')));
    $throws(static fn() => $transport->dispatch($request(), $credential()), 'google_calendar_transport_failed', 'transport error is retryable and safe');

    $reset(array($response(403, array('error' => 'forbidden'))));
    $throws(static fn() => $transport->dispatch($request(), $credential()), 'google_calendar_projection_refused', 'permanent provider refusal is surfaced');

    $reset(array($response(200, array('id' => 'abcdef123456', 'hangoutLink' => 'https://evil.example/meet'))));
    $throws(static fn() => $transport->dispatch($request(), $credential()), 'google_meet_join_uri_untrusted', 'untrusted Meet host is refused');

    $reset(array($response(200, array('id' => 'different-event-id', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij'))));
    $throws(static fn() => $transport->dispatch($request(), $credential()), 'google_calendar_projection_identity_mismatch', 'provider identity mismatch is refused');

    $reset(array($response(409), $response(200, array('id' => 'different-event-id'))));
    $throws(static fn() => $transport->dispatch($request(), $credential()), 'google_calendar_projection_identity_mismatch', '409 convergence identity mismatch is refused');

    $bad = $request('BAD');
    $throws(static fn() => $transport->dispatch($bad, $credential()), 'google_calendar_event_id_invalid', 'invalid deterministic event id is refused before any network call');

    echo "Google Calendar transport unit passed\n";
}
