<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Canonical granular permission catalogue.
 *
 * Permissions are organised as module → functional area → action. Every action maps to one or
 * more capabilities that the existing controllers and services already enforce server-side.
 * This registry does not create a second authorisation authority: it is the vocabulary a role
 * uses to grant or revoke those existing capabilities. A permission with no capability mapping
 * is never grantable (default deny).
 */
final class PermissionRegistry {
    public const FULL_ADMINISTRATOR_ROLE = 'full_administrator';

    /**
     * @return array<string,array{label:string,description:string,areas:array<string,array{label:string,description:string,actions:array<string,array{label:string,description:string,caps:string[],sensitive?:bool}>}>}>
     */
    public static function modules(): array {
        return array(
            'overview' => self::module('Overview', 'Academy-wide overview, pipeline and attention queues.', array(
                'academy' => self::area('Academy overview', 'Read pipeline health, upcoming classes and attention items.', array(
                    'view' => self::action('View overview', 'Read the academy dashboard and booking pipeline.', array('dzn_view_booking_requests')),
                    'export' => self::action('Export overview', 'Export the current overview projection.', array('dzn_finance_export'), true),
                )),
            )),
            'teachers' => self::module('Teachers', 'Teacher records, onboarding, invitations and availability.', array(
                'profiles' => self::area('Teacher profiles', 'Teacher record maintenance and identity links.', array(
                    'view' => self::action('View teachers', 'Read teacher records and profile history.', array('dzn_manage_teachers')),
                    'create' => self::action('Create teachers', 'Create a teacher record through the existing service.', array('dzn_manage_teachers')),
                    'edit' => self::action('Edit teachers', 'Edit teacher profile details.', array('dzn_manage_teachers')),
                    'archive' => self::action('Archive teachers', 'Archive or restore a teacher record.', array('dzn_manage_teachers'), true),
                    'relink_identity' => self::action('Relink portal identity', 'Repair a teacher principal link with evidence.', array('dzn_relink_teacher_principal'), true),
                )),
                'onboarding' => self::area('Onboarding & invitations', 'Invitation delivery, onboarding review and eligibility.', array(
                    'view' => self::action('View onboarding', 'Read onboarding and invitation state.', array('dzn_manage_onboarding')),
                    'invite' => self::action('Issue invitations', 'Issue a new teacher invitation.', array('dzn_issue_teacher_invitations'), true),
                    'revoke' => self::action('Revoke invitations', 'Revoke an outstanding teacher invitation.', array('dzn_revoke_teacher_invitations'), true),
                    'eligibility' => self::action('Manage teaching eligibility', 'Maintain instrument and course eligibility.', array('dzn_manage_teaching_eligibility')),
                    'availability' => self::action('Manage availability', 'Maintain teacher availability and exceptions.', array('dzn_manage_teacher_availability')),
                )),
            )),
            'students' => self::module('Students', 'Student directory, identity and account authority.', array(
                'directory' => self::area('Student directory', 'Student records and contact maintenance.', array(
                    'view' => self::action('View students', 'Read student records and learning history.', array('dzn_manage_students')),
                    'edit' => self::action('Edit students', 'Edit permitted student profile fields.', array('dzn_manage_students')),
                    'archive' => self::action('Archive students', 'Archive or restore a student record.', array('dzn_manage_students'), true),
                )),
                'identity' => self::area('Identity & accounts', 'Account claims, identity resolution and authority grants.', array(
                    'view' => self::action('View identity evidence', 'Read acceptance and identity eligibility evidence.', array('dzn_view_student_acceptance_eligibility')),
                    'resolve' => self::action('Resolve student identity', 'Resolve a booking request student identity with evidence.', array('dzn_resolve_booking_request_student_identity'), true),
                    'authority' => self::action('Grant acceptance authority', 'Record student acceptance authority evidence.', array('dzn_manage_student_acceptance_authority'), true),
                    'claim' => self::action('Issue account claims', 'Issue a student account claim.', array('dzn_issue_student_account_claims'), true),
                )),
            )),
            'classes' => self::module('Classes', 'Introductory and regular classes, scheduling, attendance and classroom follow-through.', array(
                'introductory' => self::area('Introductory classes', 'Introductory requests, completion and continuation.', array(
                    'view' => self::action('View introductory classes', 'Read introductory class records and outcomes.', array('dzn_view_booking_requests')),
                    'create' => self::action('Create introductory classes', 'Create an introductory class or request.', array('dzn_manage_lessons'), true),
                    'coordinate' => self::action('Coordinate introductory requests', 'Coordinate introductory requests and offers.', array('dzn_manage_booking_request_coordination')),
                    'complete' => self::action('Complete introductory classes', 'Record an introductory outcome.', array('dzn_manage_post_intro_automation'), true),
                )),
                'regular' => self::area('Regular classes', 'Scheduled classes and delivery evidence.', array(
                    'view' => self::action('View classes', 'Read class schedules and delivery state.', array('dzn_manage_lessons')),
                    'create' => self::action('Create classes', 'Create a scheduled class through the canonical authority.', array('dzn_manage_canonical_lessons'), true),
                    'edit' => self::action('Edit classes', 'Edit permitted class details.', array('dzn_manage_canonical_lessons')),
                    'cancel' => self::action('Cancel classes', 'Cancel a class through the canonical delivery authority.', array('dzn_manage_canonical_lesson_delivery'), true),
                    'delete' => self::action('Delete classes', 'Delete a class where the canonical service supports it.', array('dzn_manage_canonical_lessons'), true),
                )),
                'scheduling' => self::area('Scheduling', 'Assignment, rescheduling and schedule availability.', array(
                    'assign' => self::action('Assign teachers', 'Assign an eligible teacher to a class.', array('dzn_manage_teacher_assignments')),
                    'reschedule' => self::action('Reschedule classes', 'Reschedule through the canonical schedule revision service.', array('dzn_manage_canonical_lesson_schedules')),
                    'override_availability' => self::action('Override availability', 'Override schedule availability with evidence.', array('dzn_override_canonical_lesson_schedule_availability'), true),
                    'recurring' => self::action('Manage recurring schedules', 'Read and manage recurring lesson authorities.', array('dzn_view_recurring_authority')),
                )),
                'attendance' => self::area('Attendance', 'Attendance evidence, confirmation and review.', array(
                    'view' => self::action('View attendance', 'Read attendance cases and evidence.', array('dzn_manage_canonical_attendance_review')),
                    'confirm' => self::action('Confirm attendance', 'Confirm attendance through the canonical authority.', array('dzn_ingest_canonical_attendance_evidence')),
                    'review' => self::action('Review attendance', 'Adjudicate attendance review cases.', array('dzn_manage_canonical_attendance_review'), true),
                )),
                'classroom' => self::area('Classroom provisioning', 'Google Meet classroom and recovery.', array(
                    'view' => self::action('View classroom state', 'Read classroom and Meet provisioning state.', array('dzn_view_provider_integrations')),
                    'provision' => self::action('Provision classrooms', 'Provision or repair classroom associations.', array('dzn_manage_provider_integrations'), true),
                    'retry_meet' => self::action('Retry Meet provisioning', 'Retry a failed Meet classroom projection.', array('dzn_manage_provider_integrations'), true),
                    'configure' => self::action('Change classroom configuration', 'Change classroom configuration or organiser connection.', array('dzn_manage_provider_integrations'), true),
                )),
                'exceptions' => self::area('Exceptions & recovery', 'Operational exception recovery for classes.', array(
                    'view' => self::action('View class exceptions', 'Read class exceptions and recovery cases.', array('dzn_view_booking_requests')),
                    'manage' => self::action('Manage class exceptions', 'Resolve, acknowledge or dismiss class exceptions.', array('dzn_manage_exceptions'), true),
                    'recover' => self::action('Run class recovery', 'Run an existing recoverable class operation.', array('dzn_manage_exceptions'), true),
                )),
            )),
            'bookings' => self::module('Bookings and Coordination', 'Booking intake, teacher matching, offers and coordination cases.', array(
                'requests' => self::area('Booking requests', 'Booking intake and request review.', array(
                    'view' => self::action('View booking requests', 'Read booking requests and contact evidence.', array('dzn_view_booking_requests')),
                    'review_duplicates' => self::action('Review duplicates', 'Review and resolve duplicate booking flags.', array('dzn_review_booking_request_duplicates')),
                    'erase_pii' => self::action('Erase request PII', 'Erase request contact data under privacy authority.', array('dzn_erase_booking_request_pii'), true),
                )),
                'coordination' => self::area('Coordination', 'Matching, proposals, acceptance and handoff.', array(
                    'coordinate' => self::action('Coordinate requests', 'Coordinate a booking request case.', array('dzn_manage_booking_request_coordination')),
                    'match' => self::action('Match teachers', 'Prepare and issue teacher matches.', array('dzn_prepare_booking_request_matches')),
                    'propose' => self::action('Issue proposals', 'Issue booking proposals to a student.', array('dzn_issue_booking_request_proposals')),
                    'provisional_acceptance' => self::action('Record provisional acceptance', 'Record provisional acceptance evidence.', array('dzn_record_booking_request_provisional_acceptance')),
                    'finalize' => self::action('Finalise arrangements', 'Finalise accepted service arrangements.', array('dzn_finalize_service_arrangements'), true),
                )),
                'teacher_offers' => self::area('Teacher offers', 'Teacher offer, decline and acceptance states.', array(
                    'view' => self::action('View offers', 'Read teacher offers and acceptance state.', array('dzn_view_booking_requests')),
                    'dispatch' => self::action('Dispatch offers', 'Dispatch allocation offers through the existing worker.', array('dzn_manage_booking_request_coordination'), true),
                )),
            )),
            'enrolment' => self::module('Enrolment', 'Trials, enrolments, terms and conversion.', array(
                'trials' => self::area('Trials & conversion', 'Introductory trials and conversion to enrolment.', array(
                    'view' => self::action('View trials', 'Read trial and conversion queues.', array('dzn_view_booking_requests')),
                    'convert' => self::action('Convert to enrolment', 'Convert an accepted arrangement through canonical conversion.', array('dzn_convert_service_arrangements_to_enrolments'), true),
                )),
                'enrolments' => self::area('Enrolments', 'Enrolment lifecycle and evidence.', array(
                    'view' => self::action('View enrolments', 'Read enrolments and lifecycle evidence.', array('dzn_manage_enrolments')),
                    'create' => self::action('Create enrolments', 'Create an enrolment through the existing service.', array('dzn_manage_enrolments')),
                    'edit' => self::action('Edit enrolments', 'Edit permitted enrolment details.', array('dzn_manage_enrolments')),
                    'lifecycle' => self::action('Change enrolment lifecycle', 'Activate, pause, resume or close an enrolment.', array('dzn_manage_canonical_enrolment_lifecycle'), true),
                )),
                'terms' => self::area('Terms', 'Academic terms and canonical term lifecycle.', array(
                    'view' => self::action('View terms', 'Read terms and lifecycle history.', array('dzn_manage_terms')),
                    'create' => self::action('Create terms', 'Create a term through the canonical authority.', array('dzn_manage_canonical_terms')),
                    'lifecycle' => self::action('Change term lifecycle', 'Activate, close or cancel a canonical term.', array('dzn_manage_canonical_terms'), true),
                )),
            )),
            'finance' => self::module('Finance', 'Payments, pricing, statements, reconciliation and ledgers.', array(
                'payments' => self::area('Payments', 'Payment authority and execution.', array(
                    'view' => self::action('View payments', 'Read payment authority and execution evidence.', array('dzn_view_payment_execution_authority')),
                    'execute' => self::action('Execute payments', 'Execute a payment through the canonical authority.', array('dzn_manage_payment_execution'), true),
                    'ingest' => self::action('Ingest payment evidence', 'Ingest provider or commercial payment evidence.', array('dzn_ingest_commercial_payment_evidence','dzn_ingest_payment_provider_events'), true),
                    'refund' => self::action('Review refunds', 'Review and release permitted refunds.', array('dzn_manage_refund_reviews'), true),
                )),
                'pricing' => self::area('Pricing & policies', 'Commercial policies, catalogue pricing and promotions.', array(
                    'view' => self::action('View finance authority', 'Read finance authority and commercial policy state.', array('dzn_view_finance_authority')),
                    'commercial_policies' => self::action('Record commercial policies', 'Record a new versioned commercial policy.', array('dzn_manage_commercial_policies'), true),
                    'finance_policies' => self::action('Manage finance policies', 'Maintain finance policy configuration.', array('dzn_manage_finance_policies'), true),
                    'promotions' => self::action('Manage promotions', 'Manage commercial promotions.', array('dzn_manage_commercial_promotions'), true),
                )),
                'statements' => self::area('Statements & rates', 'Teacher rates, statements and payability.', array(
                    'view' => self::action('View statements', 'Read teacher statements and payability.', array('dzn_view_finance_authority')),
                    'rates' => self::action('Manage teacher rates', 'Record teacher rate changes.', array('dzn_manage_teacher_rates'), true),
                    'statements' => self::action('Manage statements', 'Draft, issue or withdraw statements.', array('dzn_manage_finance_statements'), true),
                )),
                'ledger' => self::area('Ledger & reconciliation', 'Cash ledger and reconciliation.', array(
                    'view' => self::action('View ledger', 'Read the cash ledger.', array('dzn_view_cash_ledger')),
                    'manage' => self::action('Manage ledger', 'Record permitted ledger transactions.', array('dzn_manage_cash_ledger'), true),
                    'reconcile' => self::action('Reconcile finance', 'Run finance reconciliation review.', array('dzn_manage_finance_policies'), true),
                )),
            )),
            'notifications' => self::module('Notifications', 'Notification catalogue, templates and delivery operations.', array(
                'catalogue' => self::area('Catalogue & rules', 'Business-function grouping and event choice.', array(
                    'view' => self::action('View notifications', 'Read the notification catalogue and delivery state.', array('dzn_view_notification_authority')),
                    'manage_rules' => self::action('Manage notification rules', 'Change per-event channel configuration.', array('dzn_manage_notification_workflows')),
                )),
                'templates' => self::area('Templates', 'Template draft, publish and version workflow.', array(
                    'view' => self::action('View templates', 'Read template versions and readiness.', array('dzn_view_notification_authority')),
                    'edit' => self::action('Edit templates', 'Draft and publish notification copy.', array('dzn_manage_notification_templates'), true),
                )),
                'delivery' => self::area('Delivery operations', 'Dispatch, retry and delivery evidence.', array(
                    'operate' => self::action('Operate dispatch', 'Run system dispatch and restricted retries.', array('dzn_operate_notification_dispatch'), true),
                    'recover_leases' => self::action('Recover notification leases', 'Release expired notification leases.', array('dzn_operate_notification_dispatch'), true),
                )),
            )),
            'policies' => self::module('Policies', 'Operational, legal and consent policy administration.', array(
                'operational' => self::area('Operational policies', 'Versioned operational rules.', array(
                    'view' => self::action('View policies', 'Read policy registers and versions.', array('dzn_view_commercial_authority')),
                    'edit' => self::action('Edit operational policies', 'Record a new versioned policy value.', array('dzn_manage_commercial_policies'), true),
                )),
                'legal' => self::area('Legal pages', 'Legal page drafts and publication review.', array(
                    'view' => self::action('View legal pages', 'Read legal page status and versions.', array('dzn_manage_consent_policies')),
                    'provision' => self::action('Create legal drafts', 'Create or refresh unpublished legal drafts.', array('dzn_manage_consent_policies'), true),
                )),
                'consent' => self::area('Consent management', 'Consent evidence, reminders and export.', array(
                    'view' => self::action('View consent', 'Read consent status, records and history.', array('dzn_view_consent_evidence')),
                    'send_reminder' => self::action('Send consent reminders', 'Send a catalogue-backed consent reminder.', array('dzn_manage_consent_policies'), true),
                    'retry_jobs' => self::action('Retry consent jobs', 'Retry an authorised failed consent job.', array('dzn_manage_consent_policies'), true),
                    'export' => self::action('Export consent evidence', 'Export an access-controlled consent evidence bundle.', array('dzn_export_consent_evidence'), true),
                )),
            )),
            'integrations' => self::module('Integrations', 'Google Workspace, payment and messaging providers.', array(
                'google' => self::area('Google Workspace & Meet', 'OAuth, Calendar, Meet and classroom provisioning.', array(
                    'view' => self::action('View Google integration', 'Read Google connection and projection state.', array('dzn_view_provider_integrations')),
                    'connect' => self::action('Connect Google account', 'Authorise or revoke the organiser connection.', array('dzn_manage_provider_integrations'), true),
                    'configure' => self::action('Configure Google integration', 'Edit Google OAuth and classroom configuration.', array('dzn_manage_provider_integrations'), true),
                    'repair' => self::action('Repair Google projection', 'Run an existing Google recovery operation.', array('dzn_manage_provider_integrations'), true),
                )),
                'messaging' => self::area('Email, WhatsApp & SMS', 'Notification provider configuration.', array(
                    'view' => self::action('View messaging providers', 'Read provider readiness without secrets.', array('dzn_view_provider_integrations')),
                    'configure' => self::action('Configure messaging providers', 'Save provider credentials and sender configuration.', array('dzn_manage_provider_integrations'), true),
                )),
                'payments' => self::area('Payment providers', 'Stripe and payment provider credentials.', array(
                    'view' => self::action('View payment providers', 'Read payment provider readiness.', array('dzn_view_provider_integrations')),
                    'configure' => self::action('Configure payment providers', 'Save payment provider credentials.', array('dzn_manage_payment_providers'), true),
                )),
            )),
            'diagnostics' => self::module('Diagnostics', 'Safe technical evidence and recovery links.', array(
                'health' => self::area('System health', 'Health summaries and support bundles.', array(
                    'view' => self::action('View diagnostics', 'Read redacted diagnostic evidence.', array('dzn_view_diagnostics')),
                    'export' => self::action('Download support bundle', 'Download a sanitised support bundle.', array('dzn_view_diagnostics'), true),
                )),
                'recovery' => self::area('Recovery', 'Links to existing recovery authorities.', array(
                    'operate' => self::action('Run diagnostic recovery', 'Run an eligible existing recovery action.', array('dzn_manage_exceptions'), true),
                    'portal_capabilities' => self::action('Mint portal capabilities', 'Mint or revoke bounded portal capabilities.', array('dzn_manage_portal_capabilities'), true),
                )),
            )),
            'administration' => self::module('Administration', 'Staff access, roles and delegated authority.', array(
                'access' => self::area('Delegated access', 'Roles, assignments and overrides.', array(
                    'view' => self::action('View delegated access', 'Read roles and staff assignments.', array('manage_options')),
                    'manage_roles' => self::action('Manage roles', 'Create, edit, duplicate or deactivate roles.', array('manage_options'), true),
                    'assign' => self::action('Assign staff access', 'Assign roles and overrides to staff accounts.', array('manage_options'), true),
                )),
                'recording' => self::area('Recording operations', 'Historical recording evidence and safety controls where retained.', array(
                    'view' => self::action('View recording operations', 'Read historical recording evidence.', array('dzn_view_recording_operations')),
                    'legal_hold' => self::action('Manage recording legal hold', 'Apply or release a recording legal hold.', array('dzn_manage_recording_legal_hold'), true),
                )),
            )),
        );
    }

