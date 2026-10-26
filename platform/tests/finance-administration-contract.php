<?php
/**
 * Source contract for the consolidated Finance administration redesign.
 *
 * This is a WordPress-free guard for the navigation shape, migration, single pricing
 * authority, Toman teacher default, safe statement template and public-price reuse.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($assert): void {
    foreach ($needles as $needle) $assert(str_contains($source, $needle), $message . ': ' . $needle);
};

$finance = $read('src/Admin/Controller/FinanceAdminController.php');
$shell = $read('src/Admin/Controller/AdminShellController.php');
$menu = $read('src/Admin/Controller/Menu.php');
$platform = $read('delnavazan-platform.php');
$migration = $read('src/Core/Infrastructure/Migration/Migrator.php');
$pricing = $read('src/Core/Application/Finance/Pricing/RegionalPricingService.php');
$rule = $read('src/Core/Application/Finance/Pricing/RegionalPricingRule.php');
$money = $read('src/Core/Application/Finance/Pricing/PricingMoney.php');
$settings = $read('src/Core/Application/Finance/FinanceSettingsService.php');
$template = $read('src/Core/Application/Finance/Statements/StatementTemplateRule.php');
$catalogue = $read('src/Core/Application/CommercialCatalogueService.php');
$themePricing = $read('../theme/inc/pricing.php');

// Exactly four Finance tabs.
$contains($finance, array(
    "const TAB_PAYMENTS = 'payments'", "const TAB_LEDGER = 'ledger'",
    "const TAB_STATEMENTS = 'statements'", "const TAB_SETTINGS = 'settings'",
    "self::TAB_PAYMENTS => 'Payments'", "self::TAB_LEDGER => 'Central Ledger'",
    "self::TAB_STATEMENTS => 'Teacher Statements'", "self::TAB_SETTINGS => 'Settings'",
), 'Finance must expose exactly the four required tabs');
$assert(substr_count($finance, 'self::TAB_') >= 8, 'All four tabs must be reachable');
$assert(!str_contains($finance, 'dzn-commercial-catalogue'), 'Ordinary pricing must not navigate to a separate catalogue page');
$contains($shell, array(
    "'finance' => ['Finance'",
    "['Finance', 'dzn-finance', Menu::FINANCE_WORKSPACE_CAP, ['tab' => 'payments']]",
), 'Finance must have one visible destination');
$assert(!str_contains($shell, "['Teacher rates (temporary)'") && !str_contains($shell, "['Finance policies'"), 'Redundant Finance navigation entries must be removed');
$contains($menu, array('FINANCE_WORKSPACE_CAP', "'dzn_view_finance_authority'", "'dzn_view_payment_execution_authority'", "'dzn_view_cash_ledger'", "'dzn_manage_commercial_catalogue'"), 'Finance navigation must delegate to existing capabilities');
$contains($finance, array('current_user_can(Menu::FINANCE_WORKSPACE_CAP)', "check_admin_referer('dzn_finance_settings')", "current_user_can('dzn_view_payment_execution_authority')", "current_user_can('dzn_view_cash_ledger')", "current_user_can('dzn_view_finance_authority')"), 'Every Finance surface must stay capability- and nonce-gated');

// Additive migration and rollback-safe preservation.
$assert(str_contains($platform, "DZN_PLATFORM_SCHEMA_VERSION', '52'"), 'Schema version must advance to 52');
$contains($migration, array(
    "'049_finance_administration'", 'install_finance_administration', 'verify_finance_administration_schema',
    "'050_intro_payment_authority'", 'install_intro_payment_authority', 'verify_intro_payment_authority_schema',
    "'051_teacher_rate_origin'", 'install_teacher_rate_origin', 'verify_teacher_rate_origin_schema', 'rate_origin', 'origin_setting_version',
    'finance_regional_prices', 'finance_settings', 'intro_payment_obligations', 'intro_checkout_sessions', 'intro_payment_events',
    'UNIQUE KEY region_active(region_code,active_slot)', 'UNIQUE KEY setting_active(setting_key,active_slot)',
    "COUNT(DISTINCT price.currency)", "COUNT(DISTINCT price.amount_minor)",
), 'Migration must be additive, versioned and conflict-safe');
$assert(str_contains($migration, 'if((int)$region->currencies!==1||(int)$region->amounts!==1||(int)$region->amount_minor<1)continue;'), 'Migration must skip conflicting or ambiguous prices instead of silently choosing one');
$assert(!str_contains($migration, 'DELETE FROM {$p}commercial_prices') && !str_contains($migration, 'DROP TABLE'), 'Migration must not delete financial history');

// One regional student price, converted exactly, kept in step with commercial snapshots.
$contains($pricing, array(
    'activeForRegion', 'saveRegion', 'syncProducts', 'assertProductPriceAllowed',
    'RegionalPricingRule::standardPrice', 'PricingMoney::format', 'ensureProductPrice',
    'standard_amount_minor', 'introductory_amount_minor', 'operator_decision',
), 'Regional pricing service must own the authoritative amount and intro configuration');
$assert(!str_contains($pricing, '250') && !str_contains($pricing, '150'), 'No hardcoded public price may remain in the pricing authority');
$contains($money, array('toMinor', 'fromMinor', 'minorDigits', 'TOM', 'Persian/Arabic'), 'Money conversion must handle exact minor units and Persian digits');
$contains($rule, array('REGIONS', 'COUNTRY_TO_REGION', 'INTRO_FREE', 'INTRO_PAID', 'currency_region_mismatch'), 'Regional and introductory rules must be structural and preserved');
$contains($catalogue, array('RegionalPricingService', 'assertProductPriceAllowed', 'ensureProductPrice'), 'Catalogue and checkout price resolution must use the shared authority');
$catalogueController = $read('src/Admin/Controller/CommercialCatalogueController.php');
foreach (array('name="operator_key"', 'name="amount_minor"', 'name="evidence_at"', 'staff_record') as $forbiddenField) $assert(!str_contains($catalogueController, $forbiddenField), 'Ordinary catalogue administration must not expose technical field: ' . $forbiddenField);
$assert(!str_contains($read('src/Core/Application/CatalogueService.php'), 'paid_introductory_lesson_unsupported'), 'Introductory pricing must not be rejected by instrument setup');
$contains($themePricing, array('RegionalPricingService', 'publicConfig', 'dzn_theme_introductory_pricing_note'), 'Homepage must read the authoritative price');
$assert(!str_contains($themePricing, "amount' => 250") && !str_contains($themePricing, "'display' => 'A$250'"), 'Homepage must not keep hardcoded prices');
$assert(!str_contains($read('../theme/patterns/homepage-editorial.php'), '۲۵۰ دلار آمریکا'), 'Homepage markup must not keep a hardcoded fallback price');

// Teacher compensation: Toman global default and explicit overrides.
$contains($settings, array(
    'TEACHER_GLOBAL_RATE_KEY', 'template_version', 'TEACHER_CURRENCY', 'TEACHER_CURRENCY = ', "'TOM'",
    'saveGlobalTeacherRate', 'saveTeacherRate', 'resumeGlobalTeacherRate', 'markOverride', 'TeacherRateService',
), 'Teacher global Toman rate and per-Teacher override must use the existing rate authority');
$contains($finance, array('Global teacher rate default', 'Teacher-specific rate', 'Amount per session (Toman)', 'Toman', 'resume_global_teacher_rate'), 'Settings must expose a Toman global default and override fields');

// Statement template editor: safe, presentation-only, reset and placeholders.
$contains($template, array('sanitizeHtml', 'sanitizeCss', 'safeUrl', 'DEFAULT_HEADER', 'DEFAULT_BODY', 'DEFAULT_FOOTER', 'PLACEHOLDERS', 'LESSON_PLACEHOLDERS'), 'Template must be sanitised, defaulted and documented');
$contains($finance, array('Statement Template Settings', 'logo_url', 'Reset to default template', 'dzn-finance-live-preview', 'Supported placeholders', 'Print / open'), 'Template settings must include editor, live preview, validation, default and reset');
$assert(str_contains($template, 'script|style|iframe|object|embed|form') && str_contains($template, 'javascript'), 'Template sanitizer must explicitly block scripts and unsafe URLs');

// Stripe and finance policies are consolidated without changing security or commercial rules.
$contains($finance, array('Stripe integration', 'stripeSettingsContent(true)', 'Finance policies', 'save_finance_policy', 'save_commercial_policy', 'Structural accounting invariants are not configurable'), 'Stripe and policies must be consolidated into Settings');
$contains($read('src/Admin/Controller/IntegrationControlPlaneController.php'), array('stripeSettingsContent', 'return_to_finance', 'redirectStripe'), 'Stripe settings must support the embedded Finance tab while retaining the direct route');
$assert(!str_contains($finance, 'sk_live_') && !str_contains($finance, 'sk_test_'), 'Secret keys must never be embedded in the Finance UI');

// Manual ledger independence.
// Paid introductory payment lifecycle.
$intro = $read('src/Core/Application/Finance/Intro/IntroPaymentService.php');
$introCheckout = $read('src/Core/Application/Finance/Intro/IntroCheckoutInitiationService.php');
$teacherResponse = $read('src/Core/Application/BookingTeacherResponseService.php');
$projection = $read('src/Integrations/BookingCalendarProjectionService.php');
$intake = $read('src/Core/Application/PaymentExecution/PaymentEventIntakeService.php');
$studentController = $read('src/Portals/StudentCheckoutController.php');
$portalRead = $read('src/Portals/PortalAuthenticatedReadService.php');
$contains($intro, array('STATE_PENDING', 'STATE_PAID', 'STATE_REFUNDED', 'ensureObligation', 'recordProviderEvent', 'payment_succeeded', 'payment_failed', 'refund_recorded', 'intro_payment_amount_mismatch', 'confirmPaidBooking', 'booking_request_confirmations'), 'Paid intro must create an idempotent obligation and settle only verified provider evidence');
$contains($introCheckout, array('IntroCheckoutInitiationService', 'checkout.stripe.com', 'activeSession', 'providerIdempotencyKey', 'storeReferences', 'intro_obligation_uid'), 'Paid intro Checkout must reuse the Stripe adapter and prevent duplicate sessions');
$contains($teacherResponse, array('IntroPricingService', 'IntroPaymentService', 'awaiting_payment', 'intro_payment_student_required'), 'Teacher acceptance must stop at a payment gate for paid introductions');
$assert(str_contains($teacherResponse, 'awaiting_payment') && !str_contains($teacherResponse, "state'=>'pending_projection'"), 'Paid acceptance must not project Calendar/Meet before payment');
$contains($projection, array("awaiting_payment", 'intro_payment_required'), 'Calendar projection must refuse a paid intro before payment');
$contains($intake, array('DZN-INTRO-', 'translateIntro', 'IntroPaymentService', 'duplicate_provider_event'), 'The existing verified Stripe webhook must route intro references without entering R1');
$contains($studentController, array("'/student/intro-checkout'", "'/student/intro-checkout-status'", 'IntroCheckoutInitiationService', 'IntroCheckoutReturnReadService'), 'Student portal must expose owner-bound intro Checkout and status routes');
$contains($portalRead, array('intro_payment_obligations', 'intro_payment', 'payment_required', 'confirmation_state'), 'Student portal must expose the paid intro state and retryable action');
$contains($finance, array('Introductory lesson payments', 'introPaymentCounts', 'introPayments', 'last_event_kind', 'Awaiting payment'), 'Finance Payments must show paid introductory obligations and provider events');
$contains($read('../theme/assets/js/intro-checkout.js'), array('student/intro-checkout', 'intro_obligation_uid', 'data.redirect_url', 'dzn-intro-status'), 'Portal intro payment UI must be identifier-only and redirect to Stripe Checkout');
$contains($read('../theme/template-parts/portal/booking-requests.php'), array('data-dzn-intro-checkout', 'data-dzn-intro-status', 'obligation_uid', 'intro_payment'), 'Portal booking request must render the paid intro action without exposing amount/currency inputs');

// Automatic teacher-rate inheritance.
$contains($migration, array('UNIQUE KEY teacher_scope_slot(teacher_id,scope_kind,course_scope_id,active_slot)', 'UNIQUE KEY command_key_digest(command_key_digest)'), 'Teacher-rate concurrency must fail closed through the existing active-scope and command-key uniqueness');
$contains($read('src/Core/Application/Finance/TeacherRateInheritanceService.php'), array('applyForTeacher', 'globalTeacherRate', 'overrides', 'TeacherRateService', 'TEACHER_CURRENCY', 'already_inherited', 'existing_rate_preserved', 'activationInstant', 'activated_at', 'teacher_not_activated', 'rate_origin', 'global_inherited', '->later('), 'Newly activated Teachers must inherit the global Toman rate without replacing explicit overrides or backdating before activation');
$contains($read('src/Core/Application/Finance/FinanceSettingsService.php'), array('rate_origin', 'origin_setting_version', 'inheritedCurrent', 'liveTeacherRate', 'activationInstant'), 'Global saves must use provenance and activation eligibility rather than amount/effective-date matching');
$assert(!str_contains($read('src/Core/Infrastructure/Repository/TeacherRateRepository.php'), 'updated_by=%d'), 'Teacher-rate interval closure must not write a non-existent updated_by column');
$contains($read('src/Core/Application/TeacherOnboardingService.php'), array("do_action( 'dzn_teacher_activated'", "decision === 'approve'"), 'The authoritative onboarding approval must emit the activation event');
$contains($platform, array('dzn_teacher_activated', 'TeacherRateInheritanceService', 'dzn_teacher_rate_inheritance_failed'), 'Runtime must apply rate inheritance on activation and expose failures');

$contains($finance, array('Manual academy bookkeeping only', 'Online checkout, Stripe settlement, teacher rates and student pricing never write to this ledger', 'Reconciliation', 'Not reconciled'), 'Central Ledger must remain manual and independent');
$contains($finance, array('dzn_cashbook_record', 'wp_nonce_field(\'cashbook_record\')'), 'Manual ledger entry must reuse the existing cashbook command and nonce');

// Instalment schedule visibility stays read-only and uses stored obligations.
$financeRead = $read('src/Core/Application/Finance/Read/FinanceAdminReadService.php');
$contains($financeRead, array('instalmentSchedules', "plan_kind='two_instalments'", 'outstanding_minor', 'commercial_obligation_settlements', 'UTC_TIMESTAMP()'), 'Instalment schedules must read stored offers, obligations and settlements');
$contains($finance, array('renderInstalmentSchedules', 'instalment-schedules', 'Instalment schedules', 'Outstanding', 'Before Lesson 7', 'inst_state', 'inst_paged'), 'Finance Payments must expose read-only instalment schedules and outstanding balances');
$readOnly = substr($financeRead, strpos($financeRead, 'public function instalmentSchedules'), strpos($financeRead, 'public function introPaymentCounts') - strpos($financeRead, 'public function instalmentSchedules'));
$assert(preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $readOnly) !== 1, 'Instalment visibility must never write financial state');
$assert(strpos($readOnly, 'FinanceSupport::requireCapability') !== false, 'Instalment visibility must retain the payment view capability');

echo "finance-administration-contract: OK\n";
