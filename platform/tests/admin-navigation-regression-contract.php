<?php
// Source-level contract for the single-entry Delnavazan wp-admin application.
$root = dirname(__DIR__);
$controllers = $root . '/src/Admin/Controller';
$menu = file_get_contents($controllers . '/Menu.php');
$shell = file_get_contents($controllers . '/AdminShellController.php');
$operations = file_get_contents($controllers . '/AcademyOperationsController.php');
$screen = file_get_contents($controllers . '/ScreenController.php');
$catalogue = file_get_contents($controllers . '/CommercialCatalogueController.php');
$readiness = file_get_contents($controllers . '/CoreDatasetReadinessController.php');

foreach ([$menu, $shell, $operations, $screen, $catalogue, $readiness] as $source) {
    if ($source === false) throw new RuntimeException('Unable to read unified admin shell sources.');
}

if (!str_contains($menu, "add_menu_page('Delnavazan', 'Delnavazan', 'read', self::ROOT_SLUG")
    || str_contains($menu, "['Bookings', 'dzn-bookings-menu'")
    || str_contains($menu, "['Finance', 'dzn-finance-menu'")
    || !str_contains($menu, "add_submenu_page(self::ROOT_SLUG, \$label, \$label, \$capability, \$slug, \$callback)")) {
    throw new RuntimeException('Delnavazan must have one visible WordPress sidebar entry and protected child routes.');
}

foreach ([
    "'academy' => ['Academy'", "'teachers' => ['Teachers'", "'students' => ['Students'",
    "'courses' => ['Courses'", "'enrolments_classes' => ['Enrolments / Classes'",
    "'finance' => ['Finance'", "'communications' => ['Communications'", "'settings' => ['Settings'",
] as $tab) {
    if (!str_contains($shell, $tab)) throw new RuntimeException('Missing application primary tab: ' . $tab);
}
if (!str_contains($shell, 'current_user_can($capability)')
    || !str_contains($shell, 'firstAvailableDestination')
    || !str_contains($shell, 'pruneWordPressSubmenu')
    || !str_contains($shell, '$submenu[Menu::ROOT_SLUG] = [];')
    || !str_contains($shell, 'Do not unregister pages: direct URLs must retain WordPress')) {
    throw new RuntimeException('The shell must capability-filter tabs and hide, not unregister, protected child routes.');
}

if (!str_contains($menu, "['Teachers', 'dzn_manage_teachers', 'dzn-teachers-menu'")
    || !str_contains($screen, 'teacherDirectory()')
    || !str_contains($screen, 'teacherInvitationPanel()')
    || !str_contains($screen, 'private static function profileFields(string $entity): array')
    || !str_contains($screen, "\$field!=='id'")) {
    throw new RuntimeException('Teachers/Students must retain the curated list/profile pattern and mature teacher behaviour.');
}

if (!str_contains($operations, "<h1>Academy</h1><p>Today’s work is collected here.")
    || !str_contains($operations, 'new AcademyJourneyReadService())->search(')
    || str_contains($operations, 'SELECT br.id request_id')
    || !str_contains($operations, '<th>Journey stage</th>')) {
    throw new RuntimeException('Academy must be today-oriented and reuse the capability-gated journey read model.');
}

if (!str_contains($menu, "['Stripe setup', PaymentExecutionController::CAPABILITY, 'dzn-stripe-settings'")
    || !str_contains($menu, "['Exceptions', 'dzn_manage_exceptions', 'dzn-exception'")
    || !str_contains($readiness, "'dzn-platform'")
    || !str_contains($catalogue, 'add_submenu_page(Menu::ROOT_SLUG')) {
    throw new RuntimeException('Advanced, diagnostics, lifecycle and catalogue destinations must remain protected application routes.');
}

echo "Unified Delnavazan admin shell source contract passed\n";