    /** Flat permission catalogue keyed by `module.area.action`. */
    public static function all(): array {
        $flat = array();
        foreach (self::modules() as $moduleKey => $module) {
            foreach ($module['areas'] as $areaKey => $area) {
                foreach ($area['actions'] as $actionKey => $action) {
                    $key = $moduleKey . '.' . $areaKey . '.' . $actionKey;
                    $flat[$key] = $action + array(
                        'key' => $key,
                        'module' => $moduleKey,
                        'module_label' => $module['label'],
                        'area' => $areaKey,
                        'area_label' => $area['label'],
                        'sensitive' => !empty($action['sensitive']),
                    );
                }
            }
        }
        return $flat;
    }

    public static function find(string $permission): ?array {
        return self::all()[$permission] ?? null;
    }

    /** @param string[] $permissions @return string[] */
    public static function capabilitiesFor(array $permissions): array {
        $caps = array();
        $all = self::all();
        foreach ($permissions as $permission) {
            if (!isset($all[$permission])) continue;
            foreach ($all[$permission]['caps'] as $cap) $caps[] = $cap;
        }
        return array_values(array_unique($caps));
    }

    /** @return string[] Permissions whose canonical capability set is fully held. */
    public static function permissionsForCapabilities(array $caps): array {
        $caps = array_values(array_unique($caps));
        $out = array();
        foreach (self::all() as $key => $permission) {
            if ($permission['caps'] === array()) continue;
            if (array_diff($permission['caps'], $caps) === array()) $out[] = $key;
        }
        return $out;
    }

