<?php
$root = dirname(__DIR__);
$files = array(
    'migration' => file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php'),
    'booking' => file_get_contents($root.'/src/Core/Infrastructure/Repository/BookingTeacherResponseRepository.php'),
    'onboarding' => file_get_contents($root.'/src/Core/Infrastructure/Repository/TeacherOnboardingRepository.php'),
    'workflow' => file_get_contents(dirname($root).'/.github/workflows/pr-runtime-gate.yml'),
);
$assert = static function(bool $condition,string $message):void {
    if(!$condition) throw new RuntimeException($message);
};
$assert(substr_count($files['booking'],'BINARY c.scope_snapshot = BINARY %s')===1,'booking readiness must use one byte-exact scope predicate');
$assert(substr_count($files['onboarding'],'BINARY c.scope_snapshot = BINARY %s')===1,'onboarding readiness must use one byte-exact scope predicate');
$assert(substr_count($files['migration'],'BINARY scope_snapshot <> BINARY %s')>=4,'migration must byte-compare connection and authorization scopes');
$assert(substr_count($files['migration'],'BINARY c.scope_snapshot <> BINARY %s')>=4,'migration must byte-compare joined credential and identity scopes');
$assert(str_contains($files['workflow'],'oauth-scope-exact-matching-db.php'),'PR gate must run the MariaDB exact-scope regression');
echo "OAuth scope exact-matching contracts passed.\n";
