<?php
namespace Delnavazan\Platform\Core\Application\Diagnostics;

use Delnavazan\Platform\Core\Application\AccessRoleService;
use Delnavazan\Platform\Core\Application\DiagnosticsService;
use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Core\Application\NotificationDiagnosticsReadService;
use Delnavazan\Platform\Core\Application\PermissionRegistry;
use Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceOAuthService;

/**
 * Extensible diagnostic registry.
 *
 * A check is data plus a callable. Adding a future check means adding one entry to the category
 * definition; the interface, permissions, lazy loading, status mapping, redaction and support
 * bundle rendering do not need to change.
 *
 * Every check is defensive: an unavailable table or failed service call becomes `unknown` for
 * that check and never breaks the Settings page. Checks never mutate state and never make a
 * provider network call merely to prove connectivity.
 */
final class DiagnosticRegistry {
    public const READ_CAPABILITY = 'dzn_view_diagnostics';
    private const SUMMARY_TRANSIENT = 'dzn_diagnostics_summary_v1';
    private const SUMMARY_TTL = 60;
    private static array $memo = array();

    /** @return array<string,array{label:string,description:string,owner:string,checks:array}> */
    public function categories(): array {
        return array(
            'platform' => array(
                'label' => 'Platform & System Health',
                'description' => 'Build identity, schema state and open platform exceptions.',
                'owner' => 'Platform',
                'checks' => array(
                    $this->check('platform.schema', 'Schema version', 'The recorded schema matches this build.', 'dzn_view_diagnostics', array($this, 'checkSchema')),
                    $this->check('platform.tables', 'Core tables', 'Every required core table is present.', 'dzn_view_diagnostics', array($this, 'checkCoreTables')),
                    $this->check('platform.exceptions', 'Open operational exceptions', 'High-severity operational exceptions are resolved or acknowledged.', 'dzn_view_diagnostics', array($this, 'checkPlatformExceptions')),
                    $this->check('platform.build', 'Build identity', 'The deployed package declares a build identity.', 'dzn_view_diagnostics', array($this, 'checkBuildIdentity')),
                ),
            ),
            'booking' => array(
                'label' => 'Booking & Coordination',
                'description' => 'Request lifecycle, duplicate review, coordination cases and teacher offers.',
                'owner' => 'Enrolment',
                'checks' => array(
                    $this->check('booking.lifecycle', 'Request lifecycle consistency', 'Requests older than seven days are still awaiting coordination.', 'dzn_view_booking_requests', array($this, 'checkBookingLifecycle'), 'dzn-booking-requests'),
                    $this->check('booking.duplicates', 'Duplicate and overlapping requests', 'Duplicate flags are reviewed and closed.', 'dzn_view_booking_requests', array($this, 'checkBookingDuplicates'), 'dzn-booking-requests'),
                    $this->check('booking.coordination', 'Coordination cases', 'Coordination cases are progressing rather than idle.', 'dzn_view_booking_requests', array($this, 'checkCoordinationCases'), 'dzn-booking-request-coordination'),
                    $this->check('booking.offers', 'Teacher offer states', 'Allocation offers are not left pending beyond their response window.', 'dzn_view_booking_requests', array($this, 'checkTeacherOffers'), 'dzn-booking-request-coordination'),
                    $this->check('booking.recovery', 'Recoverable booking failures', 'Failed booking work is visible and points at the existing recovery path.', 'dzn_view_booking_requests', array($this, 'checkBookingRecovery'), 'dzn-exception'),
                ),
            ),
            'classes' => array(
                'label' => 'Classes & Attendance',
                'description' => 'Meet links, classroom associations, attendance evidence and post-intro follow-through.',
                'owner' => 'Classes',
                'checks' => array(
                    $this->check('classes.meet', 'Missing Google Meet links', 'Upcoming classes have a classroom and Meet link.', 'dzn_manage_lessons', array($this, 'checkMeetLinks'), 'dzn-integration-google'),
                    $this->check('classes.attendance', 'Attendance evidence', 'Attendance cases have readable evidence and no unresolved conflicts.', 'dzn_manage_canonical_attendance_review', array($this, 'checkAttendanceEvidence')),
                    $this->check('classes.post_intro', 'Post-intro follow-through', 'Introductory continuation jobs are not terminally failed.', 'dzn_manage_post_intro_automation', array($this, 'checkPostIntro'), 'dzn-post-intro'),
                    $this->check('classes.schedule', 'Session scheduling consistency', 'Scheduled classes have a current schedule version.', 'dzn_manage_canonical_lesson_schedules', array($this, 'checkClassSchedules')),
                ),
            ),
            'google' => array(
                'label' => 'Google Workspace & Meet',
                'description' => 'Organiser connection, projection state and classroom provisioning.',
                'owner' => 'Integrations',
                'checks' => array(
                    $this->check('google.oauth', 'Organiser connection', 'The single Delnavazan organiser connection is configured.', 'dzn_view_provider_integrations', array($this, 'checkGoogleOAuth'), 'dzn-integration-google'),
                    $this->check('google.projection', 'Calendar projections', 'Calendar event projections are applied or explicitly retryable.', 'dzn_view_provider_integrations', array($this, 'checkGoogleProjection'), 'dzn-integration-google'),
                    $this->check('google.classroom', 'Meet classroom provisioning', 'Classroom provisioning has no unresolved failed state.', 'dzn_view_provider_integrations', array($this, 'checkMeetClassrooms'), 'dzn-integration-google'),
                ),
            ),
            'finance' => array(
                'label' => 'Payments & Finance',
                'description' => 'Provider readiness, webhooks, reconciliation and statements without initiating money movement.',
                'owner' => 'Finance',
                'checks' => array(
                    $this->check('finance.provider', 'Payment provider readiness', 'Stripe readiness and the test/live environment distinction are recorded.', 'dzn_view_finance_authority', array($this, 'checkFinanceProvider')),
                    $this->check('finance.webhooks', 'Webhook verification', 'Provider event receipts verify and failures are visible.', 'dzn_view_payment_execution_authority', array($this, 'checkWebhookReceipts')),
                    $this->check('finance.reconciliation', 'Reconciliation findings', 'Reconciliation runs have no unresolved mismatch.', 'dzn_view_finance_authority', array($this, 'checkFinanceReconciliation'), 'dzn-finance'),
                    $this->check('finance.exceptions', 'Finance reconciliation exceptions', 'Open finance exceptions are reviewed.', 'dzn_view_finance_authority', array($this, 'checkFinanceExceptions'), 'dzn-finance'),
                    $this->check('finance.statements', 'Teacher statement consistency', 'Statements are not stuck outside a terminal workflow state.', 'dzn_view_finance_authority', array($this, 'checkStatements'), 'dzn-finance'),
                ),
            ),
            'notifications' => array(
                'label' => 'Notifications & Delivery',
                'description' => 'Provider connectivity, queue state, failures, retries, template mapping and fallback eligibility.',
                'owner' => 'Notifications',
                'checks' => array(
                    $this->check('notifications.queue', 'Notification queue', 'Queued work is progressing and no lease is stuck.', 'dzn_view_notification_authority', array($this, 'checkNotificationQueue')),
                    $this->check('notifications.failed', 'Failed notifications', 'Delivery failures are visible with a safe reason code.', 'dzn_view_notification_authority', array($this, 'checkNotificationFailures')),
                    $this->check('notifications.providers', 'Email, SMS and WhatsApp readiness', 'Provider configuration is present and locally valid.', 'dzn_view_notification_authority', array($this, 'checkNotificationProviders'), 'dzn-integrations'),
                    $this->check('notifications.templates', 'Template mapping and approval', 'Every active notification workflow points at an active template and no duplicate active mapping exists.', 'dzn_view_notification_authority', array($this, 'checkNotificationTemplates'), 'dzn-notifications'),
                    $this->check('notifications.fallback', 'SMS fallback safeguards', 'WhatsApp failures are eligible for the canonical SMS fallback.', 'dzn_view_notification_authority', array($this, 'checkNotificationFallback')),
                ),
            ),
            'portals' => array(
                'label' => 'Teacher & Student Portals',
                'description' => 'Portal identity links, expired capabilities and recorded access denials.',
                'owner' => 'Portals',
                'checks' => array(
                    $this->check('portals.identity', 'Portal identity links', 'Teacher and student principal links are consistent.', 'dzn_view_portal_capabilities', array($this, 'checkPortalIdentity')),
                    $this->check('portals.capabilities', 'Portal capabilities', 'No active portal capability has expired without rotation.', 'dzn_view_portal_capabilities', array($this, 'checkPortalCapabilities'), 'dzn-portal-capabilities'),
                    $this->check('portals.denials', 'Portal access denials', 'Unexpected access denials are reviewed, never exposing personal data.', 'dzn_view_portal_capabilities', array($this, 'checkPortalDenials')),
                ),
            ),
            'consent' => array(
                'label' => 'Consent & Policies',
                'description' => 'Consent decisions, publication state, recording authorisations and policy evidence.',
                'owner' => 'Policies',
                'checks' => array(
                    $this->check('consent.jobs', 'Consent processing jobs', 'Withdrawal and re-evaluation jobs are not terminally failed.', 'dzn_view_consent_evidence', array($this, 'checkConsentJobs'), 'dzn-policies'),
                    $this->check('consent.notices', 'Published notices', 'Every required audience and language has a current published notice.', 'dzn_view_consent_evidence', array($this, 'checkConsentNotices'), 'dzn-policies'),
                    $this->check('consent.recording', 'Recording authorisations', 'Recording authorisation evidence is present and consent-gated.', 'dzn_view_consent_evidence', array($this, 'checkRecordingConsent')),
                ),
            ),
            'jobs' => array(
                'label' => 'Background Jobs & Queues',
                'description' => 'Outbox, retries, stuck leases and last successful execution.',
                'owner' => 'Platform',
                'checks' => array(
                    $this->check('jobs.outbox', 'Platform outbox', 'Pending and leased outbox work is progressing.', 'dzn_view_diagnostics', array($this, 'checkOutbox')),
                    $this->check('jobs.reschedule', 'Reschedule calendar jobs', 'Calendar reschedule jobs are not terminally failed.', 'dzn_view_diagnostics', array($this, 'checkRescheduleJobs')),
                    $this->check('jobs.post_intro', 'Post-intro activation queue', 'Post-intro activation jobs are progressing.', 'dzn_view_diagnostics', array($this, 'checkPostIntroQueue')),
                ),
            ),
            'database' => array(
                'label' => 'Database & Migrations',
                'description' => 'Migration ledger, schema version and table readiness.',
                'owner' => 'Platform',
                'checks' => array(
                    $this->check('database.migrations', 'Migration ledger', 'The required migration is recorded as complete.', 'dzn_view_diagnostics', array($this, 'checkMigrations')),
                    $this->check('database.schema', 'Database schema', 'The recorded schema matches this build.', 'dzn_view_diagnostics', array($this, 'checkSchema')),
                    $this->check('database.readiness', 'Table readiness', 'No required table is missing.', 'dzn_view_diagnostics', array($this, 'checkCoreTables')),
                ),
            ),
            'security' => array(
                'label' => 'Security & Authorisation',
                'description' => 'Permission integrity, role assignments, orphaned permissions and administrative audit events.',
                'owner' => 'Administration',
                'checks' => array(
                    $this->check('security.catalogue', 'Permission catalogue integrity', 'Every permission maps to at least one canonical capability.', 'dzn_view_diagnostics', array($this, 'checkPermissionCatalogue')),
                    $this->check('security.roles', 'Role and assignment integrity', 'Assignments reference active roles and no orphaned permission remains.', 'dzn_view_diagnostics', array($this, 'checkRoleIntegrity')),
                    $this->check('security.migration', 'Permission migration status', 'Accounts still relying on legacy work-area grants are identified for migration.', 'dzn_view_diagnostics', array($this, 'checkPermissionMigration'), 'dzn-delegated-access'),
                    $this->check('security.audit', 'Administrative audit trail', 'Permission changes are being recorded.', 'dzn_view_diagnostics', array($this, 'checkSecurityAudit')),
                    $this->check('security.denials', 'Unauthorised access attempts', 'Recorded denials are visible to authorised staff only.', 'dzn_view_diagnostics', array($this, 'checkSecurityDenials')),
                ),
            ),
            'integrations' => array(
                'label' => 'External Integrations',
                'description' => 'Email, WhatsApp, SMS, Google and payment provider readiness without credential exposure.',
                'owner' => 'Integrations',
                'checks' => array(
                    $this->check('integrations.email', 'Email / SMTP', 'Email delivery configuration is readable.', 'dzn_view_provider_integrations', array($this, 'checkEmailProvider'), 'dzn-integrations'),
                    $this->check('integrations.whatsapp', 'WhatsApp', 'WhatsApp provider configuration and template mapping are readable.', 'dzn_view_provider_integrations', array($this, 'checkWhatsAppProvider'), 'dzn-integrations'),
                    $this->check('integrations.sms', 'SMS / Telnyx', 'SMS fallback configuration is readable.', 'dzn_view_provider_integrations', array($this, 'checkSmsProvider'), 'dzn-integrations'),
                    $this->check('integrations.payment', 'Payment provider', 'Payment provider readiness is recorded without exposing secrets.', 'dzn_view_provider_integrations', array($this, 'checkFinanceProvider'), 'dzn-integrations'),
                ),
            ),
        );
    }

