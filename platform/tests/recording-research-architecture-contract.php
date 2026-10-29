<?php
/** Guard the approved recording/research release boundary while implementation is pending. */
$root = dirname(__DIR__);
$document = (string) file_get_contents($root . '/docs/RECORDING-RESEARCH-ARCHITECTURE-AND-DECISION-LOG.md');
$migrator = (string) file_get_contents($root . '/src/Core/Infrastructure/Migration/Migrator.php');
$plugin = (string) file_get_contents($root . '/delnavazan-platform.php');
$failures = array();
$need = array(
    'capture and research export are disabled',
    'PR #380 is a rejected design reference',
    'Browser-supplied teacher, student, guardian',
    'Pre-capture gate',
    'never a single grant',
    'Recording authorisation never satisfies research eligibility',
    'Provider acknowledgement',
    'Decisions requiring owner and legal review',
);
foreach ($need as $text) if (!str_contains($document, $text)) $failures[] = 'missing architecture decision: ' . $text;
if (str_contains($migrator, '053_recording_consent_retention_deletion_authority')) $failures[] = 'main must not adopt rejected PR #380 migration 053';
if (str_contains($plugin, 'ContractRecordingProvider')) $failures[] = 'a contract recording provider must not be registered in production';
if ($failures) throw new RuntimeException("Recording/research architecture contract failed:\n- " . implode("\n- ", $failures));
echo "Recording/research architecture contract passed\n";
