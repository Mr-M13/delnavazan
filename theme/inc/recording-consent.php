<?php
/**
 * Recording consent read-model shaping (inert preview).
 * Presentation only; real command calls are deferred to a future adapter.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function dzn_theme_recording_consent_preview_model( array $args = array() ): array {
    return array(
        'purpose_code' => 'training',
        'consent_text_version' => 'v1',
        'retention_days_raw_audio' => 90,
        'retention_days_mixed_recording' => 365,
        'retention_days_metadata' => 1825,
        'checkboxes' => array(
            'consent_recording' => false,
            'consent_students' => false,
            'hardware_ack' => false,
        ),
        'continue_enabled' => false,
    );
}
