<?php
/**
 * Disposable WordPress/MariaDB proof for the finance administration redesign.
 * Run with: DZN_FINANCE_RUNTIME_TEST=authority wp eval-file ... --user=1
 */
if (getenv('DZN_FINANCE_RUNTIME_TEST') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Finance administration runtime refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\CommercialCatalogueService;
use Delnavazan\Platform\Core\Application\Finance\FinanceIdempotency;
use Delnavazan\Platform\Core\Application\Finance\FinanceSettingsService;
use Delnavazan\Platform\Core\Application\Finance\Read\FinanceAdminReadService;
use Delnavazan\Platform\Core\Application\Finance\Intro\{IntroCheckoutInitiationService,IntroCheckoutReturnReadService,IntroPaymentService};
use Delnavazan\Platform\Core\Application\Finance\Pricing\{PricingMoney,RegionalPricingService};
use Delnavazan\Platform\Core\Application\Finance\TeacherRateInheritanceService;
use Delnavazan\Platform\Core\Application\Finance\TeacherRateService;
use Delnavazan\Platform\Admin\Controller\FinanceAdminController;
use Delnavazan\Platform\Core\Application\TeacherService;
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
use Delnavazan\Platform\Core\Support\Identifier;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
wp_set_current_user(1);
function dzn_fin_runtime_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_fin_runtime_count(string $table): int { global $wpdb; return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_{$table}"); }

dzn_fin_runtime_assert((string) DZN_PLATFORM_SCHEMA_VERSION === '52', 'Finance runtime requires Schema 52');
dzn_fin_runtime_assert((string) get_option('dzn_platform_schema_version') === '52', 'Schema 52 must be installed');

/* ---------------------------------------------------------------- regional price authority + conflicts */
foreach (array('finance_regional_prices','finance_settings','intro_payment_events','intro_checkout_sessions','intro_payment_obligations','intro_payment_commands') as $table) $wpdb->query("DELETE FROM {$p}{$table}");

$pricingEmpty = new RegionalPricingService();
dzn_fin_runtime_assert($pricingEmpty->activeAll() === array() && $pricingEmpty->conflicts() === array(), 'An empty catalogue must have no invented regional price or conflict');

$suffix = strtolower(substr(hash('sha256', (string) microtime(true)), 0, 8));
$instrumentA = (new \Delnavazan\Platform\Core\Application\CatalogueService())->instrument(array('slug' => 'finance-runtime-a-' . $suffix, 'name_fa' => 'تار آزمون ' . $suffix, 'name_en' => 'Test Tar ' . $suffix, 'status' => 'active'));
$instrumentB = (new \Delnavazan\Platform\Core\Application\CatalogueService())->instrument(array('slug' => 'finance-runtime-b-' . $suffix, 'name_fa' => 'پیانو آزمون ' . $suffix, 'name_en' => 'Test Piano ' . $suffix, 'status' => 'active'));
$courseA = (new \Delnavazan\Platform\Core\Application\CatalogueService())->course(array('instrument_id' => $instrumentA, 'name_fa' => 'کلاس استاندارد تار ' . $suffix, 'name_en' => 'Standard Tar ' . $suffix, 'course_type' => 'standard', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15));
$courseB = (new \Delnavazan\Platform\Core\Application\CatalogueService())->course(array('instrument_id' => $instrumentB, 'name_fa' => 'کلاس استاندارد پیانو ' . $suffix, 'name_en' => 'Standard Piano ' . $suffix, 'course_type' => 'standard', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15));
$now = gmdate('Y-m-d H:i:s');
$productA = (int) $wpdb->insert($p . 'commercial_products', array('uid' => Identifier::uid(), 'course_id' => $courseA, 'name_fa' => 'Tar ' . $suffix, 'name_en' => 'Tar ' . $suffix, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1, 'archived_at' => null, 'archived_by' => null));
$productA = (int) $wpdb->insert_id;
$productB = (int) $wpdb->insert($p . 'commercial_products', array('uid' => Identifier::uid(), 'course_id' => $courseB, 'name_fa' => 'Piano ' . $suffix, 'name_en' => 'Piano ' . $suffix, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1, 'archived_at' => null, 'archived_by' => null));
$productB = (int) $wpdb->insert_id;
dzn_fin_runtime_assert($productA > 0 && $productB > 0, 'Commercial products required');

$priceA = (int) $wpdb->insert($p . 'commercial_prices', array('uid' => Identifier::uid(), 'product_id' => $productA, 'region_code' => 'AU', 'currency' => 'AUD', 'amount_minor' => 1000, 'status' => 'active', 'active_slot' => 1, 'effective_from' => $now, 'effective_until' => null, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1, 'archived_at' => null, 'archived_by' => null));
$priceA = (int) $wpdb->insert_id;
$priceB = (int) $wpdb->insert($p . 'commercial_prices', array('uid' => Identifier::uid(), 'product_id' => $productB, 'region_code' => 'AU', 'currency' => 'AUD', 'amount_minor' => 1500, 'status' => 'active', 'active_slot' => 1, 'effective_from' => $now, 'effective_until' => null, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1, 'archived_at' => null, 'archived_by' => null));
$priceB = (int) $wpdb->insert_id;

$pricing = new RegionalPricingService();
$subscriberId = (int) wp_insert_user(array('user_login' => 'finance-runtime-subscriber-' . $suffix, 'user_pass' => wp_generate_password(24), 'role' => 'subscriber'));
wp_set_current_user($subscriberId);
try { $pricing->saveRegion(array('region_code' => 'AU', 'currency' => 'AUD', 'standard_price' => '99.00', 'introductory_mode' => 'free', 'effective_from' => $now)); throw new RuntimeException('Unauthorised regional price change was accepted'); }
catch (RuntimeException $error) { dzn_fin_runtime_assert($error->getMessage() === 'Unauthorized', 'Unauthorised regional price change must fail closed'); }
wp_set_current_user(1);
$conflicts = $pricing->conflicts();
$auConflict = array_values(array_filter($conflicts, static fn(array $row): bool => $row['region_code'] === 'AU'));
dzn_fin_runtime_assert($auConflict !== array(), 'Conflicting AU course prices must be reported');

$saved = $pricing->saveRegion(array('region_code' => 'AU', 'currency' => 'AUD', 'standard_price' => '40.00', 'introductory_mode' => 'paid', 'introductory_price' => '10.00', 'effective_from' => $now));
dzn_fin_runtime_assert((int) $saved['price_id'] > 0, 'Regional price save must succeed');
$activeA = (int) $wpdb->get_var($wpdb->prepare("SELECT amount_minor FROM {$p}commercial_prices WHERE product_id=%d AND region_code='AU' AND active_slot=1", $productA));
$activeB = (int) $wpdb->get_var($wpdb->prepare("SELECT amount_minor FROM {$p}commercial_prices WHERE product_id=%d AND region_code='AU' AND active_slot=1", $productB));
dzn_fin_runtime_assert($activeA === 4000 && $activeB === 4000, 'Every standard Course in AUD must use the same 4000 minor-unit price');
$oldA = (int) $wpdb->get_var($wpdb->prepare("SELECT amount_minor FROM {$p}commercial_prices WHERE id=%d", $priceA));
dzn_fin_runtime_assert($oldA === 1000, 'Historical product price must not be rewritten');

$savedUs = $pricing->saveRegion(array('region_code' => 'US', 'currency' => 'USD', 'standard_price' => '55.00', 'introductory_mode' => 'free', 'effective_from' => $now));
dzn_fin_runtime_assert((int) $savedUs['price_id'] > 0, 'US regional price save must succeed');
$usPrices = (array) $wpdb->get_col("SELECT price.amount_minor FROM {$p}commercial_prices price INNER JOIN {$p}commercial_products product ON product.id=price.product_id WHERE price.region_code='US' AND price.active_slot=1 AND product.id IN ({$productA},{$productB})");
dzn_fin_runtime_assert(count($usPrices) === 2 && array_unique(array_map('intval', $usPrices)) === array(5500), 'Every standard Course in USD must use the manually configured 5500 price');

$offerSuffix = Identifier::uid();
$offerInserted = $wpdb->insert($p . 'commercial_offers', array(
    'uid' => $offerSuffix, 'reference_code' => null, 'beneficiary_student_id' => 999999, 'authority_basis' => 'staff_attestation',
    'principal_link_id' => null, 'guardian_grant_id' => null, 'source_kind' => 'continuation_case', 'continuation_case_id' => 999999,
    'intro_lesson_id' => 999999, 'slot_authority_id' => null, 'reservation_id' => null, 'student_id' => 999999,
    'teacher_id' => 999999, 'course_id' => $courseA, 'product_id' => $productA, 'course_price_id' => $priceA,
    'region_code' => 'AU', 'currency' => 'AUD', 'base_amount_minor' => 1000, 'discount_total_minor' => 0,
    'amount_due_minor' => 1000, 'plan_kind' => 'full', 'committed_sessions' => 12, 'promotion_id' => null,
    'promotion_amount_minor' => null, 'account_adjustment_id' => null, 'account_adjustment_amount_minor' => null,
    'calculation_version' => 'commercial_pricing_v1', 'evidence_channel' => 'staff_record', 'evidence_reference_digest' => hash('sha256', 'runtime-offer'),
    'evidence_at' => $now, 'issued_at' => $now, 'expires_at' => null, 'state' => 'issued', 'offer_version' => 1,
    'created_at' => $now, 'created_by' => 1, 'updated_at' => $now, 'updated_by' => 1,
));
dzn_fin_runtime_assert($offerInserted === 1, 'Historical offer fixture required');
$offerId = (int) $wpdb->insert_id;
$pricing->saveRegion(array('region_code' => 'AU', 'currency' => 'AUD', 'standard_price' => '42.00', 'introductory_mode' => 'paid', 'introductory_price' => '12.00', 'effective_from' => gmdate('Y-m-d H:i:s')));
$offerPriceId = (int) $wpdb->get_var($wpdb->prepare("SELECT course_price_id FROM {$p}commercial_offers WHERE id=%d", $offerId));
$offerPriceAmount = (int) $wpdb->get_var($wpdb->prepare("SELECT amount_minor FROM {$p}commercial_prices WHERE id=%d", $offerPriceId));
dzn_fin_runtime_assert($offerPriceId === $priceA && $offerPriceAmount === 1000, 'An existing offer must retain its recorded price row and amount');

$resolvedA = (new CommercialCatalogueService())->resolvePrice($productA, 'AU');
$resolvedB = (new CommercialCatalogueService())->resolvePrice($productB, 'AU');
dzn_fin_runtime_assert((int) $resolvedA->amount_minor === (int) $resolvedB->amount_minor, 'Checkout resolution must be identical across standard Courses');

/* ---------------------------------------------------------------- migration repeatability */
$before = array('regional' => dzn_fin_runtime_count('finance_regional_prices'), 'settings' => dzn_fin_runtime_count('finance_settings'), 'intro' => dzn_fin_runtime_count('intro_payment_obligations'));
Migrator::maybe_upgrade();
Migrator::maybe_upgrade();
$after = array('regional' => dzn_fin_runtime_count('finance_regional_prices'), 'settings' => dzn_fin_runtime_count('finance_settings'), 'intro' => dzn_fin_runtime_count('intro_payment_obligations'));
dzn_fin_runtime_assert($before === $after, 'Repeated migration must not duplicate regional/settings/intro rows');
dzn_fin_runtime_assert((string) get_option('dzn_platform_schema_version') === '52', 'Repeated migration must retain Schema 52');

/* ---------------------------------------------------------------- teacher rate inheritance */
$settings = new FinanceSettingsService();
$activationA = gmdate('Y-m-d H:i:s', time() - (2 * 86400));
$globalA = gmdate('Y-m-d H:i:s', time() - (5 * 86400));
$settings->saveGlobalTeacherRate(array('amount' => '450000', 'effective_from' => $globalA, 'apply_to_teachers' => '0'));
$teacherId = (new TeacherService())->create(array('display_name' => 'Finance Teacher ' . $suffix, 'email' => 'finance-teacher-' . $suffix . '@example.com', 'country_code' => 'IR', 'timezone' => 'Asia/Tehran'));
dzn_fin_runtime_assert($teacherId > 0, 'Teacher fixture required');
$wpdb->insert($p . 'teacher_onboarding_states', array('teacher_id' => $teacherId, 'state' => 'active', 'readiness_state' => 'ready', 'profile_state' => 'complete', 'availability_state' => 'complete', 'agreement_state' => 'not_required', 'activated_at' => $activationA, 'version' => 1, 'created_at' => $activationA, 'updated_at' => $activationA, 'created_by' => 1, 'updated_by' => 1));
$inherited = (new TeacherRateInheritanceService())->applyForTeacher($teacherId);
dzn_fin_runtime_assert(!empty($inherited['applied']) && ($inherited['effective_from'] ?? '') === $activationA, 'A newly activated Teacher must inherit the rate no earlier than activation');
$rateRow = $wpdb->get_row($wpdb->prepare("SELECT id,amount_minor,currency,scope_kind,status,effective_from,rate_origin,origin_setting_version FROM {$p}finance_teacher_rates WHERE teacher_id=%d ORDER BY id DESC LIMIT 1", $teacherId));
dzn_fin_runtime_assert($rateRow && (int) $rateRow->amount_minor === 450000 && (string) $rateRow->currency === 'TOM' && (string) $rateRow->scope_kind === 'teacher' && (string) $rateRow->rate_origin === 'global_inherited', 'Inherited rate must be a teacher-scope TOM rate with global-inherited provenance');
dzn_fin_runtime_assert((string) $rateRow->effective_from === $activationA, 'Inherited rate must not be backdated before activation');
$firstRateId = (int) $rateRow->id;
$rateCount = dzn_fin_runtime_count('finance_teacher_rates');
$again = (new TeacherRateInheritanceService())->applyForTeacher($teacherId);
dzn_fin_runtime_assert(empty($again['applied']) && dzn_fin_runtime_count('finance_teacher_rates') === $rateCount, 'Repeated activation must not append a duplicate rate version');

$snapshotsBefore = dzn_fin_runtime_count('finance_lesson_snapshots');
$statementsBefore = dzn_fin_runtime_count('finance_statements');
$settings->saveGlobalTeacherRate(array('amount' => '460000', 'effective_from' => gmdate('Y-m-d H:i:s'), 'apply_to_teachers' => '1'));
$newInherited = $wpdb->get_row($wpdb->prepare("SELECT id,amount_minor,effective_from,rate_origin FROM {$p}finance_teacher_rates WHERE teacher_id=%d ORDER BY id DESC LIMIT 1", $teacherId));
dzn_fin_runtime_assert($newInherited && (int) $newInherited->amount_minor === 460000 && (string) $newInherited->rate_origin === 'global_inherited', 'A global default change must update an inherited rate');
$oldRate = $wpdb->get_row($wpdb->prepare("SELECT amount_minor,status,effective_until FROM {$p}finance_teacher_rates WHERE id=%d", $firstRateId));
dzn_fin_runtime_assert($oldRate && (int) $oldRate->amount_minor === 450000 && (string) $oldRate->status === 'superseded' && $oldRate->effective_until !== null, 'A superseded inherited rate must stay as immutable history');
dzn_fin_runtime_assert(dzn_fin_runtime_count('finance_lesson_snapshots') === $snapshotsBefore && dzn_fin_runtime_count('finance_statements') === $statementsBefore, 'Rate changes must not alter historical snapshots or statements');

$futureEffective = gmdate('Y-m-d H:i:s', time() + 86400);
$settings->saveGlobalTeacherRate(array('amount' => '470000', 'effective_from' => $futureEffective, 'apply_to_teachers' => '1'));
$futureRate = $wpdb->get_row($wpdb->prepare("SELECT amount_minor,effective_from,rate_origin FROM {$p}finance_teacher_rates WHERE teacher_id=%d ORDER BY id DESC LIMIT 1", $teacherId));
dzn_fin_runtime_assert($futureRate && (int) $futureRate->amount_minor === 470000 && (string) $futureRate->effective_from === $futureEffective, 'A future global effective date must be respected');
try { $settings->saveGlobalTeacherRate(array('amount' => '465000', 'effective_from' => gmdate('Y-m-d H:i:s', time() + 3600), 'apply_to_teachers' => '0')); throw new RuntimeException('A backwards global effective date was accepted'); } catch (InvalidArgumentException $error) { dzn_fin_runtime_assert($error->getMessage() === 'invalid_effective_from', 'A backwards global effective date must fail closed'); }

$overrideTeacher = (new TeacherService())->create(array('display_name' => 'Finance Override ' . $suffix, 'email' => 'finance-override-' . $suffix . '@example.com', 'country_code' => 'IR', 'timezone' => 'Asia/Tehran'));
$settings->saveTeacherRate($overrideTeacher, array('amount' => '500000', 'scope_kind' => 'teacher', 'effective_from' => $now));
$courseTeacher = (new TeacherService())->create(array('display_name' => 'Finance Course ' . $suffix, 'email' => 'finance-course-' . $suffix . '@example.com', 'country_code' => 'IR', 'timezone' => 'Asia/Tehran'));
$settings->saveTeacherRate($courseTeacher, array('amount' => '600000', 'scope_kind' => 'teacher_course', 'course_scope_id' => $courseA, 'effective_from' => $now));
$settings->saveGlobalTeacherRate(array('amount' => '480000', 'effective_from' => gmdate('Y-m-d H:i:s', time() + 90000), 'apply_to_teachers' => '1'));
$overrideRate = $wpdb->get_row($wpdb->prepare("SELECT amount_minor,rate_origin FROM {$p}finance_teacher_rates WHERE teacher_id=%d AND status='active' AND effective_until IS NULL ORDER BY id DESC LIMIT 1", $overrideTeacher));
dzn_fin_runtime_assert($overrideRate && (int) $overrideRate->amount_minor === 500000 && (string) $overrideRate->rate_origin === 'explicit', 'A global change must not overwrite an explicit teacher override');
$courseRate = $wpdb->get_row($wpdb->prepare("SELECT amount_minor,rate_origin FROM {$p}finance_teacher_rates WHERE teacher_id=%d AND scope_kind='teacher_course' AND course_scope_id=%d AND status='active' ORDER BY id DESC LIMIT 1", $courseTeacher, $courseA));
$teacherDefaultRate = $wpdb->get_row($wpdb->prepare("SELECT amount_minor,rate_origin FROM {$p}finance_teacher_rates WHERE teacher_id=%d AND scope_kind='teacher' AND status='active' ORDER BY id DESC LIMIT 1", $courseTeacher));
dzn_fin_runtime_assert($courseRate && (int) $courseRate->amount_minor === 600000 && (string) $courseRate->rate_origin === 'explicit', 'A course-specific override must be preserved');
dzn_fin_runtime_assert($teacherDefaultRate && (int) $teacherDefaultRate->amount_minor === 480000 && (string) $teacherDefaultRate->rate_origin === 'global_inherited', 'A course-specific override must not block the teacher-scope global default');
$courseInheritedAgain = (new TeacherRateInheritanceService())->applyForTeacher($courseTeacher);
dzn_fin_runtime_assert(empty($courseInheritedAgain['applied']), 'Activation retry after a global save must not duplicate the teacher-scope rate');
$resolvedOverride = (new TeacherRateService())->resolveFor($courseTeacher, $courseA, gmdate('Y-m-d H:i:s', time() + 172800));
dzn_fin_runtime_assert($resolvedOverride['resolved'] && (int) $resolvedOverride['rate']->amount_minor === 600000, 'Course-specific rate must retain precedence for its Course');

$settings->resumeGlobalTeacherRate($overrideTeacher);
$resumed = $wpdb->get_row($wpdb->prepare("SELECT amount_minor,rate_origin FROM {$p}finance_teacher_rates WHERE teacher_id=%d AND scope_kind='teacher' AND status='active' ORDER BY id DESC LIMIT 1", $overrideTeacher));
dzn_fin_runtime_assert($resumed && (int) $resumed->amount_minor === 480000 && (string) $resumed->rate_origin === 'global_inherited', 'Resume global default must record a new inherited version');
dzn_fin_runtime_assert(!in_array($overrideTeacher, $settings->overrides(), true), 'Resume global default must clear the override marker');

$legacyTeacher = (new TeacherService())->create(array('display_name' => 'Finance Legacy ' . $suffix, 'email' => 'finance-legacy-' . $suffix . '@example.com', 'country_code' => 'IR', 'timezone' => 'Asia/Tehran'));
(new TeacherRateService())->record($legacyTeacher, array('scope_kind' => 'teacher', 'course_scope_id' => 0, 'amount_minor' => 100000, 'currency' => 'TOM', 'effective_from' => gmdate('Y-m-d H:i:s', time() - 86400), 'compensation_basis' => 'per_session', 'rate_origin' => 'global_inherited', 'evidence_channel' => 'staff_record', 'evidence_reference' => 'runtime-legacy-inherited', 'evidence_at' => $now), 'runtime-legacy-inherited-' . $suffix);
$legacyUpdate = (new TeacherRateInheritanceService())->applyForTeacher($legacyTeacher);
dzn_fin_runtime_assert(!empty($legacyUpdate['applied']), 'A previously inherited rate with a different amount must not be classified as an explicit override');
$legacyNew = $wpdb->get_row($wpdb->prepare("SELECT amount_minor,rate_origin FROM {$p}finance_teacher_rates WHERE teacher_id=%d AND status='active' ORDER BY id DESC LIMIT 1", $legacyTeacher));
dzn_fin_runtime_assert($legacyNew && (int) $legacyNew->amount_minor === 480000 && (string) $legacyNew->rate_origin === 'global_inherited' && !in_array($legacyTeacher, $settings->overrides(), true), 'Inherited-origin legacy rate must update without becoming an override');

$pendingTeacher = (new TeacherService())->create(array('display_name' => 'Finance Pending ' . $suffix, 'email' => 'finance-pending-' . $suffix . '@example.com', 'country_code' => 'IR', 'timezone' => 'Asia/Tehran'));
$wpdb->insert($p . 'teacher_onboarding_states', array('teacher_id' => $pendingTeacher, 'state' => 'pending_review', 'readiness_state' => 'ready', 'profile_state' => 'complete', 'availability_state' => 'complete', 'agreement_state' => 'not_required', 'activated_at' => null, 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1));
$pendingResult = (new TeacherRateInheritanceService())->applyForTeacher($pendingTeacher);
dzn_fin_runtime_assert(empty($pendingResult['applied']) && ($pendingResult['reason'] ?? '') === 'teacher_not_activated' && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_teacher_rates WHERE teacher_id=%d", $pendingTeacher)) === 0, 'A Teacher awaiting onboarding activation must not receive a compensation rate');

/* ---------------------------------------------------------------- evidence-proven legacy classification */
$legacyRateId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}finance_teacher_rates WHERE teacher_id=%d AND status='active' ORDER BY id DESC LIMIT 1", $legacyTeacher));
$wpdb->update($p . 'finance_teacher_rates', array(
    'rate_origin' => null,
    'evidence_reference_digest' => FinanceIdempotency::evidence('finance-settings:inherit-global-rate:' . $legacyTeacher),
), array('id' => $legacyRateId));
$unknownTeacher = (new TeacherService())->create(array('display_name' => 'Finance Unknown ' . $suffix, 'email' => 'finance-unknown-' . $suffix . '@example.com', 'country_code' => 'IR', 'timezone' => 'Asia/Tehran'));
(new TeacherRateService())->record($unknownTeacher, array('scope_kind' => 'teacher', 'course_scope_id' => 0, 'amount_minor' => 91000, 'currency' => 'TOM', 'effective_from' => gmdate('Y-m-d H:i:s', time() - 3600), 'compensation_basis' => 'per_session', 'evidence_channel' => 'staff_record', 'evidence_reference' => 'runtime-unknown-explicit', 'evidence_at' => $now), 'runtime-unknown-explicit-' . $suffix);
$unknownRateId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}finance_teacher_rates WHERE teacher_id=%d ORDER BY id DESC LIMIT 1", $unknownTeacher));
$wpdb->update($p . 'finance_teacher_rates', array('rate_origin' => null, 'evidence_reference_digest' => hash('sha256', 'not-an-inherited-reference')), array('id' => $unknownRateId));
$done = (array) get_option('dzn_platform_completed_migrations', array());
update_option('dzn_platform_completed_migrations', array_values(array_filter($done, static fn($migration): bool => $migration !== '051_teacher_rate_origin')), false);
Migrator::maybe_upgrade();
$classifiedLegacy = (string) $wpdb->get_var($wpdb->prepare("SELECT rate_origin FROM {$p}finance_teacher_rates WHERE id=%d", $legacyRateId));
$unknownOrigin = $wpdb->get_var($wpdb->prepare("SELECT rate_origin FROM {$p}finance_teacher_rates WHERE id=%d", $unknownRateId));
dzn_fin_runtime_assert($classifiedLegacy === 'global_inherited', 'Schema 052 must classify only evidence-proven historical inherited rates');
dzn_fin_runtime_assert($unknownOrigin === null, 'Schema 052 must not reinterpret historical explicit/unknown rates');
dzn_fin_runtime_assert((string) get_option('dzn_platform_schema_version') === '52', 'Schema 052 classification must retain Schema 52');
$unknownResult = (new TeacherRateInheritanceService())->applyForTeacher($unknownTeacher);
dzn_fin_runtime_assert(empty($unknownResult['applied']) && ($unknownResult['reason'] ?? '') === 'existing_rate_preserved' && in_array($unknownTeacher, $settings->overrides(), true), 'An unknown historical rate must be preserved as an explicit/unknown override');
dzn_fin_runtime_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_teacher_rates WHERE teacher_id=%d", $unknownTeacher)) === 1, 'An unknown historical rate must not be duplicated');

/* ---------------------------------------------------------------- statement template versioning */
$templateSave = $settings->saveTemplate(array('header_html' => '<header><h1>{{academy_name}}</h1></header>', 'body_html' => '<p>{{period_label}}</p>', 'footer_html' => '<footer>{{teacher_name}}</footer>', 'css' => 'body{font-family:Tahoma}', 'logo_url' => '', 'locale' => 'fa'));
$templateRead = $settings->template();
dzn_fin_runtime_assert((int) ($templateRead['template_version'] ?? 0) >= 1, 'Saved statement templates must carry a version');
$settings->resetTemplate();
$templateReset = $settings->template();
dzn_fin_runtime_assert((int) ($templateReset['template_version'] ?? 0) > (int) $templateSave['setting_version'], 'Template reset must create a new version without rewriting history');

/* ---------------------------------------------------------------- paid intro obligation + event */
$studentId = (int) $wpdb->insert($p . 'students', array('uid' => Identifier::uid(), 'reference_code' => 'DZN-STU-RT-' . $suffix, 'status' => 'active', 'display_name' => 'Finance Student ' . $suffix, 'email' => 'finance-student-' . $suffix . '@example.com', 'country_code' => 'AU', 'timezone' => 'Australia/Sydney', 'locale' => 'en_AU', 'calendar_preference' => 'auto', 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1, 'archived_at' => null, 'archived_by' => null));
$studentId = (int) $wpdb->insert_id;
$wpdb->insert($p . 'student_principal_links', array('student_id' => $studentId, 'wordpress_user_id' => 1, 'status' => 'active', 'link_sequence' => 1, 'active_slot' => 1, 'version' => 1, 'verification_basis' => 'runtime_fixture', 'evidence_channel' => 'authenticated_platform', 'evidence_reference' => 'runtime-finance-' . $suffix, 'evidence_at' => $now, 'linked_at' => $now, 'linked_by' => 1, 'updated_at' => $now, 'updated_by' => 1));
$introCourseId = (int) $wpdb->insert($p . 'courses', array('uid' => Identifier::uid(), 'reference_code' => 'DZN-CRS-RT-' . $suffix, 'instrument_id' => $instrumentA, 'name_fa' => 'کلاس معارفه آزمون ' . $suffix, 'name_en' => 'Intro Test ' . $suffix, 'course_type' => 'introductory', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1, 'archived_at' => null, 'archived_by' => null));
$introCourseId = (int) $wpdb->insert_id;
$teacherForBooking = $teacherId;
$requestId = (int) $wpdb->insert($p . 'booking_requests', array('uid' => Identifier::uid(), 'reference_code' => null, 'student_id' => $studentId, 'requested_instrument_id' => $instrumentA, 'selected_intro_course_id' => $introCourseId, 'lifecycle_status' => 'submitted', 'resolution_state' => 'unresolved', 'retention_due_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1));
$requestId = (int) $wpdb->insert_id;
$wpdb->insert($p . 'booking_request_contact_snapshots', array('booking_request_id' => $requestId, 'snapshot_sequence' => 1, 'full_name' => 'Finance Student ' . $suffix, 'email' => 'finance-student-' . $suffix . '@example.com', 'mobile' => '+61400000000', 'country' => 'AU', 'city' => 'Sydney', 'timezone' => 'Australia/Sydney', 'communication_language' => 'en', 'whatsapp_same_as_mobile' => 1, 'whatsapp_number' => '+61400000000', 'privacy_notice_version' => '1', 'privacy_notice_accepted' => 1, 'privacy_notice_accepted_at' => $now, 'submission_source' => 'runtime', 'created_at' => $now));
$deliveryId = (int) $wpdb->insert($p . 'booking_request_teacher_deliveries', array('uid' => Identifier::uid(), 'booking_request_id' => $requestId, 'requested_time_id' => 0, 'teacher_id' => $teacherForBooking, 'state' => 'accepting', 'match_strength' => 'strong', 'created_at' => $now, 'updated_at' => $now));
$deliveryId = (int) $wpdb->insert_id;
$confirmationId = (int) $wpdb->insert($p . 'booking_request_confirmations', array('uid' => Identifier::uid(), 'delivery_id' => $deliveryId, 'booking_request_id' => $requestId, 'requested_time_id' => 0, 'teacher_id' => $teacherForBooking, 'connection_id' => 0, 'state' => 'awaiting_payment', 'version' => 1, 'starts_at_utc' => gmdate('Y-m-d H:i:s', time() + 86400), 'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 88200), 'schedule_timezone' => 'Australia/Sydney', 'local_wall_date' => gmdate('Y-m-d', time() + 86400), 'local_wall_time' => '10:00:00', 'provider_code' => 'google_calendar', 'provider_event_digest' => null, 'failure_reason_code' => null, 'attempt_count' => 0, 'next_attempt_at' => $now, 'confirmed_at' => null, 'created_at' => $now, 'created_by' => 1, 'updated_at' => $now, 'updated_by' => 1));
$confirmationId = (int) $wpdb->insert_id;

$intro = new IntroPaymentService();
$obligation = $intro->ensureObligation(array('booking_request_id' => $requestId, 'confirmation_id' => $confirmationId, 'student_id' => $studentId, 'teacher_id' => $teacherForBooking, 'course_id' => $introCourseId, 'region_code' => 'AU', 'currency' => 'AUD', 'amount_minor' => 1000), 'runtime-intro-' . $suffix);
dzn_fin_runtime_assert((string) $obligation['state'] === 'pending' && (int) $obligation['amount_minor'] === 1000, 'A paid intro must create one pending obligation');

$fakeCheckout = new class {
    public function key(): string { return 'stripe'; }
    public function create($request): array {
        $ref = 'cs_test_' . $request->attemptUid();
        return array('state' => 'open', 'redirect_url' => 'https://checkout.stripe.com/c/pay/' . $ref, 'provider_reference' => $ref, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 1800));
    }
};
$checkout = new IntroCheckoutInitiationService(null, $fakeCheckout);
$firstCheckout = $checkout->initiate(array('intro_obligation_uid' => $obligation['uid']));
$secondCheckout = $checkout->initiate(array('intro_obligation_uid' => $obligation['uid']));
dzn_fin_runtime_assert(($firstCheckout['checkout_state'] ?? '') === 'open' && ($secondCheckout['redirect_url'] ?? '') === ($firstCheckout['redirect_url'] ?? ''), 'Repeated checkout must reuse the same open attempt');
dzn_fin_runtime_assert(dzn_fin_runtime_count('intro_checkout_sessions') === 1, 'Repeated checkout must not create a second session');
$attemptUid = substr((string) $firstCheckout['redirect_url'], strlen('https://checkout.stripe.com/c/pay/cs_test_'));
$checkoutStatus = (new IntroCheckoutReturnReadService())->read(array('attempt_uid' => $attemptUid));
dzn_fin_runtime_assert(($checkoutStatus['payment_state'] ?? '') === 'processing' && ($checkoutStatus['checkout_state'] ?? '') === 'open', 'Owner-bound intro return status must report an open unpaid attempt');

$event = $intro->recordProviderEvent(array('reference' => $obligation['reference'], 'provider_key' => 'stripe', 'event_reference' => 'evt_IntroRuntime' . $suffix, 'event_kind' => 'payment_succeeded', 'amount_minor' => 1000, 'currency' => 'AUD', 'provider_occurred_at' => $now));
dzn_fin_runtime_assert($event['accepted'] === true, 'A verified success event must be accepted');
$paid = $wpdb->get_row($wpdb->prepare("SELECT state,paid_at FROM {$p}intro_payment_obligations WHERE id=%d", (int) $obligation['obligation_id']));
dzn_fin_runtime_assert($paid && (string) $paid->state === 'paid' && $paid->paid_at !== null, 'Successful payment must mark the obligation paid');
$confirmationState = (string) $wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}booking_request_confirmations WHERE id=%d", $confirmationId));
dzn_fin_runtime_assert($confirmationState !== 'awaiting_payment' && $confirmationState !== 'confirmed', 'Payment must release the payment gate without falsely confirming before Calendar/Meet');
dzn_fin_runtime_assert(dzn_fin_runtime_count('lessons') === 0, 'Payment completion must not create a standard lesson or post-intro enrolment');
$duplicateEvent = $intro->recordProviderEvent(array('reference' => $obligation['reference'], 'provider_key' => 'stripe', 'event_reference' => 'evt_IntroRuntime' . $suffix, 'event_kind' => 'payment_succeeded', 'amount_minor' => 1000, 'currency' => 'AUD', 'provider_occurred_at' => $now));
dzn_fin_runtime_assert($duplicateEvent['idempotent'] === true && dzn_fin_runtime_count('intro_payment_events') === 1, 'Duplicate webhook must not duplicate payment evidence');

$request2 = (int) $wpdb->insert($p . 'booking_requests', array('uid' => Identifier::uid(), 'reference_code' => null, 'student_id' => $studentId, 'requested_instrument_id' => $instrumentA, 'selected_intro_course_id' => $introCourseId, 'lifecycle_status' => 'submitted', 'resolution_state' => 'unresolved', 'retention_due_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1));
$request2 = (int) $wpdb->insert_id;
$wpdb->insert($p . 'booking_request_contact_snapshots', array('booking_request_id' => $request2, 'snapshot_sequence' => 1, 'full_name' => 'Finance Student ' . $suffix, 'email' => 'finance-student-' . $suffix . '@example.com', 'mobile' => '+61400000000', 'country' => 'AU', 'city' => 'Sydney', 'timezone' => 'Australia/Sydney', 'communication_language' => 'en', 'whatsapp_same_as_mobile' => 1, 'whatsapp_number' => '+61400000000', 'privacy_notice_version' => '1', 'privacy_notice_accepted' => 1, 'privacy_notice_accepted_at' => $now, 'submission_source' => 'runtime', 'created_at' => $now));
$delivery2 = (int) $wpdb->insert($p . 'booking_request_teacher_deliveries', array('uid' => Identifier::uid(), 'booking_request_id' => $request2, 'requested_time_id' => 0, 'teacher_id' => $teacherForBooking, 'state' => 'accepting', 'match_strength' => 'strong', 'created_at' => $now, 'updated_at' => $now));
$delivery2 = (int) $wpdb->insert_id;
$confirmation2 = (int) $wpdb->insert($p . 'booking_request_confirmations', array('uid' => Identifier::uid(), 'delivery_id' => $delivery2, 'booking_request_id' => $request2, 'requested_time_id' => 0, 'teacher_id' => $teacherForBooking, 'connection_id' => 0, 'state' => 'awaiting_payment', 'version' => 1, 'starts_at_utc' => gmdate('Y-m-d H:i:s', time() + 86400), 'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 88200), 'schedule_timezone' => 'Australia/Sydney', 'local_wall_date' => gmdate('Y-m-d', time() + 86400), 'local_wall_time' => '10:00:00', 'provider_code' => 'google_calendar', 'provider_event_digest' => null, 'failure_reason_code' => null, 'attempt_count' => 0, 'next_attempt_at' => $now, 'confirmed_at' => null, 'created_at' => $now, 'created_by' => 1, 'updated_at' => $now, 'updated_by' => 1));
$confirmation2 = (int) $wpdb->insert_id;
$obligation2 = $intro->ensureObligation(array('booking_request_id' => $request2, 'confirmation_id' => $confirmation2, 'student_id' => $studentId, 'teacher_id' => $teacherForBooking, 'course_id' => $introCourseId, 'region_code' => 'AU', 'currency' => 'AUD', 'amount_minor' => 1000), 'runtime-intro-fail-' . $suffix);
$failed = $intro->recordProviderEvent(array('reference' => $obligation2['reference'], 'provider_key' => 'stripe', 'event_reference' => 'evt_IntroFail' . $suffix, 'event_kind' => 'payment_failed', 'amount_minor' => 1000, 'currency' => 'AUD', 'provider_occurred_at' => $now));
dzn_fin_runtime_assert($failed['accepted'] === true, 'A failed payment event is recorded');
$failedRow = $wpdb->get_row($wpdb->prepare("SELECT state,failure_reason_code FROM {$p}intro_payment_obligations WHERE id=%d", (int) $obligation2['obligation_id']));
dzn_fin_runtime_assert($failedRow && (string) $failedRow->state === 'pending' && (string) $failedRow->failure_reason_code === 'payment_failed', 'Failed payment must remain unpaid and retryable');

/* ---------------------------------------------------------------- instalment schedule visibility */
$wpdb->update($p . 'commercial_offers', array('plan_kind' => 'two_instalments'), array('id' => $offerId));
foreach (array(array(1, 1, 6, 500), array(2, 7, 12, 500)) as $tranche) {
    [$sequence, $from, $to, $amount] = $tranche;
    $inserted = $wpdb->insert($p . 'commercial_offer_obligations', array(
        'uid' => Identifier::uid(), 'offer_id' => $offerId, 'obligation_sequence' => $sequence,
        'sessions_from' => $from, 'sessions_to' => $to, 'sessions_covered' => $to - $from + 1,
        'amount_minor' => $amount, 'currency' => 'AUD', 'due_at' => null,
        'obligation_reference_digest' => hash('sha256', 'runtime-obligation-' . $offerSuffix . '-' . $sequence),
        'created_at' => $now, 'created_by' => 1,
    ));
    dzn_fin_runtime_assert($inserted === 1, 'Instalment schedule fixture required');
}
/* A time-lapsed issued offer is not collectable, while an accepted commitment remains actionable. */
$wpdb->update($p . 'commercial_offers', array('expires_at' => gmdate('Y-m-d H:i:s', time() - 3600)), array('id' => $offerId));
$lapsed = (new FinanceAdminReadService())->instalmentSchedules(array('state' => 'outstanding'))['rows'];
$lapsed = array_values(array_filter($lapsed, static fn(object $row): bool => (string) $row->offer_uid === (string) $offerSuffix));
dzn_fin_runtime_assert($lapsed === array(), 'A time-lapsed issued offer must not inflate actionable receivables');
$lapsedAll = (new FinanceAdminReadService())->instalmentSchedules(array())['rows'];
$lapsedAll = array_values(array_filter($lapsedAll, static fn(object $row): bool => (string) $row->offer_uid === (string) $offerSuffix));
dzn_fin_runtime_assert(count($lapsedAll) === 2 && (string) $lapsedAll[0]->offer_state === 'issued', 'A lapsed schedule must remain visible with its real offer state');
$wpdb->update($p . 'commercial_offers', array('state' => 'accepted'), array('id' => $offerId));
$acceptedLapsed = (new FinanceAdminReadService())->instalmentSchedules(array('state' => 'outstanding'))['rows'];
$acceptedLapsed = array_values(array_filter($acceptedLapsed, static fn(object $row): bool => (string) $row->offer_uid === (string) $offerSuffix));
dzn_fin_runtime_assert(count($acceptedLapsed) === 2, 'An accepted commitment must remain actionable after its original checkout window');
$wpdb->update($p . 'commercial_offers', array('state' => 'issued', 'expires_at' => null), array('id' => $offerId));

$instalments = (new FinanceAdminReadService())->instalmentSchedules(array('state' => 'outstanding'));
dzn_fin_runtime_assert(count($instalments['rows']) === 2, 'The instalment schedule read must return both ordered obligations');
dzn_fin_runtime_assert((int) $instalments['rows'][0]->sessions_from === 1 && (int) $instalments['rows'][1]->sessions_to === 12, 'The schedule must expose exact Lesson ranges');
dzn_fin_runtime_assert((int) $instalments['rows'][0]->outstanding_minor === 1000, 'The outstanding balance must be the offer total when nothing has settled');
$searchByUid = (new FinanceAdminReadService())->instalmentSchedules(array('q' => (string) $offerSuffix))['rows'];
$searchByUid = array_values(array_filter($searchByUid, static fn(object $row): bool => (string) $row->offer_uid === (string) $offerSuffix));
dzn_fin_runtime_assert(count($searchByUid) === 2, 'The instalment search must find the visible offer identifier');
$scheduleRows = static function (array $filters) use ($offerSuffix): array {
    $rows = (new FinanceAdminReadService())->instalmentSchedules($filters)['rows'];
    return array_values(array_filter($rows, static fn(object $row): bool => (string) $row->offer_uid === (string) $offerSuffix));
};
$offerObligations = static function () use ($wpdb, $p, $offerId): array {
    return $wpdb->get_results($wpdb->prepare("SELECT id,obligation_sequence,sessions_from,sessions_to,amount_minor FROM {$p}commercial_offer_obligations WHERE offer_id=%d ORDER BY obligation_sequence", $offerId)) ?: array();
};
$obligations = $offerObligations();
dzn_fin_runtime_assert(count($obligations) === 2, 'The schedule must contain exactly two obligations');
dzn_fin_runtime_assert(array_sum(array_map(static fn(object $row): int => (int) $row->amount_minor, $obligations)) === (int) $instalments['rows'][0]->amount_due_minor, 'Obligation amounts must reconcile to the offer total');
$settlementsBefore = dzn_fin_runtime_count('commercial_obligation_settlements');
$evidenceBefore = dzn_fin_runtime_count('commercial_payment_evidence');
$settle = static function (int $obligationIndex, int $amountMinor, string $suffixLabel) use ($wpdb, $p, $obligations, $now): void {
    $obligation = $obligations[$obligationIndex];
    $evidence = $wpdb->insert($p . 'commercial_payment_evidence', array(
        'uid' => Identifier::uid(), 'reference_code' => null, 'provider_key' => 'stripe',
        'provider_account_digest' => hash('sha256', 'runtime-account'), 'evidence_reference_digest' => hash('sha256', 'runtime-evidence-' . $suffixLabel),
        'evidence_fact_digest' => hash('sha256', 'runtime-fact-' . $suffixLabel), 'evidence_kind' => 'payment',
        'amount_minor' => $amountMinor, 'currency' => 'AUD', 'obligation_reference_digest' => null,
        'provider_occurred_at' => $now, 'ingested_at' => $now, 'processing_state' => 'settled', 'reason_code' => null,
        'offer_id' => null, 'purchase_id' => null, 'obligation_id' => (int) $obligation->id,
        'created_at' => $now, 'created_by' => 1,
    ));
    dzn_fin_runtime_assert($evidence === 1, 'Instalment settlement evidence fixture required');
    $settlement = $wpdb->insert($p . 'commercial_obligation_settlements', array(
        'uid' => Identifier::uid(), 'obligation_id' => (int) $obligation->id, 'evidence_id' => (int) $wpdb->insert_id,
        'amount_minor' => $amountMinor, 'currency' => 'AUD', 'settled_at' => $now, 'created_at' => $now, 'created_by' => 1,
    ));
    dzn_fin_runtime_assert($settlement === 1, 'Instalment settlement fixture required');
};

/* Partially paid: the first tranche is settled, the second is still outstanding. */
$settle(0, 500, $offerSuffix . '-first');
$partial = $scheduleRows(array('state' => 'outstanding'));
dzn_fin_runtime_assert(count($partial) === 2, 'A partially paid offer must remain in the outstanding schedule with both tranches');
dzn_fin_runtime_assert((int) $partial[0]->obligation_sequence === 1 && (int) $partial[0]->settled_amount_minor === 500, 'The first tranche must report its settled amount');
dzn_fin_runtime_assert($partial[1]->settled_at === null, 'The second tranche must remain unsettled');
foreach ($partial as $row) dzn_fin_runtime_assert((int) $row->outstanding_minor === 500, 'A partially paid offer must report the remaining 500 minor units');
dzn_fin_runtime_assert($scheduleRows(array('state' => 'paid')) === array(), 'A partially paid offer must not be reported as fully paid');
dzn_fin_runtime_assert($scheduleRows(array('state' => 'overdue')) === array(), 'A schedule without a due date must never be reported overdue');

/* Overdue only reflects a configured past deadline on an unpaid tranche. */
$wpdb->update($p . 'commercial_offer_obligations', array('due_at' => gmdate('Y-m-d H:i:s', time() - 3600)), array('offer_id' => $offerId, 'obligation_sequence' => 2));
$overdue = $scheduleRows(array('state' => 'overdue'));
dzn_fin_runtime_assert($overdue !== array(), 'An unpaid tranche past its due date must be reported overdue');
$overdueSecond = array_values(array_filter($overdue, static fn(object $row): bool => (int) $row->obligation_sequence === 2));
dzn_fin_runtime_assert(count($overdueSecond) === 1 && strtotime((string) $overdueSecond[0]->due_at) < time(), 'Overdue must be derived from the stored due_at timestamp');
dzn_fin_runtime_assert($scheduleRows(array('state' => 'paid')) === array(), 'An overdue instalment must never be reported as paid');
$wpdb->update($p . 'commercial_offer_obligations', array('due_at' => gmdate('Y-m-d H:i:s', time() + 3600)), array('offer_id' => $offerId, 'obligation_sequence' => 2));
dzn_fin_runtime_assert($scheduleRows(array('state' => 'overdue')) === array(), 'A future due date must not be reported overdue');
$wpdb->update($p . 'commercial_offer_obligations', array('due_at' => gmdate('Y-m-d H:i:s', time() - 3600)), array('offer_id' => $offerId, 'obligation_sequence' => 2));

/* Fully paid: both tranches reconcile to the offer total with nothing outstanding. */
$settle(1, 500, $offerSuffix . '-second');
$paidSchedule = $scheduleRows(array('state' => 'paid'));
dzn_fin_runtime_assert(count($paidSchedule) === 2, 'A fully paid offer must be reported with both settled tranches');
foreach ($paidSchedule as $row) dzn_fin_runtime_assert((int) $row->outstanding_minor === 0 && $row->settled_at !== null, 'A fully paid offer must report zero outstanding');
dzn_fin_runtime_assert((int) $paidSchedule[0]->settled_amount_minor + (int) $paidSchedule[1]->settled_amount_minor === (int) $paidSchedule[0]->amount_due_minor, 'Settled tranches must reconcile to the offer total');
dzn_fin_runtime_assert($scheduleRows(array('state' => 'outstanding')) === array(), 'A fully paid offer must leave the outstanding schedule');
dzn_fin_runtime_assert($scheduleRows(array('state' => 'overdue')) === array(), 'A fully paid offer must never be reported overdue');
dzn_fin_runtime_assert(dzn_fin_runtime_count('commercial_obligation_settlements') === $settlementsBefore + 2, 'Settling two tranches must create exactly two settlements');
dzn_fin_runtime_assert(dzn_fin_runtime_count('commercial_payment_evidence') === $evidenceBefore + 2, 'Settling two tranches must create exactly two evidence rows');
dzn_fin_runtime_assert(count($offerObligations()) === 2, 'Reading and settling a schedule must never duplicate obligations');

/* A withdrawn or expired offer is historical truth, never an actionable receivable. */
$wpdb->update($p . 'commercial_offers', array('state' => 'withdrawn'), array('id' => $offerId));
dzn_fin_runtime_assert($scheduleRows(array('state' => 'outstanding')) === array(), 'A withdrawn offer must not be counted as outstanding receivables');
dzn_fin_runtime_assert($scheduleRows(array('state' => 'overdue')) === array(), 'A withdrawn offer must not be counted as overdue receivables');
$allSchedules = $scheduleRows(array());
dzn_fin_runtime_assert(count($allSchedules) === 2 && (string) $allSchedules[0]->offer_state === 'withdrawn', 'A closed schedule must remain visible with its real offer state');
dzn_fin_runtime_assert(count($scheduleRows(array('state' => 'paid'))) === 2, 'Recorded settlements must survive offer closure');
$wpdb->update($p . 'commercial_offers', array('state' => 'issued'), array('id' => $offerId));
$wpdb->update($p . 'commercial_offer_obligations', array('due_at' => null), array('offer_id' => $offerId));

/* ---------------------------------------------------------------- actual four-tab screen render */
$_GET = array('page' => 'dzn-finance', 'tab' => 'payments');
ob_start();
FinanceAdminController::screen();
$paymentsHtml = (string) ob_get_clean();
dzn_fin_runtime_assert(str_contains($paymentsHtml, 'Instalment schedules') && str_contains($paymentsHtml, 'Before Lesson 7') && str_contains($paymentsHtml, 'Offer outstanding') && str_contains($paymentsHtml, 'dzn-finance-pill">Settled'), 'Payments must render the instalment schedule and its real status cell');
$_GET = array('page' => 'dzn-finance', 'tab' => 'settings');
ob_start();
FinanceAdminController::screen();
$screenHtml = (string) ob_get_clean();
dzn_fin_runtime_assert(substr_count($screenHtml, 'class="nav-tab') >= 4, 'The actual Finance screen must render four tabs');
foreach (array('Payments', 'Central Ledger', 'Teacher Statements', 'Settings') as $tabLabel) dzn_fin_runtime_assert(str_contains($screenHtml, '>' . $tabLabel . '<'), 'Rendered Finance tab missing: ' . $tabLabel);
dzn_fin_runtime_assert(str_contains($screenHtml, 'Global regional pricing') && str_contains($screenHtml, 'Teacher compensation'), 'Settings tab must render consolidated finance fields');
$_GET = array();

echo 'Finance administration runtime: PASS' . PHP_EOL;