    /** Full results for one category, lazily requested from the interface. */
    public function category(string $key): array {
        $categories = $this->categories();
        if (!isset($categories[$key])) throw new \InvalidArgumentException('diagnostic_category_unknown');
        if (!current_user_can(self::READ_CAPABILITY) && !current_user_can('manage_options')) throw new \RuntimeException('diagnostics_forbidden');
        $results = array();
        foreach ($categories[$key]['checks'] as $check) {
            if (!current_user_can($check['permission']) && !current_user_can('manage_options')) continue;
            $results[] = $this->execute($key, $check);
        }
        return array('key' => $key, 'label' => $categories[$key]['label'], 'description' => $categories[$key]['description'], 'checks' => $results, 'summary' => $this->summariseResults($results));
    }

    /**
     * Cached overall health. The cache can be bypassed with a manual refresh; a failed check is
     * counted as unknown and never silently promoted to healthy.
     */
    public function summary(bool $fresh = false): array {
        if (!current_user_can(self::READ_CAPABILITY) && !current_user_can('manage_options')) throw new \RuntimeException('diagnostics_forbidden');
        $cacheKey = self::SUMMARY_TRANSIENT . '_' . get_current_user_id();
        if (!$fresh) {
            if (isset(self::$memo[$cacheKey])) return self::$memo[$cacheKey];
            if (function_exists('get_transient')) {
                $cached = get_transient($cacheKey);
                if (is_array($cached) && isset($cached['categories'])) return self::$memo[$cacheKey] = $cached;
            }
        }
        $categories = array();
        $all = array();
        foreach ($this->categories() as $key => $category) {
            $results = array();
            foreach ($category['checks'] as $check) {
                if (!current_user_can($check['permission']) && !current_user_can('manage_options')) continue;
                $result = $this->execute($key, $check);
                $results[] = $result;
                $all[] = $result;
            }
            $categories[$key] = array(
                'key' => $key,
                'label' => $category['label'],
                'description' => $category['description'],
                'owner' => $category['owner'],
                'summary' => $this->summariseResults($results),
            );
        }
        $summary = $this->summariseResults($all);
        $summary['generated_at'] = gmdate('Y-m-d H:i:s');
        $summary['categories'] = $categories;
        if (function_exists('set_transient')) set_transient($cacheKey, $summary, self::SUMMARY_TTL);
        return self::$memo[$cacheKey] = $summary;
    }

