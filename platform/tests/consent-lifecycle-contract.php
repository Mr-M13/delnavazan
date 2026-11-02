<?php
/**
 * Static and catalogue contract for the consent lifecycle implementation.
 *
 * This guard does not prove runtime behaviour. It proves the required authority is present, that
 * capture/export remain disabled, that no administrator write path creates consent, and that the
 * Settings → Policies surface is wired into the existing administration shell.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$migrator = $read('src/Core/Infrastructure/Migration/Migrator.php');
$authority = $read('src/Core/Application/RecordingDecisionAuthorityService.php');
$gate = $read('src/Core/Application/RecordingAuthorisationGate.php');
$dataset = $read('src/Core/Application/MlDatasetEligibilityService.php');
$withdrawal = $read('src/Core/Application/ConsentWithdrawalService.php');
$worker = $read('src/Core/Application/ConsentLifecycleWorker.php');
$readService = $read('src/Core/Application/ConsentOperationsReadService.php');
$controller = $read('src/Admin/Controller/PoliciesController.php');
$shell = $read('src/Admin/Controller/AdminShellController.php');
$menu = $read('src/Admin/Controller/Menu.php');
$plugin = $read('../platform/delnavazan-platform.php');
$student = $read('../theme/template-parts/portal/recording-choices.php');
$teacher = $read('../theme/template-parts/teacher-portal/recording-terms.php');
$livekit = $read('src/Integrations/LiveKit/LiveKitClassroomAuthorizer.php');
$fail = array();
$need = static function(bool $ok, string $message) use (&$fail): void { if (!$ok) $fail[] = $message; };

/* Migration and evidence model. */
foreach (array(
    '065_consent_lifecycle_authority', 'consent_purposes', 'consent_recording_authorisations',
    'consent_dataset_items', 'consent_processing_jobs', 'consent_processing_events',
    'guardian_authority_grant_id', 'consent_statement_digest', 'collection_channel', 'workflow_code',
    'scope_code', 'supersedes_decision_id', 'withdrawal_of_decision_id', 'effective_from', 'effective_until',
    'verify_consent_lifecycle_authority_schema',
) as $needle) $need($needle !== '' && str_contains($migrator, $needle), 'Migration/evidence missing: ' . $needle);
$need(str_contains($migrator, 'INSERT IGNORE INTO {$p}consent_purposes'), 'Purpose registry must be seeded without overwriting existing definitions');
$need(!str_contains($migrator, 'UPDATE {\$p}recording_decisions SET decision_code'), 'Migration must never backfill or rewrite a decision');

/* Authority: independent purposes and guardian self-service. */
foreach (array('TEACHER_RECORDING', 'TEACHER_RESEARCH', 'STUDENT_RECORDING', 'STUDENT_RESEARCH', 'PRIVACY_TERMS',
    'recordTeacherChoice', 'recordOwnStudentChoice', 'recordGuardianChoiceForResolvedStudent',
    'teacherConsentState', 'studentDecisionState', 'guardianAuthorityState') as $needle) {
    $need(str_contains($authority, $needle), 'Decision authority missing: ' . $needle);
}
$need(str_contains($authority, 'CAPTURE_ENABLED=false'), 'Capture must remain disabled');
$need(str_contains($authority, 'RESEARCH_EXPORT_ENABLED=false'), 'Research export must remain disabled');
$need(str_contains($authority, "g.authority_scope=%s AND g.state='active'"), 'Guardian authority must be revalidated from the canonical grant');
$need(str_contains($authority, "'guardian_authority_grant_id'=>\$grantId"), 'Guardian decisions must cite the authority grant');
$need(!preg_match('/\b(?:UPDATE|DELETE)\b[^;]*recording_decision/i', $authority), 'Decision evidence must stay append-only');
$need(!str_contains($authority, "\$_POST['student_id']") && !str_contains($authority, "\$_POST['teacher_id']"), 'The authority must not accept browser-supplied identity');

/* Gates. */
$need(str_contains($gate, 'assertCaptureAuthorised'), 'A capture caller must have an explicit fail-closed seam');
$need(str_contains($gate, 'assertProviderSupported'), 'A provider that cannot enforce consent must be refused');
$need(str_contains($gate, 'PROVIDER_GOOGLE_MEET'), 'Google Meet recording must be explicitly identified and disabled');
$need(str_contains($gate, 'recording_not_authorised:'), 'Refusal reason must be stable and non-sensitive');
$need(str_contains($gate, 'guardianGrantActive'), 'The gate must revalidate guardian authority');
$need(str_contains($livekit, 'recording_authorised'), 'LiveKit must expose the consent gate for recording');
$need(str_contains($livekit, 'livekit_recording_not_authorised'), 'LiveKit must refuse unauthorised recording');

