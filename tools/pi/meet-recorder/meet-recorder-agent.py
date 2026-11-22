#!/usr/bin/env python3
"""Delnavazan Meet recording companion.

Polls the platform for a pending classroom join, opens that class in the signed-in organizer
Chromium profile over the loopback DevTools protocol, clicks Join, waits for the call to start
(which starts Google auto-recording), then leaves. The class itself never depends on this agent:
if it is down or fails, the teacher/student still join and the platform reports the missing
recording.

Configuration (environment, normally /etc/dzn-meet-recorder.env):
  DZN_BASE_URL          https://delnavazan.com
  DZN_RECORDER_TOKEN    shared bearer token set by the platform operator
  DZN_CDP_URL           http://127.0.0.1:9222
  DZN_POLL_SECONDS      5
  DZN_JOIN_TIMEOUT      30
  DZN_LEAVE_AFTER       120   seconds to stay in the call before leaving
"""

from __future__ import annotations

import json
import os
import sys
import time
import urllib.error
import urllib.request
from urllib.parse import urlparse

try:
    import websocket  # websocket-client
except ImportError:  # pragma: no cover - surfaced at startup
    print("meet-recorder: python package websocket-client is required", file=sys.stderr)
    raise SystemExit(2)

BASE_URL = os.environ.get("DZN_BASE_URL", "https://delnavazan.com").rstrip("/")
TOKEN = os.environ.get("DZN_RECORDER_TOKEN", "").strip()
CDP_URL = os.environ.get("DZN_CDP_URL", "http://127.0.0.1:9222").rstrip("/")
POLL_SECONDS = max(2, int(os.environ.get("DZN_POLL_SECONDS", "5")))
JOIN_TIMEOUT = max(10, int(os.environ.get("DZN_JOIN_TIMEOUT", "30")))
LEAVE_AFTER = max(30, int(os.environ.get("DZN_LEAVE_AFTER", "120")))
API = f"{BASE_URL}/wp-json/delnavazan-platform/v1/meet/recorder"
NEXT_URL = f"{BASE_URL}/wp-json/delnavazan-platform/v1/meet/recorder/next"
RESULT_URL = f"{BASE_URL}/wp-json/delnavazan-platform/v1/meet/recorder/result"


MEET_HOST = "meet.google.com"


def is_meet_url(value: str) -> bool:
    """True only for a bare https://meet.google.com/<code> URL."""
    try:
        parts = urlparse(value)
        host = (parts.hostname or "").lower()
    except ValueError:
        return False
    return (
        parts.scheme == "https"
        and host == MEET_HOST
        and not parts.username
        and not parts.password
        and parts.path.strip("/") != ""
    )


def log(message: str) -> None:
    print(f"meet-recorder: {message}", file=sys.stderr, flush=True)


def http_json(url: str, method: str = "GET", payload: dict | None = None, timeout: int = 20) -> dict:
    data = None
    headers = {"Authorization": f"Bearer {TOKEN}", "Accept": "application/json"}
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"
    request = urllib.request.Request(url, data=data, headers=headers, method=method)
    with urllib.request.urlopen(request, timeout=timeout) as response:
        body = response.read().decode("utf-8", "replace")
    return json.loads(body) if body.strip() else {}


def cdp_get(path: str) -> dict:
    with urllib.request.urlopen(f"{CDP_URL}{path}", timeout=10) as response:
        return json.loads(response.read().decode("utf-8", "replace"))


def browser_socket() -> str:
    version = cdp_get("/json/version")
    socket_url = str(version.get("webSocketDebuggerUrl", ""))
    if not socket_url:
        raise RuntimeError("cdp_browser_socket_missing")
    return socket_url


def page_socket_for(target_id: str, timeout: int = 20) -> str:
    """Resolve the DevTools socket of the tab we just created, never another tab."""
    deadline = time.time() + timeout
    while time.time() < deadline:
        for target in cdp_get("/json"):
            if target.get("type") != "page":
                continue
            socket_url = str(target.get("webSocketDebuggerUrl", ""))
            if not socket_url:
                continue
            if target_id and str(target.get("id", "")) == target_id:
                return socket_url
        time.sleep(0.5)
    for target in cdp_get("/json"):
        if target.get("type") == "page" and is_meet_url(str(target.get("url", ""))):
            socket_url = str(target.get("webSocketDebuggerUrl", ""))
            if socket_url:
                return socket_url
    raise RuntimeError("cdp_page_socket_missing")


def close_stale_meet_tabs() -> None:
    """A crash can leave the organizer joined in an orphan tab; start from one clean state."""
    try:
        targets = cdp_get("/json")
    except Exception:
        return
    stale = [
        str(target.get("id", ""))
        for target in targets
        if target.get("type") == "page" and is_meet_url(str(target.get("url", "")))
    ]
    if not stale:
        return
    control = Cdp(browser_socket())
    try:
        for target_id in stale:
            control.call("Target.closeTarget", {"targetId": target_id})
        log(f"closed {len(stale)} stale Meet tab(s)")
    finally:
        control.close()


