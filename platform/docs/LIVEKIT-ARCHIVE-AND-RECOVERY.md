# LiveKit classroom archive and recovery

This branch preserves the final working LiveKit classroom implementation. It is **not** the active
production classroom after the Google Meet migration. Nothing here authorises deletion of data,
credentials or recordings.

| Item | Value |
| --- | --- |
| Repository | `Mr-M13/delnavazan` |
| Final working commit on `main` | `cbd2c5ad` |
| Immutable tag | `livekit-classroom-final-main-20261104` |
| Archival branch | `archive/livekit-classroom-final-main` |
| Production schema at archive | 57 |
| Recorded production build | `main-cee1d2f23157` (see `LIVEKIT-MUSIC-CLASSROOM-PROTOTYPE.md`) |

## 1. What is preserved

Source:

- `platform/src/Integrations/LiveKit/` — configuration, canonical authorizer, launch ticket, JWT
  issuer, RoomService client, room registry, revocation listener/revoker, signed webhook verifier and
  controller, token REST controller, explicit Google Meet fallback.
- `theme/template-parts/classroom/music.php` — self-hosted classroom UI.
- `theme/assets/js/music-classroom.js` — browser client, device selection, music mode, reconnect logic.
- `theme/assets/js/vendor/livekit-client.umd.min.js` — pinned LiveKit client SDK 2.23.0 and Apache
  licence.
- `theme/assets/css/music-classroom.css`.
- `platform/runtime/browser/livekit-classroom-browser-test.js`.
- `platform/tests/livekit-*.php` — unit, runtime and portal integration proofs.
- `platform/docs/LIVEKIT-MUSIC-CLASSROOM-PROTOTYPE.md` — architecture and production notes.

Data preserved in place (not migrated or deleted):

- `wp_dzn_integration_connections`, `integration_credentials`,
  `integration_oauth_authorizations`, `provider_identity_mappings`,
  `provider_calendar_event_mappings`, `provider_meeting_mappings`,
  `provider_projection_secrets`, `provider_ingest_*`, `provider_event_conflicts`,
  `provider_integration_commands`;
- `wp_dzn_recording_notice_versions`, `recording_decisions`, `recording_decision_commands`;
- canonical attendance, delivery, lesson, finance and notification history;
- any Google Meet links already vaulted under `provider_projection_secrets`.

LiveKit itself stored only short-lived WordPress transients for room/session validation. They expire;
there is no LiveKit-owned durable database table to export. LiveKit egress/recording was **not
implemented** in this archive; the prototype document explicitly marks recording, transcription,
analytics and PixelBox storage as future work. Therefore there are no LiveKit recordings to preserve.
Any existing recording metadata, consent decisions and attendance history live in the canonical
tables above and are untouched by the Google Meet migration.

## 2. Architecture

- Protected route `/music-classroom/` renders the LiveKit UI after login.
- Portal action `dzn_teacher_livekit_class` / `dzn_student_livekit_class` re-proves canonical lesson or
  booking ownership and redirects with a signed, user-bound launch ticket in the URL fragment.
- `POST /wp-json/delnavazan-platform/v1/livekit/session` exchanges the ticket for a room-scoped,
  five-minute participant token.
- Server creates/verifies a two-participant room through the LiveKit RoomService Twirp API.
- Signed webhook `POST /wp-json/delnavazan-platform/v1/livekit/webhook` rejects a third identity and
  closes rooms whose canonical authorisation was withdrawn.
- Explicit backup action posts to the existing authenticated Google Meet join service.

## 3. Dependencies

- PHP 8.1+ WordPress 6.4+ platform plugin.
- A LiveKit server (the recorded production deployment used LiveKit 1.13.11 at
  `rtc.delnavazan.com`, config `/opt/livekit/livekit.yaml`).
- `wss://` and `https://` reachability; reverse proxy/TLS.
- LiveKit client SDK 2.23.0 (vendored, no CDN).
- WordPress salts for launch-ticket signing (`wp_salt('dzn_livekit_ticket')`).
- Optional Google Calendar/Meet integration for the explicit backup path.

## 4. Secrets required

Read only from process configuration, never from the database or repository:

```text
DZN_LIVEKIT_URL=wss://rtc.delnavazan.com
DZN_LIVEKIT_API_KEY=<rotated key>
DZN_LIVEKIT_API_SECRET=<rotated secret>
DZN_LIVEKIT_API_URL=https://rtc.delnavazan.com   # optional; derived when absent
```

Recorded production location (do not commit):

```
/home/delnavaz/domains/delnavazan.com/private/livekit-config.php
mode 0600, owner delnavaz:delnavaz
```

`wp-config.php` loads that file only when readable. The previous secret was rotated after appearing in
a local diagnostic; the rotated value is not in Git.

LiveKit webhook configuration requires the same API key and:

```yaml
webhook:
  api_key: <DZN_LIVEKIT_API_KEY>
  urls:
    - https://delnavazan.com/wp-json/delnavazan-platform/v1/livekit/webhook
```

## 5. Rebuild from this archive

1. Check out the immutable tag:
   `git checkout livekit-classroom-final-20261104`.
2. Install the plugin and theme from the repository without copying credentials.
3. Restore the protected production config file from the secret store (not Git).
4. Run the migration/verification path:
   `wp plugin activate delnavazan-platform` then `wp eval 'Delnavazan\\Platform\\Core\\Infrastructure\\Migration\\Migrator::maybe_upgrade();'`.
5. Run the preserved proofs:
   ```sh
   php platform/tests/livekit-jwt-issuer-unit.php
   php platform/tests/livekit-classroom-authorization-unit.php
   DZN_LIVEKIT_RUNTIME=authority wp eval-file platform/tests/livekit-classroom-runtime.php --user=1
   php platform/tests/livekit-portal-integration-contract.php
   ```
6. Configure the LiveKit server with the same key/secret and webhook URL, then verify
   `https://rtc.delnavazan.com` and a real two-participant room.

The rebuild is proven only when the server, credentials, webhook and a real room are tested. The
repository alone cannot prove provider reachability.

## 6. Recovery after the Google Meet migration

The LiveKit code is preserved but its routes are deliberately not registered on the migration branch.
Recovery is a deliberate rollback:

1. Define `DZN_MEET_CLASSROOM_ENABLED=false` to stop new Google Meet provisioning while preserving
   existing links and rows.
2. Deploy the archival tag/branch and restore the protected LiveKit config from the secret store.
3. Re-register the LiveKit webhook and verify the server.
4. Keep all `meet_*`, `integration_*`, recording and attendance rows, and all Google Drive recordings.
   Do not drop tables or delete Drive files.
5. Re-run the full classroom acceptance matrix before declaring rollback complete.

## 7. What must not be committed

- `DZN_LIVEKIT_API_SECRET`, any rotated secret, or the private config file.
- OAuth access/refresh tokens.
- Recording files, Drive exports, attendance exports or student/teacher personal data.
- Production database dumps.

The archive branch contains source and documentation only.

## 8. Later LiveKit extensions included in this archive

The latest `main` commit also contains the additive LiveKit attendance correlation adapter
(`LIVEKIT-CANONICAL-ATTENDANCE-ADAPTER.md`), the administrator classroom launch/copy controls, and
the LiveKit recording/archive control plane (`RECORDING-PI-ARCHIVAL.md`). Those are preserved here.
The Google Meet migration does not delete their tables or archived media; it only stops registering
their LiveKit routes and worker entry points. Recording archive tables and consent evidence remain
readable for historical operations.

An earlier checkpoint tag `livekit-classroom-final-20261104` (`ebb45607`) is retained as well.
