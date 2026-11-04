<?php
/**
 * Retirement contract for the archived LiveKit canonical attendance adapter.
 *
 * The adapter source and schema verifier remain recoverable on the archive branch. On the Google
 * Meet branch it must not be installed or scheduled, and the active provider evidence path is the
 * official Meet participant-session reader.
 */
$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{
    $file=$root.'/'.$path;
    if(!is_file($file))throw new RuntimeException('Missing source file: '.$path);
    return (string)file_get_contents($file);
};
$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('LiveKit attendance retirement contract failed: '.$message);};
$plugin=$read('delnavazan-platform.php');
$meet=$read('src/Integrations/GoogleWorkspace/MeetAttendanceService.php');
$check(!str_contains($plugin,'LiveKitAttendanceSchema::maybeInstall'),'LiveKit attendance schema must not install on the Meet branch');
$check(!str_contains($plugin,'LiveKitAttendanceReconciliationWorker::register'),'LiveKit attendance worker must not be scheduled');
$check(!str_contains($plugin,'LiveKitTokenRestController'),'LiveKit token issuance must not be registered');
$check(is_file($root.'/src/Integrations/LiveKit/LiveKitAttendanceIntegration.php'),'LiveKit attendance source must remain archived');
$check(str_contains($meet,'listParticipantSessions'),'active attendance must use official Meet participant sessions');
$check(str_contains($meet,'verifiedAttendanceMapping'),'only a verified Phase-P identity mapping may settle attendance');
$check(str_contains($meet,'ingestProviderEvidence'),'Meet evidence must use the canonical Phase-P intake seam');
$check(!str_contains($meet,'CanonicalLessonAuthorityService')&&!str_contains($meet,'settleDelivered'),'Meet attendance must not complete or settle lessons directly');
$docs=$read('docs/LIVEKIT-CANONICAL-ATTENDANCE-ADAPTER.md');
$check(str_contains($docs,'NOT DEPLOYED'),'the archived adapter documentation must remain preserved');
echo "LiveKit attendance retirement contract passed\n";
