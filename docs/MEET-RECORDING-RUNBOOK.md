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
Conclusion: this is a join-device requirement, not a Delnavazan configuration fault. The pre-class
page now tells the Teacher this explicitly.

## Mobile devices (Android and iPhone)

Google's device rules differ, and the platform has no API to override them:

| Device | Automatic recording | Manual recording by the COHOST Teacher |
|---|---|---|
| Computer web browser | Starts automatically when the host/co-host joins | Works |
| Android Meet app | **Never starts automatically** — Google requires a host/co-host on web | **Works**: Teacher opens the meeting menu (⋮) → Recording → Start recording and keeps the app open |
| iPhone / iPad | Never starts automatically | **Not supported at all** — Google only allows recording on a computer or Android device |

Operational guidance:

- **Android Teachers**: start the recording manually at the beginning of class; it is saved to the
  organizer's Drive exactly like an automatic recording, and the platform reconciles and alerts the
  same way.
- **iPhone Teachers**: recording is not possible from the device. For a class that must be recorded,
  the Teacher joins from a computer browser, or Delnavazan arranges an Android device or a computer
  for that class.
- Turning moderation off does not "unlock" mobile recording: out-of-domain participants cannot
  record in non-moderated meetings, so it makes mobile recording worse, not better. (Moderation is
  off for a different reason — see *Waiting room and join policy* below.)

## Automated organizer companion (Raspberry Pi)

Recording no longer depends on the Teacher's device. An operator-owned Raspberry Pi keeps a signed-in
`classroom@delnavazan.com` browser profile and joins the class as the organizer **on web** when the
Teacher presses the portal **Start class** button. That organizer web join is what satisfies Google's
auto-recording requirement; the Teacher stays a co-host on any device.

- Platform side: the Teacher join action enqueues one coalesced companion request for that classroom
  (a Student join never triggers it), the queue holds only the classroom identity, and the Meet URI
  is unsealed at claim time and never stored. Token-protected endpoints under
  `/wp-json/delnavazan-platform/v1/meet/recorder/` serve the companion; an empty
  `dzn_meet_recorder_token` option disables them.
- Device side: `tools/pi/meet-recorder/` holds the agent, its systemd unit and its runbook. The agent
  drives the organizer profile over the **loopback** DevTools protocol (port 9222, never exposed),
  clicks Join, confirms the in-call UI, reports the result, and leaves after `DZN_LEAVE_AFTER`
  seconds. Google keeps recording while the Teacher and Student remain.
- Governance exception: browser automation is forbidden **inside the platform plugin** for the Meet
  integration, because a portal join must stay a pure redirect and never a scraping dependency. The
  companion lives outside the plugin on the operator's own hardware, is best-effort, and holds no
  production write access beyond that single signed-in organizer session. If it fails or is offline,
  the Teacher and Student still join, and the platform's `recording_missing` /
  `recording_ended_early` reconciliation still reports the gap.
- The unverified residual risk is Google's Terms of Use for automated interaction with Meet; the
  owner accepted it explicitly for this single organizer account.


## Audio quality: Studio sound and noise cancellation

Google ties Studio sound to noise cancellation: *"Google Workspace with Gemini users: Studio sound
replaces noise cancellation"*, and *"Studio sound is automatically active for dial-in and web when
noise cancellation is enabled."* Noise cancellation is **on by default** on Business Standard, so
every eligible meeting starts with AI audio processing that is tuned for speech, not instruments.

What can and cannot be done about it:

- **There is no organisation-wide switch.** The Meet service pages in the Admin console (Meet video
  settings, Meet safety settings, Gemini settings) have no noise-cancellation or Studio sound policy
  on this edition, and Google's Meet Help documents only the per-user toggle. Do not promise an
  admin-side fix.
- **There is no API switch either.** Verified against the live discovery document on 2026-11-25
  (`https://meet.googleapis.com/$discovery/rest?version=v2`, revision `20261117`): the `SpaceConfig`
  schema exposes only `accessType`, `moderation`, `moderationRestrictions`, `entryPointAccess`,
  `artifactConfig` (recording, smart notes, transcription) and `attendanceReportGenerationType`.
  There is no audio, noise-cancellation or Studio sound field, so
  `GoogleWorkspaceClassroomService` cannot set it when it patches
  `config.accessType,config.moderation,config.artifactConfig.recordingConfig.autoRecordingGeneration`.
- **It is per account *and* per device/browser.** Meet remembers the choice for one account on one
  device. A new laptop, a new browser profile or a switched Google account brings Studio sound back
  on. This is the most common reason an operator believes "we turned it off and it came back".
