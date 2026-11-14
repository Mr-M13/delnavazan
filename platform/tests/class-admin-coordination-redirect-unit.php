<?php
/** Coordination may return only to the booking record owned by its case. */
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

final class CoordinationCaseRepository {
    public function caseForRead(int $caseId): ?object {
        return $caseId === 17 ? (object) array('id' => 17, 'booking_request_id' => 42) : null;
    }
}

namespace Delnavazan\Platform\Admin\Controller;

function absint($value): int { return abs((int) $value); }
function admin_url(string $path): string { return 'https://academy.example/wp-admin/' . $path; }
function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args); }
function nocache_headers(): void {}
function wp_safe_redirect(string $url): bool { $GLOBALS['class_admin_redirect'] = $url; return false; }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function wp_die(): never { throw new \RuntimeException('redirect captured'); }

require_once __DIR__ . '/../src/Admin/Controller/EnrolmentWorkspaceController.php';
require_once __DIR__ . '/../src/Admin/Controller/CoordinationCaseController.php';

$redirect = new \ReflectionMethod(CoordinationCaseController::class, 'redirect');
$redirect->setAccessible(true);

$_POST = array('dzn_record_request_id' => '42', 'dzn_modal' => '1');
try { $redirect->invoke(null, 17, 'Saved'); } catch (\RuntimeException $expected) {}
$url = (string) ($GLOBALS['class_admin_redirect'] ?? '');
if (!str_contains($url, 'page=dzn-enrolment-workspace') || !str_contains($url, 'request_id=42') || !str_contains($url, 'dzn_modal=1')) throw new \RuntimeException('Matching coordination case did not return to its modal record.');

$_POST = array('dzn_record_request_id' => '43', 'dzn_modal' => '1');
try { $redirect->invoke(null, 17, 'Saved'); } catch (\RuntimeException $expected) {}
$url = (string) ($GLOBALS['class_admin_redirect'] ?? '');
if (!str_contains($url, 'page=dzn-booking-request-coordination') || str_contains($url, 'request_id=43')) throw new \RuntimeException('Mismatched record context changed the coordination destination.');

echo "Class admin coordination redirect passed\n";
