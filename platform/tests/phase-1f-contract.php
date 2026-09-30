<?php
// Source/package-readiness contract only. Runtime evidence belongs in the
// controlled WordPress/PHP/MySQL matrix, not in this local check.
$root = dirname(__DIR__);
$runtime = file_get_contents("{$root}/docs/PHASE-1F-RUNTIME-VALIDATION.md");
foreach ([
    'Package, prerequisites, and rollback', 'all ten tables are ready',
    '001_initial_core_schema', 'Controlled exception harness',
    'PASS/FAIL/BLOCKED', 'Do not uninstall',
] as $fragment) {
    if (!str_contains($runtime, $fragment)) throw new RuntimeException("Phase 1F runbook missing: {$fragment}");
}

$bootstrap = file_get_contents("{$root}/delnavazan-platform.php");
foreach (['Requires PHP: 8.1', 'register_activation_hook', 'spl_autoload_register', 'DZN_PLATFORM_PHASE_1F_NONCE_DIAGNOSTICS', 'DZN_PLATFORM_BUILD_ID', 'NonceLifecycleDiagnostic::register()'] as $fragment) {
    if (!str_contains($bootstrap, $fragment)) throw new RuntimeException("Phase 1F bootstrap rule missing: {$fragment}");
}
if (!preg_match("/DZN_PLATFORM_BUILD_ID', '[a-z0-9.-]+'/", $bootstrap)) throw new RuntimeException('Phase 1F build identity must remain explicit');

$screen = file_get_contents("{$root}/src/Admin/Controller/ScreenController.php");
foreach ([
    'public static function handlePost(string $screen)',
    "NonceLifecycleDiagnostic::logStage('mutation_load_hook')",
    'logNonceDiagnostic($action, $post)',
    'wp_verify_nonce($post[\'_wpnonce\'], self::nonceAction($action))',
    'check_admin_referer(self::nonceAction($action))',
    'wp_nonce_field(self::nonceAction($action))',
    "NonceLifecycleDiagnostic::logStage('nonce_verified')",
    "NonceLifecycleDiagnostic::logStage('mutation_complete')",
    "NonceLifecycleDiagnostic::logStage('redirect_enter')",
    'if (wp_safe_redirect($url)) exit',
    "' · Build ' . esc_html(DZN_PLATFORM_BUILD_ID)",
    "'nonce_present'", "'nonce_scalar'", "'nonce_verify'", "'user_id'",
    "'capability_allowed'", "'page'", "'method'",
] as $fragment) {
    if (!str_contains($screen, $fragment)) throw new RuntimeException("Phase 1F nonce diagnostic missing: {$fragment}");
}
foreach (['$_COOKIE', 'HTTP_AUTHORIZATION', 'AUTH_SALT', "'nonce_value'"] as $forbidden) {
    if (str_contains($screen, $forbidden)) throw new RuntimeException("Phase 1F nonce diagnostic exposes forbidden data: {$forbidden}");
}
if (str_contains($screen, 'self::handleMutation(); self::renderMessages()')) {
    throw new RuntimeException('Phase 1F mutation handling must not run in the post-header render callback');
}

$menu = file_get_contents("{$root}/src/Admin/Controller/Menu.php");
foreach (["add_action('load-' . \$hook", 'ScreenController::handlePost($screen)', 'before admin-header.php'] as $fragment) {
    if (!str_contains($menu, $fragment)) throw new RuntimeException("Phase 1F pre-header mutation hook missing: {$fragment}");
}

$creator = file_get_contents("{$root}/src/Core/Application/Creator.php");
$repository = file_get_contents("{$root}/src/Core/Infrastructure/Repository/BaseRepository.php");
foreach (['creationAuditValues', 'array_replace($data,$repo->creationAuditValues'] as $fragment) {
    if (!str_contains($creator, $fragment)) throw new RuntimeException("Phase 1F schema-aware creation audit missing: {$fragment}");
}
foreach (['creationAuditValues', 'hasColumn', 'DESCRIBE {$this->table}', 'Unable to inspect persistence schema'] as $fragment) {
    if (!str_contains($repository, $fragment)) throw new RuntimeException("Phase 1F repository audit compatibility missing: {$fragment}");
}
$schema = file_get_contents("{$root}/src/Core/Infrastructure/Migration/Migrator.php");
preg_match('/CREATE TABLE \\{\\$p\\}instruments \(([^\"]+)/', $schema, $instrumentDefinition);
if (($instrumentDefinition[1] ?? '') === '' || str_contains($instrumentDefinition[1], 'created_by') || str_contains($instrumentDefinition[1], 'updated_by')) {
    throw new RuntimeException('Phase 1F Instrument schema/audit contract changed unexpectedly');
}

