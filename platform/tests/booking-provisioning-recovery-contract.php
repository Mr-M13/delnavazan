<?php
/** Source contract for acceptance-preserving provisioning recovery and portal/dashboard visibility. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$theme = dirname($root);
$projection = $read('src/Integrations/BookingCalendarProjectionService.php');
$delivery = $read('src/Core/Infrastructure/Repository/BookingTeacherDeliveryRepository.php');
$workspace = $read('src/Core/Application/EnrolmentWorkspaceReadService.php');
$controller = $read('src/Admin/Controller/EnrolmentWorkspaceController.php');
$cli = $read('src/Admin/Cli/MeetMigrationCommand.php');
$bridge = (string) file_get_contents($theme . '/theme/inc/platform-bridge.php');
$portal = (string) file_get_contents($theme . '/theme/inc/teacher-portal.php');
$attention = (string) file_get_contents($theme . '/theme/template-parts/teacher-portal/attention.php');
$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

$expect(preg_match("/transitionDelivery\\([^;]*'accepting','pending_request'/", $projection) !== 1, 'A provider failure must never move an accepted delivery back to pending_request.');
$expect(str_contains($projection, 'assertRecoveryEligible') && str_contains($projection, 'Booking allocation has been superseded') && str_contains($projection, 'Booking request is no longer active'), 'Recovery must reject superseded, cancelled and reallocated bookings.');
$expect(str_contains($projection, 'activeAllocationOffer') && str_contains($projection, "'pending_request','accepting'"), 'Legacy pending_request repair must move forward only.');

$expect(str_contains($delivery, 'acceptedProvisioningForTeacher'), 'The Teacher portal needs a dedicated accepted-provisioning read.');
$expect(str_contains($delivery, "'pending_projection','retryable','awaiting_conference','failed','awaiting_payment'"), 'Provisioning visibility must include failures and pending states.');
$expect(str_contains($delivery, "d.state IN ('accepting','pending_request')"), 'Historical accepted deliveries must remain visible.');
$expect(str_contains($delivery, "r.lifecycle_status='submitted'") && str_contains($delivery, "r.resolution_state='unresolved'"), 'Provisioning reads must remain bounded to active requests.');

$expect(str_contains($workspace, "'provisioning_failed'") && str_contains($workspace, 'تأیید مدرس انجام شده؛ ساخت کلاس ناموفق بود') && str_contains($workspace, 'تلاش مجدد برای ساخت کلاس'), 'The dashboard must use the required failed status and next action.');
$expect(str_contains($workspace, "'provisioning_pending'") && str_contains($workspace, "'Creating classroom'") && str_contains($workspace, "'Monitor provisioning'"), 'The dashboard must distinguish provisioning pending.');
$expect(str_contains($workspace, "'Awaiting coordination'") && str_contains($workspace, "'Complete coordination'"), 'Unreviewed coordination must not be labelled as a Teacher request.');
$expect(strpos($workspace, 'if ($pendingDelivery !== null)') < strpos($workspace, "if ((string) (\$coordination['state']"), 'The pending delivery must outrank stale coordination candidates.');

$expect(str_contains($controller, "case 'retry_booking_projection'") && str_contains($controller, 'expected_confirmation_version'), 'The dashboard retry must be an explicit, version-checked operation.');
$expect(str_contains($cli, 'function booking_recovery') && str_contains($cli, 'Dry run only. No booking, offer, classroom or notification was changed.') && str_contains($cli, '--apply requires --confirmation_id and --expected_version'), 'Recovery discovery must be dry-run by default and require exact selection for apply.');

$expect(str_contains($bridge, "'booking_provisioning'") && str_contains($bridge, 'درخواست شما پذیرفته شده است. آماده‌سازی کلاس با مشکل مواجه شده و در حال پیگیری است. نیازی به تأیید مجدد نیست.'), 'The Teacher portal must show the required Persian provisioning-failure message.');
$expect(str_contains($bridge, "\$data['booking_provisioning']"), 'The Teacher portal must consume the accepted-provisioning read.');
$expect(str_contains($portal, "'booking_provisioning'"), 'The attention validator must accept the provisioning card.');
$expect(str_contains($attention, "'booking_provisioning'") && str_contains($attention, "'intro_request' === \$item['state']") && !str_contains($attention, "'booking_provisioning' === \$item['state'] &&"), 'Provisioning cards must render as information; only a genuinely pending intro request may show Accept controls.');

echo "Booking provisioning recovery contract passed\n";
