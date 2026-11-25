<?php
/**
 * Refusal coverage ratchet.
 *
 * Every command a service refuses throws a machine code. If the admin layer does not know that code,
 * the operator reads "The action could not be completed; no change was saved." instead of a sentence
 * that names the control — the class behind the opaque EWI- references (audit finding OPS-11).
 *
 * The domain can throw far more codes than the admin maps today, so this is a ratchet, not a demand:
 * the committed baseline records the codes that are still unmapped. The contract fails when a code is
 * added that is not in the baseline, so a new opaque refusal cannot ship, and it fails when a code is
 * fixed without updating the baseline, so the list shrinks deliberately and visibly.
 *
 * Update the baseline only after mapping the new codes in the admin layer:
 *   DZN_REFUSAL_BASELINE_UPDATE=1 php platform/tests/operator-refusal-coverage-contract.php
 */
$root = dirname(__DIR__);
$baselineFile = __DIR__ . '/fixtures/operator-refusal-unmapped.txt';

$phpFiles = static function (string $directory): array {
    if (!is_dir($directory)) throw new RuntimeException('Missing source directory: ' . $directory);
    $files = array();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') $files[] = $file->getPathname();
    }
    sort($files);
    return $files;
};

$refusals = array();
foreach (array('Core/Application', 'Integrations') as $area) {
    foreach ($phpFiles($root . '/src/' . $area) as $file) {
        $source = (string) file_get_contents($file);
        preg_match_all(
            "/(?:InvalidArgumentException|RuntimeException|LogicException|FinanceRefusalException|PersistenceException)\(\s*'([a-z][a-z0-9_]{4,60})'/",
            $source,
            $matches
        );
        foreach ($matches[1] as $code) {
            if (str_contains($code, '_')) $refusals[$code] = true;   // snake_case machine codes only
        }
    }
}
if (count($refusals) < 100) throw new RuntimeException('The refusal scan found too few codes to be meaningful');

$admin = '';
foreach ($phpFiles($root . '/src/Admin') as $file) $admin .= (string) file_get_contents($file);

$unmapped = array();
foreach (array_keys($refusals) as $code) {
    $quoted = str_contains($admin, "'" . $code . "'") || str_contains($admin, '"' . $code . '"');
    if (!$quoted) $unmapped[] = $code;
}
sort($unmapped);

if (getenv('DZN_REFUSAL_BASELINE_UPDATE') === '1') {
    if (!is_dir(dirname($baselineFile))) mkdir(dirname($baselineFile), 0775, true);
    file_put_contents($baselineFile, implode("\n", $unmapped) . "\n");
    echo 'operator-refusal-coverage-contract: baseline written with ' . count($unmapped) . " unmapped codes\n";
    exit(0);
}

$baseline = is_file($baselineFile)
    ? array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($baselineFile)))))
    : array();
if ($baseline === array()) throw new RuntimeException('Missing refusal baseline: ' . $baselineFile);

$added   = array_values(array_diff($unmapped, $baseline));
$removed = array_values(array_diff($baseline, $unmapped));
if ($added !== array()) {
    throw new RuntimeException(
        "New refusal codes have no operator sentence, and no baseline entry was expected:\n  "
        . implode("\n  ", array_slice($added, 0, 40))
        . "\nMap each one in the admin layer (EnrolmentWorkspaceController::message() or the owner of the\n"
        . 'command), or update the baseline deliberately with DZN_REFUSAL_BASELINE_UPDATE=1.'
    );
}
if ($removed !== array()) {
    throw new RuntimeException(
        "Refusal codes were mapped but the baseline still lists them:\n  "
        . implode("\n  ", array_slice($removed, 0, 40))
        . "\nRegenerate the baseline so the ratchet only moves forward:\n"
        . '  DZN_REFUSAL_BASELINE_UPDATE=1 php platform/tests/operator-refusal-coverage-contract.php'
    );
}

// The registry is the one place a sentence is added; the admin mappers must consult it, or the
// registry is decorative and the operator still reads the generic sentence.
$workspaceMapper = (string) file_get_contents($root . '/src/Admin/Controller/EnrolmentWorkspaceController.php');
$deskMapper = (string) file_get_contents($root . '/src/Admin/Controller/EnrolmentDeskController.php');
foreach (array('workspace' => $workspaceMapper, 'desk' => $deskMapper) as $name => $source) {
    if (!str_contains($source, 'OperatorRefusalCopy::sentence')) {
        throw new RuntimeException('The ' . $name . ' refusal mapper must consult OperatorRefusalCopy before its generic sentence.');
    }
}
if (!class_exists('Delnavazan\Platform\Admin\OperatorRefusalCopy')) {
    require_once $root . '/src/Admin/OperatorRefusalCopy.php';
}
if (\Delnavazan\Platform\Admin\OperatorRefusalCopy::sentence('teacher_unavailable') === null) {
    throw new RuntimeException('The operator refusal registry must answer for a mapped code.');
}

echo 'operator-refusal-coverage-contract: OK (' . count($refusals) . ' codes, '
    . (count($refusals) - count($unmapped)) . ' explained, ' . count($unmapped) . " baselined)\n";