$lesson = file_get_contents("{$root}/src/Core/Application/LessonService.php");
foreach ([
    "isset(\$d['enrolment_id'])?Normalizer::id(\$d['enrolment_id'],false):null",
    "isset(\$d['term_id'])?Normalizer::id(\$d['term_id'],false):null",
    "Normalizer::id(\$d['replacement_for_lesson_id']??null,false)",
    "\$d['enrolment_id']=\$enrolment",
    "\$d['term_id']=\$term",
    "\$d['replacement_for_lesson_id']=\$original",
    "if(\$type!=='replacement'&&\$original!==null)",
    'Replacement Lesson requires original Lesson',
] as $fragment) {
    if (!str_contains($lesson, $fragment)) throw new RuntimeException("Phase 1F optional Lesson relationship normalization missing: {$fragment}");
}
$normalizer = file_get_contents("{$root}/src/Core/Application/Normalizer.php");
if (!str_contains($normalizer, 'return false===$v?null:(int)$v;')) {
    throw new RuntimeException('Phase 1F optional Lesson relationship IDs must normalize invalid or blank inputs to null');
}
$archive = file_get_contents("{$root}/src/Core/Application/ArchiveService.php");
foreach ([
    'optionalLessonRelationshipId',
    "\$value === 0 || \$value === '0'",
    'Lesson relationship identifier is invalid',
    'replacementOriginalId',
] as $fragment) {
    if (!str_contains($archive, $fragment)) throw new RuntimeException("Phase 1F legacy optional Lesson relationship restore guard missing: {$fragment}");
}

$lifecycle = file_get_contents("{$root}/src/Admin/Diagnostic/NonceLifecycleDiagnostic.php");
foreach ([
    'plugin_bootstrap', 'plugins_loaded', 'admin_init_early', 'admin_menu_early',
    'current_screen', 'admin_head', 'load_page', 'mutation_load_hook',
    'nonce_verified', 'mutation_complete', 'redirect_enter', 'redirect_failed',
    'submenu_callback',
    'watchLoadHook',
    "'stage'", "'action'", "'nonce_present'", "'nonce_scalar'", "'nonce_verify'",
    "'user_id'", "'capability_allowed'", "'page'", "'method'",
    "'referer_field_present'", "'core_action_present'", "'core_action2_present'",
] as $fragment) {
    if (!str_contains($lifecycle . $screen, $fragment)) throw new RuntimeException("Phase 1F lifecycle diagnostic missing: {$fragment}");
}
foreach (['$_COOKIE', 'HTTP_AUTHORIZATION', 'AUTH_SALT', "'nonce_value'", "'post_body'", "'password'", "'credential'"] as $forbidden) {
    if (str_contains($lifecycle, $forbidden)) throw new RuntimeException("Phase 1F lifecycle diagnostic exposes forbidden data: {$forbidden}");
}

$uninstall = file_get_contents("{$root}/uninstall.php");
if (!str_contains($uninstall, 'preserved on uninstall') || str_contains($uninstall, 'DROP TABLE')) {
    throw new RuntimeException('Phase 1F uninstall preservation rule missing');
}

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/src")) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $source = file_get_contents($file->getPathname());
    if (preg_match('/\b(?:amelia_|wp_amelia)/i', $source) || str_contains($source, 'add_rest_route') || str_contains($source, 'wp_ajax_')) {
        throw new RuntimeException('Phase 1F Core source has forbidden runtime dependency: ' . $file->getFilename());
    }
}

$runtimeFiles = [
    "{$root}/delnavazan-platform.php",
    "{$root}/uninstall.php",
];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/src")) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $runtimeFiles[] = $file->getPathname();
    }
}

// These are source-code contexts, not string literals: an autoloader prefix
// such as 'Delnavazan\\Platform\\' is intentional and must remain untouched.
$doubledNamespaceContexts = [
    'new \\\\',
    'extends \\\\',
    'implements \\\\',
    'instanceof \\\\',
    'catch (\\\\',
    'use \\\\',
];
foreach ($runtimeFiles as $runtimeFile) {
    $source = file_get_contents($runtimeFile);
    foreach ($doubledNamespaceContexts as $needle) {
        if (str_contains($source, $needle)) {
            throw new RuntimeException('Phase 1F malformed doubled namespace separator: ' . $runtimeFile);
        }
    }
    foreach (['\\\\RuntimeException', '\\\\InvalidArgumentException'] as $needle) {
        if (str_contains($source, $needle)) {
            throw new RuntimeException('Phase 1F malformed global exception reference: ' . $runtimeFile);
        }
    }
}

echo "Phase 1F source/package-readiness contract passed (not runtime evidence)\n";
