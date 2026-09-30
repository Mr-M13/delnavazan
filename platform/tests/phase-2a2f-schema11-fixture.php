<?php
/** Representative Schema 11 state for the real Phase 2A.2-F migration test. */
if (
    getenv('DZN_PHASE_2A2F_RUNTIME_TEST') !== 'schema11_fixture'
    || !defined('WP_CLI')
    || !WP_CLI
    || !in_array(wp_get_environment_type(), array('local', 'development'), true)
) {
    fwrite(STDERR, "Phase 2A.2-F Schema 11 fixture refused.\n");
    exit(1);
}

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
if ((string) get_option('dzn_platform_schema_version') !== '11') {
    throw new RuntimeException('Schema 11 fixture requires exact Schema 11');
}
if ($wpdb->get_row("SHOW COLUMNS FROM {$p}student_principal_links LIKE 'link_sequence'")) {
    throw new RuntimeException('Schema 12 principal columns already exist');
}

$actor = get_current_user_id();
if ($actor < 1) {
    throw new RuntimeException('Schema 11 fixture actor unavailable');
}
$suffix = substr(hash('sha256', wp_generate_uuid4()), 0, 12);
$userA = wp_insert_user(array(
    'user_login' => 'dzn-2a2f-legacy-active-' . $suffix,
    'user_pass' => wp_generate_password(32, true, true),
    'user_email' => 'legacy-active-' . $suffix . '@phase-2a2f.invalid',
));
$userB = wp_insert_user(array(
    'user_login' => 'dzn-2a2f-legacy-revoked-' . $suffix,
    'user_pass' => wp_generate_password(32, true, true),
    'user_email' => 'legacy-revoked-' . $suffix . '@phase-2a2f.invalid',
));
if (is_wp_error($userA) || is_wp_error($userB)) {
    throw new RuntimeException('Schema 11 WordPress principal fixture failed');
}

$now = gmdate('Y-m-d H:i:s');
$studentIds = array();
foreach (array('Active', 'Revoked') as $label) {
    if (!$wpdb->insert($p . 'students', array(
        'uid' => strtoupper(substr(hash('sha256', $label . $suffix), 0, 26)),
        'reference_code' => null,
        'status' => 'active',
        'display_name' => 'Synthetic Schema 11 ' . $label,
        'created_at' => $now,
        'updated_at' => $now,
        'created_by' => $actor,
        'updated_by' => $actor,
    ))) {
        throw new RuntimeException('Schema 11 Student fixture failed');
    }
    $studentIds[] = (int) $wpdb->insert_id;
}

if (!$wpdb->insert($p . 'student_principal_links', array(
    'student_id' => $studentIds[0],
    'wordpress_user_id' => (int) $userA,
    'status' => 'active',
    'linked_at' => $now,
    'linked_by' => $actor,
))) {
    throw new RuntimeException('Legacy active principal fixture failed');
}
$activeLink = (int) $wpdb->insert_id;
if (!$wpdb->insert($p . 'student_principal_links', array(
    'student_id' => $studentIds[1],
    'wordpress_user_id' => (int) $userB,
    'status' => 'revoked',
    'linked_at' => $now,
    'linked_by' => $actor,
    'revoked_at' => $now,
    'revoked_by' => $actor,
    'reason_code' => 'legacy_review',
))) {
    throw new RuntimeException('Legacy revoked principal fixture failed');
}
$revokedLink = (int) $wpdb->insert_id;

$acceptanceUid = strtoupper(substr(hash('sha256', 'acceptance-' . $suffix), 0, 26));
$acceptanceKey = hash('sha256', 'command-' . $suffix);
if (!$wpdb->insert($p . 'proposal_acceptance_events', array(
    'uid' => $acceptanceUid,
    'reference_code' => null,
    'booking_request_id' => 900001,
    'coordination_case_id' => 900001,
    'proposal_family_id' => 900001,
    'proposal_option_id' => 900001,
    'proposal_version_id' => 900001,
    'family_uid' => strtoupper(substr(hash('sha256', 'family-' . $suffix), 0, 26)),
    'option_uid' => strtoupper(substr(hash('sha256', 'option-' . $suffix), 0, 26)),
    'version_uid' => strtoupper(substr(hash('sha256', 'version-' . $suffix), 0, 26)),
    'version_number' => 1,
    'version_fingerprint' => hash('sha256', 'version-fingerprint-' . $suffix),
    'event_kind' => 'accepted_pending_conditions',
    'prospective_subject_ref' => 'booking_request:900001',
    'accepting_subject_state' => 'authority_unresolved',
    'evidence_channel' => 'message_reference',
    'evidence_reference' => hash('sha256', 'evidence-' . $suffix),
    'evidence_at' => $now,
    'recorded_at' => $now,
    'recorded_by' => $actor,
    'command_key_digest' => $acceptanceKey,
    'command_payload_digest' => hash('sha256', 'payload-' . $suffix),
    'created_at' => $now,
    'created_by' => $actor,
))) {
    throw new RuntimeException('Schema 11 provisional acceptance fixture failed');
}

update_option('dzn_phase_2a2f_schema11_fixture', array(
    'active_link_id' => $activeLink,
    'revoked_link_id' => $revokedLink,
    'active_student_id' => $studentIds[0],
    'revoked_student_id' => $studentIds[1],
    'active_user_id' => (int) $userA,
    'revoked_user_id' => (int) $userB,
    'principal_count' => 2,
    'acceptance_uid' => $acceptanceUid,
    'acceptance_count' => 1,
), false);
echo "Phase 2A.2-F Schema 11 fixture passed\n";
