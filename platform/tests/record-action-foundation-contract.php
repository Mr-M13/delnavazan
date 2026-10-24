<?php
/** Source-level guard for the shared contextual record-action foundation. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($expect): void {
    foreach ($needles as $needle) $expect(str_contains($source, $needle), $message . ': ' . $needle);
};

$controller = $read('src/Admin/RecordAction/RecordActionController.php');
$request = $read('src/Admin/RecordAction/RecordActionRequest.php');
$token = $read('src/Admin/RecordAction/RecordActionToken.php');
$context = $read('src/Admin/RecordAction/RecordActionContext.php');
$resolver = $read('src/Admin/RecordAction/RecordActionResolver.php');
$registry = $read('src/Admin/RecordAction/RecordActionRegistry.php');
$renderer = $read('src/Admin/RecordAction/RecordActionRenderer.php');
$assets = $read('src/Admin/RecordAction/RecordActionAssets.php');
$screen = $read('src/Admin/Controller/ScreenController.php');
$plugin = $read('delnavazan-platform.php');

$contains($plugin, array('RecordActionController::register()'), 'The record-action endpoint must be registered by the platform bootstrap');
$contains($controller, array(
    "add_action('admin_post_dzn_record_action'",
    'RecordActionRequest::fromPost(',
    'current_user_can($capability)',
    'RecordActionRequest::fromPost',
    'new RecordActionResolver())->resolve($request[\'record_type\'], $request[\'record_id\'])',
    'new RecordActionContext(',
    'RecordActionRegistry::handle($key, $context, $input)',
    'wp_safe_redirect($url)',
    'no change was saved.',
), 'The endpoint must enforce actor, record capability, CSRF, signed context, server resolution and canonical dispatch');
$expect(!str_contains($controller, '$wpdb') && !str_contains($controller, 'INSERT ') && !str_contains($controller, 'UPDATE ') && !str_contains($controller, 'DELETE '), 'Record-action transport must not write to the database directly');

$contains($request, array(
    "if (\$userId < 1) throw new RecordActionException('authenticated_actor_required')",
    "if (!\$can((string) \$definition['capability']) || !\$can((string) \$definition['record_capability']))",
    "if (!\$nonce(\$actionKey)) throw new RecordActionException('nonce_failed')",
    'RecordActionToken::verify($contextToken, $context, $userId)',
    "throw new RecordActionException('context_invalid')",
), 'The request boundary must check capability before CSRF and CSRF/signature before dispatch');
$contains($controller, array('wp_verify_nonce(', "'dzn_record_action_' . \$actionKey", ') !== false'), 'CSRF must accept both valid WordPress nonce ticks and reject a failed verification');

$contains($token, array(
    'wp_generate_uuid4()',
    "hash_hmac('sha256'",
    "wp_salt('dzn_record_action')",
    'hash_equals(self::sign($context), $token)',
    'TTL_SECONDS',
    'FUTURE_SKEW_SECONDS',
    "'user_id' => \$userId",
    "'idempotency_key' => (string)",
), 'Action context must be signed, actor-bound, expiring and include generated idempotency metadata');
$contains($context, array('private int $recordId', 'private string $page', 'private string $idempotencyKey', 'function redirectUrl'), 'Resolved context must own the record id, page and generated key');
$contains($resolver, array(
    'READ_CAPABILITIES',
    "current_user_can(self::READ_CAPABILITIES[\$recordType])",
    "new CoreReadService()",
    "'record_not_found'",
), 'Record context must be re-resolved through a capability-gated read service');

$contains($registry, array(
    "'teacher-archive'",
    "'teacher-restore'",
    "'record_capability' => 'dzn_manage_teachers'",
    "'capability' => 'dzn_manage_teachers'",
    "'require_confirmation' => true",
    "'consequence' =>",
    'ArchiveService',
    "->archive('teacher', \$context->recordId())",
    "->restore('teacher', \$context->recordId())",
), 'The pilot actions must call the existing canonical ArchiveService and declare capability, confirmation and consequence');
$expect(!str_contains($registry, '$wpdb') && !str_contains($registry, 'INSERT ') && !str_contains($registry, 'UPDATE ') && !str_contains($registry, 'DELETE '), 'Record-action handlers must not bypass the canonical service');

$contains($renderer, array(
    'RecordActionToken::issue(',
    'current_user_can((string) $definition[\'capability\'])',
    'data-dzn-record-action-open',
    '<dialog ',
    'aria-labelledby',
    'aria-describedby',
    'wp_nonce_field(\'dzn_record_action_\' . $key)',
    'name="context_token"',
    'name="confirm" value="1" required',
    'dzn-record-action-consequence',
    'showModal',
    'renderNotices',
), 'Focused action forms must be dialog-based, accessible, CSRF-protected and consequence-aware');
$contains($assets, array(
    '.dzn-record-action-dialog',
    'max-inline-size',
    'max-block-size',
    'overflow:auto',
    'margin-inline-end',
    'border-inline-start',
    'text-align:start',
    'inline-size:100%',
    '@media(max-width:782px)',
), 'Record-action styles must use logical properties and a mobile layout');
$expect(!str_contains($assets, 'margin-left') && !str_contains($assets, 'margin-right') && !str_contains($assets, 'padding-left') && !str_contains($assets, 'padding-right'), 'Record-action styles must not use physical left/right properties');

$contains($screen, array(
    'RecordActionRenderer::renderNotices()',
    "RecordActionRenderer::render('teacher', \$record, 'dzn-teachers-menu')",
    "if(\$entity==='teacher')throw new \\InvalidArgumentException('Use the authorised Teacher record action')",
), 'The teacher record must use the shared renderer and reject the legacy parallel mutation path');
$expect(!str_contains($screen, 'teacherArchiveActions('), 'The teacher archive action must not remain a parallel form');
$expect(str_contains($screen, "archiveActions('student'"), 'The unresolved student status model must keep its existing authorised action until its own domain PR');

echo "Record action foundation contract passed\n";
