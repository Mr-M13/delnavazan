<?php
/**
 * Clock-fixture ratchet.
 *
 * A suite that derives a fixture time from the wall clock passes until the run crosses a boundary:
 * a midnight, a month end, a DST change or a 24h response window. #768 and #769 fixed two instances;
 * the class keeps returning because nothing stops a new one.
 *
 * This is a ratchet, not a ban. The committed baseline lists the current wall-clock-derived fixture
 * lines. A new one fails the contract, and a removed one fails until the baseline is regenerated, so
 * the list only shrinks deliberately.
 *
 * Replace `strtotime('+N days')` and `new DateTimeImmutable('+N days')` with the shared fixture clock
 * when you touch one of these lines. Regenerate only after the list has genuinely shrunk:
 *   DZN_CLOCK_BASELINE_UPDATE=1 php platform/tests/operator-clock-fixture-contract.php
 */
$root = dirname(__DIR__);
$baselineFile = __DIR__ . '/fixtures/operator-clock-fixtures.txt';
$self = __FILE__;

// Built by concatenation so this file never matches itself.
$needles = array(
    "strtotime(" . "'+",
    'strtotime(' . '"+',
    "new DateTimeImmutable(" . "'+",
    'new DateTimeImmutable(' . '"+',
    "new DateTime(" . "'+",
    'new DateTime(' . '"+',
);

$entries = array();
foreach (array('tests', 'runtime') as $area) {
    $directory = $root . '/' . $area;
    if (!is_dir($directory)) continue;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        if (!in_array(strtolower($file->getExtension()), array('php', 'sh'), true)) continue;
        $path = $file->getPathname();
        if ($path === $self) continue;
        $relative = ltrim(str_replace($root, '', $path), '/');
        foreach (file($path) ?: array() as $line) {
            foreach ($needles as $needle) {
                if (str_contains($line, $needle)) {
                    // Key by file and the matched line's content, not its number: an unrelated edit
                    // elsewhere in the file must not churn the baseline. Changing the line itself is
                    // a new entry, which is exactly the deliberate act this ratchet wants to see.
                    $entries[] = $relative . ' | ' . trim($line);
                    break;
                }
            }
        }
    }
}
$entries = array_values(array_unique($entries));
sort($entries);
if (count($entries) < 50) throw new RuntimeException('The clock-fixture scan found too few lines to be meaningful');

if (getenv('DZN_CLOCK_BASELINE_UPDATE') === '1') {
    if (!is_dir(dirname($baselineFile))) mkdir(dirname($baselineFile), 0775, true);
    file_put_contents($baselineFile, implode("\n", $entries) . "\n");
    echo 'operator-clock-fixture-contract: baseline written with ' . count($entries) . " wall-clock fixture lines\n";
    exit(0);
}

$baseline = is_file($baselineFile)
    ? array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($baselineFile)))))
    : array();
if ($baseline === array()) throw new RuntimeException('Missing clock-fixture baseline: ' . $baselineFile);

$added = array_values(array_diff($entries, $baseline));
$removed = array_values(array_diff($baseline, $entries));
if ($added !== array()) {
    throw new RuntimeException(
        "New fixtures derive an absolute time from the wall clock:\n  "
        . implode("\n  ", array_slice($added, 0, 40))
        . "\nUse a fixed instant or the shared fixture clock instead of +/- offsets from now."
    );
}
if ($removed !== array()) {
    throw new RuntimeException(
        "Clock-derived fixture lines are gone but the baseline still lists them:\n  "
        . implode("\n  ", array_slice($removed, 0, 40))
        . "\nRegenerate the baseline: DZN_CLOCK_BASELINE_UPDATE=1 php platform/tests/operator-clock-fixture-contract.php"
    );
}

echo 'operator-clock-fixture-contract: OK (' . count($entries) . " baselined wall-clock fixture lines)\n";
