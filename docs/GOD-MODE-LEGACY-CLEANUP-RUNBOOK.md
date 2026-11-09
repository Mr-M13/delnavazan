# God Mode — Legacy Student-Data Cleanup Runbook

**Status: NOT EXECUTED. This document is a controlled procedure, not approval.**

This runbook covers the designated Super Tester account's previous student-related records. It does
**not** cover deletion of WordPress users, administrators, teachers, genuine students, financial
evidence, consent evidence, recordings, or audit logs.

## 1. Preconditions and approvals

Required before any step that changes data:

- [ ] Written approval from the platform owner for this exact cleanup window.
- [ ] Written approval from the finance owner for the treatment of every financial-evidence table.
- [ ] Written approval from the privacy/legal owner for retained audit and consent evidence.
- [ ] A maintenance window with no scheduled classes, provider workers or payment reconciliation.
- [ ] Confirmation that God Mode authority is active for the designated account.
- [ ] Confirmation that no destructive step will run automatically from a deployment or cron job.

Do not proceed if any approval is missing.

## 2. Freeze and capture the environment

Record, without changing data:

```sh
cd /path/to/wordpress
wp core version
wp plugin list --status=active
wp option get dzn_platform_schema_version
wp option get dzn_platform_completed_migrations --format=json
wp eval 'echo DZN_PLATFORM_BUILD_ID . "\n" . DZN_PLATFORM_SCHEMA_VERSION . "\n";'
```

Capture the current God Mode schema and audit counts:

```sh
wp db query "SELECT COUNT(*) FROM wp_dzn_god_mode_sessions WHERE activity_type='test';"
wp db query "SELECT COUNT(*) FROM wp_dzn_god_mode_audit_events;"
```

Replace `wp_` with the real table prefix if different.

## 3. Full database backup

A recoverable inventory manifest alone is not a database backup. Take a full logical backup and a
binary-safe copy before any mutation:

```sh
mkdir -p /secure/backups/delnavazan-god-mode-cleanup
mysqldump --single-transaction --routines --triggers --events \
  --default-character-set=utf8mb4 \
  -h "$DB_HOST" -u "$DB_USER" -p "$DB_NAME" \
  | gzip -9 > /secure/backups/delnavazan-god-mode-cleanup/pre-cleanup.sql.gz
sha256sum /secure/backups/delnavazan-god-mode-cleanup/pre-cleanup.sql.gz \
  > /secure/backups/delnavazan-god-mode-cleanup/pre-cleanup.sql.gz.sha256
```

Also back up uploads that can contain booking documents, exports or recording metadata:

```sh
tar -C /path/to/wordpress/wp-content -czf \
  /secure/backups/delnavazan-god-mode-cleanup/wp-content.tar.gz uploads
sha256sum /secure/backups/delnavazan-god-mode-cleanup/wp-content.tar.gz \
  > /secure/backups/delnavazan-god-mode-cleanup/wp-content.tar.gz.sha256
```

Store the backup outside the web root with restrictive permissions. Verify the dump can be read:

```sh
gzip -t /secure/backups/delnavazan-god-mode-cleanup/pre-cleanup.sql.gz
```

## 4. Recoverable God Mode inventory snapshot

In **Admin → God Mode → Settings**:

1. Press **Run dry-run inventory**.
2. Review every domain, count, classification and retention marker.
3. Press **Write recoverable snapshot**.
4. Record the returned snapshot filename in the change ticket.

The snapshot contains record IDs, counts and classifications only. It does not replace the full
database backup.

## 5. Dependency inventory and classification

The dry run must classify every row into one of:

| Class | Examples | Cleanup action |
| --- | --- | --- |
| WordPress identity | `wp_users`, administrator role, login credentials | **Never delete** |
| Genuine student identity | `dzn_students`, principal links | Preserve unless separately approved |
| Genuine student activity | Booking requests, intro classes, enrolments, terms, lessons, attendance, surveys | Archive/close through domain lifecycle, not raw delete |
| Financial evidence | `dzn_finance_lesson_snapshots`, `dzn_intro_payment_obligations`, commercial offers/purchases/entitlements, payment evidence, refunds, statements | **Immutable retain** |
| Consent/legal evidence | `dzn_recording_decisions`, consent events, policy versions | **Immutable retain** |
| Operational notifications | Portal notifications, notification attempts, outbox jobs | Expire/archive according to retention |
| God Mode test records | `dzn_god_mode_*` where `activity_type='test'` | Eligible for test-only cleanup |
| Recordings | Meet/Drive recording artifacts and archive metadata | **Never silently delete** |

