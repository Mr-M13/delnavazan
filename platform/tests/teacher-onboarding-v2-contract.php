<?php
/** Regression contract for invitation-led Teacher Onboarding v2. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($path);
$files = array(
    'claim' => $read($root . '/src/Portals/TeacherInvitationController.php'),
    'onboarding' => $read($root . '/src/Core/Application/TeacherOnboardingService.php'),
    'availability' => $read($root . '/src/Core/Application/TeacherAvailabilityService.php'),
    'time' => $read($root . '/src/Core/Application/AvailabilityLocalTime.php'),
    'wizard' => $read(dirname($root) . '/theme/template-parts/teacher-portal/onboarding.php'),
    'shell' => $read(dirname($root) . '/theme/template-parts/teacher-portal/shell.php'),
    'routes' => $read(dirname($root) . '/theme/inc/routes.php'),
    'bridge' => $read(dirname($root) . '/theme/inc/platform-bridge.php'),
    'admin' => $read($root . '/src/Admin/Controller/ScreenController.php'),
);
$contracts = array(
    'native password setup notification after claim' => str_contains($files['claim'], 'wp_new_user_notification') && str_contains($files['claim'], 'wp_generate_password') && !str_contains($files['claim'], "\$_POST['password']"),
    'existing claim preserves authenticated account password' => str_contains($files['claim'], 'beginExistingClaim') && !str_contains($files['claim'], 'wp_set_password'),
    'automatic activation from complete readiness' => str_contains($files['onboarding'], "'state' => \$to, 'readiness_state' => \$progress['ready_for_activation'] ? 'ready' : 'not_ready'") && str_contains($files['onboarding'], "? 'active'"),
    'complete profile and availability activates without later review' => str_contains($files['onboarding'], "'state' => 'active', 'readiness_state' => 'ready'") && str_contains($files['onboarding'], 'reconcileLegacyReviewState') && !str_contains($files['onboarding'], 'function review('),
    '2400 next-day exclusive boundary' => str_contains($files['availability'], "=== '2400' ? '24:00:00'") && str_contains($files['time'], "'24:00:00' ? self::wall"),
    'recurring overlap rejected in canonical service' => str_contains($files['availability'], 'rejectOverlap') && str_contains($files['availability'], 'Recurring availability overlaps'),
    'apply-day is an atomic canonical write with explicit target days' => str_contains($files['availability'], 'copyOwnRecurringDay') && str_contains($files['availability'], '$this->repo->commit()') && str_contains($files['wizard'], 'روزهای مقصد'),
    'timezone-selected DST-aware golden time display' => str_contains($files['wizard'], 'setTimezone(new DateTimeZone($timezone))') && str_contains($files['wizard'], 'AcademyDemandWindowRule::regions()') && str_contains($files['wizard'], 'تغییر ساعت تابستانی'),
    'distinct four-step onboarding views' => str_contains($files['wizard'], '$current === 1') && str_contains($files['wizard'], '$current === 2') && str_contains($files['wizard'], '$current === 3') && str_contains($files['wizard'], '$current === 4'),
    'teacher menu logout and signed-out login path' => str_contains($files['shell'], 'wp_logout_url') && str_contains($files['routes'], 'redirect_to') && str_contains($files['routes'], "home_url( '/login/' )"),
    'teacher navigation has distinct route destinations' => str_contains($files['bridge'], "add_query_arg( 'teacher-view', 'finance'") && str_contains($files['bridge'], "add_query_arg( 'teacher-view', 'settings'") && str_contains($files['routes'], "'finance', 'settings', 'onboarding'"),
    'administrator diagnostics do not expose routine approval action' => str_contains($files['admin'], 'Readiness is diagnostic') && !str_contains($files['admin'], 'Approve and activate'),
    'administrator can invite by email without selecting a Teacher ID' => str_contains($files['admin'], 'issue_teacher_invitation_by_email') && str_contains($files['admin'], 'issueForEmail') && str_contains($files['admin'], 'Email <input required type="email" name="recipient"'),
    'a teacher readiness refusal returns to setup rather than a generic portal error' => str_contains($files['bridge'], "'teacher_onboarding_required' === \$reason ? 'onboarding_required'") && str_contains($files['bridge'], "'not_linked','onboarding_required'"),
);
foreach ($contracts as $name => $passed) {
    if (!$passed) throw new RuntimeException('Teacher onboarding v2 contract failed: ' . $name);
}
echo 'Teacher onboarding v2 contracts passed (' . count($contracts) . ").\n";
