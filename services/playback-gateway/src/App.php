<?php
declare(strict_types=1);

namespace Delnavazan\Gateway;

/**
 * Playback gateway front controller. It is intended for the OVH host only, bound to the Tailscale
 * interface and never published through Caddy, WordPress or a public reverse proxy.
 */
final class App {
    private GrantValidator $validator;
    private ArchiveResolver $archive;
    private SessionStore $sessions;
    private ConcurrencyLimiter $limiter;
    private RangeStreamer $streamer;
    private AccessLog $log;

    public function __construct(private Config $config, private int $now) {
        $keys = new KeyStore((string) $config->get('keys_json'));
        $this->validator = new GrantValidator($keys, $now, (string) $config->get('issuer'));
        $this->archive = new ArchiveResolver($config);
        $this->sessions = new SessionStore($config);
        $this->limiter = new ConcurrencyLimiter($config);
        $this->log = new AccessLog($config);
        $this->streamer = new RangeStreamer($config, $this->log);
    }

    public function handle(string $method, string $path, string $range, string $body = ''): void {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        if ($method === 'GET' && ($path === '/' || $path === '/index.html')) { $this->page(); return; }
        if ($method === 'POST' && $path === '/session') { $this->session($body); return; }
        if (($method === 'GET' || $method === 'HEAD') && preg_match('#^/stream/([a-f0-9]{48})$#D', $path, $m) === 1) { $this->stream($method, $m[1], $range); return; }
        if ($method === 'GET' && $path === '/health') { $this->health(); return; }
        if ($method === 'GET' && $path === '/metrics') { $this->metrics(); return; }
        if ($path === '/favicon.ico') { http_response_code(204); return; }
        $this->json(404, ['error' => 'not_found']);
    }

    private function page(): void {
        header('Content-Type: text/html; charset=utf-8');
        echo <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Delnavazan recording</title>
<style>body{margin:0;background:#0b1f1a;color:#f4dfb0;font-family:system-ui,sans-serif}main{max-width:1100px;margin:0 auto;padding:24px}video{width:100%;background:#000;border-radius:14px}#status{margin:12px 0;color:#d7e8df}</style></head>
<body><main><h1>Delnavazan lesson recording</h1><p id="status">Validating the short-lived grant…</p><video id="player" controls playsinline preload="metadata"></video></main>
<script>
(function(){
  var el=document.getElementById('status'),video=document.getElementById('player');
  var m=location.hash.match(/(?:^#|&)grant=([^&]+)/);
  if(!m){el.textContent='No playback grant was supplied.';return;}
  var b64=m[1].replace(/-/g,'+').replace(/_/g,'/');while(b64.length%4)b64+='=';
  var grant;try{grant=JSON.parse(decodeURIComponent(escape(atob(b64))));}catch(e){el.textContent='The playback grant is invalid.';return;}
  history.replaceState(null,document.title,location.pathname);
  fetch('/session',{method:'POST',credentials:'omit',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify({grant:grant})}).then(function(r){if(!r.ok)throw new Error('unavailable');return r.json();}).then(function(data){
    video.src=data.stream_url;el.textContent='Authorised. The archive is streamed from the private Pi archive.';
    video.play().catch(function(){});
  }).catch(function(){el.textContent='This recording is temporarily unavailable or the grant is no longer valid.';});
})();
</script></body></html>
HTML;
    }

    private function session(string $body): void {
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || !is_array($decoded['grant'] ?? null)) throw new \RuntimeException('playback_grant_invalid');
            $payload = $this->validator->validate($decoded['grant']);
            $file = $this->archive->resolve((string) $payload['archive_object_key']);
            $this->sessions->recordJti((string) $payload['jti']);
            $id = $this->sessions->create($payload);
            $this->log->write('allow', ['principal_type' => $payload['principal_type'], 'principal_id' => (int) $payload['principal_id'], 'recording_uid' => $payload['recording_uid'], 'content_version' => (int) $payload['content_version'], 'jti_digest' => hash('sha256', (string) $payload['jti'])]);
            $this->json(200, ['stream_url' => '/stream/' . $id, 'expires_in' => (int) $this->config->get('ticket_ttl_seconds'), 'content_type' => 'video/mp4', 'size' => $file['size']]);
        } catch (\Throwable $error) {
            $reason = $error->getMessage();
            $status = $reason === 'archive_temporarily_unavailable' ? 503 : 403;
            $this->log->write('refuse', ['reason' => $reason]);
            $this->json($status, ['error' => $status === 503 ? 'recording_temporarily_unavailable' : 'recording_playback_denied'] + (getenv('DZN_PLAYBACK_DEBUG') ? ['debug' => $reason] : []));
        }
    }

    private function stream(string $method, string $ticket, string $range): void {
        $principal = null;
        try {
            $principal = $this->sessions->get($ticket);
            $file = $this->archive->resolve((string) $principal['archive_object_key']);
            $key = (string) $principal['principal_type'] . ':' . (int) $principal['principal_id'];
            $limit = $this->limiter->acquire($key);
            if (!$limit['ok']) { $this->log->write('refuse', ['reason' => $limit['reason'], 'principal_type' => $principal['principal_type'], 'principal_id' => (int) $principal['principal_id']]); $this->json(429, ['error' => 'playback_concurrency_limit']); return; }
            try { $this->streamer->serve($file, $method, $range, $principal, $ticket); }
            finally { $this->limiter->release($key); }
        } catch (\Throwable $error) {
            $reason = $error->getMessage();
            $this->log->write('refuse', ['reason' => $reason, 'ticket_digest' => hash('sha256', $ticket)]);
            if ($reason === 'archive_temporarily_unavailable') $this->json(503, ['error' => 'recording_temporarily_unavailable']);
            else $this->json(403, ['error' => 'recording_playback_denied']);
        }
    }

    private function health(): void {
        $state = $this->archive->state();
        $this->json($state['available'] ? 200 : 503, ['ready' => $state['available'], 'archive' => $state['reason'], 'identity' => $state['identity']]);
    }

    private function metrics(): void {
        $state = $this->archive->state();
        $sessions = $this->sessions->activeCount();
        $access = $this->log->summary(3600);
        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        $lines = [
            '# HELP dzn_playback_service_up Playback gateway process healthy',
            '# TYPE dzn_playback_service_up gauge',
            'dzn_playback_service_up 1',
            '# HELP dzn_playback_archive_mounted Pi archive mount available',
            '# TYPE dzn_playback_archive_mounted gauge',
            'dzn_playback_archive_mounted ' . ($state['available'] ? 1 : 0),
            '# HELP dzn_playback_active_sessions Unexpired stream tickets',
            '# TYPE dzn_playback_active_sessions gauge',
            'dzn_playback_active_sessions ' . $sessions,
            '# HELP dzn_playback_events_total Playback access events in the last hour',
            '# TYPE dzn_playback_events_total counter',
        ];
        foreach ($access as $event => $count) $lines[] = 'dzn_playback_events_total{event="' . $event . '"} ' . (int) $count;
        echo implode("\n", $lines) . "\n";
    }

    private function json(int $status, array $payload): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private, max-age=0');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}
