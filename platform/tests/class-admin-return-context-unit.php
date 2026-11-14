<?php
/** The admin-post redirect must return a class action to the open record. */
namespace Delnavazan\Platform\Admin\Controller;

function admin_url(string $path): string { return 'https://academy.example/wp-admin/' . $path; }
function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args); }
function wp_unslash(string $value): string { return stripslashes($value); }
function absint($value): int { return abs((int) $value); }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }

require_once __DIR__ . '/../src/Admin/Controller/EnrolmentWorkspaceController.php';

$method = new \ReflectionMethod(EnrolmentWorkspaceController::class, 'returnUrl');
$method->setAccessible(true);
$_GET = array();
$_POST = array('action' => EnrolmentWorkspaceController::ACTION, 'request_id' => '42', 'dzn_modal' => '1');
$requestUrl = $method->invoke(null);
if (!str_contains($requestUrl, 'request_id=42') || !str_contains($requestUrl, 'dzn_modal=1') || str_contains($requestUrl, 'action=')) throw new \RuntimeException('Intro action lost its record or modal context.');

$_POST = array('action' => EnrolmentWorkspaceController::ACTION, 'class_id' => '73', 'dzn_modal' => '1');
$classUrl = $method->invoke(null);
if (!str_contains($classUrl, 'class_id=73') || !str_contains($classUrl, 'dzn_modal=1')) throw new \RuntimeException('Class action lost its record or modal context.');

$_GET = array('page' => EnrolmentWorkspaceController::PAGE, 'attention' => '1');
$_POST = array('action' => EnrolmentWorkspaceController::ACTION);
$listUrl = $method->invoke(null);
if (!str_contains($listUrl, 'attention=1') || str_contains($listUrl, 'request_id=')) throw new \RuntimeException('List action changed its filter context.');

echo "Class admin return context passed\n";