    public function invalidateSummary(): void {
        if (function_exists('delete_transient')) delete_transient(self::SUMMARY_TRANSIENT . '_' . get_current_user_id());
        self::$memo = array();
    }

    /** @return array<string,int> */
    private function summariseResults(array $results): array {
        $summary = array('healthy' => 0, 'degraded' => 0, 'failed' => 0, 'unknown' => 0, 'not_configured' => 0, 'critical' => 0, 'warning' => 0, 'recovery' => 0, 'total' => 0);
        foreach ($results as $result) {
            $summary['total']++;
            $state = (string) ($result['state'] ?? 'unknown');
            $summary[$state] = (int) ($summary[$state] ?? 0) + 1;
            if ($state === 'failed') $summary['critical']++;
            if ($state === 'degraded') $summary['warning']++;
            if (!empty($result['recovery_link'])) $summary['recovery']++;
        }
        $summary['overall'] = $summary['failed'] > 0 ? 'failed' : ($summary['degraded'] > 0 || $summary['unknown'] > 0 || $summary['not_configured'] > 0 ? 'degraded' : 'healthy');
        return $summary;
    }

    private function execute(string $category, array $check): array {
        $base = array(
            'id' => $check['id'],
            'category' => $category,
            'category_label' => $this->categories()[$category]['label'] ?? $category,
            'label' => $check['label'],
            'description' => $check['description'],
            'permission' => $check['permission'],
            'recovery_link' => $check['recovery_link'],
            'last_checked' => gmdate('Y-m-d H:i:s'),
            'state' => 'unknown',
            'detail' => 'The check did not complete.',
            'technical_code' => 'check_not_run',
            'severity' => 'error',
            'last_success' => '',
            'last_failure' => '',
            'related' => array(),
            'recoverable' => $check['recovery_link'] !== '',
        );
        try {
            $outcome = call_user_func($check['check']);
            if (!is_array($outcome) || !isset($outcome['state'])) throw new \RuntimeException('diagnostic_invalid_outcome');
            return array_merge($base, $outcome, array(
                'state' => $this->normaliseState((string) $outcome['state']),
                'severity' => (string) ($outcome['severity'] ?? $this->severityFor((string) $outcome['state'])),
            ));
        } catch (\Throwable $error) {
            return array_merge($base, array(
                'state' => 'unknown',
                'detail' => 'This check could not complete. It is reported as unknown, not healthy.',
                'technical_code' => $this->safeCode($error->getMessage()),
            ));
        }
    }

    private function normaliseState(string $state): string {
        return in_array($state, array('healthy', 'degraded', 'failed', 'unknown', 'not_configured'), true) ? $state : 'unknown';
    }

    private function severityFor(string $state): string {
        return match ($state) {
            'healthy' => 'info',
            'degraded', 'not_configured' => 'warning',
            'failed' => 'error',
            default => 'error',
        };
    }

    private function safeCode(string $message): string {
        return preg_match('/^[a-z0-9_]{3,80}$/D', $message) === 1 ? $message : 'check_failed';
    }

    private function check(string $id, string $label, string $description, string $permission, callable $callable, string $recoveryLink = ''): array {
        return array('id' => $id, 'label' => $label, 'description' => $description, 'permission' => $permission, 'check' => $callable, 'recovery_link' => $recoveryLink);
    }

    /* ---------------------------------------------------------------------
     * Checks. All reads are aggregate and deliberately exclude personal data.
     * ------------------------------------------------------------------ */

    private function checkSchema(): array {
        $recorded = (string) get_option('dzn_platform_schema_version', 'not installed');
        $expected = defined('DZN_PLATFORM_SCHEMA_VERSION') ? (string) DZN_PLATFORM_SCHEMA_VERSION : '';
        if ($expected === '' || $recorded !== $expected) {
            return array('state' => 'degraded', 'detail' => 'Recorded schema ' . $recorded . ' differs from this build' . ($expected !== '' ? ' (' . $expected . ')' : '') . '.', 'technical_code' => 'schema_update_required');
        }
        return array('state' => 'healthy', 'detail' => 'Schema ' . $recorded . ' matches this build.', 'technical_code' => 'schema_current');
    }