- **What the platform does instead:** the classroom page renders a prominent pre-join sound-check
  card for both roles (`theme/template-parts/classroom/meet.php`, class
  `dzn-meet-entry__sound-check`) that says the academy cannot switch it off remotely and gives the
  three steps. Keep that card: it is the only moment the participant can act before the class.
- **Per account** (survives for that Google account): Meet → **Settings → Audio** → turn **Studio
  sound** / **Noise cancellation** off. It can be done before joining (More options → Settings →
  Audio) or mid-call. Google's own guidance: *"If you need other sounds in your meeting, like music,
  turn off noise cancellation."*
- **Who it affects the recording:** each participant's processed microphone audio is what reaches
  the meeting mix, so it is the **Teacher's** account that matters. The organizer companion joins
  from the Pi with no microphone (`Mic not found`), so its setting never changes a recording.
- **Teachers on personal Google accounts** are outside any Workspace policy, so this stays a
  one-time device setting for them; the pre-class page should keep telling them to do it.

## Recording layout

- A viewer can stop the active-speaker switching in their own window: **More options → Change
  layout → Tiled**. Meet remembers the layout per device/account.
- The **cloud recording** is composited server-side and follows the active speaker plus any shared
  content. There is no admin, space or API setting for a tiled/side-by-side recording, so a
  participant's layout choice never changes the file.
- If a fixed grid is a hard requirement, the only route is a client-side capture of a Tiled window
  (for example the Pi recording its own browser window and system audio) instead of Google's
  recorder — a different pipeline, not a Meet setting.

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

## Waiting room and join policy

Every space is created with **moderation OFF** (`GoogleWorkspaceClassroomRule::moderation()`), so a
participant joins the room directly instead of waiting for somebody to admit them. That matters
because the companion is the only organizer: with a waiting room, a class whose co-host had not
arrived yet could strand everyone behind an admit button that nobody could press. The Meet link is
obtainable only through the authenticated portal (`DZN_MEET_CALENDAR_ATTENDEES` off, attendees
detached), so the portal is the boundary, not the waiting room.

- `config.accessType` stays `OPEN` so the organizer's absence is supported; set
  `DZN_MEET_ACCESS_TYPE` to `TRUSTED`/`RESTRICTED` to tighten it.
- To restore the waiting room, define `DZN_MEET_SPACE_MODERATION` as `ON` in `wp-config.php`. New and
  re-provisioned spaces pick it up immediately, and the five-minute worker converges booking spaces
  at most hourly (audit `space_access_converged`).
- Consequence to accept knowingly: anyone who has the Meet link can join without approval.

## Attendance auto-confirmation

The owner policy since v3 (`canonical_attendance_presence_v3`) is **presence, not identity**: a class
settles as delivered when **at least two participants other than the organizer/liaison are
simultaneously present for 1200 seconds (20 minutes)** inside `[start − 30 min, end + 30 min)`.

- **Who** a participant is does not matter. A signed-in account, a personal Gmail account and a guest
  all count as one person; two devices of the same account are unioned, never double-counted. A
  verified identity mapping only labels the role and is no longer a precondition.
- **When** matters. The 20 minutes must fall inside the class window: 30 minutes before the scheduled
  start through 30 minutes after the scheduled end. An open link the day before, the day after, or at
  any other date never qualifies. The threshold is contiguous: a gap splits the run (1199 s fails).
- **The liaison is excluded.** The recording companion joins as the single Delnavazan organizer. On
  its first recorded class the platform binds the organizer's Meet account from the companion's
  `recorder_join` event (nearest signed-in arrival inside the same 180 s tolerance, 30 s distinct
  margin) and stores it once as a `liaison` mapping. That account is never counted. Until the binding
  succeeds, the class defers to manual review instead of risking a two-person false positive.
- **Everything else is manual.** One participant, a short overlap, an open interval (no leave time), a
  cancelled class, a conflicting recorded outcome or a closed Term produce an
  anomaly/review case and never auto-settle. Teacher or Student reports to the administrator follow
  the existing reviewed-outcome path.
- **Activation.** The rule applies to occurrences beginning at or after the recorded cutover policy.
  Record the next prospective cutover once per rule change:
  `wp dzn-meet attendance_cutover --at="<UTC a few minutes ahead>" --user=<administrator_id>`.
  A class that started earlier keeps the rule it was admitted under; the retired
  `canonical_attendance_overlap_v2` (verified Teacher ∩ Student, one hour of grace either side) stays
  readable for those cases.
- **One-time portal claims still help.** They attribute roles for the portals and summaries, but they
  are no longer required for attendance.