class Cdp:
    def __init__(self, socket_url: str) -> None:
        self.ws = websocket.create_connection(socket_url, timeout=15)
        self.next_id = 0

    def call(self, method: str, params: dict | None = None) -> dict:
        self.next_id += 1
        identifier = self.next_id
        self.ws.send(json.dumps({"id": identifier, "method": method, "params": params or {}}))
        while True:
            message = json.loads(self.ws.recv())
            if int(message.get("id", 0)) == identifier:
                return message

    def evaluate(self, expression: str) -> str:
        result = self.call("Runtime.evaluate", {"expression": expression, "returnByValue": True})
        value = result.get("result", {}).get("result", {}).get("value")
        return str(value) if value is not None else ""

    def close(self) -> None:
        try:
            self.ws.close()
        except Exception:
            pass


JOIN_JS = """(() => {
  const buttons = [...document.querySelectorAll('button, [role="button"]')];
  const match = buttons.find((b) => {
    const text = ((b.textContent || '') + ' ' + (b.getAttribute('aria-label') || '')).toLowerCase();
    return text.includes('join the call') || text.includes('join now') || text.includes('ask to join');
  });
  if (!match) return 'notfound';
  match.click();
  return 'clicked';
})()"""

IN_CALL_JS = """(() => {
  const buttons = [...document.querySelectorAll('button, [role="button"]')];
  return buttons.some((b) => {
    const text = ((b.textContent || '') + ' ' + (b.getAttribute('aria-label') || '')).toLowerCase();
    return text.includes('leave call') || text.includes('leave the call');
  }) ? 'incall' : 'waiting';
})()"""

LEAVE_JS = """(() => {
  const buttons = [...document.querySelectorAll('button, [role="button"]')];
  const match = buttons.find((b) => {
    const text = ((b.textContent || '') + ' ' + (b.getAttribute('aria-label') || '')).toLowerCase();
    return text.includes('leave call') || text.includes('leave the call');
  });
  if (!match) return 'notfound';
  match.click();
  return 'clicked';
})()"""

CONFIRM_LEAVE_JS = """(() => {
  const buttons = [...document.querySelectorAll('button, [role="button"]')];
  const match = buttons.find((b) => (b.textContent || '').trim() === 'Just leave the call');
  if (!match) return 'none';
  match.click();
  return 'left';
})()"""


def open_target(join_uri: str) -> tuple[str, str]:
    control = Cdp(browser_socket())
    try:
        created = control.call("Target.createTarget", {"url": join_uri})
        target_id = str(created.get("result", {}).get("targetId", ""))
        if not target_id:
            raise RuntimeError("cdp_target_create_failed")
    finally:
        control.close()
    return target_id, page_socket_for(target_id)


def join_call(page: Cdp) -> bool:
    """Click Join once it appears and confirm the in-call UI, whichever comes first."""
    deadline = time.time() + JOIN_TIMEOUT
    clicked = False
    while time.time() < deadline:
        try:
            if page.evaluate(IN_CALL_JS) == "incall":
                return True
            if not clicked and page.evaluate(JOIN_JS) == "clicked":
                clicked = True
        except Exception:
            pass
        time.sleep(1.0)
    return False


def leave_call(page: Cdp) -> None:
    try:
        page.evaluate(LEAVE_JS)
    except Exception:
        return
    time.sleep(2.0)
    try:
        page.evaluate(CONFIRM_LEAVE_JS)
    except Exception:
        pass


def close_target(target_id: str) -> None:
    try:
        control = Cdp(browser_socket())
        control.call("Target.closeTarget", {"targetId": target_id})
        control.close()
    except Exception:
        pass


def report(job: dict, state: str, detail: str) -> None:
    payload = {
        "uid": str(job.get("uid", "")),
        "classroom_id": int(job.get("classroom_id", 0)),
        "state": state,
        "detail": detail[:200],
    }
    deadline = time.time() + 60
    while time.time() < deadline:
        try:
            http_json(RESULT_URL, "POST", payload)
            return
        except Exception as error:  # retry a transient platform failure
            log(f"result report retry: {type(error).__name__}")
            time.sleep(5)
    log("result report failed")


def process(job: dict) -> None:
    classroom_id = int(job.get("classroom_id", 0))
    join_uri = str(job.get("join_uri", ""))
    if classroom_id < 1 or not is_meet_url(join_uri):
        report(job, "failed", "recorder_job_invalid")
        return
    target_id = ""
    try:
        target_id, socket_url = open_target(join_uri)
        page = Cdp(socket_url)
        try:
            if not join_call(page):
                report(job, "failed", "recorder_call_not_started")
                return
            log(f"joined classroom {classroom_id}; recording should be active")
            report(job, "joined", "recorder_in_call")
            time.sleep(LEAVE_AFTER)
            leave_call(page)
            log(f"left classroom {classroom_id}")
        finally:
            page.close()
    except Exception as error:
        log(f"classroom {classroom_id} failed: {type(error).__name__}")
        report(job, "failed", f"recorder_agent_{type(error).__name__}")
    finally:
        if target_id:
            close_target(target_id)


def main() -> None:
    if not TOKEN:
        log("DZN_RECORDER_TOKEN is not configured; agent disabled")
        raise SystemExit(2)
    close_stale_meet_tabs()
    log(f"polling {NEXT_URL} every {POLL_SECONDS}s")
    while True:
        try:
            response = http_json(NEXT_URL)
            job = response.get("job")
            if isinstance(job, dict) and job:
                process(job)
            else:
                time.sleep(POLL_SECONDS)
        except urllib.error.HTTPError as error:
            log(f"platform HTTP {error.code}; backing off")
            time.sleep(max(POLL_SECONDS, 30))
        except Exception as error:
            log(f"poll failed: {type(error).__name__}")
            time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    main()
