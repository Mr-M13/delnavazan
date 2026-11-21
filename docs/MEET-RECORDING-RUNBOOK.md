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
| Recording spins for a few minutes, then "recording stopped" / nothing saved | **The host or co-host did not join on web.** Google only initialises auto-recording when the organizer or the COHOST Teacher joins from a computer browser; a Teacher on the mobile app or a phone produces exactly this spinner-then-stop. Drive quota, Drive write policy and admin recording settings are secondary causes | Meet quality tool → open the meeting → **Recording status**. It reports **"Failed to initialise"** when no web host/co-host was present. Check the participant device icons in the same view: a phone icon next to the Teacher's email confirms it |
| Short recording file exists but the class continued | Recording was cut short mid-call (for example Drive quota or a policy stop) | The platform raises the `recording_ended_early` alert (`recording_stopped_while_participants_remained`) |
| No recording file at all after class | Recording never started, or stopped before any file persisted | The platform raises the `recording_missing` alert after the 2-hour processing grace |

Note the July 2026 Google change: the "Meet Recordings" folder is now "Legacy Meet Recordings"
inside a new "Google Meet" folder with per-meeting sub-folders. Old links keep working.

## Confirmed production case (21 November 2026)

Two introductory classes (17 and 20 November 2026) failed exactly this way. The organizer account
had **300 GB free and 0 bytes used**, Business Standard was assigned, the Meet recording policy and
participant consent were both ON, and the space config was `autoRecordingGeneration: ON`. The Meet
quality tool showed **`Recording status: Failed to initialise`** for the calls, and the Teacher
co-host (`nimasepandar@gmail.com`, a verified COHOST space member) had joined from a **phone** in
every affected meeting while the organizer was not in the call. The recording never initialised.
Conclusion: this is a join-device requirement, not a Delnavazan configuration fault. The Teacher
must join from a computer web browser for auto-recording to start. The pre-class page now tells the
Teacher this explicitly.

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
4. **Meet quality tool** — Apps → Google Workspace → Google Meet → Meet quality tool → open the
   meeting: `Recording status` should read `Recording` (or a completed recording). `Failed to
   initialise` means no host/co-host was in the call on web. The participant rows show device icons;
   a phone icon next to the Teacher confirms the cause.
5. **In-meeting evidence** — the Meet UI message at the moment of failure names the cause
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