    /** @return string[] */
    public static function managedCapabilities(): array {
        $caps = array();
        foreach (self::all() as $permission) foreach ($permission['caps'] as $cap) $caps[] = $cap;
        return array_values(array_unique($caps));
    }

    /** @return array<string,string> */
    public static function moduleLabels(): array {
        $labels = array();
        foreach (self::modules() as $key => $module) $labels[$key] = $module['label'];
        return $labels;
    }

    /** Read-only permission aliases used by older work-area grants. */
    public static function legacyAreaPermission(string $area): array {
        return match ($area) {
            'overview' => array('overview.academy.view'),
            'teachers' => array('teachers.profiles.view', 'teachers.profiles.edit', 'teachers.onboarding.view'),
            'students' => array('students.directory.view', 'students.directory.edit'),
            'classes' => array('classes.introductory.view', 'classes.regular.view', 'classes.attendance.view'),
            'enrolment' => array('enrolment.trials.view', 'enrolment.enrolments.view', 'enrolment.terms.view'),
            'finance' => array('finance.payments.view', 'finance.pricing.view', 'finance.statements.view', 'finance.ledger.view'),
            'settings' => array('diagnostics.health.view'),
            default => array(),
        };
    }

    private static function module(string $label, string $description, array $areas): array {
        return array('label' => $label, 'description' => $description, 'areas' => $areas);
    }

    private static function area(string $label, string $description, array $actions): array {
        return array('label' => $label, 'description' => $description, 'actions' => $actions);
    }

    private static function action(string $label, string $description, array $caps, bool $sensitive = false): array {
        return array('label' => $label, 'description' => $description, 'caps' => $caps, 'sensitive' => $sensitive);
    }
}
