<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$coordination = file_get_contents($root . '/src/Admin/Controller/CoordinationCaseController.php');
$repository = file_get_contents($root . '/src/Core/Infrastructure/Repository/CoordinationCaseRepository.php');
$dashboard = file_get_contents($root . '/src/Admin/Controller/AcademyOperationsController.php');
foreach (['activeMatchAttentionForRequest', 'no_current_coverage', 'Review teacher availability', 'dzn_manage_teacher_availability', 'Review request and reassess matches', 'dzn_prepare_booking_request_matches', 'does not reserve capacity or select a teacher'] as $needle) {
    if (!str_contains($coordination . $repository, $needle)) throw new RuntimeException('Missing canonical coverage handoff contract: ' . $needle);
}
if (!str_contains($repository, "status IN ('open','acknowledged')") || !str_contains($dashboard, "match_attention_code === 'no_current_coverage'")) {
    throw new RuntimeException('Coverage handoff must depend on active attention and appear as the dashboard next step.');
}
if (str_contains($coordination, 'ExceptionService') || str_contains($coordination, 'resolveMatchAttention')) {
    throw new RuntimeException('Coordination must not dismiss or resolve matching exceptions.');
}
echo "Booking coverage operator handoff contract passed\n";