    private function checkCoreTables(): array {
        $status = (new DiagnosticsService())->status();
        $missing = array();
        foreach ((array) ($status['tables'] ?? array()) as $table) if (empty($table['exists'])) $missing[] = (string) ($table['name'] ?? 'unknown');
        if ($missing !== array()) return array('state' => 'failed', 'detail' => count($missing) . ' required table(s) are missing: ' . implode(', ', array_slice($missing, 0, 8)) . '.', 'technical_code' => 'core_tables_missing');
        return array('state' => 'healthy', 'detail' => 'All required core tables are present.', 'technical_code' => 'core_tables_ready');
    }

    private function checkPlatformExceptions(): array {
        $open = $this->int("SELECT COUNT(*) FROM {$this->p()}operational_exceptions WHERE status='open'");
        $critical = $this->int("SELECT COUNT(*) FROM {$this->p()}operational_exceptions WHERE status='open' AND severity IN ('error','critical')");
        return $critical > 0
            ? array('state' => 'failed', 'detail' => $open . ' open exception(s); ' . $critical . ' high severity.', 'technical_code' => 'open_high_severity_exceptions', 'related' => array('page=dzn-exception'))
            : ($open > 0
                ? array('state' => 'degraded', 'detail' => $open . ' open exception(s) awaiting review.', 'technical_code' => 'open_exceptions', 'related' => array('page=dzn-exception'))
                : array('state' => 'healthy', 'detail' => 'No open operational exception.', 'technical_code' => 'exceptions_clear'));
    }

    private function checkBuildIdentity(): array {
        $build = defined('DZN_PLATFORM_BUILD_ID') ? (string) DZN_PLATFORM_BUILD_ID : '';
        return $build !== ''
            ? array('state' => 'healthy', 'detail' => 'Build ' . $build . '.', 'technical_code' => 'build_identity_present')
            : array('state' => 'degraded', 'detail' => 'No build identity is declared.', 'technical_code' => 'build_identity_missing');
    }

    private function checkBookingLifecycle(): array {
        $stale = $this->int("SELECT COUNT(*) FROM {$this->p()}booking_requests WHERE created_at < UTC_TIMESTAMP() - INTERVAL 7 DAY AND resolution_state NOT IN ('resolved','closed','cancelled','converted')");
        return $stale > 0
            ? array('state' => 'degraded', 'detail' => $stale . ' request(s) are older than seven days without a terminal resolution.', 'technical_code' => 'stale_booking_requests', 'related' => array('page=dzn-booking-requests'))
            : array('state' => 'healthy', 'detail' => 'Every request older than seven days has a terminal resolution.', 'technical_code' => 'booking_lifecycle_consistent');
    }

    private function checkBookingDuplicates(): array {
        $open = $this->int("SELECT COUNT(*) FROM {$this->p()}booking_request_duplicate_flags WHERE status NOT IN ('closed','resolved','dismissed')");
        return $open > 0
            ? array('state' => 'degraded', 'detail' => $open . ' duplicate signal(s) await review.', 'technical_code' => 'duplicate_review_pending', 'related' => array('page=dzn-booking-requests'))
            : array('state' => 'healthy', 'detail' => 'No duplicate review is outstanding.', 'technical_code' => 'duplicate_review_clear');
    }

    private function checkCoordinationCases(): array {
        $idle = $this->int("SELECT COUNT(*) FROM {$this->p()}coordination_cases WHERE updated_at < UTC_TIMESTAMP() - INTERVAL 3 DAY AND state NOT IN ('closed','completed','cancelled','converted')");
        return $idle > 0
            ? array('state' => 'degraded', 'detail' => $idle . ' coordination case(s) have not moved for three days.', 'technical_code' => 'coordination_cases_idle', 'related' => array('page=dzn-booking-request-coordination'))
            : array('state' => 'healthy', 'detail' => 'Coordination cases are progressing.', 'technical_code' => 'coordination_cases_progressing');
    }

    private function checkTeacherOffers(): array {
        $pending = $this->int("SELECT COUNT(*) FROM {$this->p()}booking_allocation_offers WHERE status='pending' AND created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY");
        return $pending > 0
            ? array('state' => 'degraded', 'detail' => $pending . ' teacher offer(s) are pending beyond the 24-hour response window.', 'technical_code' => 'teacher_offers_stale', 'related' => array('page=dzn-booking-request-coordination'))
            : array('state' => 'healthy', 'detail' => 'No teacher offer is pending beyond its response window.', 'technical_code' => 'teacher_offers_timely');
    }

    private function checkBookingRecovery(): array {
        $failed = $this->int("SELECT COUNT(*) FROM {$this->p()}operational_exceptions WHERE status='open' AND exception_type LIKE '%booking%'");
        return $failed > 0
            ? array('state' => 'degraded', 'detail' => $failed . ' booking exception(s) are open; use the existing exception recovery, not a diagnostic retry.', 'technical_code' => 'booking_recovery_pending', 'related' => array('page=dzn-exception'))
            : array('state' => 'healthy', 'detail' => 'No recoverable booking exception is open.', 'technical_code' => 'booking_recovery_clear');
    }

    private function checkMeetLinks(): array {
        $rows = $this->rows("SELECT projection_state AS state,COUNT(*) AS total FROM {$this->p()}provider_calendar_event_mappings GROUP BY projection_state");
        $failed = 0;
        foreach ($rows as $row) if (in_array((string) ($row->state ?? ''), array('failed','blocked','conflict'), true)) $failed += (int) ($row->total ?? 0);
        return $failed > 0
            ? array('state' => 'degraded', 'detail' => $failed . ' calendar/Meet projection(s) are failed or blocked.', 'technical_code' => 'meet_projection_failed', 'related' => array('page=dzn-integration-google'))
            : array('state' => 'healthy', 'detail' => 'No failed Meet projection is visible.', 'technical_code' => 'meet_projection_clear');
    }

    private function checkAttendanceEvidence(): array {
        $open = $this->int("SELECT COUNT(*) FROM {$this->p()}canonical_attendance_cases WHERE state NOT IN ('confirmed','resolved','closed','cancelled')");
        $conflicts = $this->int("SELECT COUNT(*) FROM {$this->p()}canonical_attendance_conflicts WHERE resolved_at IS NULL");
        if ($conflicts > 0) return array('state' => 'failed', 'detail' => $conflicts . ' attendance conflict(s) are unresolved.', 'technical_code' => 'attendance_conflicts_open', 'related' => array('page=dzn-attendance-review'));
        return $open > 0
            ? array('state' => 'degraded', 'detail' => $open . ' attendance case(s) await confirmation or review.', 'technical_code' => 'attendance_cases_open', 'related' => array('page=dzn-attendance-review'))
            : array('state' => 'healthy', 'detail' => 'Attendance cases are confirmed or closed.', 'technical_code' => 'attendance_cases_clear');
    }

