# LiveKit two-participant staging verification

**Date:** 2026-11-01
**Environment:** local disposable WordPress + MariaDB at `127.0.0.1:18081`, local LiveKit
`livekit/livekit-server:latest` development server at `127.0.0.1:7880`, headless Chrome with fake
media devices. No production credentials, rooms or traffic were used.

## Result

**PASS** for the two-participant classroom behaviour exercised below.

| Check | Observed |
| --- | --- |
| Teacher connection | connected, 2/2 participants |
| Student connection | connected, 2/2 participants |
| Audio path | one remote audio element with a subscribed track on each participant |
| Local media | local video attached and microphone enabled on both |
| Shared music mode | teacher toggled on; student checkbox became `true` |
| Reconnection | student went offline, reconnected; connection returned to connected and music remained `true` |
| Student Exit | classroom window closed; Lesson remained `authorised` |
| Teacher Finish | Lesson became `completed` through `teacher_confirmed_delivery` |

The teacher finish evidence was recorded as:

- delivery outcome `reason_code=teacher_confirmed_delivery`
- `evidence_channel=authenticated_platform`
- `recorded_by=3` (the Teacher)
- lifecycle event `authorised → completed`, `reason_code=canonical_lesson_completed`

Student Exit did not create a delivery outcome or completion. The completion happened only after the
Teacher used Finish.

## Defect found and fixed

The LiveKit participant token set `canPublishData=false`. The shared music mode is carried over
reliable LiveKit data messages, so the teacher toggle was silently not delivered. The token now grants
`canPublishData=true` while keeping publish sources limited to camera and microphone. Unit and
contract tests were updated to require the data grant.

## Reproduce

1. Start a local LiveKit server and configure `DZN_LIVEKIT_URL=ws://127.0.0.1:7880`,
   `DZN_LIVEKIT_API_URL=http://host.docker.internal:7880`, `DZN_LIVEKIT_API_KEY=devkey`,
   `DZN_LIVEKIT_API_SECRET=secret` in the disposable WordPress runtime.
2. Seed `phase-2a2j-fixture.php` and `acceptance/fixtures/livekit-staging.php`.
3. Log in one Teacher and one Student context and click the primary LiveKit action.
4. Verify the DOM state, toggle music, interrupt the Student network, exit Student, then wait for the
   canonical end and use Teacher Finish.

## Limitation

Headless Chrome used a fake media device, so the verification proves publication, subscription and
audio-track attachment, not physical speaker output. A final manual audible check on a real staging
device remains a release checklist item.
