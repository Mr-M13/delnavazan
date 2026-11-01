<?php
/**
 * Pure Term replacement allocation rule.
 *
 * Confirmed replacement Lessons consume slots first; remaining slots are allocated once to
 * distinct eligible Student absence occurrences in deterministic order. Excess occurrences are not
 * allocated and therefore cannot create a Teacher task.
 */
require_once dirname(__DIR__) . '/src/Core/Application/TermReplacementAllocationRule.php';

use Delnavazan\Platform\Core\Application\TermReplacementAllocationRule;

$fail = static function (string $name, bool $ok): void { if (!$ok) throw new RuntimeException('Term replacement allocation unit failed: ' . $name); };

$two = TermReplacementAllocationRule::allocate(2, 0, array(10, 11));
$fail('two pending absences consume both slots', $two['pending'] === 2 && $two['available'] === 0 && $two['allocated_occurrence_keys'] === array(10, 11));

$oneUsed = TermReplacementAllocationRule::allocate(2, 1, array(10, 11, 12));
$fail('a confirmed replacement leaves one slot for the first pending absence', $oneUsed['pending'] === 1 && $oneUsed['available'] === 0 && $oneUsed['allocated_occurrence_keys'] === array(10));

$exhausted = TermReplacementAllocationRule::allocate(2, 2, array(10, 11));
$fail('confirmed replacements exhaust the Term before pending absences', $exhausted['pending'] === 0 && $exhausted['available'] === 0 && $exhausted['allocated_occurrence_keys'] === array());

$override = TermReplacementAllocationRule::allocate(3, 0, array(10, 11, 12));
$fail('an audited Term allowance override is respected', $override['pending'] === 3 && $override['available'] === 0);

$duplicates = TermReplacementAllocationRule::allocate(2, 0, array(10, 10, 11));
$fail('the same absence occurrence is never counted twice', $duplicates['pending'] === 2 && $duplicates['allocated_occurrence_keys'] === array(10, 11));

$ordinary = TermReplacementAllocationRule::allocate(2, 0, array());
$fail('an ordinary schedule change consumes no Student allowance', $ordinary['pending'] === 0 && $ordinary['available'] === 2);

$zero = TermReplacementAllocationRule::allocate(0, 0, array(10));
$fail('a zero allowance allocates nothing', $zero['pending'] === 0 && $zero['available'] === 0);

echo "Term replacement allocation unit passed\n";
