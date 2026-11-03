<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Delnavazan\Recorder\Archive\LocalArchiveAdapter;
use Delnavazan\Recorder\Archive\RsyncArchiveAdapter;
use Delnavazan\Recorder\Config;
use Delnavazan\Recorder\Database;
use Delnavazan\Recorder\Repository;
use Delnavazan\Recorder\Storage\StorageLayout;
use Delnavazan\Recorder\Worker;

$config = Config::fromEnvironment();
$database = new Database($config);
$repository = new Repository($database);
$storage = new StorageLayout($config);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if ($path === '/health' || $path === '/status') {
    try {
        $transport = (string) $config->get('archive_transport');
        $archive = $transport === 'local' ? new LocalArchiveAdapter($config) : new RsyncArchiveAdapter($config);
        $worker = new Worker($config, $repository, $storage, new \Delnavazan\Recorder\Provider\LiveKitEgressClient($config), $archive, new \Delnavazan\Recorder\Media\MediaVerifier(), new \Delnavazan\Recorder\SystemClock());
        $health = $worker->health();
        unset($health['queue_counts']);
        $payload = $path === '/status' ? ['health' => $health, 'counts' => $repository->counts(), 'storage' => $storage->describe()] : $health;
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    } catch (Throwable $error) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ready' => false, 'reason' => 'service_unavailable']);
    }
    return;
}

if ($path === '/egress/template' || $path === '/egress/template/') {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Delnavazan recording layout</title>
<style>
  *{box-sizing:border-box}html,body{margin:0;width:100%;height:100%;background:#0b1f1a;overflow:hidden;font-family:system-ui,sans-serif}
  #stage{position:relative;display:grid;grid-template-columns:1fr 1fr;gap:8px;width:100vw;height:100vh;padding:8px;background:linear-gradient(160deg,#0b1f1a,#14342c)}
  .participant{position:relative;overflow:hidden;border-radius:18px;background:#102820;border:1px solid rgba(255,255,255,.14)}
  .participant video{width:100%;height:100%;object-fit:cover}
  .participant .name{position:absolute;inset-block-end:10px;inset-inline-start:12px;color:#fff;background:rgba(0,0,0,.45);padding:4px 10px;border-radius:999px;font-size:14px}
  #watermark{position:absolute;inset-block-end:18px;inset-inline-end:22px;opacity:.55;color:#f4dfb0;font-weight:700;letter-spacing:.14em;font-size:clamp(14px,1.6vw,24px);text-shadow:0 1px 2px #000}
  #missing{display:none;position:absolute;inset:0;place-items:center;color:#f4dfb0;background:#0b1f1a;font-size:20px}
</style>
</head>
<body>
<div id="stage" data-dzn-recording-layout="dzn-two-person" data-dzn-recording-native-watermark="true">
  <div class="participant" id="participant-a" data-participant-region="a"><video id="video-a" autoplay playsinline muted></video><span class="name">Participant A</span></div>
  <div class="participant" id="participant-b" data-participant-region="b"><video id="video-b" autoplay playsinline muted></video><span class="name">Participant B</span></div>
  <div id="watermark" aria-label="Delnavazan">DELNAVAZAN</div>
  <div id="missing">Recording layout is waiting for the two lesson participants.</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/livekit-client@2.15.0/dist/livekit-client.umd.min.js"></script>
<script>
/* LiveKit Room Composite custom template. The egress worker supplies ws url + token in query. */
(function(){
  var q=new URLSearchParams(location.search);
  var url=q.get('url'), token=q.get('token');
  if(!url||!token||!window.LivekitClient){document.getElementById('missing').style.display='grid';return;}
  var room=new window.LivekitClient.Room({adaptiveStream:false,dynacast:false,audioCaptureDefaults:{echoCancellation:false,noiseSuppression:false,autoGainControl:false},videoCaptureDefaults:{resolution:window.LivekitClient.VideoPresets.h720.resolution}});
  var slots={};
  room.on(window.LivekitClient.RoomEvent.TrackSubscribed,function(track,pub,participant){
    if(track.kind!=='video'&&track.kind!=='audio')return;
    if(track.kind==='video'){
      var id=slots[participant.identity];
      if(!id){var used=Object.keys(slots);id=used.indexOf('a')<0?'a':(used.indexOf('b')<0?'b':null);if(id)slots[participant.identity]=id;}
      if(id)track.attach(document.getElementById('video-'+id));
      var label=document.querySelector('#participant-'+id+' .name');if(label)label.textContent=participant.name||participant.identity;
    }else{
      track.attach();
    }
  });
  room.connect(url,token).catch(function(){document.getElementById('missing').style.display='grid';});
})();
</script>
</body>
</html>
HTML;
    return;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'not_found']);
