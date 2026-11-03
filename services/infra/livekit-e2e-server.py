#!/usr/bin/env python3
"""Isolated LiveKit Egress test room/token server. Localhost only; secrets never exposed."""
import base64
import hashlib
import hmac
import json
import os
import time
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

CONFIG = os.environ.get("EGRESS_CONFIG_FILE", "/etc/delnavazan-staging/egress.yaml")
ROOM = os.environ.get("DZN_E2E_ROOM", "dzn-rec-testroom")
WS_URL = os.environ.get("DZN_E2E_WS_URL", "wss://rtc.delnavazan.com")
PORT = int(os.environ.get("DZN_E2E_PORT", "19090"))
LIVEKIT_JS = "/opt/dzn-e2e-test/livekit-client.umd.min.js"

def credentials():
    values = {}
    with open(CONFIG, "r", encoding="utf-8") as handle:
        for line in handle:
            if ":" in line and not line.lstrip().startswith("#"):
                key, value = line.split(":", 1)
                values[key.strip()] = value.strip().strip('"').strip("'")
    return values["api_key"], values["api_secret"]

def b64(value: bytes) -> str:
    return base64.urlsafe_b64encode(value).rstrip(b"=").decode()

def token(identity: str, name: str, grant: dict, ttl: int = 900) -> str:
    key, secret = credentials()
    now = int(time.time())
    header = {"alg": "HS256", "typ": "JWT"}
    payload = {"iss": key, "sub": identity, "nbf": now - 5, "iat": now, "exp": now + ttl, "name": name, "video": grant}
    body = b64(json.dumps(header, separators=(",", ":")).encode()) + "." + b64(json.dumps(payload, separators=(",", ":")).encode())
    return body + "." + b64(hmac.new(secret.encode(), body.encode(), hashlib.sha256).digest())

def create_room() -> dict:
    room_token = token("dzn-e2e-room-admin", "E2E room admin", {"roomCreate": True, "roomList": True, "roomAdmin": True}, 120)
    request = urllib.request.Request(
        "http://127.0.0.1:7880/twirp/livekit.RoomService/CreateRoom",
        data=json.dumps({"name": ROOM, "empty_timeout": 600, "max_participants": 2}).encode(),
        headers={"Authorization": "Bearer " + room_token, "Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=10) as response:
        return json.loads(response.read().decode() or "{}")

PAGE = r'''<!doctype html><html><head><meta charset="utf-8"><title>DZN E2E participant</title>
<style>body{margin:0;background:#111;color:#eee;font-family:system-ui}canvas{width:640px;height:360px;display:block}#s{padding:8px}button{padding:10px 18px;font-size:16px}</style></head>
<body><div id="s">E2E participant <button id="join">Join and publish</button></div><canvas id="c" width="640" height="360"></canvas>
<script src="/livekit.js"></script><script>
(function(){
  var role=new URLSearchParams(location.search).get('role')||'teacher';
  var canvas=document.getElementById('c'),ctx=canvas.getContext('2d'),phase=0;
  var colors=role==='teacher'?['#1f6f5c','#f4dfb0']:['#5c3a1f','#b0dff4'];
  function draw(){phase+=0.02;ctx.fillStyle=colors[0];ctx.fillRect(0,0,640,360);ctx.fillStyle=colors[1];ctx.font='bold 42px system-ui';ctx.fillText(role.toUpperCase(),40,90);ctx.font='24px system-ui';ctx.fillText('Delnavazan E2E '+Math.sin(phase).toFixed(2),40,150);ctx.fillStyle='#fff';ctx.fillRect(40,210,400,24);ctx.fillStyle=colors[1];ctx.fillRect(40,210,(Math.sin(phase)+1)*200,24);requestAnimationFrame(draw);} draw();
  document.getElementById('join').onclick=async function(){
    var status=document.getElementById('s');status.textContent='requesting token…';
    try{
      var response=await fetch('/token?role='+encodeURIComponent(role),{cache:'no-store'});if(!response.ok)throw new Error('token');
      var data=await response.json();
      var room=new LivekitClient.Room({adaptiveStream:false,dynacast:false,audioCaptureDefaults:{echoCancellation:false,noiseSuppression:false,autoGainControl:false},videoCaptureDefaults:{resolution:{width:640,height:360,frameRate:30}}});
      var ac=new AudioContext();await ac.resume();var osc=ac.createOscillator();var gain=ac.createGain();gain.gain.value=0.06;osc.frequency.value=role==='teacher'?440:660;var dest=ac.createMediaStreamDestination();osc.connect(gain).connect(dest);osc.start();
      await room.connect(data.url,data.token);
      var vstream=canvas.captureStream(30);
      await room.localParticipant.publishTrack(vstream.getVideoTracks()[0],{name:'e2e-video',source:LivekitClient.Track.Source.Camera});
      await room.localParticipant.publishTrack(dest.stream.getAudioTracks()[0],{name:'e2e-audio',source:LivekitClient.Track.Source.Microphone});
      room.on(LivekitClient.RoomEvent.Disconnected,function(){console.log('END_RECORDING');});
      status.textContent='connected as '+role+' · room '+room.name+' · participants '+room.numParticipants;
      setTimeout(function(){console.log('START_RECORDING');},1500);
    }catch(e){status.textContent='failed: '+e.message;}
  };
})();
</script></body></html>'''

class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        return
    def send(self, status, body, content_type):
        data = body.encode() if isinstance(body, str) else body
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(data)
    def do_GET(self):
        path = urlparse(self.path).path
        if path == "/livekit.js":
            with open(LIVEKIT_JS, "rb") as handle:
                self.send(200, handle.read(), "application/javascript")
            return
        if path == "/token":
            role = (parse_qs(urlparse(self.path).query).get("role", ["teacher"])[0] or "teacher")
            identity = "e2e-" + role
            value = token(identity, identity, {"roomJoin": True, "room": ROOM, "canPublish": True, "canSubscribe": True, "canPublishData": True, "canPublishSources": ["camera", "microphone"], "roomCreate": False, "roomAdmin": False, "roomRecord": False})
            self.send(200, json.dumps({"url": WS_URL, "token": value, "room": ROOM}), "application/json")
            return
        if path == "/create-room":
            try:
                self.send(200, json.dumps(create_room()), "application/json")
            except Exception as error:
                self.send(503, json.dumps({"error": str(error)}), "application/json")
            return
        self.send(200, PAGE, "text/html; charset=utf-8")

ThreadingHTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
