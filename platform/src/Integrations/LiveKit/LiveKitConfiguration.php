<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Reads LiveKit credentials only from process configuration.
 *
 * The API secret is never stored in a WordPress option, returned by an API, logged, or committed
 * to source. The public WebSocket URL and the server-side HTTPS API URL may be configured
 * separately; when no API URL is supplied it is derived from the WebSocket host.
 */
final class LiveKitConfiguration {
    private const DEFAULT_URL = 'wss://rtc.delnavazan.com';
    private const ROOM_EMPTY_TIMEOUT_SECONDS = 300;
    private const MAX_PARTICIPANTS = 2;

    public function values(): array {
        $url = $this->value('DZN_LIVEKIT_URL', self::DEFAULT_URL);
        $key = $this->value('DZN_LIVEKIT_API_KEY');
        $secret = $this->value('DZN_LIVEKIT_API_SECRET');
        $apiUrl = $this->value('DZN_LIVEKIT_API_URL');
        $parts = parse_url($url);
        if (!$key || !$secret || !is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'wss'
            || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('livekit_not_configured');
        }
        if ($apiUrl === '') {
            $apiUrl = 'https://' . strtolower((string)$parts['host']) . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
        }
        $apiParts = parse_url($apiUrl);
        if (!is_array($apiParts)
            || strtolower((string)($apiParts['scheme'] ?? '')) !== 'https'
            || empty($apiParts['host'])
            || isset($apiParts['user']) || isset($apiParts['pass']) || isset($apiParts['query']) || isset($apiParts['fragment'])
            || !preg_match('/^[A-Za-z0-9.-]+$/D', (string)$apiParts['host'])) {
            throw new \RuntimeException('livekit_not_configured');
        }
        return array(
            'url' => rtrim($url, '/'),
            'api_url' => rtrim($apiUrl, '/'),
            'key' => $key,
            'secret' => $secret,
            'room_empty_timeout' => self::ROOM_EMPTY_TIMEOUT_SECONDS,
            'max_participants' => self::MAX_PARTICIPANTS,
        );
    }

    private function value(string $name, string $default = ''): string {
        if (defined($name)) return trim((string)constant($name));
        $value = getenv($name);
        return $value === false ? $default : trim((string)$value);
    }
}