The inventory must explicitly separate `activity_type='test'` God Mode records from the
designated account's genuine previous student records. The two classes must never share a cleanup
batch.

## 6. Dry-run report review

The dry run must list:

- every affected table and record count;
- sample IDs for verification (not raw personal data);
- retained financial and consent evidence;
- pending provider operations;
- recordings and legal holds;
- any background job, outbox, calendar event or Meet reference still attached;
- an explicit statement that no deletion has occurred.

Do not proceed if any of the following is true:

- a table has no benign lifecycle path and would require raw deletion;
- a financial or consent row is proposed for deletion;
- a recording or legal hold is present;
- a provider operation is pending;
- the account's student identity is ambiguous;
- the report includes another student or teacher.

## 7. Safe execution sequence

All destructive steps require a second operator review.

### 7.1 Close provider-facing activity through domain services

Use the existing cancellation/rescheduling/close services for any active test or student
occurrence. Do not run raw `DELETE` or `UPDATE` against:

- `dzn_lessons`
- `dzn_canonical_lesson_schedule_versions`
- `dzn_enrolments`
- `dzn_terms`
- `dzn_teacher_assignments`
- `dzn_canonical_attendance_*`
- `dzn_finance_*`
- `dzn_recording_*`

### 7.2 Archive before deletion

For God Mode test records:

1. Confirm `activity_type='test'`.
2. Cancel/retire the provider event where appropriate.
3. Archive the test session.
4. Re-run cleanup eligibility checks.
5. Remove only eligible test rows through **Admin → God Mode → Settings → Remove all eligible
   archived test data**, or the equivalent audited service.

The cleanup must refuse when:

- the session is not test-classified;
- the session is not archived;
- a provider operation is pending;
- recording evidence is retained;
- the caller lacks God Mode authority or a valid nonce.

### 7.3 Genuine student experience cleanup

Only after separate approval:

- Use existing domain lifecycle services to close/move/archive genuine student activity.
- Preserve financial and consent evidence.
- Remove or expire only the active student experience records named in the approved dry run.
- Do not delete the student profile or WordPress account.

## 8. Verification after execution

1. Re-run **Run dry-run inventory** and compare against the approved baseline.
2. Confirm no orphaned rows:
   ```sql
   SELECT COUNT(*) FROM wp_dzn_lessons l
   LEFT JOIN wp_dzn_enrolments e ON e.id=l.enrolment_id
   WHERE e.id IS NULL;
   ```
3. Confirm no pending external jobs for the affected records.
4. Confirm teacher finance statements and payouts are unchanged.
5. Confirm the designated account still has its WordPress identity and administrator access.
6. Confirm God Mode audit events remain present.
7. Confirm recordings and legal-hold evidence are untouched.
8. Export the final God Mode diagnostic evidence.

## 9. Rollback

If any check fails:

1. Stop all workers and admin cleanup actions.
2. Restore the full database from `pre-cleanup.sql.gz`.
3. Restore uploads from `wp-content.tar.gz` if required.
4. Re-run `wp option get dzn_platform_completed_migrations` and `Migrator::verify_current_schema()`.
5. Re-verify the designated account's authority and the God Mode table counts.
6. Record the rollback in the audit/change ticket.

Rollback is **not** satisfied by deleting the inventory snapshot; the database backup is the
authoritative recovery path.

## 10. Explicit non-goals

This runbook does not authorise:

- deleting or demoting the WordPress administrator account;
- deleting genuine students or teachers;
- deleting financial evidence, statements, refunds, payments or payouts;
- deleting consent or recording decisions;
- deleting Google Drive recordings;
- merging or deploying the God Mode PR;
- running cleanup during a production deployment.