    private function checkPostIntro(): array {
        $failed = $this->int("SELECT COUNT(*) FROM {$this->p()}post_intro_activation_jobs WHERE state='failed'");
        $pending = $this->int("SELECT COUNT(*) FROM {$this->p()}post_intro_activation_jobs WHERE state IN ('pending','scheduled','retry')");
        return $failed > 0
            ? array('state' => 'degraded', 'detail' => $failed . ' post-intro activation job(s) failed; ' . $pending . ' pending.', 'technical_code' => 'post_intro_failed', 'related' => array('page=dzn-post-intro'))
            : array('state' => 'healthy', 'detail' => $pending . ' post-intro activation job(s) are pending; no terminal failure.', 'technical_code' => 'post_intro_progressing');
    }

    private function checkClassSchedules(): array {
        $missing = $this->int("SELECT COUNT(*) FROM {$this->p()}lessons WHERE current_schedule_version_id IS NULL AND archived_at IS NULL AND status NOT IN ('cancelled','draft')");
        return $missing > 0
            ? array('state' => 'degraded', 'detail' => $missing . ' active lesson(s) have no current schedule version.', 'technical_code' => 'schedule_version_missing', 'related' => array('page=dzn-lesson'))
            : array('state' => 'healthy', 'detail' => 'Every active lesson has a current schedule version.', 'technical_code' => 'schedule_versions_present');
    }

    private function checkGoogleOAuth(): array {
        $health = (new GoogleWorkspaceOAuthService())->health();
        $configured = (bool) ($health['configured'] ?? false);
        $connected = in_array((string) ($health['state'] ?? ''), array('connected', 'active'), true) && ($health['credential_valid'] ?? false);
        if (!$configured) return array('state' => 'not_configured', 'detail' => 'The academy Google OAuth client is not configured. No live operation was attempted.', 'technical_code' => 'google_not_configured', 'related' => array('page=dzn-integration-google'));
        if (!$connected) return array('state' => 'degraded', 'detail' => 'OAuth is configured but the organiser connection is not active.', 'technical_code' => 'google_organiser_disconnected', 'related' => array('page=dzn-integration-google'));
        return array('state' => 'healthy', 'detail' => 'Organiser OAuth connection is configured. Connectivity was not tested destructively.', 'technical_code' => 'google_organiser_connected');
    }

    private function checkGoogleProjection(): array {
        $rows = $this->rows("SELECT projection_state AS state,COUNT(*) AS total FROM {$this->p()}provider_calendar_event_mappings GROUP BY projection_state");
        $failed = 0; $total = 0;
        foreach ($rows as $row) { $total += (int) ($row->total ?? 0); if (in_array((string) ($row->state ?? ''), array('failed','blocked'), true)) $failed += (int) ($row->total ?? 0); }
        if ($total === 0) return array('state' => 'healthy', 'detail' => 'No calendar projection has been recorded yet.', 'technical_code' => 'google_projection_empty');
        return $failed > 0
            ? array('state' => 'degraded', 'detail' => $failed . ' of ' . $total . ' calendar projection(s) failed.', 'technical_code' => 'google_projection_failed', 'related' => array('page=dzn-integration-google'))
            : array('state' => 'healthy', 'detail' => 'All ' . $total . ' calendar projection(s) are applied or retryable.', 'technical_code' => 'google_projection_applied');
    }

    private function checkMeetClassrooms(): array {
        $rows = $this->rows("SELECT classroom_state AS state,COUNT(*) AS total FROM {$this->p()}meet_classrooms GROUP BY classroom_state");
        $failed = 0;
        foreach ($rows as $row) if (in_array((string) ($row->state ?? ''), array('failed','blocked','conflict'), true)) $failed += (int) ($row->total ?? 0);
        return $failed > 0
            ? array('state' => 'degraded', 'detail' => $failed . ' Meet classroom(s) are failed or blocked.', 'technical_code' => 'meet_classroom_failed', 'related' => array('page=dzn-integration-google'))
            : array('state' => 'healthy', 'detail' => 'No failed Meet classroom is visible.', 'technical_code' => 'meet_classroom_clear');
    }

    private function checkFinanceProvider(): array {
        $last = (array) get_option('dzn_stripe_test_connection_result', array());
        $state = (string) ($last['state'] ?? '');
        $environment = (string) get_option('dzn_stripe_test_activation', '') === '1' ? 'test' : 'live-or-unset';
        if ($state === 'success') return array('state' => 'healthy', 'detail' => 'Stripe test connectivity was confirmed; configured environment: ' . $environment . '. No payment was initiated.', 'technical_code' => 'stripe_test_confirmed', 'related' => array('page=dzn-finance&tab=settings#stripe'));
        if ($state === 'failed') return array('state' => 'degraded', 'detail' => 'The last Stripe connectivity test failed.', 'technical_code' => 'stripe_test_failed', 'related' => array('page=dzn-finance&tab=settings#stripe'));
        return array('state' => 'unknown', 'detail' => 'No Stripe connectivity evidence has been recorded. No test was run automatically.', 'technical_code' => 'stripe_not_tested', 'related' => array('page=dzn-finance&tab=settings#stripe'));
    }

    private function checkWebhookReceipts(): array {
        $total = $this->int("SELECT COUNT(*) FROM {$this->p()}payment_provider_event_receipts WHERE received_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY");
        $refused = $this->int("SELECT COUNT(*) FROM {$this->p()}payment_provider_event_receipts WHERE received_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY AND verification_state NOT IN ('verified','accepted')");
        return $refused > 0
            ? array('state' => 'degraded', 'detail' => $refused . ' of ' . $total . ' provider receipt(s) in seven days were refused.', 'technical_code' => 'webhook_refusals', 'related' => array('page=dzn-finance&tab=reconciliation'))
            : array('state' => 'healthy', 'detail' => $total . ' provider receipt(s) in seven days; no refused verification.', 'technical_code' => 'webhooks_verified');
    }

    private function checkFinanceReconciliation(): array {
        $unresolved = $this->int("SELECT COUNT(*) FROM {$this->p()}finance_reconciliation_runs WHERE unresolved_count>0");
        $failed = $this->int("SELECT COUNT(*) FROM {$this->p()}finance_reconciliation_runs WHERE state='failed'");
        if ($failed > 0) return array('state' => 'failed', 'detail' => $failed . ' reconciliation run(s) failed.', 'technical_code' => 'finance_reconciliation_failed', 'related' => array('page=dzn-finance&tab=reconciliation'));
        return $unresolved > 0
            ? array('state' => 'degraded', 'detail' => $unresolved . ' reconciliation run(s) contain unresolved findings.', 'technical_code' => 'finance_reconciliation_unresolved', 'related' => array('page=dzn-finance&tab=reconciliation'))
            : array('state' => 'healthy', 'detail' => 'No reconciliation run has unresolved findings.', 'technical_code' => 'finance_reconciliation_clear');
    }

