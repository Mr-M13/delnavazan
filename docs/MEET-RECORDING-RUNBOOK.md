# Google Meet recording runbook

This runbook covers Google Meet recordings for Delnavazan classrooms. Recordings are owned by the
organizer account (`classroom@delnavazan.com` by default), are written to that account's Google
Drive, and are verified read-only by the platform. Delnavazan never starts a second recorder and
never moves or deletes recording files.

## How recording is configured

- Provisioning sets the Meet space's `artifactConfig.recordingConfig.autoRecordingGeneration` to
  `ON` (or `OFF` for a declined Student opt-out). For introductory bookings the default is `ON`.
- Google only auto-starts the recording when *someone with the privilege to record joins the
  meeting on web*: the organizer, or the Teacher as the space COHOST. If the Teacher joins from the
  mobile app or a phone, the recording does not start.
- Recording depends on the organizer account, not the Teacher: the Workspace edition must support
  recording, the Workspace admin must allow recording for Meet, and the organizer's Drive must be
  enabled with free quota.

## Symptom → cause checklist

| Symptom | Most likely cause | Verification |
|---|---|---|
| Recording never starts (no spinner) | `autoRecordingGeneration` OFF (opt-out or failed config PATCH), Teacher joined on mobile, admin disabled recording, unsupported Workspace edition | Check the classroom row `recording_state` in the recording health report; re-run config sync |
| Recording starts, then stops mid-class with a "recording stopped" message | Organizer Drive quota exhausted (personal **or** organization quota); Drive disabled mid-flight; admin policy change | Check Drive storage of the organizer account and the Workspace org storage |
| Short recording file exists but the class continued | Same as above — Meet kept a partial MP4 | The platform raises the `recording_ended_early` alert (`recording_stopped_while_participants_remained`) |
| No recording file at all after class | Recording never started, or stopped before any file persisted | The platform raises the `recording_missing` alert after the 2-hour processing grace |

Note the July 2026 Google change: the "Meet Recordings" folder is now "Legacy Meet Recordings"
inside a new "Google Meet" folder with per-meeting sub-folders. Old links keep working.

## Owner-side verification (Google admin)

1. **Drive storage** — open Drive as the organizer account (`classroom@delnavazan.com`) and check
   the storage page (Settings → Storage). Every class adds a recording; nothing deletes them
   automatically. Move or export older files to free quota. Check the organization storage quota in
   the Workspace Admin console (Storage) as well: a full organization quota blocks recordings even
   when the personal quota has room.
2. **Workspace edition** — recording requires Business Standard/Plus, Enterprise, Essentials,
   Teaching and Learning Upgrade, Workspace Individual, or a 2 TB+ Google One plan. Business Starter
   does not include recording.
3. **Admin console** — Apps → Google Workspace → Google Meet → Meet video settings: confirm
   "Let users record their meetings" is ON for the organizer's organizational unit, and that the
   organizer's Drive is not disabled.
4. **In-meeting evidence** — the Meet UI message at the moment of failure names the cause
   ("Not enough storage space", admin policy, etc.). Ask the Teacher to screenshot it.

## What the platform reports

- The five-minute classroom worker reconciles ended classrooms (lessons **and** introductory
  bookings since #740) against the official `conferenceRecords → recordings` API and verifies each
  MP4's metadata, permissions and readability.
- Alerts land in `meet_recording_alerts`, fire `dzn_meet_recording_alert`, and email the WordPress
  admin address: `recording_missing`, `recording_ended_without_artifact`, `recording_did_not_end`,
  `recording_unplayable`, `recording_privacy_*`, and `recording_ended_early` (raised post-hoc with
  detail `recording_stopped_while_participants_remained` when the recording ended at least five
  minutes before the last participant left).
- The recording health report is visible under Settings → Integrations (Google Meet classroom).
- A truncated recording remains playable and verified; the artifact row is marked with
  `failure_reason_code = recording_ended_early` so the evidence trail distinguishes it.

## Google emails that cannot be disabled

Calendar invitation, update, cancellation and reminder emails for Delnavazan events are suppressed
by the platform (`sendUpdates=none` and `reminders.useDefault=false`). Google still sends a "your
recording is ready" email to the meeting organizer and to the person who started the recording when
a recording completes; that email is a Meet product behaviour and cannot be disabled through the
API.
