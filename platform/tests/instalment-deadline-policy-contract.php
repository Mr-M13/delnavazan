<?php
/**
 * B2b source contract: the second-instalment deadline is an explicit administrator policy,
 * is unset by default, is exactly 168 hours before Lesson 7, and is never taken from a client.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $file = $root . '/' . $path;
    if (!is_file($file)) throw new RuntimeException('Missing source file: ' . $path);
    return (string) file_get_contents($file);
};
$contains = static function (string $source, array $needles, string $message): void {
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) throw new RuntimeException($message . ': ' . $needle);
    }
};

$policy = $read('src/Core/Application/InstalmentDeadlinePolicy.php');
$continuation = $read('src/Core/Application/PostIntroContinuationService.php');
$repository = $read('src/Core/Infrastructure/Repository/PostIntroRepository.php');
$controller = $read('src/Portals/PostIntroContinuationController.php');
$migration = $read('src/Core/Infrastructure/Migration/Migrator.php');
$financeDocs = $read('docs/FINANCE-ADMINISTRATION.md');
$registry = $read('docs/COMMERCIAL-POLICY-REGISTRY.md');

$contains($policy, array(
    "POLICY_KEY = 'INSTALMENT_DUE_DATE_POLICY'",
    'LESSON_SEQUENCE = 7',
    'LEAD_SECONDS = 604800',
    "APPROVED_WEEKS = 1",
    'latestEffectivePolicy',
    'CommercialValidator::policyValid',
    'policy_value === null',
    'continuation_slot_wall_clock_invalid',
    'intervalsFor($pattern, self::LESSON_SEQUENCE)',
    "return self::unset();",
    'instalment_due_date_policy_invalid',
), 'The deadline policy must be explicit, unset by default and derived from Lesson 7');

$contains($continuation, array(
    "if (\$planKind === 'two_instalments')",
    '(new InstalmentDeadlinePolicy())->forPattern',
    "'instalment_due_at' => \$instalmentDueAt",
    "'instalment_due_date_policy_version' => \$instalmentPolicyVersion",
    'instalmentDeadlineForOffer',
    "\$changes['policy_version'] = \$deadline['policy_version']",
    "\$changes = array('offer_id' =>",
    'instalment_due_date_policy_invalid',
), 'Offer issuance must apply the configured policy only to a two-instalment plan');
$contains($repository, array(
    'instalmentDeadlineForOffer',
    'obligation_sequence=2',
    'INSTALMENT_DUE_DATE_POLICY',
    "return array('due_at' => null, 'policy_version' => null);",
), 'The plan choice must record the exact deadline and policy version, never a version without a due instant');
$contains($read('src/Core/Infrastructure/Repository/CommercialAuthorityRepository.php'), array('latestEffectivePolicy', "effective_from<=%s", "status='active'"), 'Policy resolution must fall back to the latest version that is actually effective');

// The deadline is authoritative server-side. A browser cannot supply or override it.
if (preg_match('/instalment_due_at\s*=\s*[^;]*\$_POST/is', $continuation . $controller) === 1) {
    throw new RuntimeException('The instalment deadline must never be accepted from the browser');
}
if (str_contains($controller, 'instalment_due_at')) {
    throw new RuntimeException('The student continuation controller must not accept a due instant');
}

// No migration seeds or rewrites a deadline; B2b is configuration over the B2a schema.
if (str_contains($migration, 'INSTALMENT_DUE_DATE_POLICY')) {
    throw new RuntimeException('B2b must not seed a policy value in a migration');
}
$contains($financeDocs, array('168 hours before Lesson 7', 'INSTALMENT_DUE_DATE_POLICY'), 'Finance documentation must describe the unset-by-default policy');
$contains($registry, array('INSTALMENT_DUE_DATE_POLICY', '168 hours before Lesson 7'), 'The policy registry must name the approved B2b deadline');

echo "instalment-deadline-policy-contract: OK\n";