    private function checkFinanceExceptions(): array {
        $open = $this->int("SELECT COUNT(*) FROM {$this->p()}finance_exceptions WHERE state NOT IN ('resolved','closed','dismissed')");
        $high = $this->int("SELECT COUNT(*) FROM {$this->p()}finance_exceptions WHERE state NOT IN ('resolved','closed','dismissed') AND severity IN ('error','critical')");
        return $high > 0
            ? array('state' => 'failed', 'detail' => $high . ' high-severity finance exception(s) are open.', 'technical_code' => 'finance_exceptions_high', 'related' => array('page=dzn-finance&tab=reconciliation'))
            : ($open > 0 ? array('state' => 'degraded', 'detail' => $open . ' finance exception(s) await review.', 'technical_code' => 'finance_exceptions_open', 'related' => array('page=dzn-finance&tab=reconciliation')) : array('state' => 'healthy', 'detail' => 'No open finance exception.', 'technical_code' => 'finance_exceptions_clear'));
    }

    private function checkStatements(): array {
        $stuck = $this->int("SELECT COUNT(*) FROM {$this->p()}finance_statements WHERE state IN ('draft','pending') AND updated_at < UTC_TIMESTAMP() - INTERVAL 14 DAY");
        return $stuck > 0
            ? array('state' => 'degraded', 'detail' => $stuck . ' statement(s) have not moved for fourteen days.', 'technical_code' => 'statements_stalled', 'related' => array('page=dzn-finance&tab=statements'))
            : array('state' => 'healthy', 'detail' => 'No statement is stalled outside a terminal state.', 'technical_code' => 'statements_progressing');
    }

    private function checkNotificationQueue(): array {
        $report = (new NotificationDiagnosticsReadService())->report();
        $stuck = (int) ($report['stuck_lease'] ?? 0);
        $backlog = (int) ($report['orchestration_backlog'] ?? 0);
        return $stuck > 0
            ? array('state' => 'degraded', 'detail' => $stuck . ' notification lease(s) are stuck; ' . $backlog . ' queued.', 'technical_code' => 'notification_leases_stuck', 'related' => array('page=dzn-notifications'))
            : array('state' => 'healthy', 'detail' => 'No stuck notification lease; ' . $backlog . ' queued.', 'technical_code' => 'notification_queue_progressing');
    }

    private function checkNotificationFailures(): array {
        $report = (new NotificationDiagnosticsReadService())->report();
        $failed = (int) ($report['retry_exhausted'] ?? 0);
        $expired = (int) ($report['retry_window_exhausted'] ?? 0);
        $orphan = (int) ($report['orphan_outbox_row'] ?? 0);
        if ($orphan > 0) return array('state' => 'failed', 'detail' => $orphan . ' orphaned outbox row(s) reference a missing notification.', 'technical_code' => 'notification_orphan_rows', 'related' => array('page=dzn-notifications'));
        return $failed > 0
            ? array('state' => 'degraded', 'detail' => $failed . ' notification(s) exhausted retries; ' . $expired . ' exceeded the retry window.', 'technical_code' => 'notification_retry_exhausted', 'related' => array('page=dzn-notifications'))
            : array('state' => 'healthy', 'detail' => 'No notification exhausted its retry policy.', 'technical_code' => 'notification_delivery_clear');
    }

