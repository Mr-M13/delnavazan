#!/bin/sh
# Keep the organizer Chromium profile alive with loopback DevTools for the recorder companion.
#
# The companion agent (meet-recorder-agent.py) drives this browser over 127.0.0.1:9222. Nothing is
# exposed off the device: no host forwarding, no firewall exception. See README.md in this folder.
set -eu

PROFILE="${DZN_CLASSROOM_PROFILE:-$HOME/.config/chromium-classroom}"
PORT="${DZN_CDP_PORT:-9222}"
START_URL="${1:-about:blank}"

while :; do
    if curl -sf --max-time 2 "http://127.0.0.1:$PORT/json/version" >/dev/null 2>&1; then
        sleep 10
        continue
    fi
    chromium \
        --ozone-platform=wayland \
        --user-data-dir="$PROFILE" \
        --remote-debugging-port="$PORT" \
        --remote-allow-origins='*' \
        --no-first-run \
        --no-default-browser-check \
        --disable-sync \
        "$START_URL" || true
    sleep 5
done
