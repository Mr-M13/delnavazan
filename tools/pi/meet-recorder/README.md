# Meet recording companion (Raspberry Pi)

The companion is an operator-owned device that joins a class as the **organizer** (`classroom@`)
on web. Google only starts `autoRecordingGeneration` when a host/co-host joins on web, so this
device triggers the recording for every class regardless of the teacher's phone/browser. The class
never depends on it: if the companion is offline or fails, the teacher and student still join and
teach, and the platform's recording reconciliation raises the missing-recording alert.

## Flow

1. A Teacher presses the portal **Start class / Join** button (the only entry to a class). The join
   controller enqueues one **companion join** for that classroom (coalesced; a rejoin creates a new
   request after the previous one completed). A Student joining never triggers the companion: there
   is nothing worth recording before the teacher is there.
2. The platform keeps the request in the `dzn_meet_recorder_jobs` option (classroom id only; the
   Meet URI is resolved from the sealed vault on claim and never stored in the option).
3. This agent polls `GET /wp-json/delnavazan-platform/v1/meet/recorder/next` with its bearer token,
   receives the join URI, opens it in the signed-in `chromium-classroom` profile over the
   loopback DevTools protocol (CDP), clicks **Join**, waits for the in-call UI (recording starts),
   reports `joined`, stays `DZN_LEAVE_AFTER` seconds, then leaves.
4. Google keeps recording while the teacher/student remain, so a short stay is enough. A total
   dropout followed by a teacher rejoin creates a new request and starts a new recording. Set
   `DZN_LEAVE_AFTER` to the full class length if you prefer the organizer to stay.
5. One companion joins one class at a time: while it is staying in a call it is not polling, so a
   second class that starts within `DZN_LEAVE_AFTER` seconds is picked up when that stay ends.

## One-time installation

On the Pi as `hamed`:

```sh
# 1. Python environment for the agent
python3 -m venv ~/.local/share/meet-recorder/venv
~/.local/share/meet-recorder/venv/bin/pip install websocket-client

# 2. Agent and service
install -d ~/bin
install -m 0755 meet-recorder-agent.py ~/bin/meet-recorder-agent.py
sudo install -m 0644 dzn-meet-recorder.service /etc/systemd/system/dzn-meet-recorder.service

# 3. Secret configuration (root-owned, never committed)
sudo install -m 0600 dzn-meet-recorder.env.example /etc/dzn-meet-recorder.env
sudoedit /etc/dzn-meet-recorder.env   # set DZN_RECORDER_TOKEN
sudo systemctl daemon-reload
sudo systemctl enable --now dzn-meet-recorder.service
```

On the WordPress host, set the same token once:

```sh
wp option update dzn_meet_recorder_token "$(openssl rand -hex 32)"
```

The token is a secret: never commit it, never print it, and keep the env file root-owned `0600`.
An empty token disables the recorder endpoints (HTTP 503).

## Organizer browser profile

The companion profile is signed into `classroom@delnavazan.com` and is kept alive by
`classroom-browser.sh`, which launches Chromium with CDP on loopback only. Install it and let the
script own the launch:

```sh
install -m 0755 classroom-browser.sh ~/bin/classroom-browser.sh
```

The flags it keeps alive (do not add others — a different `--password-store` invalidates the
profile's cookies):

```sh
chromium --ozone-platform=wayland \
  --user-data-dir=/home/hamed/.config/chromium-classroom \
  --remote-debugging-port=9222 --remote-allow-origins=* \
  --no-first-run --disable-sync about:blank
```

Start it from the graphical session so it restarts after a crash or a reboot — on the Raspberry Pi
that is one line in `~/.config/labwc/autostart`:

```sh
/home/hamed/bin/classroom-browser.sh >/dev/null 2>&1 &
```

CDP listens on `127.0.0.1` only; do not expose port 9222.

## Verification

```sh
# The agent polls and logs to the journal
journalctl -u dzn-meet-recorder -f

# The platform audit trail records each companion join
wp eval 'global $wpdb; foreach ($wpdb->get_results("SELECT classroom_id,result_state,reason_code,created_at FROM {$wpdb->prefix}dzn_meet_classroom_events WHERE operation=\"recorder_join\" ORDER BY id DESC LIMIT 10") as $row) echo json_encode($row),"\n";'

# Google's quality tool must show "Recording" for the class (not "Failed to initialise")
```

## Failure behavior

- The agent, platform queue, or organizer profile can fail independently; none blocks a class.
- A pending request older than 15 minutes is discarded; a claimed request whose agent vanished is
  reclaimable after 10 minutes.
- Only classrooms whose `recording_state` is `configured` are enqueued, so a Student opt-out never
  joins the companion.
- The agent never logs the bearer token or the Meet URI.