/* Dataset manifest and withdrawal. */
foreach (array('source_recording_id', 'content_digest', 'consent_evidence_digest', 'eligibility_result',
    'processing_lineage', 'storage_reference', 'pseudonym_subject', 'exclusion_state', 'exportManifest') as $needle) {
    $need(str_contains($dataset, $needle), 'Dataset authority missing: ' . $needle);
}
$need(str_contains($dataset, 'assertEligible') && str_contains($dataset, 'ml_dataset_ingestion_refused'), 'Dataset ingestion must fail closed');
$need(str_contains($dataset, "current_user_can(self::EXPORT_CAPABILITY)") || str_contains($dataset, "current_user_can(self::EXPORT_CAPABILITY)&&"), 'Dataset export must be capability-gated');
$need(str_contains($withdrawal, 'queueJob'), 'Withdrawal must create durable downstream jobs');
$need(str_contains($withdrawal, 'dataset_exclusion') && str_contains($withdrawal, 'recording_disposition'), 'Withdrawal must cover dataset and recording dispositions');
$need(str_contains($worker, 'FOR UPDATE'), 'Worker claim must be serialised');
$need(str_contains($worker, 'max_attempts') && str_contains($worker, 'lease_token_digest'), 'Worker must have bounded retries and a lease');
$need(str_contains($worker, 'no_capture_assets'), 'Worker must report truthful recording disposition when no asset exists');

/* Administration. */
$need(str_contains($plugin, 'PoliciesController::register()'), 'The plugin must register Policies');
$need(str_contains($menu, "'dzn-policies'"), 'Policies must be a registered Settings route');
$need(str_contains($shell, "'policies' => ['Policies'"), 'Policies must be a visible Settings section');
$need(str_contains($shell, "'dzn-policies' => 'settings'"), 'Policies must belong to the existing Settings shell');
$need(str_contains($shell, "'dzn-policies' => 'dzn_manage_consent_policies'"), 'Policies route must be capability-gated');
$need(str_contains($controller, 'ConsentOperationsReadService'), 'Policies must read authoritative counts');
$need(str_contains($controller, 'dzn_recording_notice_command'), 'Publishing must reuse the canonical publication authority');
foreach (array('teachers_awaiting', 'students_awaiting', 'guardian_attention', 'recording_authorised_lessons',
    'ml_eligible_recordings', 'blocked_or_quarantined', 'pending_withdrawals', 'failed_jobs') as $needle) {
    $need(str_contains($readService, $needle), 'Indicator missing: ' . $needle);
}
$need(!str_contains($controller, 'wp_insert_post') && !str_contains($controller, 'recording_decisions'), 'Policies must not write a consent decision directly');
$need(str_contains($controller, 'check_admin_referer'), 'Every administrative action must verify a nonce');
$need(str_contains($controller, 'current_user_can(self::CAPABILITY)'), 'Every administrative action must re-check capability');
$need(!str_contains($controller, 'wp_remote_'), 'Policies must not bypass the canonical notification transport');
$need(str_contains($controller, 'ConsentNotificationService'), 'Reminders must use the canonical notification producer');

/* UI: independent choices, no preselected enrolment option, guardian path, RTL. */
$need(str_contains($student, 'purpose" value="lesson_recording"'), 'Student recording form must be separate');
$need(str_contains($student, 'purpose" value="research_model_training"'), 'Student research form must be separate');
$need(str_contains($student, "'guardian' === \$authority"), 'Minor students must see the guardian-authority state');
$need(str_contains($student, 'privacy_terms'), 'Privacy acknowledgement must be separately evidenced');
$need(str_contains($student, 'dir="rtl"'), 'The public consent surface must remain RTL');
$need(!preg_match('/name="choice" value="allow"\s+checked/', $student), 'Enrolment must not preselect recording');
$need(!preg_match('/name="choice" value="opt_in"\s+checked/', $student), 'Enrolment must not preselect research');
$need(str_contains($teacher, 'teacher_lesson_recording'), 'Teacher recording must be a separate purpose');
$need(str_contains($teacher, 'teacher_research_model_training'), 'Teacher research must be a separate purpose');
$need(str_contains($teacher, 'required>'), 'Teacher optional choices must require an affirmative action');
$need(!preg_match('/name="choice" value="allow"\s+checked/', $teacher), 'Teacher choices must not be preselected');

/* Notification catalogue events. */
require_once $root . '/src/Integrations/Notifications/NotificationCatalogue.php';
$catalogue = \Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue::events();
foreach (array(
    'consent.teacher_required.teacher', 'consent.student_required.student', 'consent.guardian_required.guardian',
    'consent.recorded.student', 'consent.policy_updated.student', 'consent.withdrawal_confirmed.student',
    'consent.admin_attention.admin',
) as $key) $need(isset($catalogue[$key]), 'Notification catalogue missing: ' . $key);
foreach ($catalogue as $key => $event) {
    if (!str_starts_with((string) $key, 'consent.')) continue;
    $need(!preg_match('/notice_text_digest|decision_id|guardian_actor|recording_url|content_digest/i', json_encode($event)), 'Consent notification must not carry evidence or media references: ' . $key);
}

if ($fail) {
    echo "Consent lifecycle contract failed:\n- " . implode("\n- ", $fail) . "\n";
    exit(1);
}
echo "Consent lifecycle contract passed\n";