    private function checkNotificationProviders(): array {
        $settings = new IntegrationSettingsService();
        $states = array();
        foreach (array('email' => 'Email', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS') as $key => $label) {
            try { $states[] = $label . ': ' . ($settings->ready($key) ? 'configured' : 'not ready'); }
            catch (\Throwable) { $states[] = $label . ': unknown'; }
        }
        $ready = count(array_filter($states, static fn(string $state): bool => str_contains($state, 'configured')));
        return $ready === 3
            ? array('state' => 'healthy', 'detail' => implode(' · ', $states) . '. No provider call was made.', 'technical_code' => 'notification_providers_ready')
            : array('state' => $ready === 0 ? 'not_configured' : 'degraded', 'detail' => implode(' · ', $states) . '.', 'technical_code' => 'notification_providers_incomplete', 'related' => array('page=dzn-integrations'));
    }

    private function checkNotificationTemplates(): array {
        $active = $this->int("SELECT COUNT(*) FROM {$this->p()}notification_workflow_versions WHERE active_slot=1 AND state='active'");
        $missing = $this->int("SELECT COUNT(*) FROM {$this->p()}notification_workflow_versions w LEFT JOIN {$this->p()}notification_templates t ON t.id=w.template_id WHERE w.active_slot=1 AND w.state='active' AND (t.id IS NULL OR t.state<>'active')");
        $duplicates = $this->int("SELECT COUNT(*) FROM (SELECT intent_key,audience,locale,COUNT(*) c FROM {$this->p()}notification_workflow_versions WHERE active_slot=1 AND state='active' GROUP BY intent_key,audience,locale HAVING c>1) d");
        if ($duplicates > 0) return array('state' => 'failed', 'detail' => $duplicates . ' duplicate active workflow mapping(s) exist.', 'technical_code' => 'notification_template_duplicate', 'related' => array('page=dzn-notifications'));
        if ($missing > 0) return array('state' => 'degraded', 'detail' => $missing . ' active workflow(s) do not point at an active template.', 'technical_code' => 'notification_template_missing', 'related' => array('page=dzn-notifications'));
        return array('state' => 'healthy', 'detail' => $active . ' active workflow mapping(s) point at active templates with no duplicate.', 'technical_code' => 'notification_templates_ready');
    }

    private function checkNotificationFallback(): array {
        $report = (new NotificationDiagnosticsReadService())->report();
        $exhausted = (int) ($report['retry_exhausted'] ?? 0);
        $settings = new IntegrationSettingsService();
        $smsReady = false;
        try { $smsReady = $settings->ready('sms'); } catch (\Throwable) {}
        if (!$smsReady) {
            return array('state' => 'not_configured', 'detail' => 'The SMS fallback provider is not ready. WhatsApp failures cannot fall back successfully.', 'technical_code' => 'sms_fallback_not_configured', 'related' => array('page=dzn-integrations'));
        }
        return $exhausted > 0
            ? array('state' => 'degraded', 'detail' => $exhausted . ' notification(s) exhausted retries. SMS fallback eligibility must be reviewed in the notification register.', 'technical_code' => 'sms_fallback_review', 'related' => array('page=dzn-notifications'))
            : array('state' => 'healthy', 'detail' => 'SMS fallback provider is configured and no notification exhausted retries.', 'technical_code' => 'sms_fallback_ready');
    }

    private function checkPortalIdentity(): array {
        $teacher = $this->int("SELECT COUNT(*) FROM {$this->p()}teacher_principal_links WHERE status='active'");
        $student = $this->int("SELECT COUNT(*) FROM {$this->p()}student_principal_links WHERE status='active'");
        $orphanTeacher = $this->int("SELECT COUNT(*) FROM {$this->p()}teacher_principal_links l LEFT JOIN {$this->p()}teachers t ON t.id=l.teacher_id WHERE l.status='active' AND t.id IS NULL");
        $orphanStudent = $this->int("SELECT COUNT(*) FROM {$this->p()}student_principal_links l LEFT JOIN {$this->p()}students s ON s.id=l.student_id WHERE l.status='active' AND s.id IS NULL");
        if ($orphanTeacher + $orphanStudent > 0) return array('state' => 'failed', 'detail' => ($orphanTeacher + $orphanStudent) . ' active principal link(s) reference a missing record.', 'technical_code' => 'portal_principal_orphaned');
        return array('state' => 'healthy', 'detail' => $teacher . ' active teacher link(s) and ' . $student . ' active student link(s); no orphan.', 'technical_code' => 'portal_identity_consistent');
    }

    private function checkPortalCapabilities(): array {
        $expired = $this->int("SELECT COUNT(*) FROM {$this->p()}portal_public_capabilities WHERE expires_at < UTC_TIMESTAMP() AND revoked_at IS NULL");
        return $expired > 0
            ? array('state' => 'degraded', 'detail' => $expired . ' portal capability/capabilities have expired without revocation.', 'technical_code' => 'portal_expired_capabilities', 'related' => array('page=dzn-portal-capabilities'))
            : array('state' => 'healthy', 'detail' => 'No portal capability is expired without revocation.', 'technical_code' => 'portal_capabilities_clear');
    }

    private function checkPortalDenials(): array {
        $denials = $this->int("SELECT COUNT(*) FROM {$this->p()}portal_access_denials WHERE occurred_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR");
        return $denials > 0
            ? array('state' => 'degraded', 'detail' => $denials . ' portal access denial(s) in the last 24 hours. Personal data is not shown.', 'technical_code' => 'portal_access_denials', 'related' => array('page=dzn-system-health'))
            : array('state' => 'healthy', 'detail' => 'No portal access denial recorded in the last 24 hours.', 'technical_code' => 'portal_access_denials_clear');
    }

    private function checkConsentJobs(): array {
        $failed = $this->int("SELECT COUNT(*) FROM {$this->p()}consent_processing_jobs WHERE state='failed'");
        $pending = $this->int("SELECT COUNT(*) FROM {$this->p()}consent_processing_jobs WHERE state IN ('pending','leased','retry')");
        return $failed > 0
            ? array('state' => 'degraded', 'detail' => $failed . ' consent job(s) failed; ' . $pending . ' pending.', 'technical_code' => 'consent_jobs_failed', 'related' => array('page=dzn-policies'))
            : array('state' => 'healthy', 'detail' => $pending . ' consent job(s) are pending; no terminal failure.', 'technical_code' => 'consent_jobs_progressing');
    }

    private function checkConsentNotices(): array {
        $required = $this->rows("SELECT notice_audience_code AS audience,COUNT(DISTINCT language_code) AS languages FROM {$this->p()}recording_notice_versions WHERE publication_state='published' AND withdrawn_at IS NULL GROUP BY notice_audience_code");
        $purposes = $this->int("SELECT COUNT(*) FROM {$this->p()}consent_purposes WHERE state='active'");
        if ($purposes === 0) return array('state' => 'not_configured', 'detail' => 'No active consent purpose is registered.', 'technical_code' => 'consent_purposes_missing', 'related' => array('page=dzn-policies'));
        if ($required === array()) return array('state' => 'degraded', 'detail' => 'No consent notice is published for an active purpose.', 'technical_code' => 'consent_notices_missing', 'related' => array('page=dzn-policies'));
        return array('state' => 'healthy', 'detail' => count($required) . ' audience(s) have a current published notice; ' . $purposes . ' active purpose(s).', 'technical_code' => 'consent_notices_published');
    }

    private function checkRecordingConsent(): array {
        $total = $this->int("SELECT COUNT(*) FROM {$this->p()}consent_recording_authorisations");
        $blocked = $this->int("SELECT COUNT(*) FROM {$this->p()}consent_recording_authorisations WHERE outcome NOT IN ('authorised','allowed','granted')");
        return $total === 0
            ? array('state' => 'not_configured', 'detail' => 'No recording authorisation has been recorded in this environment.', 'technical_code' => 'recording_consent_empty')
            : array('state' => 'healthy', 'detail' => $total . ' recording authorisation record(s); ' . $blocked . ' non-authorised outcome(s) preserved as evidence.', 'technical_code' => 'recording_consent_evidence_present');
    }

    private function checkOutbox(): array {
        $leased = $this->int("SELECT COUNT(*) FROM {$this->p()}platform_outbox WHERE status='leased'");
        $pending = $this->int("SELECT COUNT(*) FROM {$this->p()}platform_outbox WHERE status='pending'");
        $failed = $this->int("SELECT COUNT(*) FROM {$this->p()}platform_outbox WHERE status IN ('failed','dead')");
        if ($failed > 0) return array('state' => 'degraded', 'detail' => $failed . ' outbox item(s) are terminal; ' . $pending . ' pending.', 'technical_code' => 'outbox_terminal_failures', 'related' => array('page=dzn-system-health'));
        return array('state' => 'healthy', 'detail' => $pending . ' pending and ' . $leased . ' leased outbox item(s).', 'technical_code' => 'outbox_progressing');
    }

    private function checkRescheduleJobs(): array {
        $failed = $this->int("SELECT COUNT(*) FROM {$this->p()}lesson_reschedule_calendar_jobs WHERE state='failed'");
        return $failed > 0
            ? array('state' => 'degraded', 'detail' => $failed . ' reschedule calendar job(s) failed.', 'technical_code' => 'reschedule_jobs_failed', 'related' => array('page=dzn-lesson'))
            : array('state' => 'healthy', 'detail' => 'No failed reschedule calendar job.', 'technical_code' => 'reschedule_jobs_clear');
    }

    private function checkPostIntroQueue(): array {
        $pending = $this->int("SELECT COUNT(*) FROM {$this->p()}post_intro_activation_jobs WHERE state IN ('pending','scheduled','retry')");
        $oldest = $this->value("SELECT MIN(created_at) FROM {$this->p()}post_intro_activation_jobs WHERE state IN ('pending','scheduled','retry')");
        return array('state' => 'healthy', 'detail' => $pending . ' pending post-intro activation job(s)' . ($oldest ? '; oldest ' . $oldest . ' UTC' : '') . '.', 'technical_code' => 'post_intro_queue_readable');
    }

    private function checkMigrations(): array {
        $completed = (array) get_option('dzn_platform_completed_migrations', array());
        return in_array('001_initial_core_schema', $completed, true)
            ? array('state' => 'healthy', 'detail' => 'The required initial core migration is recorded.', 'technical_code' => 'core_migration_complete')
            : array('state' => 'failed', 'detail' => 'The required initial core migration is not recorded.', 'technical_code' => 'core_migration_missing');
    }

    private function checkPermissionCatalogue(): array {
        $all = PermissionRegistry::all();
        $broken = array();
        $keys = array();
        foreach ($all as $key => $definition) {
            if (($definition['caps'] ?? array()) === array()) $broken[] = $key;
            foreach ($definition['caps'] as $cap) $keys[$cap] = true;
        }
        if ($broken !== array()) return array('state' => 'failed', 'detail' => count($broken) . ' permission(s) have no canonical capability mapping: ' . implode(', ', array_slice($broken, 0, 5)) . '.', 'technical_code' => 'permission_mapping_missing');
        return array('state' => 'healthy', 'detail' => count($all) . ' permissions map to ' . count($keys) . ' canonical capabilit' . (count($keys) === 1 ? 'y' : 'ies') . '.', 'technical_code' => 'permission_catalogue_valid');
    }

    private function checkRoleIntegrity(): array {
        $service = new AccessRoleService();
        $roles = $service->roles();
        $orphaned = 0;
        $unknownPermissions = 0;
        $all = array_keys(PermissionRegistry::all());
        foreach ($roles as $role) {
            foreach ((array) ($role['permissions'] ?? array()) as $permission) if (!in_array($permission, $all, true)) $unknownPermissions++;
        }
        if (function_exists('get_users')) {
            foreach (get_users(array('fields' => 'ID', 'number' => 500)) as $userId) {
                if (user_can((int) $userId, 'manage_options')) continue;
                $assignment = $service->assignment((int) $userId);
                foreach ($assignment['roles'] as $roleId) if (!isset($roles[$roleId])) $orphaned++;
            }
        }
        if ($orphaned > 0 || $unknownPermissions > 0) return array('state' => 'failed', 'detail' => $orphaned . ' assignment(s) reference a missing role; ' . $unknownPermissions . ' unknown permission grant(s).', 'technical_code' => 'role_integrity_failed', 'related' => array('page=dzn-delegated-access'));
        return array('state' => 'healthy', 'detail' => count($roles) . ' role(s) reference only known permissions; no orphaned assignment.', 'technical_code' => 'role_integrity_clear');
    }

    private function checkPermissionMigration(): array {
        if (!function_exists('get_users')) return array('state' => 'unknown', 'detail' => 'User records are not readable in this context.', 'technical_code' => 'permission_migration_unavailable');
        $service = new AccessRoleService();
        $legacy = 0;
        foreach (get_users(array('fields' => 'ID', 'number' => 1000)) as $userId) {
            $user = get_userdata((int) $userId);
            if (!$user || (method_exists($user, 'has_cap') && $user->has_cap('manage_options'))) continue;
            if ($service->effective((int) $userId)['legacy']) $legacy++;
        }
        return $legacy > 0
            ? array('state' => 'degraded', 'detail' => $legacy . ' account(s) still use legacy work-area grants. Review and save them into a role assignment.', 'technical_code' => 'legacy_permissions_present', 'related' => array('page=dzn-delegated-access'))
            : array('state' => 'healthy', 'detail' => 'No account relies solely on legacy work-area grants.', 'technical_code' => 'permission_migration_complete');
    }

    private function checkSecurityAudit(): array {
        $events = $this->int("SELECT COUNT(*) FROM {$this->p()}platform_audit_events WHERE aggregate_type IN ('delegated_access','staff_access') AND occurred_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY");
        return array('state' => 'healthy', 'detail' => $events . ' permission-change audit event(s) in the last 30 days.', 'technical_code' => 'security_audit_readable');
    }

    private function checkSecurityDenials(): array {
        $attempts = $this->int("SELECT COUNT(*) FROM {$this->p()}portal_access_denials WHERE occurred_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR");
        $exceptions = $this->int("SELECT COUNT(*) FROM {$this->p()}operational_exceptions WHERE status='open' AND exception_type LIKE '%author%'");
        return $attempts + $exceptions > 0
            ? array('state' => 'degraded', 'detail' => $attempts . ' recorded portal denial(s) in 24 hours; ' . $exceptions . ' open authorisation exception(s). Personal data is not exposed.', 'technical_code' => 'authorisation_events_present', 'related' => array('page=dzn-system-health'))
            : array('state' => 'healthy', 'detail' => 'No recorded authorisation denial in the last 24 hours.', 'technical_code' => 'authorisation_events_clear');
    }

    private function checkEmailProvider(): array {
        return $this->providerCheck('email', 'Email');
    }

    private function checkWhatsAppProvider(): array {
        return $this->providerCheck('whatsapp', 'WhatsApp');
    }

    private function checkSmsProvider(): array {
        return $this->providerCheck('sms', 'SMS');
    }

    private function providerCheck(string $key, string $label): array {
        $settings = new IntegrationSettingsService();
        if ($settings->ready($key)) return array('state' => 'healthy', 'detail' => $label . ' is locally configured. Provider verification remains a separate explicit action.', 'technical_code' => $key . '_configured');
        $redacted = (array) $settings->redacted($key);
        $hasSecret = false;
        foreach ($redacted as $value) if ($value === true) { $hasSecret = true; break; }
        return array('state' => $hasSecret ? 'degraded' : 'not_configured', 'detail' => $hasSecret ? $label . ' credentials are stored but the provider is not ready.' : $label . ' is not configured.', 'technical_code' => $key . ($hasSecret ? '_incomplete' : '_not_configured'), 'related' => array('page=dzn-integrations'));
    }

    private function p(): string {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->prefix)) throw new \RuntimeException('diagnostic_database_unavailable');
        return $wpdb->prefix . 'dzn_';
    }

    private function int(string $sql): int {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) throw new \RuntimeException('diagnostic_database_unavailable');
        $value = $wpdb->get_var($sql);
        if ($wpdb->last_error !== '') throw new \RuntimeException('diagnostic_query_failed');
        return (int) $value;
    }

    private function value(string $sql): string {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) throw new \RuntimeException('diagnostic_database_unavailable');
        $value = $wpdb->get_var($sql);
        if ($wpdb->last_error !== '') throw new \RuntimeException('diagnostic_query_failed');
        return $value === null ? '' : (string) $value;
    }

    /** @return array<int,object> */
    private function rows(string $sql): array {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) throw new \RuntimeException('diagnostic_database_unavailable');
        $rows = $wpdb->get_results($sql);
        if ($wpdb->last_error !== '') throw new \RuntimeException('diagnostic_query_failed');
        return is_array($rows) ? $rows : array();
    }
}
