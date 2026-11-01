<?php
/** Pure Students directory input contract. */
require_once dirname(__DIR__) . '/src/Core/Application/StudentWorkspaceFilters.php';

use Delnavazan\Platform\Core\Application\StudentWorkspaceFilters;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

$default = StudentWorkspaceFilters::normalize(array());
$assert($default['status'] === 'active', 'The first page must prioritise current active students.');
$assert($default['sort'] === 'recent', 'The default sort must be recent activity.');
$assert($default['direction'] === 'desc', 'Recent activity must default to newest first.');
$assert($default['page'] === 1, 'The default page must be the first page.');
$assert($default['per_page'] === 20, 'The directory must load only one page of twenty students.');

$custom = StudentWorkspaceFilters::normalize(array(
    'q' => '  +61 (0) 400 123 456 ',
    'student_status' => 'payment_due',
    'student_sort' => 'next_lesson',
    'student_order' => 'asc',
    'student_page' => '3',
    's' => 'ignored when q exists',
));
$assert($custom['query'] === '+61 (0) 400 123 456', 'Search input must be trimmed without losing meaningful phone characters.');
$assert($custom['status'] === 'payment_due', 'Canonical payment filter must survive normalization.');
$assert($custom['sort'] === 'next_lesson' && $custom['direction'] === 'asc', 'Valid sorting must survive normalization.');
$assert($custom['page'] === 3, 'Page must survive normalization.');

$legacySearch = StudentWorkspaceFilters::normalize(array('s' => 'DZN-STU-42'));
$assert($legacySearch['query'] === 'DZN-STU-42', 'Legacy s= search links must keep working.');

$invalid = StudentWorkspaceFilters::normalize(array('student_status' => 'made_up', 'student_sort' => 'unsafe', 'student_order' => 'sideways', 'student_page' => '-9'));
$assert($invalid['status'] === 'active' && $invalid['sort'] === 'recent', 'Unknown filters must fall back to canonical defaults.');
$assert($invalid['direction'] === 'desc', 'Unknown direction must fall back to the sort default.');
$assert($invalid['page'] === 1, 'Negative pages must clamp to the first page.');

$assert(StudentWorkspaceFilters::phoneDigits('+61 (0) 400-123') === '610400123', 'Phone normalization must compare digits only.');
$assert(StudentWorkspaceFilters::isPhoneLike('+61 400 123'), 'A formatted phone must be recognised as phone-like.');
$assert(!StudentWorkspaceFilters::isPhoneLike('Sara Ahmadi'), 'A name must not be treated as a phone number.');

$assert(StudentWorkspaceFilters::directoryStatus('archived', true, false, true) === 'Archived', 'Archived always wins.');
$assert(StudentWorkspaceFilters::directoryStatus('inactive', false, true, true) === 'Inactive', 'Inactive is a canonical operational state.');
$assert(StudentWorkspaceFilters::directoryStatus('active', false, true, false) === 'Introductory', 'A non-expired intro request without an enrolment is introductory.');
$assert(StudentWorkspaceFilters::directoryStatus('active', false, false, true) === 'Active', 'An active enrolment is active.');
$assert(StudentWorkspaceFilters::directoryStatus('active', false, false, false) === 'Awaiting enrolment', 'An active student without an enrolment or intro is awaiting enrolment.');

$statusLabels = StudentWorkspaceFilters::statusLabels();
foreach (array('All students','Active','Introductory','Awaiting enrolment','Renewal due','Payment due','Inactive','Archived') as $label) {
    $assert(in_array($label, $statusLabels, true), 'Missing filter label: ' . $label);
}

echo "student-workspace-filters-unit: pass\n";
