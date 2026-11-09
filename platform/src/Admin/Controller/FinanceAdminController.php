<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\CommercialPolicyService;
use Delnavazan\Platform\Core\Application\CommercialPromotionService;
use Delnavazan\Platform\Core\Application\CommercialRule;
use Delnavazan\Platform\Core\Application\Finance\FinancePolicyService;
use Delnavazan\Platform\Core\Application\Finance\FinanceRule;
use Delnavazan\Platform\Core\Application\Finance\FinanceExportService;
use Delnavazan\Platform\Core\Application\Finance\FinanceSettingsService;
use Delnavazan\Platform\Core\Application\Finance\GoldenHourPolicyService;
use Delnavazan\Platform\Core\Application\Finance\GoldenHourRule;
use Delnavazan\Platform\Core\Application\Finance\ManualPaymentSettlementService;
use Delnavazan\Platform\Core\Application\Finance\ManualRefundService;
use Delnavazan\Platform\Core\Application\Finance\OperationalCashRule;
use Delnavazan\Platform\Core\Application\Finance\Pricing\{PricingMoney,RegionalPricingRule,RegionalPricingService};
use Delnavazan\Platform\Core\Application\Finance\Read\FinanceAdminReadService;
use Delnavazan\Platform\Core\Application\Finance\Read\FinanceAdminRedesignReadService;
use Delnavazan\Platform\Core\Application\Finance\Read\TeacherRateReadService;
use Delnavazan\Platform\Core\Application\Finance\Read\TeacherStatementReadService;
use Delnavazan\Platform\Core\Application\Finance\Reporting\{StripeRefundAssessmentService,StudentReceiptsReportService};
use Delnavazan\Platform\Core\Application\Finance\Statements\{StatementTemplateRule,StatementTemplateService,TeacherStatementBatchService,TeacherStatementBatchWorkflowService,TeacherStatementEmailService,TeacherStatementScheduleService};
use Delnavazan\Platform\Core\Application\Finance\TeacherStatementService;
use Delnavazan\Platform\Core\Application\RefundReviewService;
use Delnavazan\Platform\Core\Support\PersianCalendar;

/**
 * One Finance administration page with exactly four top-level tabs.
 *
 * Existing routes remain protected direct routes for compatibility, but ordinary finance work
 * happens here: Student payments, Teacher payments, Manual ledger and Settings. The four tab
 * values (`payments`, `statements`, `ledger`, `settings`) remain the protected compatibility
 * parameters; only their visible labels and order changed. Each write keeps its own narrow
 * capability and nonce; the page capability is only a navigation gate.
 */
final class FinanceAdminController {
    public const MENU_SLUG = 'dzn-finance';
    public const PARENT_SLUG = Menu::ROOT_SLUG;
    public const TAB_PAYMENTS = 'payments';
    public const TAB_LEDGER = 'ledger';
    public const TAB_STATEMENTS = 'statements';
    public const TAB_SETTINGS = 'settings';
    public const TABS = array(
        self::TAB_PAYMENTS => 'Student payments',
        self::TAB_STATEMENTS => 'Teacher payments',
        self::TAB_LEDGER => 'Manual ledger',
        self::TAB_SETTINGS => 'Settings',
    );
    /** Old and new human-readable tab parameters that must keep resolving. */
    private const TAB_ALIASES = array(
        'student-payments' => self::TAB_PAYMENTS, 'student_payments' => self::TAB_PAYMENTS,
        'teacher-payments' => self::TAB_STATEMENTS, 'teacher_payments' => self::TAB_STATEMENTS,
        'teacher-statements' => self::TAB_STATEMENTS, 'teacher_statements' => self::TAB_STATEMENTS,
        'manual-ledger' => self::TAB_LEDGER, 'manual_ledger' => self::TAB_LEDGER,
        'central-ledger' => self::TAB_LEDGER, 'central_ledger' => self::TAB_LEDGER,
    );

    public static function register(): void {
        add_action('admin_post_dzn_finance_settings', array(__CLASS__, 'handleSettings'));
        add_action('admin_post_dzn_finance_promotion', array(__CLASS__, 'handlePromotion'));
        add_action('admin_post_dzn_finance_statement_export', array(__CLASS__, 'exportStatement'));
        add_action('admin_post_dzn_finance_manual_payment', array(__CLASS__, 'handleManualPayment'));
        add_action('admin_post_dzn_finance_statement', array(__CLASS__, 'handleStatement'));
        add_action('admin_post_dzn_finance_statement_email', array(__CLASS__, 'handleStatementEmail'));
        add_action('admin_post_dzn_finance_statement_batch', array(__CLASS__, 'handleStatementBatch'));
        add_action('admin_post_dzn_finance_statement_workflow', array(__CLASS__, 'handleStatementWorkflow'));
        add_action('admin_post_dzn_finance_statement_staff_summary', array(__CLASS__, 'handleStatementStaffSummary'));
        add_action('admin_post_dzn_finance_receipts_report', array(__CLASS__, 'handleReceiptsReport'));
        add_action('admin_post_dzn_finance_refund', array(__CLASS__, 'handleRefund'));
        add_action('admin_post_dzn_finance_refund_review', array(__CLASS__, 'handleRefundReview'));
        add_action('admin_post_dzn_finance_refund_release', array(__CLASS__, 'handleRefundRelease'));
        add_action('admin_post_dzn_finance_statement_schedule', array(__CLASS__, 'handleStatementSchedule'));
        add_action('admin_post_dzn_finance_export', array(__CLASS__, 'handleExport'));
    }

    public static function menu(): void {
        if (!function_exists('add_submenu_page')) return;
        add_submenu_page(self::PARENT_SLUG, 'Finance', 'Finance', Menu::FINANCE_WORKSPACE_CAP, self::MENU_SLUG, array(__CLASS__, 'screen'));
    }

    public static function screen(): void {
        if (!current_user_can(Menu::FINANCE_WORKSPACE_CAP)) wp_die('Forbidden', 403);
        $tab = self::tab();
        $read = new FinanceAdminReadService();
        echo '<div class="wrap dzn-finance-admin" dir="' . (is_rtl() ? 'rtl' : 'ltr') . '">';
        self::styles();
        echo '<h1>Finance</h1><p class="dzn-finance-admin__intro">Student payments, teacher compensation, the manual academy ledger and the settings behind them. The manual ledger is independent from online payments and from the pricing fields; existing entries are never rewritten by a configuration change.</p>';
        self::notices();
        echo '<nav class="nav-tab-wrapper dzn-finance-admin__tabs" aria-label="Finance sections">';
        foreach (self::TABS as $key => $label) {
            echo '<a class="nav-tab' . ($tab === $key ? ' nav-tab-active' : '') . '" href="' . esc_url(self::tabUrl($key)) . '"' . ($tab === $key ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        try {
            match ($tab) {
                self::TAB_PAYMENTS => self::renderPayments($read),
                self::TAB_LEDGER => self::renderLedger($read),
                self::TAB_STATEMENTS => self::renderStatements($read),
                default => self::renderSettings($read),
            };
        } catch (\Throwable $error) {
            echo '<div class="notice notice-error"><p>' . esc_html(self::safeError($error)) . '</p></div>';
        }
        echo '</div>';
        echo '<script>(function(){var root=document.querySelector(".dzn-finance-admin");if(!root)return;root.querySelectorAll("table.widefat").forEach(function(table){if(table.parentElement&&table.parentElement.classList.contains("dzn-finance-table-scroll"))return;var wrap=document.createElement("div");wrap.className="dzn-finance-table-scroll";table.parentNode.insertBefore(wrap,table);wrap.appendChild(table);});})();</script>';
    }

    public static function handleSettings(): void {
        if (!current_user_can(Menu::FINANCE_WORKSPACE_CAP)) wp_die('Forbidden', 403);
        check_admin_referer('dzn_finance_settings');
        $_POST = wp_unslash($_POST);
        $operation = sanitize_key((string) ($_POST['finance_operation'] ?? ''));
        $result = 'saved';
        $errorCode = '';
        try {
            if ($operation === 'save_regional_price') {
                (new RegionalPricingService())->saveRegion(array(
                    'region_code' => (string) ($_POST['region_code'] ?? ''),
                    'currency' => (string) ($_POST['currency'] ?? ''),
                    'standard_price' => (string) ($_POST['standard_price'] ?? ''),
                    'introductory_mode' => (string) ($_POST['introductory_mode'] ?? RegionalPricingRule::INTRO_FREE),
                    'introductory_price' => (string) ($_POST['introductory_price'] ?? ''),
                    'effective_from' => (string) ($_POST['effective_from'] ?? ''),
                ));
                $result = 'price_saved';
            } elseif ($operation === 'save_regional_pricing_bundle') {
                $regions = array();
                foreach ((array) ($_POST['regions'] ?? array()) as $code => $row) {
                    if (!is_array($row)) continue;
                    $regions[(string) $code] = array(
                        'standard_price' => (string) ($row['standard_price'] ?? ''),
                        'introductory_price' => (string) ($row['introductory_price'] ?? ''),
                    );
                }
                (new RegionalPricingService())->saveBundle(array(
                    'introductory_mode' => (string) ($_POST['introductory_mode'] ?? RegionalPricingRule::INTRO_FREE),
                    'effective_from' => (string) ($_POST['effective_from'] ?? ''),
                    'regions' => $regions,
                ));
                $result = 'price_bundle_saved';
            } elseif ($operation === 'save_intro_payability') {
                $value = isset($_POST['intro_payability']) ? 'payable' : 'non_payable';
                $now = gmdate('Y-m-d H:i:s');
                $service = new FinancePolicyService();
                $prior = $service->history('INTRO_PAYABILITY_POLICY');
                $service->record('INTRO_PAYABILITY_POLICY', array(
                    'policy_value' => $value,
                    'value_type' => 'policy_reference',
                    'effective_from' => $now,
                    'evidence_channel' => 'authenticated_platform',
                    'evidence_reference' => 'finance-settings:intro-payability:' . $now,
                    'evidence_at' => $now,
                ), 'finance-settings-intro-payability-' . $now . '-' . wp_generate_uuid4());
                foreach ($prior as $row) {
                    if ((string) $row['status'] === 'active') {
                        try {
                            $service->supersede('INTRO_PAYABILITY_POLICY', (int) $row['policy_version'], array(
                                'evidence_channel' => 'authenticated_platform',
                                'evidence_reference' => 'finance-settings:intro-payability-supersede:' . $now,
                                'evidence_at' => $now,
                            ), 'finance-settings-intro-payability-supersede-' . (int) $row['policy_version'] . '-' . $now);
                        } catch (\Throwable) { /* a concurrent supersession leaves the recorded version intact */ }
                    }
                }
                $result = 'intro_payability_saved';
            } elseif ($operation === 'copy_golden_hour_rule') {
                $source = absint($_POST['golden_rule_index'] ?? 999);
                $days = array_values(array_unique(array_filter(array_map('absint', (array)($_POST['copy_days'] ?? array())), static fn(int $day): bool => $day >= 1 && $day <= 7)));
                if ($days === array()) throw new \InvalidArgumentException('golden_hour_weekdays_invalid');
                $current = (new GoldenHourPolicyService())->latest(GoldenHourRule::GLOBAL_IRAN_KEY);
                $rules = self::goldenRules($current);
                if (!isset($rules[$source])) throw new \InvalidArgumentException('golden_hour_rule_required');
                $base = $rules[$source];
                $baseDays = array_map('intval', (array)($base['weekdays'] ?? array()));
                $days = array_values(array_diff($days, $baseDays));
                if ($days === array()) throw new \InvalidArgumentException('golden_hour_weekdays_invalid');
                foreach ($days as $day) {
                    $rules[] = array(
                        'weekdays' => array($day),
                        'start' => (string)$base['start'],
                        'end' => (string)$base['end'],
                        'bonus_percentage' => number_format(((int)$base['bonus_basis_points']) / 100, 2, '.', ''),
                        'effective_from' => (string)($base['effective_from'] ?? gmdate('Y-m-d H:i:s')),
                    );
                }
                (new GoldenHourPolicyService())->save(GoldenHourRule::GLOBAL_IRAN_KEY, array(
                    'enabled' => !empty($current['enabled']) ? '1' : '',
                    'timezone' => GoldenHourRule::GLOBAL_IRAN_TIMEZONE,
                    'rules' => $rules,
                    'effective_from' => gmdate('Y-m-d H:i:s'),
                    'effective_until' => '',
                ));
                $result = 'golden_hour_rule_copied';
            } elseif ($operation === 'retire_golden_hour_rule') {
                $index = absint($_POST['golden_rule_index'] ?? 999);
                $current = (new GoldenHourPolicyService())->latest(GoldenHourRule::GLOBAL_IRAN_KEY);
                $rules = self::goldenRules($current);
                if (!isset($rules[$index])) throw new \InvalidArgumentException('golden_hour_rule_required');
                unset($rules[$index]);
                $rules = array_values($rules);
                if ($rules === array()) throw new \InvalidArgumentException('golden_hour_period_required');
                (new GoldenHourPolicyService())->save(GoldenHourRule::GLOBAL_IRAN_KEY, array(
                    'enabled' => !empty($current['enabled']) ? '1' : '',
                    'timezone' => GoldenHourRule::GLOBAL_IRAN_TIMEZONE,
                    'rules' => $rules,
                    'effective_from' => gmdate('Y-m-d H:i:s'),
                    'effective_until' => '',
                ));
                $result = 'golden_hour_rule_retired';
            } elseif ($operation === 'save_global_teacher_rate') {
                (new FinanceSettingsService())->saveGlobalTeacherRate(array(
                    'amount' => (string) ($_POST['teacher_global_amount'] ?? ''),
                    'effective_from' => (string) ($_POST['teacher_global_effective_from'] ?? ''),
                    'apply_to_teachers' => isset($_POST['apply_to_teachers']) ? '1' : '0',
                ));
                $result = 'teacher_default_saved';
            } elseif ($operation === 'save_teacher_rate') {
                (new FinanceSettingsService())->saveTeacherRate(absint($_POST['teacher_id'] ?? 0), array(
                    'amount' => (string) ($_POST['teacher_amount'] ?? ''),
                    'effective_from' => (string) ($_POST['teacher_effective_from'] ?? ''),
                    'scope_kind' => (string) ($_POST['scope_kind'] ?? 'teacher'),
                    'course_scope_id' => absint($_POST['course_scope_id'] ?? 0),
                    'evidence_reference' => (string) ($_POST['rate_evidence_reference'] ?? ''),
                ));
                $result = 'teacher_rate_saved';
            } elseif ($operation === 'resume_global_teacher_rate') {
                (new FinanceSettingsService())->resumeGlobalTeacherRate(absint($_POST['teacher_id'] ?? 0));
                $result = 'teacher_default_resumed';
            } elseif ($operation === 'save_template') {
                (new FinanceSettingsService())->saveTemplate(array(
                    'header_html' => (string) ($_POST['header_html'] ?? ''),
                    'body_html' => (string) ($_POST['body_html'] ?? ''),
                    'footer_html' => (string) ($_POST['footer_html'] ?? ''),
                    'css' => (string) ($_POST['css'] ?? ''),
                    'logo_url' => (string) ($_POST['logo_url'] ?? ''),
                    'locale' => (string) ($_POST['locale'] ?? 'fa'),
                ));
                $result = 'template_saved';
            } elseif ($operation === 'reset_template') {
                (new FinanceSettingsService())->resetTemplate();
                $result = 'template_reset';
            } elseif ($operation === 'save_golden_hour_policy') {
                (new GoldenHourPolicyService())->save(GoldenHourRule::GLOBAL_IRAN_KEY, self::goldenHourInput());
                $result = 'golden_hour_saved';
            } elseif ($operation === 'edit_golden_hour_policy') {
                (new GoldenHourPolicyService())->editVersion(absint($_POST['golden_setting_id'] ?? 0), self::goldenHourInput());
                $result = 'golden_hour_updated';
            } elseif ($operation === 'remove_golden_hour_policy') {
                (new GoldenHourPolicyService())->removeVersion(absint($_POST['golden_setting_id'] ?? 0));
                $result = 'golden_hour_removed';
            } elseif ($operation === 'save_finance_policy') {
                self::saveFinancePolicy();
                $result = 'policy_saved';
            } elseif ($operation === 'save_commercial_policy') {
                self::saveCommercialPolicy();
                $result = 'policy_saved';
            } else {
                throw new \InvalidArgumentException('unknown_finance_operation');
            }
        } catch (\Throwable $error) {
            $result = 'error';
            $message = trim($error->getMessage());
            if (preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1) $errorCode = $message;
        }
        $args = array('page' => self::MENU_SLUG, 'tab' => self::TAB_SETTINGS, 'finance_result' => $result);
        if ($errorCode !== '') $args['finance_error'] = $errorCode;
        $teacherId = absint($_POST['teacher_id'] ?? 0);
        if ($teacherId > 0) $args['teacher_id'] = $teacherId;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /** Create or deactivate one Student discount code from Finance → Payments. */
    public static function handlePromotion(): void {
        if (!current_user_can('dzn_manage_commercial_promotions')) wp_die('Forbidden', 403);
        check_admin_referer('dzn_finance_promotion');
        $_POST = wp_unslash($_POST);
        $operation = sanitize_key((string) ($_POST['promotion_operation'] ?? ''));
        $result = 'error';
        $errorCode = '';
        $promotionId = 0;
        try {
            $service = new CommercialPromotionService();
            if ($operation === 'create') {
                $code = strtoupper(trim((string) ($_POST['promotion_code'] ?? '')));
                if (preg_match('/^[A-Z0-9._-]{3,32}$/D', $code) !== 1) {
                    throw new \InvalidArgumentException('promotion_code_invalid');
                }
                $percentage = trim(str_replace(',', '.', (string) ($_POST['promotion_percentage'] ?? '')));
                $basisPoints = self::promotionPercentageBasisPoints($percentage);
                $maxRedemptions = self::promotionLimit($_POST['max_redemptions'] ?? '', 1000000, true, 'promotion_limit_invalid');
                $perBeneficiary = self::promotionLimit($_POST['per_beneficiary_limit'] ?? '1', 100, false, 'promotion_limit_invalid');
                $validFrom = self::promotionValidityDate($_POST['valid_from'] ?? '', false);
                $validUntil = self::promotionValidityDate($_POST['valid_until'] ?? '', true);
                if ($validFrom !== null && $validUntil !== null && $validUntil <= $validFrom) {
                    throw new \InvalidArgumentException('promotion_validity_invalid');
                }
                $created = $service->define(array(
                    'promotion_code' => $code,
                    'name' => 'Student percentage discount',
                    'kind' => 'percentage',
                    'percentage_bp' => $basisPoints,
                    'first_term_only' => false,
                    'max_redemptions' => $maxRedemptions,
                    'per_beneficiary_limit' => $perBeneficiary,
                    'stackable' => isset($_POST['stackable']) ? 1 : 0,
                    'valid_from' => $validFrom,
                    'valid_until' => $validUntil,
                    'status' => 'active',
                    'evidence_channel' => 'staff_record',
                    'evidence_reference' => 'admin-promotion-' . strtolower($code) . '-' . gmdate('YmdHis'),
                    'evidence_at' => gmdate('Y-m-d H:i:s'),
                ), 'admin-promotion-' . strtolower($code) . '-' . wp_generate_uuid4());
                $promotionId = (int) ($created['promotion_id'] ?? 0);
                $result = 'promotion_created';
            } elseif ($operation === 'deactivate') {
                $promotionId = absint($_POST['promotion_id'] ?? 0);
                if ($promotionId < 1) throw new \InvalidArgumentException('promotion_required');
                $service->updateStatus($promotionId, array(
                    'status' => 'inactive',
                    'reason_code' => 'admin_deactivated',
                    'evidence_channel' => 'staff_record',
                    'evidence_reference' => 'admin-promotion-deactivate-' . $promotionId . '-' . gmdate('YmdHis'),
                    'evidence_at' => gmdate('Y-m-d H:i:s'),
                ), 'admin-promotion-deactivate-' . $promotionId . '-' . wp_generate_uuid4());
                $result = 'promotion_deactivated';
            } else {
                throw new \InvalidArgumentException('promotion_operation_invalid');
            }
        } catch (\Throwable $error) {
            $message = trim($error->getMessage());
            $errorCode = preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'promotion_operation_failed';
        }
        $args = array('page' => self::MENU_SLUG, 'tab' => self::TAB_PAYMENTS, 'finance_result' => $result);
        if ($errorCode !== '') $args['finance_error'] = $errorCode;
        if ($promotionId > 0) $args['finance_promotion_id'] = $promotionId;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . '#discount-codes');
        exit;
    }

    /** Parse a human percentage (including 100) into exact integer basis points. */
    private static function promotionPercentageBasisPoints(string $value): int {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/D', $value, $matches) !== 1) {
            throw new \InvalidArgumentException('promotion_percentage_invalid');
        }
        $whole = (int) $matches[1];
        $fraction = isset($matches[2]) ? str_pad($matches[2], 2, '0') : '00';
        $basisPoints = $whole * 100 + (int) $fraction;
        if ($basisPoints < 1 || $basisPoints > 10000) {
            throw new \InvalidArgumentException('promotion_percentage_invalid');
        }
        return $basisPoints;
    }

    /** Parse an optional positive usage limit without accepting floats or signs. */
    private static function promotionLimit(mixed $value, int $max, bool $nullable, string $error): ?int {
        $value = trim((string) $value);
        if ($value === '') {
            if ($nullable) return null;
            throw new \InvalidArgumentException($error);
        }
        if (preg_match('/^\d{1,8}$/D', $value) !== 1) throw new \InvalidArgumentException($error);
        $limit = (int) $value;
        if ($limit < 1 || $limit > $max) throw new \InvalidArgumentException($error);
        return $limit;
    }

    /**
     * Convert an optional operator date (YYYY-MM-DD) into a UTC validity boundary.
     *
     * The form exposes dates, not instants: a start date begins at 00:00:00 UTC and an end date
     * covers the whole day through 23:59:59 UTC. Empty means "no bound".
     */
    private static function promotionValidityDate(mixed $value, bool $endOfDay): ?string {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('promotion_validity_invalid');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$parsed || $parsed->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('promotion_validity_invalid');
        }
        return $parsed->format('Y-m-d') . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
    }

    /** Read-only CSV export for payments, refunds or teacher statements. */
    public static function handleExport(): void {
        if (!is_user_logged_in()) wp_die('Forbidden', 403);
        check_admin_referer('dzn_finance_export');
        $_POST = wp_unslash($_POST);
        $type = sanitize_key((string) ($_POST['export_type'] ?? ''));
        try {
            $service = new FinanceExportService();
            $csv = match ($type) {
                'payments' => $service->payments(),
                'refunds' => $service->refunds(),
                'statements' => $service->statements(),
                default => throw new \InvalidArgumentException('export_type_invalid'),
            };
        } catch (\Throwable) {
            wp_die('Export unavailable', 403);
        }
        $filename = 'delnavazan-' . $type . '-' . gmdate('Ymd-His') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF" . $csv;
        exit;
    }

    /** Record an administrator-verified offline payment against one canonical obligation. */
    public static function handleManualPayment(): void {
        if (!current_user_can(ManualPaymentSettlementService::MANAGE_CAPABILITY)) wp_die('Forbidden', 403);
        check_admin_referer('dzn_finance_manual_payment');
        $_POST = wp_unslash($_POST);
        $kind = sanitize_key((string) ($_POST['payment_kind'] ?? ''));
        $commandKey = sanitize_text_field((string) ($_POST['command_key'] ?? ''));
        $args = array('page' => self::MENU_SLUG, 'tab' => self::TAB_PAYMENTS);
        try {
            $service = new ManualPaymentSettlementService();
            if ($kind === 'standard') {
                $service->markStandardPaid(array(
                    'offer_uid' => (string) ($_POST['offer_uid'] ?? ''),
                    'obligation_sequence' => (string) ($_POST['obligation_sequence'] ?? ''),
                    'evidence_reference' => (string) ($_POST['evidence_reference'] ?? ''),
                    'received_on' => (string) ($_POST['received_on'] ?? ''),
                ), $commandKey);
            } elseif ($kind === 'intro') {
                $service->markIntroPaid(array(
                    'obligation_uid' => (string) ($_POST['obligation_uid'] ?? ''),
                    'evidence_reference' => (string) ($_POST['evidence_reference'] ?? ''),
                    'received_on' => (string) ($_POST['received_on'] ?? ''),
                ), $commandKey);
            } else {
                throw new \InvalidArgumentException('manual_payment_kind_invalid');
            }
            $args['finance_result'] = 'payment_marked_paid';
        } catch (\Throwable $error) {
            $args['finance_result'] = 'error';
            $message = trim($error->getMessage());
            $args['finance_error'] = preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'manual_payment_failed';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . '#outstanding-payments');
        exit;
    }

    /** Draft, issue, withdraw or supersede a teacher compensation statement. */
    public static function handleStatement(): void {
        if (!current_user_can('dzn_manage_finance_statements')) wp_die('Forbidden', 403);
        check_admin_referer('dzn_finance_statement');
        $_POST = wp_unslash($_POST);
        $operation = sanitize_key((string) ($_POST['statement_operation'] ?? ''));
        $teacherId = absint($_POST['teacher_id'] ?? 0);
        $statementId = absint($_POST['statement_id'] ?? 0);
        $args = array('page' => self::MENU_SLUG, 'tab' => self::TAB_STATEMENTS);
        if ($teacherId > 0) $args['teacher_id'] = $teacherId;
        if ($statementId > 0) $args['statement_id'] = $statementId;
        try {
            $service = new TeacherStatementService();
            $now = gmdate('Y-m-d H:i:s');
            $key = 'finance-statement-' . $operation . '-' . wp_generate_uuid4();
            if ($operation === 'draft') {
                $start = self::statementDate((string) ($_POST['period_start'] ?? ''));
                $end = self::statementDate((string) ($_POST['period_end'] ?? ''), true);
                $result = $service->draft($teacherId, $start, $end, $key);
                $args['statement_id'] = (int) $result['statement_id'];
                $args['finance_result'] = 'statement_drafted';
            } elseif ($operation === 'issue') {
                if ($statementId < 1) throw new \InvalidArgumentException('statement_required');
                $service->issue($statementId, $key);
                $args['finance_result'] = 'statement_issued';
            } elseif ($operation === 'withdraw') {
                if ($statementId < 1) throw new \InvalidArgumentException('statement_required');
                $service->withdraw($statementId, array(
                    'reason_code' => 'operator_decision',
                    'evidence_channel' => 'authenticated_platform',
                    'evidence_reference' => 'finance-statement-withdraw:' . $statementId . ':' . $now,
                    'evidence_at' => $now,
                ), $key);
                $args['finance_result'] = 'statement_withdrawn';
            } elseif ($operation === 'supersede') {
                if ($statementId < 1) throw new \InvalidArgumentException('statement_required');
                $result = $service->supersede($statementId, array(
                    'reason_code' => 'operator_decision',
                    'evidence_channel' => 'authenticated_platform',
                    'evidence_reference' => 'finance-statement-supersede:' . $statementId . ':' . $now,
                    'evidence_at' => $now,
                ), $key);
                $args['statement_id'] = (int) ($result['statement_id'] ?? $statementId);
                $args['finance_result'] = 'statement_superseded';
            } elseif ($operation === 'record_payment') {
                if ($statementId < 1) throw new \InvalidArgumentException('statement_required');
                $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
                $paidAt = $now;
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $paymentDate) === 1) {
                    $paidAt = $paymentDate === gmdate('Y-m-d') ? $now : $paymentDate . ' 23:59:59';
                }
                (new \Delnavazan\Platform\Core\Application\Finance\Statements\TeacherStatementPaymentService())->record($statementId, array(
                    'paid_at' => $paidAt,
                    'method_code' => sanitize_key((string) ($_POST['payment_method'] ?? 'manual_bank_transfer')),
                    'evidence_channel' => 'authenticated_platform',
                    'evidence_reference' => sanitize_text_field((string) ($_POST['payment_reference'] ?? '')),
                    'reason' => 'operator_decision',
                ), $key);
                $args['finance_result'] = 'statement_paid';
            } else {
                throw new \InvalidArgumentException('statement_operation_invalid');
            }
        } catch (\Throwable $error) {
            $args['finance_result'] = 'error';
            $message = trim($error->getMessage());
            $args['finance_error'] = preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'statement_operation_failed';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . '#teacher-statements');
        exit;
    }

    /** Email one issued statement to its recorded Teacher address. */
    public static function handleStatementEmail(): void {
        if (!current_user_can('dzn_manage_finance_statements')) wp_die('Forbidden', 403);
        check_admin_referer('dzn_finance_statement_email');
        $_POST = wp_unslash($_POST);
        $statementId = absint($_POST['statement_id'] ?? 0);
        $teacherId = absint($_POST['teacher_id'] ?? 0);
        $args = array('page' => self::MENU_SLUG, 'tab' => self::TAB_STATEMENTS);
        if ($teacherId > 0) $args['teacher_id'] = $teacherId;
        if ($statementId > 0) $args['statement_id'] = $statementId;
        try {
            if ($statementId < 1) throw new \InvalidArgumentException('statement_required');
            $result = (new TeacherStatementEmailService())->enqueue($statementId, isset($_POST['retry_email']));
            if (!empty($result['queued'])) {
                $args['finance_result'] = (string) ($result['reason'] ?? '') === 'already_transport_accepted' ? 'statement_email_already_accepted' : 'statement_email_queued';
            } else {
                $args['finance_result'] = 'statement_email_failed';
                if (!empty($result['reason'])) $args['finance_error'] = (string) $result['reason'];
            }
        } catch (\Throwable $error) {
            $args['finance_result'] = 'error';
            $message = trim($error->getMessage());
            $args['finance_error'] = preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'statement_email_failed';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . '#teacher-statements');
        exit;
    }

    /** Generate one statement per Teacher with active work in the chosen completed month. */
    public static function handleStatementBatch(): void {
        if (!current_user_can('dzn_manage_finance_statements')) wp_die('Forbidden', 403);
        check_admin_referer('dzn_finance_statement_batch');
        $_POST = wp_unslash($_POST);
        $month = sanitize_text_field((string) ($_POST['batch_month'] ?? ''));
        $args = array('page' => self::MENU_SLUG, 'tab' => self::TAB_STATEMENTS, 'batch_month' => $month);
        try {
            $result = (new TeacherStatementBatchService())->run($month, array(
                'issue' => isset($_POST['batch_issue']) ? '1' : '',
                'email' => isset($_POST['batch_email']) ? '1' : '',
            ), sanitize_text_field((string) ($_POST['command_key'] ?? '')));
            set_transient('dzn_finance_statement_batch_' . get_current_user_id(), $result, 15 * MINUTE_IN_SECONDS);
            $args['finance_result'] = 'statement_batch_complete';
        } catch (\Throwable $error) {
            $args['finance_result'] = 'error';
            $message = trim($error->getMessage());
            $args['finance_error'] = preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'statement_batch_failed';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . '#statement-batch');
        exit;
    }

    /** Generate drafts, issue reviewed drafts, or send issued statements. */
    public static function handleStatementWorkflow(): void {
        if (!current_user_can(TeacherStatementBatchWorkflowService::MANAGE_CAPABILITY)) wp_die('Forbidden', 403);
        check_admin_referer('dzn_finance_statement_workflow');
        $_POST = wp_unslash($_POST);
        $operation = sanitize_key((string) ($_POST['workflow_operation'] ?? ''));
        $args = array('page' => self::MENU_SLUG, 'tab' => self::TAB_STATEMENTS);
        try {
            $period = self::workflowPeriod();
            $workflow = new TeacherStatementBatchWorkflowService();
            $key = sanitize_text_field((string) ($_POST['command_key'] ?? ''));
            if ($key === '') $key = wp_generate_uuid4();
            $teacherIds = array_map('absint', (array) ($_POST['teacher_ids'] ?? array()));
            $statementIds = array_map('absint', (array) ($_POST['statement_ids'] ?? array()));
            if ($operation === 'generate') {
                $result = $workflow->generateDrafts($period, $teacherIds, $key);
                $args['finance_result'] = 'statement_workflow_generated';
                $args['workflow'] = 'drafts';
            } elseif ($operation === 'issue') {
                $result = $workflow->issue($statementIds, $period, $key);
                $args['finance_result'] = 'statement_workflow_issued';
                $args['workflow'] = 'issue';
            } elseif ($operation === 'send') {
                $result = $workflow->send($statementIds, $period, $key);
                $args['finance_result'] = 'statement_workflow_sent';
                $args['workflow'] = 'send';
            } else {
                throw new \InvalidArgumentException('statement_workflow_operation_invalid');
            }
            set_transient('dzn_finance_statement_workflow_' . get_current_user_id(), $result, 30 * MINUTE_IN_SECONDS);
        } catch (\Throwable $error) {
            $args['finance_result'] = 'error';
            $message = trim($error->getMessage());
            $args['finance_error'] = preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'statement_workflow_failed';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . '#teacher-statement-workflow');
        exit;
    }

    /** Send an administrative batch summary to one staff address. */
    public static function handleStatementStaffSummary(): void {
        if (!current_user_can(TeacherStatementBatchWorkflowService::MANAGE_CAPABILITY)) wp_die('Forbidden', 403);
        check_admin_referer('dzn_finance_statement_staff_summary');
        $_POST = wp_unslash($_POST);
        $args = array('page' => self::MENU_SLUG, 'tab' => self::TAB_STATEMENTS);
        try {
            $result = get_transient('dzn_finance_statement_workflow_' . get_current_user_id());
            if (!is_array($result)) throw new \InvalidArgumentException('statement_workflow_result_required');
            $period = self::workflowPeriod();
            $sent = (new TeacherStatementBatchWorkflowService())->sendStaffSummary(
                $result,
                sanitize_text_field((string) ($_POST['staff_summary_email'] ?? '')),
                $period,
                sanitize_text_field((string) ($_POST['command_key'] ?? wp_generate_uuid4()))
            );
            $args['finance_result'] = !empty($sent['sent']) ? 'statement_staff_summary_sent' : 'statement_staff_summary_failed';
            if (empty($sent['sent'])) $args['finance_error'] = 'staff_summary_transport_failed';
        } catch (\Throwable $error) {
            $args['finance_result'] = 'error';
            $message = trim($error->getMessage());
            $args['finance_error'] = preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'statement_staff_summary_failed';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . '#statement-batch-history');
        exit;
    }

    /** Download the Student receipts report as an auditable CSV. */
    public static function handleReceiptsReport(): void {
        if (!current_user_can(StudentReceiptsReportService::VIEW_CAPABILITY)) wp_die('Forbidden', 403);
        check_admin_referer('dzn_finance_receipts_report');
        $_POST = wp_unslash($_POST);
        try {
            $report = (new StudentReceiptsReportService())->report(array(
                'month' => sanitize_text_field((string) ($_POST['report_month'] ?? '')),
                'start' => sanitize_text_field((string) ($_POST['report_start'] ?? '')),
                'end' => sanitize_text_field((string) ($_POST['report_end'] ?? '')),
            ));
            $csv = (new StudentReceiptsReportService())->csv($report);
        } catch (\Throwable) {
            wp_die('The receipts report could not be produced. No financial record was changed.', 'Report unavailable', array('response' => 400));
        }
        $filename = 'delnavazan-student-receipts-' . preg_replace('/[^0-9A-Za-z_-]+/', '-', (string) $report['period_label']) . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF" . $csv;
        exit;
    }

    /** @return array<string,mixed> */
    private static function workflowPeriod(): array {
        $batch = new TeacherStatementBatchService();
        $mode = sanitize_key((string) ($_POST['period_mode'] ?? 'persian_month'));
        if ($mode === 'custom') {
            return $batch->customPeriod(
                sanitize_text_field((string) ($_POST['period_start'] ?? '')),
                sanitize_text_field((string) ($_POST['period_end'] ?? ''))
            );
        }
        $month = sanitize_text_field((string) ($_POST['persian_month'] ?? ''));
        if ($month === '') $month = PersianCalendar::previousMonthForInstant(gmdate('Y-m-d H:i:s'))['key'];
        return $batch->persianPeriod($month);
    }

    /** Record one administrator-only manual refund and its review case. */
    public static function handleRefund(): void {
        if (!current_user_can(ManualRefundService::MANAGE_CAPABILITY)) wp_die('Forbidden', 403);
        check_admin_referer('dzn_finance_refund');
        $_POST = wp_unslash($_POST);
        $args = array('page' => self::MENU_SLUG, 'tab' => self::TAB_PAYMENTS);
        try {
            $result = (new ManualRefundService())->record(array(
                'offer_uid' => (string) ($_POST['offer_uid'] ?? ''),
                'obligation_sequence' => (string) ($_POST['obligation_sequence'] ?? ''),
                'amount' => (string) ($_POST['refund_amount'] ?? ''),
                'evidence_reference' => (string) ($_POST['evidence_reference'] ?? ''),
                'refunded_on' => (string) ($_POST['refunded_on'] ?? ''),
                'note' => (string) ($_POST['refund_note'] ?? ''),
            ), sanitize_text_field((string) ($_POST['command_key'] ?? '')));
            $args['finance_result'] = 'refund_recorded';
            if ((int) ($result['refund_review_id'] ?? 0) > 0) $args['finance_refund_review_id'] = (int) $result['refund_review_id'];
        } catch (\Throwable $error) {
            $args['finance_result'] = 'error';
            $message = trim($error->getMessage());
            $args['finance_error'] = preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'refund_failed';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . '#refund-reviews');
        exit;
    }

    /** Release a pre-evidence reservation that was interrupted before evidence existed. */
    public static function handleRefundRelease(): void {
        if (!current_user_can('dzn_manage_refund_reviews')) wp_die('Forbidden', 403);
        check_admin_referer('dzn_finance_refund_release');
        $_POST = wp_unslash($_POST);
        $args = array('page' => self::MENU_SLUG, 'tab' => self::TAB_PAYMENTS);
        try {
            (new ManualRefundService())->release(
                absint($_POST['reservation_id'] ?? 0),
                sanitize_key((string) ($_POST['reason_code'] ?? 'operator_released')),
                sanitize_text_field((string) ($_POST['command_key'] ?? ''))
            );
            $args['finance_result'] = 'refund_reservation_released';
        } catch (\Throwable $error) {
            $args['finance_result'] = 'error';
            $message = trim($error->getMessage());
            $args['finance_error'] = preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'refund_reservation_release_failed';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . '#refund-reviews');
        exit;
    }

    /** Route, resolve or dismiss one manual refund review. */
    public static function handleRefundReview(): void {
        if (!current_user_can('dzn_manage_refund_reviews')) wp_die('Forbidden', 403);
        check_admin_referer('dzn_finance_refund_review');
        $_POST = wp_unslash($_POST);
        $operation = sanitize_key((string) ($_POST['refund_operation'] ?? ''));
        $caseId = absint($_POST['refund_review_id'] ?? 0);
        $args = array('page' => self::MENU_SLUG, 'tab' => self::TAB_PAYMENTS);
        try {
            if ($caseId < 1) throw new \InvalidArgumentException('refund_review_required');
            $now = gmdate('Y-m-d H:i:s');
            $service = new RefundReviewService();
            $proof = array(
                'evidence_channel' => 'staff_record',
                'evidence_reference' => 'finance-refund-review:' . $operation . ':' . $caseId . ':' . $now,
                'evidence_at' => $now,
            );
            $key = 'finance-refund-review-' . $operation . '-' . $caseId . '-' . wp_generate_uuid4();
            if ($operation === 'route') {
                $service->routeForReview($caseId, $proof, $key);
                $args['finance_result'] = 'refund_review_routed';
            } elseif ($operation === 'resolve') {
                $service->resolve($caseId, $proof + array('resolution_note' => sanitize_textarea_field((string) ($_POST['resolution_note'] ?? ''))), $key);
                $args['finance_result'] = 'refund_review_resolved';
            } elseif ($operation === 'dismiss') {
                $service->dismiss($caseId, $proof, $key);
                $args['finance_result'] = 'refund_review_dismissed';
            } else {
                throw new \InvalidArgumentException('refund_review_operation_invalid');
            }
        } catch (\Throwable $error) {
            $args['finance_result'] = 'error';
            $message = trim($error->getMessage());
            $args['finance_error'] = preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'refund_review_failed';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . '#refund-reviews');
        exit;
    }

    /** Enable/disable monthly draft-only scheduling or run the previous month now. */
    public static function handleStatementSchedule(): void {
        if (!current_user_can('dzn_manage_finance_statements')) wp_die('Forbidden', 403);
        check_admin_referer('dzn_finance_statement_schedule');
        $_POST = wp_unslash($_POST);
        $operation = sanitize_key((string) ($_POST['schedule_operation'] ?? ''));
        $args = array('page' => self::MENU_SLUG, 'tab' => self::TAB_STATEMENTS);
        try {
            $schedule = new TeacherStatementScheduleService();
            if ($operation === 'enable') { $schedule->setEnabled(true); $args['finance_result'] = 'statement_schedule_enabled'; }
            elseif ($operation === 'disable') { $schedule->setEnabled(false); $args['finance_result'] = 'statement_schedule_disabled'; }
            elseif ($operation === 'run') {
                $result = $schedule->runPreviousMonth(false);
                $args['finance_result'] = (string) ($result['state'] ?? '') === 'completed' ? 'statement_schedule_ran' : 'statement_schedule_failed';
            } else throw new \InvalidArgumentException('statement_schedule_operation_invalid');
        } catch (\Throwable $error) {
            $args['finance_result'] = 'error';
            $message = trim($error->getMessage());
            $args['finance_error'] = preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'statement_schedule_failed';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . '#statement-schedule');
        exit;
    }

    /** The statement period is a half-open UTC range: start date 00:00 to the day after the inclusive end. */
    private static function statementDate(string $date, bool $inclusiveEnd = false): string {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) throw new \InvalidArgumentException('statement_period_invalid');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new \InvalidArgumentException('statement_period_invalid');
        if ($inclusiveEnd) $parsed = $parsed->modify('+1 day');
        return $parsed->format('Y-m-d') . ' 00:00:00';
    }

    public static function exportStatement(): void {
        if (!current_user_can('dzn_view_finance_authority')) wp_die('Forbidden', 403);
        check_admin_referer('dzn_finance_statement_export');
        $_POST = wp_unslash($_POST);
        $statementId = absint($_POST['statement_id'] ?? 0);
        $download = !empty($_POST['download']);
        try {
            $statement = (new TeacherStatementReadService())->statement($statementId);
            $teacher = (new FinanceAdminReadService())->teacherIdentity((int) $statement['teacher_id']);
            $statement['teacher_name'] = $teacher['name'] ?? '';
            $statement['teacher_reference'] = $teacher['reference'] ?? '';
            $lines = (new FinanceAdminReadService())->statementLines($statementId);
            $html = (new StatementTemplateService())->render($statement, $lines, (new FinanceSettingsService())->template(), self::academyName());
            nocache_headers();
            header('Content-Type: text/html; charset=utf-8');
            if ($download) header('Content-Disposition: attachment; filename="teacher-statement-' . $statementId . '.html"');
            echo $html;
            exit;
        } catch (\Throwable) {
            wp_die('The statement could not be rendered. No financial record was changed.', 'Statement unavailable', array('response' => 404));
        }
    }

    private static function renderPayments(FinanceAdminReadService $read): void {
        if (!current_user_can('dzn_view_payment_execution_authority')) { self::denied('Payment execution view authority is required.'); return; }
        $redesign = new FinanceAdminRedesignReadService();
        $counts = $read->paymentCounts();
        $summary = array();
        try { $summary = $redesign->financeSummary(); } catch (\Throwable) { $summary = array(); }
        echo '<section class="dzn-finance-panel"><h2>Student payments</h2><p>Confirmed settlements, outstanding obligations and administrator-recorded offline payments. A checkout attempt is never shown as received money; confirmed paid amounts come only from accepted settlement evidence.</p>';
        echo '<div class="dzn-finance-cards">';
        foreach (array('all' => 'All attempts', 'pending' => 'Pending', 'checkout_completed' => 'Checkout complete', 'failed' => 'Failed', 'settled' => 'Settled', 'refunded' => 'Refund evidence') as $key => $label) {
            echo '<a class="dzn-finance-card" href="' . esc_url(add_query_arg(array('page' => self::MENU_SLUG, 'tab' => self::TAB_PAYMENTS, 'state' => $key), admin_url('admin.php'))) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) ($counts[$key] ?? 0)) . '</strong></a>';
        }
        echo '</div>';
        if ($summary !== array()) self::renderFinanceSummaryStrip($summary, 'This month at a glance');
        echo '<p class="dzn-finance-toolbar"><a class="button" href="#active-student-payments">Active student payments</a> <a class="button" href="#student-receipts-report">Student receipts report</a> <a class="button" href="#refund-reviews">Refunds</a> <a class="button" href="#intro-payments">Introductory lesson payments</a></p>';
        $attention = array();
        try { $attention = $redesign->attention(); } catch (\Throwable) { $attention = array(); }
        self::renderAttention($attention);
        self::renderActiveStudentPayments($redesign);
        self::renderStudentPaymentDetail($redesign);
        self::renderRecentSettlements($read);
        self::renderOutstandingObligations($read);
        self::renderCheckoutAttempts($read, $counts);
        self::renderInstalmentSchedules($read);
        self::renderPromotionCodes();
        self::renderStudentReceiptsReport();
        self::renderRefundWorkspace($read);
        self::renderIntroPayments($read);
        if (current_user_can('dzn_manage_payment_providers') || current_user_can(PaymentExecutionController::CAPABILITY)) {
            echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=' . self::TAB_SETTINGS . '#stripe')) . '">Open Stripe configuration and readiness</a></p>';
        }
        echo '<p class="description">Failed or unmatched Stripe events are reconciled only through the existing guarded action in the payment execution service. No provider payload or secret is shown here.</p>';
        self::exportButton('payments', 'Export payments CSV');
        if (current_user_can(FinanceExportService::REFUNDS_CAPABILITY)) self::exportButton('refunds', 'Export refunds CSV');
        echo '</section>';
    }

    /** Shared summary strip used on Student payments and Settings. */
    public static function renderFinanceSummaryStrip(array $summary, string $heading = ''): void {
        if ($heading !== '') echo '<h3 class="dzn-finance-summary__heading">' . esc_html($heading) . '</h3>';
        echo '<div class="dzn-finance-summary">';
        $blocks = array(
            array('title' => 'Confirmed receipts this month', 'rows' => (array)($summary['receipts'] ?? array()), 'mode' => 'gross', 'source' => (string)($summary['receipts_source'] ?? 'Accepted settlement evidence.')),
            array('title' => 'Outstanding due this month', 'rows' => (array)($summary['due_this_month'] ?? array()), 'mode' => 'due', 'source' => (string)($summary['due_source'] ?? 'Canonical obligations.')),
            array('title' => 'Expected due next month', 'rows' => (array)($summary['due_next_month'] ?? array()), 'mode' => 'due', 'source' => (string)($summary['due_source'] ?? 'Canonical obligations.')),
        );
        foreach ($blocks as $block) {
            echo '<div class="dzn-finance-summary__block"><h4>' . esc_html($block['title']) . '</h4>';
            if (!$block['rows']) {
                echo '<p class="dzn-finance-muted">None recorded.</p>';
            } else {
                echo '<dl>';
                foreach ($block['rows'] as $row) {
                    $currency = (string)($row['currency'] ?? '');
                    $amount = $block['mode'] === 'gross' ? (int)($row['gross_minor'] ?? 0) : (int)($row['amount_minor'] ?? 0);
                    echo '<div><dt>' . esc_html($currency) . '</dt><dd>' . esc_html(PricingMoney::format($amount, $currency)) . '</dd></div>';
                }
                echo '</dl>';
            }
            echo '<p class="description">Source: ' . esc_html($block['source']) . '</p></div>';
        }
        echo '</div>';
    }

    /** Payments needing attention, including an urgent refund exception that is never hidden lower. */
    private static function renderAttention(array $attention): void {
        $counts = (array)($attention['counts'] ?? array());
        $urgent = (int)($counts['refund_cases'] ?? 0) + (int)($counts['refund_reservations'] ?? 0);
        echo '<section class="dzn-finance-attention" id="payments-needing-attention"><h3>Payments needing attention</h3>';
        if ($urgent > 0) {
            echo '<div class="notice notice-error inline"><p><strong>Urgent refund exception:</strong> ' . esc_html((string)$urgent) . ' refund case(s) or interrupted reservation(s) require an administrator decision. <a href="#refund-reviews">Open refunds</a>.</p></div>';
        }
        if ($counts === array() || array_sum(array_map('intval', $counts)) === 0) {
            echo '<p class="dzn-finance-muted">No failed attempts, unsettled completions, overdue obligations or refund exceptions.</p></section>';
            return;
        }
        echo '<div class="dzn-finance-cards dzn-finance-cards--attention">';
        foreach (array(
            'failed_checkout' => array('Failed checkout attempts', '#checkout-attempts'),
            'unsettled_checkout' => array('Checkout complete, not settled', '#checkout-attempts'),
            'overdue' => array('Overdue obligations', '#outstanding-payments'),
            'refund_cases' => array('Open refund reviews', '#refund-reviews'),
            'refund_reservations' => array('Interrupted refund reservations', '#refund-reviews'),
            'payability_pending' => array('Pending teacher payability', '#teacher-payment-summary'),
        ) as $key => $meta) {
            $count = (int)($counts[$key] ?? 0);
            if ($count < 1) continue;
            echo '<a class="dzn-finance-card' . ($key === 'refund_cases' || $key === 'refund_reservations' ? ' dzn-finance-card--urgent' : '') . '" href="' . esc_url($meta[1]) . '"><span>' . esc_html($meta[0]) . '</span><strong>' . esc_html((string)$count) . '</strong></a>';
        }
        echo '</div>';
        if (!empty($attention['failed'])) {
            echo '<details class="dzn-finance-subsection"><summary>Latest failed checkout attempts</summary><table class="widefat striped dzn-finance-table"><thead><tr><th>Created</th><th>Student</th><th>Offer</th><th>Amount</th><th>Reason</th></tr></thead><tbody>';
            foreach ((array)$attention['failed'] as $row) echo '<tr><td>' . esc_html((string)$row->created_at) . '</td><td>' . esc_html((string)($row->student_name ?: '—')) . '</td><td>' . esc_html((string)($row->offer_reference ?: '—')) . '</td><td>' . esc_html(PricingMoney::format((int)$row->amount_minor, (string)$row->currency)) . '</td><td>' . esc_html((string)($row->close_reason ?: 'Provider failure')) . '</td></tr>';
            echo '</tbody></table></details>';
        }
        if (!empty($attention['unsettled'])) {
            echo '<details class="dzn-finance-subsection"><summary>Checkout complete but settlement not accepted</summary><table class="widefat striped dzn-finance-table"><thead><tr><th>Created</th><th>Student</th><th>Offer</th><th>Amount</th></tr></thead><tbody>';
            foreach ((array)$attention['unsettled'] as $row) echo '<tr><td>' . esc_html((string)$row->created_at) . '</td><td>' . esc_html((string)($row->student_name ?: '—')) . '</td><td>' . esc_html((string)($row->offer_reference ?: '—')) . '</td><td>' . esc_html(PricingMoney::format((int)$row->amount_minor, (string)$row->currency)) . '</td></tr>';
            echo '</tbody></table></details>';
        }
        echo '</section>';
    }

    /** Searchable, sortable active-student payment table. */
    private static function renderActiveStudentPayments(FinanceAdminRedesignReadService $service): void {
        $filters = array(
            'q' => self::query('student_q'), 'sort' => self::query('student_sort'),
            'status' => self::query('student_status'),
        );
        $page = max(1, absint($_GET['student_paged'] ?? 1));
        $data = $service->activeStudentPayments($filters, $page, 25);
        echo '<section class="dzn-finance-settings-section" id="active-student-payments"><h3>Active student payments</h3>';
        echo '<p>Every current or authorised enrolment and its latest live offer. Confirmed paid is the sum of accepted settlements; outstanding is the canonical offer amount minus those settlements. Select a row to open that exact student and obligation.</p>';
        echo '<form method="get" class="dzn-finance-filter"><input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '"><input type="hidden" name="tab" value="' . esc_attr(self::TAB_PAYMENTS) . '">';
        echo '<label for="student-q">Search student, course, teacher, term or offer</label><input id="student-q" type="search" name="student_q" value="' . esc_attr($filters['q']) . '">';
        echo '<label for="student-status">Payment state</label><select id="student-status" name="student_status"><option value="">All active students</option><option value="outstanding"' . selected($filters['status'], 'outstanding', false) . '>Outstanding</option><option value="overdue"' . selected($filters['status'], 'overdue', false) . '>Overdue</option><option value="instalments"' . selected($filters['status'], 'instalments', false) . '>Instalment plans</option></select>';
        echo '<label for="student-sort">Sort</label><select id="student-sort" name="student_sort"><option value="student"' . selected($filters['sort'], 'student', false) . '>Student</option><option value="course"' . selected($filters['sort'], 'course', false) . '>Course</option><option value="teacher"' . selected($filters['sort'], 'teacher', false) . '>Teacher</option><option value="paid"' . selected($filters['sort'], 'paid', false) . '>Confirmed paid</option><option value="outstanding"' . selected($filters['sort'], 'outstanding', false) . '>Outstanding</option><option value="due"' . selected($filters['sort'], 'due', false) . '>Next due</option><option value="renewals"' . selected($filters['sort'], 'renewals', false) . '>Renewals</option></select>';
        echo '<button class="button">Apply</button><a class="button-link" href="' . esc_url(self::tabUrl(self::TAB_PAYMENTS) . '#active-student-payments') . '">Reset</a></form>';
        echo '<table class="widefat striped dzn-finance-table dzn-finance-table--students"><thead><tr><th>Student / enrolment</th><th>Course / teacher</th><th>Term / renewals</th><th>Plan / method</th><th>Confirmed paid</th><th>Outstanding</th><th>Next due</th><th>Days</th><th>Discounts</th><th>Replacements</th></tr></thead><tbody>';
        if (!$data['rows']) echo '<tr><td colspan="10">No active student payment row matches these filters.</td></tr>';
        foreach ($data['rows'] as $row) {
            $url = add_query_arg(array('page' => self::MENU_SLUG, 'tab' => self::TAB_PAYMENTS, 'student_id' => (int)$row['student_id'], 'offer_uid' => $row['offer_uid']), admin_url('admin.php')) . '#student-payment-detail';
            $days = $row['days_until_due'];
            $daysLabel = $days === null ? '—' : ($days < 0 ? 'Overdue ' . abs((int)$days) . 'd' : ((int)$days === 0 ? 'Due today' : (int)$days . 'd'));
            $currency = (string)$row['currency'];
            echo '<tr><td><a href="' . esc_url($url) . '"><strong>' . esc_html($row['student_name'] ?: 'Student #' . $row['student_id']) . '</strong></a><br><small>' . esc_html($row['student_reference']) . ' · ' . esc_html($row['enrolment_reference']) . '</small></td>';
            echo '<td>' . esc_html($row['course_name'] ?: '—') . '<br><small>' . esc_html($row['teacher_name'] ?: 'No teacher') . '</small></td>';
            echo '<td>' . esc_html($row['term_reference'] ?: '—') . '<br><small>' . esc_html((string)$row['renewals']) . ' renewal(s)</small></td>';
            echo '<td>' . esc_html($row['plan_label']) . '<br><small>' . esc_html($row['payment_method']) . '</small></td>';
            echo '<td>' . esc_html($currency !== '' ? PricingMoney::format((int)$row['confirmed_paid_minor'], $currency) : '—') . ($row['refunded_minor'] > 0 ? '<br><small>Refunded ' . esc_html(PricingMoney::format((int)$row['refunded_minor'], $currency)) . '</small>' : '') . '</td>';
            echo '<td><strong>' . esc_html($currency !== '' ? PricingMoney::format((int)$row['outstanding_minor'], $currency) : '—') . '</strong></td>';
            echo '<td>' . esc_html($row['next_due_label']) . '</td>';
            echo '<td>' . ($row['due_state'] === 'overdue' ? '<span class="dzn-finance-pill dzn-finance-pill--danger">' : '<span class="dzn-finance-pill">') . esc_html($daysLabel) . '</span></td>';
            echo '<td>' . esc_html($row['discount_label']) . '</td>';
            echo '<td>' . esc_html((string)$row['replacements_used']) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ((int)$data['pages'] > 1) {
            $base = array('page' => self::MENU_SLUG, 'tab' => self::TAB_PAYMENTS) + array_filter($filters, static fn($value) => $value !== '');
            echo '<p class="tablenav">Student page ' . esc_html((string)$data['page']) . ' of ' . esc_html((string)$data['pages']);
            if ((int)$data['page'] > 1) echo ' <a class="button" href="' . esc_url(add_query_arg($base + array('student_paged' => (int)$data['page'] - 1), admin_url('admin.php'))) . '#active-student-payments">Previous</a>';
            if ((int)$data['page'] < (int)$data['pages']) echo ' <a class="button" href="' . esc_url(add_query_arg($base + array('student_paged' => (int)$data['page'] + 1), admin_url('admin.php'))) . '#active-student-payments">Next</a>';
            echo '</p>';
        }
        echo '<p class="description">Days are counted in the academy reporting timezone, ' . esc_html(PersianCalendar::DEFAULT_TIMEZONE) . '. “Before Lesson 7” is shown only where the existing funding authority has no explicit due date; no deadline is invented.</p></section>';
    }

    /** Exact student and obligation context for a selected active-student row. */
    private static function renderStudentPaymentDetail(FinanceAdminRedesignReadService $service): void {
        $studentId = absint($_GET['student_id'] ?? 0);
        if ($studentId < 1) return;
        $offerUid = self::query('offer_uid');
        $detail = $service->studentPaymentDetail($studentId, $offerUid);
        if ($detail === null) {
            echo '<section class="dzn-finance-settings-section" id="student-payment-detail"><h3>Student payment detail</h3><p>That student could not be read.</p></section>';
            return;
        }
        echo '<section class="dzn-finance-settings-section" id="student-payment-detail"><h3>Student payment detail · ' . esc_html((string)$detail['student']['name']) . '</h3>';
        echo '<p>' . esc_html((string)$detail['student']['reference']) . ($detail['enrolment'] ? ' · enrolment ' . esc_html((string)$detail['enrolment']['reference']) . ' (' . esc_html((string)$detail['enrolment']['state']) . ')' : '') . '</p>';
        echo '<p>' . esc_html((string)($detail['course']['name'] ?? 'No course')) . ' · ' . esc_html((string)($detail['teacher']['name'] ?? 'No teacher')) . ' · ' . esc_html((string)($detail['term']['reference'] ?? 'No current term')) . '</p>';
        echo '<p><a class="button" href="' . esc_url(add_query_arg(array('page' => 'dzn-student', 'student_id' => (int)$detail['student']['id']), admin_url('admin.php'))) . '">Open student profile</a> <a class="button" href="' . esc_url(self::tabUrl(self::TAB_PAYMENTS) . '#active-student-payments') . '">Back to active students</a></p>';
        if ($detail['offer'] !== null) {
            $offer = $detail['offer'];
            echo '<p><strong>Offer ' . esc_html((string)$offer['reference']) . '</strong> · ' . esc_html((string)$offer['state']) . ' · ' . esc_html((string)$offer['plan_kind']) . ' · ' . esc_html(PricingMoney::format((int)$offer['amount_due_minor'], (string)$offer['currency'])) . ' · ' . esc_html((string)$offer['committed_sessions']) . ' lessons</p>';
            echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>Instalment</th><th>Lessons</th><th>Amount</th><th>Due</th><th>Settled</th><th>Refunded</th><th>State</th></tr></thead><tbody>';
            foreach ((array)$detail['obligations'] as $obligation) {
                $settled = $obligation->settled_at !== null;
                echo '<tr><td>#' . (int)$obligation->obligation_sequence . '</td><td>' . (int)$obligation->sessions_from . '–' . (int)$obligation->sessions_to . '</td><td>' . esc_html(PricingMoney::format((int)$obligation->amount_minor, (string)$obligation->currency)) . '</td><td>' . esc_html((string)($obligation->due_at ?? ($obligation->obligation_sequence === 2 ? 'Before Lesson 7' : 'Not scheduled'))) . '</td><td>' . ($settled ? esc_html((string)$obligation->settled_at) : 'Not settled') . '</td><td>' . ((int)$obligation->refunded_minor > 0 ? esc_html(PricingMoney::format((int)$obligation->refunded_minor, (string)$obligation->currency)) : '—') . '</td><td><span class="dzn-finance-pill">' . esc_html($settled ? 'Settled' : 'Outstanding') . '</span></td></tr>';
            }
            echo '</tbody></table>';
        }
        if ((array)$detail['settlements'] !== array()) {
            echo '<h4>Settlements</h4><table class="widefat striped dzn-finance-table"><thead><tr><th>Settled</th><th>Amount</th><th>Source</th><th>Evidence</th></tr></thead><tbody>';
            foreach ((array)$detail['settlements'] as $settlement) echo '<tr><td>' . esc_html((string)$settlement->settled_at) . '</td><td>' . esc_html(PricingMoney::format((int)$settlement->amount_minor, (string)$settlement->currency)) . '</td><td>' . esc_html((string)$settlement->provider_key) . '</td><td>' . esc_html((string)$settlement->evidence_kind . ' · ' . (string)$settlement->processing_state) . '</td></tr>';
            echo '</tbody></table>';
        }
        if ((array)$detail['discounts'] !== array()) {
            echo '<h4>Applied discounts</h4><ul>';
            foreach ((array)$detail['discounts'] as $discount) echo '<li>' . esc_html((string)$discount->source_type . ' · ' . (string)$discount->kind . ' · ' . PricingMoney::format((int)$discount->applied_amount_minor, (string)$discount->currency)) . '</li>';
            echo '</ul>';
        }
        echo '<p class="description">Replacement lessons used: ' . (int)$detail['replacements_used'] . '. Manual refunds and ledger entries remain separate paths; this detail creates neither.</p></section>';
    }

    /** Outstanding standard-course obligations and the guarded manual-settlement action. */
    private static function renderOutstandingObligations(FinanceAdminReadService $read): void {
        $filters = array(
            'q' => self::query('ob_q'), 'state' => self::query('ob_state'),
            'from' => self::query('ob_from'), 'through' => self::query('ob_through'),
        );
        $page = max(1, absint($_GET['ob_paged'] ?? 1));
        $data = $read->outstandingObligations($filters, $page);
        $canManage = current_user_can(ManualPaymentSettlementService::MANAGE_CAPABILITY);
        echo '<h3 id="outstanding-payments">Outstanding payments</h3>';
        echo '<p>Every collectable obligation with a positive amount. Recording a payment here uses the same canonical settlement and funding authority as Stripe: it creates the purchase, entitlement and academic funding exactly once. The amount is read from the stored obligation and is never entered by the browser.</p>';
        echo '<form method="get" class="dzn-finance-filter"><input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '"><input type="hidden" name="tab" value="' . esc_attr(self::TAB_PAYMENTS) . '">';
        echo '<label for="ob-q">Search student, offer or obligation</label><input id="ob-q" type="search" name="ob_q" value="' . esc_attr($filters['q']) . '">';
        echo '<label for="ob-state">Status</label><select id="ob-state" name="ob_state"><option value="">All outstanding</option><option value="overdue"' . selected($filters['state'], 'overdue', false) . '>Overdue</option><option value="instalment"' . selected($filters['state'], 'instalment', false) . '>Two-instalment plans</option></select>';
        echo '<label for="ob-from">From</label><input id="ob-from" type="date" name="ob_from" value="' . esc_attr($filters['from']) . '">';
        echo '<label for="ob-through">Through</label><input id="ob-through" type="date" name="ob_through" value="' . esc_attr($filters['through']) . '">';
        echo '<button class="button">Filter outstanding</button></form>';
        echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>Student</th><th>Course / offer</th><th>Instalment</th><th>Amount</th><th>Due</th><th>State</th><th>Settlement</th></tr></thead><tbody>';
        if (!$data['rows']) echo '<tr><td colspan="7">No outstanding standard-course obligation matches these filters.</td></tr>';
        foreach ($data['rows'] as $row) {
            $overdue = $row->due_at !== null && (string) $row->due_at < gmdate('Y-m-d H:i:s');
            $instalment = (int) $row->obligation_sequence;
            $plan = (string) $row->plan_kind === 'two_instalments' ? 'Two instalments' : 'Full term';
            $predecessor = (int) ($row->predecessor_outstanding ?? 0) === 1;
            $state = $overdue ? 'Overdue' : 'Outstanding';
            $due = $row->due_at !== null ? (string) $row->due_at : ($instalment === 2 ? 'Before Lesson 7' : 'Not scheduled');
            $action = '—';
            if ($canManage) {
                $action = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-manual-pay">'
                    . '<input type="hidden" name="action" value="dzn_finance_manual_payment"><input type="hidden" name="payment_kind" value="standard">'
                    . '<input type="hidden" name="offer_uid" value="' . esc_attr((string) $row->offer_uid) . '">'
                    . '<input type="hidden" name="obligation_sequence" value="' . esc_attr((string) $instalment) . '">'
                    . '<input type="hidden" name="command_key" value="' . esc_attr(wp_generate_uuid4()) . '">'
                    . wp_nonce_field('dzn_finance_manual_payment', '_wpnonce', true, false)
                    . '<label>Reference<input type="text" name="evidence_reference" maxlength="191" required placeholder="Bank / receipt reference"></label>'
                    . '<label>Received<input type="date" name="received_on" value="' . esc_attr(gmdate('Y-m-d')) . '" max="' . esc_attr(gmdate('Y-m-d')) . '" required></label>'
                    . '<button class="button button-small">Mark paid</button></form>';
            }
            echo '<tr><td>' . esc_html((string) ($row->student_name ?: '—')) . '<br><small>' . esc_html((string) ($row->student_reference ?: '')) . '</small></td>'
                . '<td>' . esc_html((string) ($row->course_name ?: '—')) . '<br><small>' . esc_html('Offer ' . (string) ($row->offer_reference ?: $row->offer_uid) . ' · ' . $plan . ' · ' . (int) $row->committed_sessions . ' lessons') . '</small></td>'
                . '<td>#' . esc_html((string) $instalment) . '<br><small>Lessons ' . esc_html((string) $row->sessions_from . '–' . (string) $row->sessions_to) . '</small></td>'
                . '<td>' . esc_html(PricingMoney::format((int) $row->amount_minor, (string) $row->currency)) . '</td>'
                . '<td>' . esc_html($due) . '</td>'
                . '<td><span class="dzn-finance-pill">' . esc_html($state) . '</span>'
                . ((string) $row->offer_state === 'issued' && $row->expires_at !== null ? '<br><small>Offer expires ' . esc_html((string) $row->expires_at) . '</small>' : '')
                . ($predecessor ? '<br><small>Earlier instalment outstanding — funding stays blocked until it settles.</small>' : '')
                . '</td><td>' . $action . '</td></tr>';
        }
        echo '</tbody></table>';
        if ((int) $data['pages'] > 1) {
            $base = array('page' => self::MENU_SLUG, 'tab' => self::TAB_PAYMENTS) + array_filter(array('ob_q' => $filters['q'], 'ob_state' => $filters['state'], 'ob_from' => $filters['from'], 'ob_through' => $filters['through']), static fn($value) => $value !== '');
            echo '<p class="tablenav">Outstanding page ' . esc_html((string) $data['page']) . ' of ' . esc_html((string) $data['pages']);
            if ((int) $data['page'] > 1) echo ' <a class="button" href="' . esc_url(add_query_arg($base + array('ob_paged' => (int) $data['page'] - 1), admin_url('admin.php'))) . '">Previous</a>';
            if ((int) $data['page'] < (int) $data['pages']) echo ' <a class="button" href="' . esc_url(add_query_arg($base + array('ob_paged' => (int) $data['page'] + 1), admin_url('admin.php'))) . '">Next</a>';
            echo '</p>';
        }
        echo '<p class="description">A manual payment is recorded as staff evidence with a required receipt/reference and the actual receipt date. It never rewrites a Stripe checkout, and an already-settled obligation cannot be settled again.</p>';
        if (!$canManage) echo '<p class="description">Recording an offline payment requires payment-execution management authority.</p>';
    }

    /** Authoritative recent settlements, including offline payments with no checkout attempt. */
    private static function renderRecentSettlements(FinanceAdminReadService $read): void {
        $settlements = $read->recentSettlements(25);
        echo '<h3 id="recent-settlements">Recent settlements</h3><p>Authoritative settlement facts, including offline payments that have no checkout attempt. Provider references remain digested and are not displayed. A checkout attempt is not included here unless an accepted settlement exists.</p>';
        echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>Settled</th><th>Student</th><th>Course / offer</th><th>Instalment</th><th>Amount</th><th>Source</th><th>State</th></tr></thead><tbody>';
        if (!$settlements) echo '<tr><td colspan="7">No payment settlement has been recorded yet.</td></tr>';
        foreach ($settlements as $row) {
            $provider = (string) $row->provider_key;
            $source = $provider === 'manual' ? 'Manual / offline' : ($provider === 'stripe' ? 'Stripe' : ucfirst($provider));
            echo '<tr><td>' . esc_html((string) $row->settled_at) . '</td>'
                . '<td>' . esc_html((string) ($row->student_name ?: '—')) . '<br><small>' . esc_html((string) ($row->student_reference ?: '')) . '</small></td>'
                . '<td>' . esc_html((string) ($row->course_name ?: '—')) . '<br><small>' . esc_html('Offer ' . (string) ($row->offer_reference ?: $row->offer_uid)) . '</small></td>'
                . '<td>#' . esc_html((string) (int) $row->obligation_sequence) . '<br><small>Lessons ' . esc_html((string) $row->sessions_from . '–' . (string) $row->sessions_to) . '</small></td>'
                . '<td>' . esc_html(PricingMoney::format((int) $row->amount_minor, (string) $row->currency)) . '</td>'
                . '<td>' . esc_html($source) . '<br><small>' . esc_html((string) $row->evidence_kind) . '</small></td>'
                . '<td><span class="dzn-finance-pill">' . esc_html((string) $row->processing_state) . '</span>' . ($row->reason_code ? '<br><small>' . esc_html((string) $row->reason_code) . '</small>' : '') . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** Checkout attempts stay available for investigation but are clearly not settlements. */
    private static function renderCheckoutAttempts(FinanceAdminReadService $read, array $counts): void {
        $filters = array('q' => self::query('q'), 'state' => self::query('state'), 'from' => self::query('from'), 'through' => self::query('through'));
        $page = max(1, absint($_GET['paged'] ?? 1));
        $data = $read->payments($filters, $page);
        echo '<details class="dzn-finance-settings-section" id="checkout-attempts"><summary><strong>Checkout attempts and payment records</strong> · ' . esc_html((string)($counts['all'] ?? 0)) . ' attempt(s)</summary>';
        echo '<p>A completed Checkout is not an accepted settlement. Administrator-recorded offline payments do not need a Checkout attempt; they appear under Recent settlements.</p>';
        self::filterForm(self::TAB_PAYMENTS, array(
            'q' => array('label' => 'Search student, offer, purchase or attempt', 'type' => 'search'),
            'state' => array('label' => 'Status', 'type' => 'select', 'options' => array('' => 'All statuses', 'pending' => 'Pending', 'checkout_completed' => 'Checkout complete (not yet settled)', 'settled' => 'Settled', 'failed' => 'Failed', 'refunded' => 'Refund evidence')),
            'from' => array('label' => 'From', 'type' => 'date'), 'through' => array('label' => 'Through', 'type' => 'date'),
        ), $filters);
        echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>Date</th><th>Student</th><th>Course / offer</th><th>Amount</th><th>Checkout state</th><th>Settlement / reconciliation</th><th>Reference</th></tr></thead><tbody>';
        if (!$data['rows']) echo '<tr><td colspan="7">No payments match these filters.</td></tr>';
        foreach ($data['rows'] as $row) {
            $rowState = (string) $row->state;
            $paid = (int) ($row->settled_evidence ?? 0) > 0;
            $refund = (int) ($row->refund_evidence ?? 0) > 0;
            $reconciliation = $refund ? 'Refund evidence recorded'
                : ($paid ? 'Settlement recorded'
                : ($rowState === 'completed' ? 'Checkout complete; settlement not accepted'
                : ($rowState === 'failed' ? 'Failed / needs review' : 'Awaiting payment')));
            echo '<tr><td>' . esc_html((string) $row->created_at) . '</td><td>' . esc_html((string) ($row->student_name ?: '—')) . '<br><small>' . esc_html((string) ($row->student_reference ?: '')) . '</small></td><td>' . esc_html((string) ($row->course_name ?: '—')) . '<br><small>' . esc_html((string) ($row->offer_reference ?: ('#' . (int) $row->id))) . '</small></td><td>' . esc_html(PricingMoney::format((int) $row->amount_minor, (string) $row->currency)) . '</td><td><span class="dzn-finance-pill">' . esc_html($rowState) . '</span>' . ($row->close_reason ? '<br><small>' . esc_html((string) $row->close_reason) . '</small>' : '') . '</td><td>' . esc_html($reconciliation) . '<br><small>' . esc_html((string) ($row->provider_key ?: 'provider')) . '</small></td><td><code>' . esc_html((string) $row->uid) . '</code>' . ($row->purchase_reference ? '<br><small>' . esc_html((string) $row->purchase_reference) . '</small>' : '') . '</td></tr>';
        }
        echo '</tbody></table>';
        self::pagination($data, self::TAB_PAYMENTS, $filters);
        echo '</details>';
    }

    /** Student receipts report for a calendar month or custom date range. */
    private static function renderStudentReceiptsReport(): void {
        if (!current_user_can(StudentReceiptsReportService::VIEW_CAPABILITY)) return;
        $month = self::query('report_month');
        $start = self::query('report_start');
        $end = self::query('report_end');
        $report = null;
        $error = '';
        if ($month !== '' || ($start !== '' && $end !== '')) {
            try {
                $report = (new StudentReceiptsReportService())->report(array('month' => $month, 'start' => $start, 'end' => $end));
            } catch (\Throwable $exception) {
                $error = trim($exception->getMessage());
            }
        }
        echo '<section class="dzn-finance-settings-section" id="student-receipts-report"><h3>Student receipts report</h3>';
        echo '<p>Confirmed student payments and accepted refunds for a calendar month or custom range. Receipts are dated by the recorded settlement/received date; refunds by accepted refund evidence. Boundaries are <strong>' . esc_html(StudentReceiptsReportService::TIMEZONE) . '</strong>. Currencies are never combined. This is a receipts report, not a tax invoice.</p>';
        echo '<form method="get" class="dzn-finance-filter"><input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '"><input type="hidden" name="tab" value="' . esc_attr(self::TAB_PAYMENTS) . '">';
        echo '<label for="report-month">Calendar month<input id="report-month" type="month" name="report_month" value="' . esc_attr($month) . '"></label>';
        echo '<label for="report-start">Custom start<input id="report-start" type="date" name="report_start" value="' . esc_attr($start) . '"></label>';
        echo '<label for="report-end">Custom end<input id="report-end" type="date" name="report_end" value="' . esc_attr($end) . '"></label>';
        echo '<button class="button button-primary">Show report</button></form>';
        if ($error !== '') echo '<div class="notice notice-error inline"><p>The report could not be produced: ' . esc_html($error) . '.</p></div>';
        if ($report !== null) {
            echo '<h4>' . esc_html((string)$report['period_label']) . '</h4>';
            echo '<p class="description">' . esc_html((string)$report['date_basis']) . ' Generated ' . esc_html((string)$report['generated_at']) . ' UTC.' . (empty($report['include_refunds']) ? ' Refund rows are hidden because your role lacks refund-view authority; totals therefore show receipts only.' : '') . '</p>';
            echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>Currency</th><th>Gross receipts</th><th>Refunds</th><th>Net receipts</th></tr></thead><tbody>';
            if (!$report['totals']) echo '<tr><td colspan="4">No confirmed receipt or accepted refund falls in this period.</td></tr>';
            foreach ((array)$report['totals'] as $total) echo '<tr><td>' . esc_html((string)$total['currency']) . '</td><td>' . esc_html(PricingMoney::format((int)$total['gross_minor'], (string)$total['currency'])) . '</td><td>' . esc_html(PricingMoney::format((int)$total['refunds_minor'], (string)$total['currency'])) . '</td><td><strong>' . esc_html(PricingMoney::format((int)$total['net_minor'], (string)$total['currency'])) . '</strong></td></tr>';
            echo '</tbody></table>';
            foreach ((array)$report['sources'] as $currency => $sources) {
                echo '<h5>' . esc_html((string)$currency) . ' by source</h5><table class="widefat striped dzn-finance-table"><thead><tr><th>Source</th><th>Gross</th><th>Refunds</th></tr></thead><tbody>';
                foreach ((array)$sources as $source) echo '<tr><td>' . esc_html((string)$source['source_label']) . '</td><td>' . esc_html(PricingMoney::format((int)$source['gross_minor'], (string)$currency)) . '</td><td>' . esc_html(PricingMoney::format((int)$source['refunds_minor'], (string)$currency)) . '</td></tr>';
                echo '</tbody></table>';
            }
            echo '<details><summary>Transaction rows (' . count((array)$report['rows']) . ')</summary><table class="widefat striped dzn-finance-table"><thead><tr><th>Type</th><th>Date</th><th>Student</th><th>Course / offer</th><th>Amount</th><th>Source</th></tr></thead><tbody>';
            foreach ((array)$report['rows'] as $row) echo '<tr><td>' . esc_html(ucfirst((string)$row['type'])) . '</td><td>' . esc_html((string)$row['transaction_at']) . '</td><td>' . esc_html((string)$row['student_name']) . '<br><small>' . esc_html((string)$row['student_reference']) . '</small></td><td>' . esc_html((string)$row['course_name']) . '<br><small>' . esc_html((string)$row['offer_reference']) . '</small></td><td>' . esc_html((string)$row['amount_display']) . '</td><td>' . esc_html((string)$row['source_label']) . '</td></tr>';
            echo '</tbody></table></details>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-inline-form">';
            echo '<input type="hidden" name="action" value="dzn_finance_receipts_report"><input type="hidden" name="report_month" value="' . esc_attr($month) . '"><input type="hidden" name="report_start" value="' . esc_attr($start) . '"><input type="hidden" name="report_end" value="' . esc_attr($end) . '">';
            wp_nonce_field('dzn_finance_receipts_report');
            echo '<button class="button">Download auditable CSV</button></form>';
        }
        echo '</section>';
    }

    /** Introductory payments remain available for paid mode, collapsed while introductions are free. */
    private static function renderIntroPayments(FinanceAdminReadService $read): void {
        $introCounts = $read->introPaymentCounts();
        $mode = 'free';
        try {
            foreach ((new RegionalPricingService())->activeAll() as $region) {
                if ((string)($region['introductory_mode'] ?? 'free') === RegionalPricingRule::INTRO_PAID) { $mode = 'paid'; break; }
            }
        } catch (\Throwable) { $mode = 'free'; }
        $filters = array('q' => self::query('q'), 'state' => self::query('state'), 'from' => self::query('from'), 'through' => self::query('through'));
        $introPage = max(1, absint($_GET['intro_paged'] ?? 1));
        $introFilters = $filters;
        if (in_array((string)($introFilters['state'] ?? ''), array('successful', 'settled', 'checkout_completed'), true)) $introFilters['state'] = 'paid';
        $intro = $read->introPayments($introFilters, $introPage);
        echo '<details class="dzn-finance-settings-section" id="intro-payments"><summary><strong>Introductory lesson payments</strong> · ' . esc_html((string)($introCounts['all'] ?? 0)) . ' obligation(s) · ' . ($mode === 'free' ? 'currently free' : 'paid mode configured') . '</summary>';
        echo $mode === 'free'
            ? '<p>Every configured region is currently in free introductory mode. No introductory charge is created; this section stays available so paid-intro evidence is preserved if pricing changes.</p>'
            : '<p>Paid introductions use the same verified Stripe webhook and test-mode adapter. A free introduction has no row here and never creates a charge.</p>';
        echo '<div class="dzn-finance-cards">';
        foreach (array('all' => 'All intro obligations', 'pending' => 'Awaiting payment', 'paid' => 'Paid', 'failed' => 'Failed attempt', 'refunded' => 'Refunded') as $key => $label) echo '<div class="dzn-finance-card"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) ($introCounts[$key] ?? 0)) . '</strong></div>';
        echo '</div><table class="widefat striped dzn-finance-table"><thead><tr><th>Created</th><th>Student</th><th>Intro course</th><th>Amount</th><th>Obligation state</th><th>Checkout</th><th>Latest provider event</th><th>Reference</th><th>Action</th></tr></thead><tbody>';
        if (!$intro['rows']) echo '<tr><td colspan="9">No paid introductory obligations match these filters.</td></tr>';
        foreach ($intro['rows'] as $row) {
            $eventLabel = $row->last_event_kind ? (string) $row->last_event_kind . ' · ' . (string) $row->last_event_result . ($row->last_event_reason ? ' · ' . (string) $row->last_event_reason : '') : 'No provider event yet';
            $introPending = in_array((string) $row->state, array('pending', 'failed'), true);
            $introAction = '—';
            if ($introPending && current_user_can(ManualPaymentSettlementService::MANAGE_CAPABILITY)) {
                $introAction = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-inline-form">'
                    . '<input type="hidden" name="action" value="dzn_finance_manual_payment"><input type="hidden" name="payment_kind" value="intro">'
                    . '<input type="hidden" name="obligation_uid" value="' . esc_attr((string) $row->uid) . '">'
                    . '<input type="hidden" name="command_key" value="' . esc_attr(wp_generate_uuid4()) . '">'
                    . wp_nonce_field('dzn_finance_manual_payment', '_wpnonce', true, false)
                    . '<label>Reference<input type="text" name="evidence_reference" maxlength="191" required placeholder="Bank / receipt reference"></label>'
                    . '<label>Received<input type="date" name="received_on" value="' . esc_attr(gmdate('Y-m-d')) . '" max="' . esc_attr(gmdate('Y-m-d')) . '" required></label>'
                    . '<button class="button button-small">Mark paid</button></form>';
            }
            echo '<tr><td>' . esc_html((string) $row->created_at) . '</td><td>' . esc_html((string) ($row->student_name ?: '—')) . '<br><small>' . esc_html((string) ($row->student_reference ?: '')) . '</small></td><td>' . esc_html((string) ($row->course_name ?: '—')) . '</td><td>' . esc_html(PricingMoney::format((int) $row->amount_minor, (string) $row->currency)) . '</td><td><span class="dzn-finance-pill">' . esc_html((string) $row->state) . '</span></td><td>' . esc_html((string) ($row->checkout_state ?: 'not started')) . '<br><small>' . esc_html((string) ($row->expires_at ?: '')) . '</small></td><td>' . esc_html($eventLabel) . '</td><td><code>' . esc_html((string) $row->reference_code) . '</code></td><td>' . $introAction . '</td></tr>';
        }
        echo '</tbody></table>';
        if ((int) $intro['pages'] > 1) {
            $introBase = array('page' => self::MENU_SLUG, 'tab' => self::TAB_PAYMENTS) + array_filter($filters, static fn($value) => $value !== '');
            echo '<p class="tablenav">Intro page ' . esc_html((string) $intro['page']) . ' of ' . esc_html((string) $intro['pages']);
            if ((int) $intro['page'] > 1) echo ' <a class="button" href="' . esc_url(add_query_arg($introBase + array('intro_paged' => (int) $intro['page'] - 1), admin_url('admin.php'))) . '">Previous</a>';
            if ((int) $intro['page'] < (int) $intro['pages']) echo ' <a class="button" href="' . esc_url(add_query_arg($introBase + array('intro_paged' => (int) $intro['page'] + 1), admin_url('admin.php'))) . '">Next</a>';
            echo '</p>';
        }
        echo '</details>';
    }

    /** Administrator-only manual refunds and their review lifecycle. */
    private static function renderRefundWorkspace(FinanceAdminReadService $read): void {
        if (!current_user_can('dzn_view_recurring_authority')) return;
        $canManage = current_user_can('dzn_manage_refund_reviews');
        $settlements = $read->recentSettlements(25);
        $reservations = $read->manualRefundReservations(50);
        $reviews = $read->refundReviews(50);
        echo '<section class="dzn-finance-settings-section" id="refund-reviews"><h3>Manual refunds</h3>';
        echo '<p>Refunds are recorded and reviewed by an administrator only. Recording a refund creates accepted manual refund evidence and a review case. It does not move money, rewrite the settlement, reduce academic funding, cancel a Lesson or change a statement. Complete the actual bank/card refund outside this page, then route and resolve the case here.</p>';
        $stripeAssessment = array();
        try { $stripeAssessment = (new StripeRefundAssessmentService())->summary(); } catch (\Throwable) { $stripeAssessment = array(); }
        if ($stripeAssessment !== array()) {
            echo '<details class="dzn-finance-subsection" id="stripe-refund-assessment"><summary><strong>Integrated Stripe refund · implementation assessment</strong></summary>';
            echo '<p>' . esc_html((string)($stripeAssessment['summary'] ?? '')) . '</p>';
            echo '<p class="description">This is an assessment, not a refund action. No Stripe refund can be created from this page. The manual/offline path below is separate and does not write the manual ledger.</p><ul>';
            foreach ((array)($stripeAssessment['reasons'] ?? array()) as $reason) echo '<li><code>' . esc_html((string)$reason) . '</code></li>';
            echo '</ul></details>';
        }
        echo '<h4>Refundable settlements</h4>';
        echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>Settled</th><th>Student</th><th>Offer / instalment</th><th>Settled</th><th>Refunded</th><th>Remaining</th><th>Action</th></tr></thead><tbody>';
        if (!$settlements) echo '<tr><td colspan="7">No settled obligation is available for a refund.</td></tr>';
        foreach ($settlements as $row) {
            $settledMinor = (int) $row->amount_minor;
            $refundedMinor = (int) ($row->refunded_minor ?? 0);
            $reservedMinor = (int) ($row->reserved_minor ?? 0);
            $remaining = (int) ($row->remaining_minor ?? max(0, $settledMinor - $refundedMinor - $reservedMinor));
            $action = $remaining > 0 ? 'Available' : 'Fully refunded';
            if ($canManage && $remaining > 0) {
                $action = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-manual-pay">'
                    . '<input type="hidden" name="action" value="dzn_finance_refund"><input type="hidden" name="command_key" value="' . esc_attr(wp_generate_uuid4()) . '">'
                    . '<input type="hidden" name="offer_uid" value="' . esc_attr((string) $row->offer_uid) . '"><input type="hidden" name="obligation_sequence" value="' . esc_attr((string) (int) $row->obligation_sequence) . '">'
                    . wp_nonce_field('dzn_finance_refund', '_wpnonce', true, false)
                    . '<label>Amount (' . esc_html((string) $row->currency) . ', blank = full remaining)<input type="text" name="refund_amount" inputmode="decimal" placeholder="' . esc_attr(PricingMoney::fromMinor($remaining, (string) $row->currency)) . '"></label>'
                    . '<label>Refunded on<input type="date" name="refunded_on" value="' . esc_attr(gmdate('Y-m-d')) . '" max="' . esc_attr(gmdate('Y-m-d')) . '" required></label>'
                    . '<label>Evidence reference<input type="text" name="evidence_reference" maxlength="191" required placeholder="Bank / refund reference"></label>'
                    . '<label>Note<input type="text" name="refund_note" maxlength="500" placeholder="Optional decision note"></label>'
                    . '<button class="button button-small" onclick="return confirm(\'Record this manual refund? It creates evidence and a review case but does not move money.\')">Record refund</button></form>';
            }
            echo '<tr><td>' . esc_html((string) $row->settled_at) . '</td>'
                . '<td>' . esc_html((string) ($row->student_name ?: '—')) . '<br><small>' . esc_html((string) ($row->student_reference ?: '')) . '</small></td>'
                . '<td>' . esc_html((string) $row->offer_reference ?: (string) $row->offer_uid) . '<br><small>#' . esc_html((string) (int) $row->obligation_sequence) . ' · Lessons ' . esc_html((string) $row->sessions_from . '–' . (string) $row->sessions_to) . '</small></td>'
                . '<td>' . esc_html(PricingMoney::format($settledMinor, (string) $row->currency)) . '</td>'
                . '<td>' . esc_html(PricingMoney::format($refundedMinor, (string) $row->currency)) . '</td>'
                . '<td><strong>' . esc_html(PricingMoney::format($remaining, (string) $row->currency)) . '</strong></td>'
                . '<td>' . $action . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h4>Refund reservations</h4>';
        echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>Reservation</th><th>Student</th><th>Offer / instalment</th><th>Amount</th><th>State</th><th>Evidence / review</th><th>Action</th></tr></thead><tbody>';
        if (!$reservations) echo '<tr><td colspan="7">No manual refund reservation has been recorded.</td></tr>';
        foreach ($reservations as $reservation) {
            $reservationId = (int) $reservation->id;
            $reservationState = (string) $reservation->state;
            $action = '—';
            if ($canManage && $reservationState === 'reserved' && $reservation->evidence_id === null) {
                $action = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline" onsubmit="return confirm(\'Release this reservation? Only do this when no refund evidence exists.\')">'
                    . '<input type="hidden" name="action" value="dzn_finance_refund_release"><input type="hidden" name="reservation_id" value="' . $reservationId . '"><input type="hidden" name="reason_code" value="operator_released"><input type="hidden" name="command_key" value="' . esc_attr(wp_generate_uuid4()) . '">'
                    . wp_nonce_field('dzn_finance_refund_release', '_wpnonce', true, false)
                    . '<button class="button button-small">Release reservation</button></form>';
            }
            $evidence = $reservation->evidence_id === null ? '—' : 'Evidence #' . (int) $reservation->evidence_id;
            if ($reservation->refund_review_id !== null) $evidence .= ' · Review #' . (int) $reservation->refund_review_id;
            echo '<tr><td>#' . $reservationId . '<br><small>' . esc_html((string) $reservation->uid) . '</small></td>'
                . '<td>' . esc_html((string) ($reservation->student_name ?: '—')) . '<br><small>' . esc_html((string) ($reservation->student_reference ?: '')) . '</small></td>'
                . '<td>' . esc_html((string) ($reservation->offer_reference ?: $reservation->offer_uid)) . '<br><small>#' . esc_html((string) (int) $reservation->obligation_sequence) . '</small></td>'
                . '<td>' . esc_html(PricingMoney::format((int) $reservation->amount_minor, (string) $reservation->currency)) . '</td>'
                . '<td><span class="dzn-finance-pill">' . esc_html($reservationState) . '</span>' . ($reservation->failure_reason_code ? '<br><small>' . esc_html((string) $reservation->failure_reason_code) . '</small>' : '') . '</td>'
                . '<td>' . esc_html($evidence) . '</td>'
                . '<td>' . $action . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h4>Refund review cases</h4>';
        echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>Case</th><th>Student</th><th>Offer / instalment</th><th>Amount</th><th>State</th><th>Note</th><th>Action</th></tr></thead><tbody>';
        if (!$reviews) echo '<tr><td colspan="7">No refund review case has been recorded.</td></tr>';
        foreach ($reviews as $review) {
            $caseId = (int) $review->id;
            $state = (string) $review->state;
            $action = '—';
            if ($canManage && in_array($state, array('open', 'review_required'), true)) {
                $action = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
                    . '<input type="hidden" name="action" value="dzn_finance_refund_review"><input type="hidden" name="refund_review_id" value="' . $caseId . '"><input type="hidden" name="command_key" value="' . esc_attr(wp_generate_uuid4()) . '">'
                    . wp_nonce_field('dzn_finance_refund_review', '_wpnonce', true, false);
                if ($state === 'open') {
                    $action .= '<input type="hidden" name="refund_operation" value="route"><button class="button button-small">Route for review</button></form>';
                }
                if ($state === 'review_required') {
                    $action .= '<input type="hidden" name="refund_operation" value="resolve"><input type="text" name="resolution_note" maxlength="500" placeholder="Decision note" required><button class="button button-small button-primary">Resolve</button></form>';
                }
                $action .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
                    . '<input type="hidden" name="action" value="dzn_finance_refund_review"><input type="hidden" name="refund_review_id" value="' . $caseId . '"><input type="hidden" name="command_key" value="' . esc_attr(wp_generate_uuid4()) . '">'
                    . wp_nonce_field('dzn_finance_refund_review', '_wpnonce', true, false)
                    . '<input type="hidden" name="refund_operation" value="dismiss"><button class="button button-small" onclick="return confirm(\'Dismiss this refund review? This decision is recorded and terminal.\')">Dismiss</button></form>';
            }
            echo '<tr><td>#' . $caseId . '<br><small>' . esc_html((string) ($review->refunded_at ?: '')) . '</small></td>'
                . '<td>' . esc_html((string) ($review->student_name ?: '—')) . '<br><small>' . esc_html((string) ($review->student_reference ?: '')) . '</small></td>'
                . '<td>' . esc_html((string) ($review->offer_reference ?: $review->offer_uid)) . '<br><small>#' . esc_html((string) (int) $review->obligation_sequence) . '</small></td>'
                . '<td>' . esc_html(PricingMoney::format((int) $review->amount_minor, (string) $review->currency)) . '</td>'
                . '<td><span class="dzn-finance-pill">' . esc_html($state) . '</span></td>'
                . '<td>' . esc_html((string) ($review->resolution_note ?: '—')) . '</td>'
                . '<td>' . $action . '</td></tr>';
        }
        echo '</tbody></table>';
        if (!$canManage) echo '<p class="description">Recording, routing, resolving and dismissing a refund require administrator refund-review authority.</p>';
        echo '</section>';
    }

    /** Create, list and deactivate Student percentage discount codes. */
    private static function renderPromotionCodes(): void {
        $canView = current_user_can('dzn_view_commercial_authority');
        $canManage = current_user_can('dzn_manage_commercial_promotions');
        if (!$canView && !$canManage) return;
        echo '<section class="dzn-finance-panel" id="discount-codes"><h2>Discount codes</h2>';
        echo '<p>Create a percentage code that Students can apply themselves on the payment page. The backend re-reads the offer, recalculates the discount and controls Checkout; the browser never supplies an amount. A 100% code settles the offer at zero value and completes the enrolment without a Stripe charge.</p>';
        if ($canManage) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-promotion-form">';
            echo '<input type="hidden" name="action" value="dzn_finance_promotion"><input type="hidden" name="promotion_operation" value="create">';
            wp_nonce_field('dzn_finance_promotion');
            echo '<label for="dzn-promotion-code">Code <span class="dzn-finance-muted">(3–32 letters, numbers, . _ -)</span><input id="dzn-promotion-code" type="text" name="promotion_code" maxlength="32" pattern="[A-Za-z0-9._-]{3,32}" autocomplete="off" spellcheck="false" required></label>';
            echo '<label for="dzn-promotion-percentage">Discount %<input id="dzn-promotion-percentage" type="number" name="promotion_percentage" min="0.01" max="100" step="0.01" required></label>';
            echo '<label for="dzn-promotion-from">Valid from <span class="dzn-finance-muted">(UTC date, optional)</span><input id="dzn-promotion-from" type="date" name="valid_from"></label>';
            echo '<label for="dzn-promotion-until">Valid until <span class="dzn-finance-muted">(UTC date, optional)</span><input id="dzn-promotion-until" type="date" name="valid_until"></label>';
            echo '<label for="dzn-promotion-max">Total use limit <span class="dzn-finance-muted">(blank = unlimited)</span><input id="dzn-promotion-max" type="number" name="max_redemptions" min="1" max="1000000" step="1"></label>';
            echo '<label for="dzn-promotion-per">Uses per Student<input id="dzn-promotion-per" type="number" name="per_beneficiary_limit" min="1" max="100" step="1" value="1" required></label>';
            echo '<label class="dzn-finance-checkbox" for="dzn-promotion-stackable"><input id="dzn-promotion-stackable" type="checkbox" name="stackable" value="1"> Can combine with another code</label>';
            echo '<button class="button button-primary">Create discount code</button></form>';
            echo '<p class="description">Validity dates are stored in UTC; a start date begins at 00:00 and an end date covers the whole day. Leave either field blank for no bound. The stackable flag is saved on the code, but the current payment authority applies one promotion per offer, so a second code is rejected as already applied; true multi-code stacking requires a later schema change.</p>';
        }
        try {
            $promotions = (new CommercialPromotionService())->promotions();
        } catch (\Throwable) {
            $promotions = array();
        }
        echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>ID</th><th>Code</th><th>Discount</th><th>Status</th><th>Use limit</th><th>Per Student</th><th>Stackable</th><th>Valid from</th><th>Valid until</th><th>Action</th></tr></thead><tbody>';
        if (!$promotions) echo '<tr><td colspan="10">No discount codes have been created yet.</td></tr>';
        foreach ($promotions as $promotion) {
            $id = (int) $promotion['promotion_id'];
            $code = $promotion['code'] ?? null;
            $discount = $promotion['kind'] === 'percentage' && $promotion['percentage_bp'] !== null
                ? number_format(((int) $promotion['percentage_bp']) / 100, 2) . '%'
                : ($promotion['fixed_amount_minor'] !== null ? PricingMoney::format((int) $promotion['fixed_amount_minor'], (string) $promotion['currency']) : '—');
            $status = (string) $promotion['status'];
            if ($promotion['archived_at'] !== null) $status .= ' · archived';
            $statusLabel = $status;
            $nowUtc = gmdate('Y-m-d H:i:s');
            if ($status === 'active' && $promotion['valid_from'] !== null && (string) $promotion['valid_from'] > $nowUtc) {
                $statusLabel .= ' · scheduled';
            } elseif ($status === 'active' && $promotion['valid_until'] !== null && (string) $promotion['valid_until'] < $nowUtc) {
                $statusLabel .= ' · expired';
            }
            $max = $promotion['max_redemptions'] === null ? 'Unlimited' : (string) $promotion['max_redemptions'];
            $from = $promotion['valid_from'] !== null ? substr((string) $promotion['valid_from'], 0, 10) : '—';
            $until = $promotion['valid_until'] !== null ? substr((string) $promotion['valid_until'], 0, 10) : '—';
            echo '<tr><td>#' . $id . '</td><td>' . ($code !== null && $code !== '' ? '<code>' . esc_html((string) $code) . '</code>' : '<span class="dzn-finance-muted">Not stored (legacy)</span>') . '</td><td>' . esc_html($discount) . '</td><td><span class="dzn-finance-pill">' . esc_html($statusLabel) . '</span></td><td>' . esc_html($max) . '</td><td>' . esc_html((string) (int) $promotion['per_beneficiary_limit']) . '</td><td>' . (!empty($promotion['stackable']) ? 'Yes' : 'No') . '</td><td>' . esc_html($from) . '</td><td>' . esc_html($until) . '</td><td>';
            if ($canManage && $status === 'active') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
                echo '<input type="hidden" name="action" value="dzn_finance_promotion"><input type="hidden" name="promotion_operation" value="deactivate"><input type="hidden" name="promotion_id" value="' . $id . '">';
                wp_nonce_field('dzn_finance_promotion');
                echo '<button class="button button-small">Deactivate</button></form>';
            } else {
                echo '—';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">Codes are case-insensitive and are stored in uppercase for display; the lookup itself uses a salted digest. A code that has reached its total-use limit, its per-Student limit, or its validity window is rejected by the backend. The stackable flag is recorded but is not yet enforced for multi-code offers. Codes created before this change have no stored display value and appear as “Not stored (legacy)”.</p></section>';
    }

    private static function renderInstalmentSchedules(FinanceAdminReadService $read): void {
        $filters = array(
            'q' => self::query('inst_q'),
            'state' => self::query('inst_state'),
            'from' => self::query('inst_from'),
            'through' => self::query('inst_through'),
        );
        $page = max(1, absint($_GET['inst_paged'] ?? 1));
        $data = $read->instalmentSchedules($filters, $page);
        echo '<h3 id="instalment-schedules">Instalment schedules</h3><p>Every two-instalment commitment, its obligations and the stored outstanding balance. This view is read-only and never re-prices, settles or rewrites an offer.</p>';
        echo '<form method="get" class="dzn-finance-filter"><input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '"><input type="hidden" name="tab" value="' . esc_attr(self::TAB_PAYMENTS) . '">';
        echo '<label for="inst-q">Search student or offer</label><input id="inst-q" type="search" name="inst_q" value="' . esc_attr($filters['q']) . '">';
        echo '<label for="inst-state">Status</label><select id="inst-state" name="inst_state"><option value="">All schedules</option><option value="outstanding"' . selected($filters['state'], 'outstanding', false) . '>Outstanding</option><option value="overdue"' . selected($filters['state'], 'overdue', false) . '>Overdue</option><option value="paid"' . selected($filters['state'], 'paid', false) . '>Fully settled</option></select>';
        echo '<label for="inst-from">From</label><input id="inst-from" type="date" name="inst_from" value="' . esc_attr($filters['from']) . '">';
        echo '<label for="inst-through">Through</label><input id="inst-through" type="date" name="inst_through" value="' . esc_attr($filters['through']) . '">';
        echo '<button class="button">Filter instalments</button></form>';
        echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>Issued</th><th>Student</th><th>Course / plan</th><th>Instalment</th><th>Lessons</th><th>Amount</th><th>Offer outstanding</th><th>Due</th><th>Status</th></tr></thead><tbody>';
        if (!$data['rows']) echo '<tr><td colspan="9">No two-instalment schedules match these filters.</td></tr>';
        $lastOfferUid = null;
        foreach ($data['rows'] as $row) {
            $settled = $row->settled_at !== null;
            $overdue = !$settled && $row->due_at !== null && (string) $row->due_at < gmdate('Y-m-d H:i:s');
            $closed = in_array((string) $row->offer_state, array('withdrawn', 'expired'), true);
            $lapsed = !$settled && (string) $row->offer_state === 'issued'
                && $row->expires_at !== null && (string) $row->expires_at <= gmdate('Y-m-d H:i:s');
            $status = $settled ? 'Settled' : ($closed ? ucfirst((string) $row->offer_state) : ($lapsed ? 'Expired' : ($overdue ? 'Overdue' : 'Outstanding')));
            $due = $row->due_at !== null ? (string) $row->due_at : ($lapsed ? 'Offer expired' : ((int) $row->obligation_sequence === 2 ? 'Before Lesson 7' : 'Not scheduled'));
            $showOutstanding = (string) $row->offer_uid !== (string) $lastOfferUid;
            $lastOfferUid = (string) $row->offer_uid;
            echo '<tr><td>' . esc_html((string) $row->issued_at) . '</td><td>' . esc_html((string) ($row->student_name ?: '—')) . '<br><small>' . esc_html((string) ($row->student_reference ?: '')) . '</small></td><td>' . esc_html((string) ($row->course_name ?: '—')) . '<br><small>' . esc_html('Offer ' . (string) ($row->offer_reference ?: $row->offer_uid)) . ' · ' . esc_html((string) $row->committed_sessions) . ' lessons · ' . esc_html(PricingMoney::format((int) $row->amount_due_minor, (string) $row->currency)) . '</small></td><td>' . esc_html('#' . (string) $row->obligation_sequence) . '</td><td>' . esc_html('Lessons ' . (string) $row->sessions_from . '–' . (string) $row->sessions_to) . '</td><td>' . esc_html(PricingMoney::format((int) $row->obligation_amount_minor, (string) $row->currency)) . '</td><td>' . ($showOutstanding ? esc_html(PricingMoney::format((int) $row->outstanding_minor, (string) $row->currency)) : '—') . '</td><td>' . esc_html($due) . '</td><td><span class="dzn-finance-pill">' . esc_html($status) . '</span>' . ($settled ? '<br><small>' . esc_html((string) $row->settled_at) . '</small>' : '') . '</td></tr>';
        }
        echo '</tbody></table>';
        if ((int) $data['pages'] > 1) {
            $base = array('page' => self::MENU_SLUG, 'tab' => self::TAB_PAYMENTS) + array_filter(array('inst_q' => $filters['q'], 'inst_state' => $filters['state'], 'inst_from' => $filters['from'], 'inst_through' => $filters['through']), static fn($value) => $value !== '');
            echo '<p class="tablenav">Instalment page ' . esc_html((string) $data['page']) . ' of ' . esc_html((string) $data['pages']);
            if ((int) $data['page'] > 1) echo ' <a class="button" href="' . esc_url(add_query_arg($base + array('inst_paged' => (int) $data['page'] - 1), admin_url('admin.php'))) . '">Previous</a>';
            if ((int) $data['page'] < (int) $data['pages']) echo ' <a class="button" href="' . esc_url(add_query_arg($base + array('inst_paged' => (int) $data['page'] + 1), admin_url('admin.php'))) . '">Next</a>';
            echo '</p>';
        }
        echo '<p class="description">Offer outstanding is the offer total minus settled obligations, shown once per schedule. A second instalment with no recorded date remains outstanding and is blocked structurally before Lesson 7; this page does not invent a deadline. A time-lapsed issued offer is labelled Expired and is excluded from actionable receivables.</p>';
    }

    private static function renderLedger(FinanceAdminReadService $read): void {
        if (!current_user_can('dzn_view_cash_ledger')) { self::denied('Central Ledger view authority is required.'); return; }
        $filters = array(
            'q' => self::query('q'), 'account' => self::query('account'), 'kind' => self::query('kind'),
            'from' => self::query('from'), 'through' => self::query('through'),
        );
        $page = max(1, absint($_GET['paged'] ?? 1));
        $data = $read->ledger($filters, $page);
        echo '<section class="dzn-finance-panel"><h2>Manual ledger</h2><p>Manual academy bookkeeping only, with Iran (TOM) and Australia (AUD) shown as separate currencies. Online checkout, Stripe settlement, teacher rates, student receipts and teacher payments never write to this ledger; keeping the ledger manual is intentional. The Overview balances are read-only views of this same ledger calculation.</p>';
        echo '<div class="dzn-finance-cards">';
        foreach (OperationalCashRule::ACCOUNTS as $key => $meta) {
            $amount = (int) ($data['balances'][$key] ?? 0);
            echo '<div class="dzn-finance-card"><span>' . esc_html((string) $meta['label']) . '</span><strong>' . esc_html(OperationalCashRule::format($amount, (string) $meta['currency'])) . '</strong></div>';
        }
        echo '</div>';
        self::filterForm(self::TAB_LEDGER, array(
            'q' => array('label' => 'Search description, category or evidence', 'type' => 'search'),
            'account' => array('label' => 'Account', 'type' => 'select', 'options' => array('' => 'All accounts', 'australia' => 'Australia account (AUD)', 'iran' => 'Iran account (TOM)')),
            'kind' => array('label' => 'Type', 'type' => 'select', 'options' => array('' => 'All types', 'income' => 'Money received', 'expense' => 'Money spent', 'opening_balance' => 'Opening balance', 'balance_adjustment' => 'Balance adjustment', 'transfer_out' => 'Transfer out', 'transfer_in' => 'Transfer in', 'reversal' => 'Reversal')),
            'from' => array('label' => 'From', 'type' => 'date'), 'through' => array('label' => 'Through', 'type' => 'date'),
        ), $filters);
        echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Category / description</th><th>Amount</th><th>Currency</th><th>Reconciliation</th></tr></thead><tbody>';
        if (!$data['rows']) echo '<tr><td colspan="7">No manual ledger entries match these filters.</td></tr>';
        $labels = array('income' => 'Income', 'expense' => 'Expense', 'opening_balance' => 'Opening balance', 'balance_adjustment' => 'Balance adjustment', 'transfer_out' => 'Transfer out', 'transfer_in' => 'Transfer in', 'reversal' => 'Reversal', 'conversion' => 'Conversion');
        foreach ($data['rows'] as $row) {
            $gross = (int) $row->amount_minor;
            $net = $gross * (int) $row->direction;
            $covered = $row->latest_reconciliation !== null && (string) $row->latest_reconciliation >= (string) $row->transaction_date;
            $difference = $row->latest_reconciliation_difference === null ? null : (int) $row->latest_reconciliation_difference;
            $reference = $row->evidence_reference ?: ('CASH-' . (int) $row->id);
            $reconciliation = !$covered
                ? 'Not reconciled'
                : ($difference === null ? 'Reconciled to statement' : ($difference === 0 ? 'Reconciled · no difference' : 'Statement difference ' . ($difference > 0 ? '+' : '−') . PricingMoney::format(abs($difference), (string) $row->currency)));
            echo '<tr><td>' . esc_html((string) $row->transaction_date) . '</td><td>' . esc_html($labels[(string) $row->transaction_kind] ?? (string) $row->transaction_kind) . '</td><td><code>' . esc_html((string) $reference) . '</code></td><td>' . esc_html(trim((string) ($row->category ?: '') . ((string) ($row->category ?: '') !== '' && (string) ($row->description ?: '') !== '' ? ' · ' : '') . (string) ($row->description ?: '—'))) . '</td><td>' . esc_html(($net < 0 ? '−' : '') . PricingMoney::format(abs($net), (string) $row->currency)) . '</td><td>' . esc_html((string) $row->currency) . '</td><td>' . esc_html($reconciliation) . '</td></tr>';
        }
        echo '</tbody></table>';
        self::pagination($data, self::TAB_LEDGER, $filters);
        if (current_user_can('dzn_manage_cash_ledger')) {
            self::manualEntryForm();
            echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-finance-cashbook')) . '">Open the full cashbook for transfers, statement comparisons and reversals</a></p>';
        }
        echo '<p class="description">Entries are append-only. Mistakes are corrected by a reversal and a corrected entry; the existing academy cashbook rules remain authoritative. Online settlement and this manual ledger stay deliberately separate: a payment marked complete in Payments does not silently write a bookkeeping entry, and a ledger entry never marks a Student obligation paid.</p></section>';
    }

    private static function renderStatements(FinanceAdminReadService $read): void {
        if (!current_user_can('dzn_view_finance_authority')) { self::denied('Finance authority view capability is required.'); return; }
        $redesign = new FinanceAdminRedesignReadService();
        $workflow = new TeacherStatementBatchWorkflowService();
        echo '<section class="dzn-finance-panel" id="teacher-statements"><h2>Teacher payments</h2><p>Completed teaching, recorded compensation and effective-dated statements. A statement records lesson compensation only; it is not a payout, invoice or bank instruction. Generated drafts are reviewed, then issued, then sent. Generating a draft never sends email.</p>';
        self::renderTeacherSummary($redesign);
        self::renderStatementWorkflow($workflow);
        self::renderStatementHistory($workflow);
        self::renderIndividualStatements($read);
        self::renderStatementSchedule();
        self::exportButton('statements', 'Export statements CSV');
        $template = (new FinanceSettingsService())->template();
        self::statementTemplateSettings($template, new StatementTemplateService());
        echo '</section>';
    }

    /** Searchable teacher compensation summary for the current and previous Persian month. */
    private static function renderTeacherSummary(FinanceAdminRedesignReadService $service): void {
        $filters = array('q' => self::query('teacher_q'), 'sort' => self::query('teacher_sort'));
        $page = max(1, absint($_GET['teacher_paged'] ?? 1));
        $data = $service->teacherSummaries($filters, $page, 25);
        $current = $data['current_period'] ?? array('label_fa' => '', 'label' => '');
        $previous = $data['previous_period'] ?? array('label_fa' => '', 'label' => '');
        echo '<section class="dzn-finance-settings-section" id="teacher-payment-summary"><h3>Teacher payment summary</h3>';
        echo '<p>Completed classes and teaching hours for this and the previous Persian month. <strong>Earned</strong> is the recorded payable snapshot plus its committed golden-hour bonus; <strong>paid</strong> is a recorded statement settlement. They are never merged.</p>';
        echo '<p class="description">Current period: ' . esc_html((string)($current['label_fa'] ?? '')) . ' (' . esc_html((string)($current['start_utc'] ?? '')) . ' → ' . esc_html((string)($current['end_utc'] ?? '')) . ' UTC). Previous: ' . esc_html((string)($previous['label_fa'] ?? '')) . '.</p>';
        echo '<form method="get" class="dzn-finance-filter"><input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '"><input type="hidden" name="tab" value="' . esc_attr(self::TAB_STATEMENTS) . '">';
        echo '<label for="teacher-q">Search teacher</label><input id="teacher-q" type="search" name="teacher_q" value="' . esc_attr($filters['q']) . '">';
        echo '<label for="teacher-sort">Sort</label><select id="teacher-sort" name="teacher_sort"><option value="teacher"' . selected($filters['sort'], 'teacher', false) . '>Teacher</option><option value="hours"' . selected($filters['sort'], 'hours', false) . '>Teaching hours</option><option value="earned"' . selected($filters['sort'], 'earned', false) . '>Earned</option><option value="paid"' . selected($filters['sort'], 'paid', false) . '>Paid</option><option value="outstanding"' . selected($filters['sort'], 'outstanding', false) . '>Earned minus paid</option><option value="status"' . selected($filters['sort'], 'status', false) . '>Statement status</option></select>';
        echo '<button class="button">Apply</button></form>';
        echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>Teacher</th><th>Completed classes<br><small>current / previous</small></th><th>Teaching hours<br><small>current / previous</small></th><th>Base rate</th><th>Golden-hour bonus<br><small>current month</small></th><th>Earned<br><small>current month</small></th><th>Paid<br><small>current month</small></th><th>Latest payment</th><th>Latest statement</th></tr></thead><tbody>';
        if (!$data['rows']) echo '<tr><td colspan="9">No active teacher matches these filters.</td></tr>';
        foreach ($data['rows'] as $row) {
            $url = add_query_arg(array('page' => self::MENU_SLUG, 'tab' => self::TAB_STATEMENTS, 'teacher_id' => (int)$row['teacher_id']), admin_url('admin.php')) . '#individual-statement-review';
            $currency = (string)$row['rate_currency'];
            $fmt = static fn(int $minor, string $currency): string => $currency === '' ? '—' : PricingMoney::format($minor, $currency);
            echo '<tr><td><a href="' . esc_url($url) . '"><strong>' . esc_html($row['teacher_name']) . '</strong></a><br><small>' . esc_html($row['teacher_reference']) . '</small></td>';
            echo '<td>' . (int)$row['current_lessons'] . ' / ' . (int)$row['previous_lessons'] . '</td>';
            echo '<td>' . esc_html(number_format((int)$row['current_minutes'] / 60, 1)) . ' / ' . esc_html(number_format((int)$row['previous_minutes'] / 60, 1)) . '</td>';
            echo '<td>' . esc_html($row['rate_minor'] === null ? 'Not configured' : $fmt((int)$row['rate_minor'], $currency)) . '<br><small>' . esc_html($row['rate_source']) . '</small></td>';
            echo '<td>' . esc_html($fmt((int)$row['bonus_minor'], 'TOM')) . '</td>';
            echo '<td><strong>' . esc_html($fmt((int)$row['earned_minor'], 'TOM')) . '</strong></td>';
            echo '<td>' . esc_html($fmt((int)$row['paid_minor'], 'TOM')) . '</td>';
            echo '<td>' . esc_html($row['latest_paid_at'] !== '' ? $row['latest_paid_at'] : '—') . '</td>';
            echo '<td><span class="dzn-finance-pill">' . esc_html($row['status_label']) . '</span>' . ($row['latest_statement_id'] > 0 ? '<br><small>#' . (int)$row['latest_statement_id'] . ' · ' . esc_html($row['latest_statement_period']) . '</small>' : '') . '</td></tr>';
        }
        echo '</tbody></table>';
        if ((int)$data['pages'] > 1) {
            $base = array('page' => self::MENU_SLUG, 'tab' => self::TAB_STATEMENTS) + array_filter($filters, static fn($value) => $value !== '');
            echo '<p class="tablenav">Teacher page ' . esc_html((string)$data['page']) . ' of ' . esc_html((string)$data['pages']);
            if ((int)$data['page'] > 1) echo ' <a class="button" href="' . esc_url(add_query_arg($base + array('teacher_paged' => (int)$data['page'] - 1), admin_url('admin.php'))) . '#teacher-payment-summary">Previous</a>';
            if ((int)$data['page'] < (int)$data['pages']) echo ' <a class="button" href="' . esc_url(add_query_arg($base + array('teacher_paged' => (int)$data['page'] + 1), admin_url('admin.php'))) . '#teacher-payment-summary">Next</a>';
            echo '</p>';
        }
        echo '</section>';
    }

    /** Preview -> Generate drafts -> Review -> Issue/publish -> Send. */
    private static function renderStatementWorkflow(TeacherStatementBatchWorkflowService $workflow): void {
        $canManage = current_user_can(TeacherStatementBatchWorkflowService::MANAGE_CAPABILITY);
        $defaultMonth = PersianCalendar::previousMonthForInstant(gmdate('Y-m-d H:i:s'))['key'];
        $requestedMonth = self::query('persian_month');
        $mode = self::query('period_mode') ?: 'persian_month';
        $requestedStart = self::query('period_start');
        $requestedEnd = self::query('period_end');
        $previewRequested = self::query('workflow_preview') === '1';
        $period = null;
        $preview = null;
        $previewError = '';
        if ($previewRequested) {
            try {
                $period = $mode === 'custom'
                    ? (new TeacherStatementBatchService())->customPeriod($requestedStart, $requestedEnd)
                    : (new TeacherStatementBatchService())->persianPeriod($requestedMonth !== '' ? $requestedMonth : $defaultMonth);
                $preview = $workflow->preview($period);
            } catch (\Throwable $error) {
                $previewError = trim($error->getMessage());
            }
        }
        $result = get_transient('dzn_finance_statement_workflow_' . get_current_user_id());
        echo '<section class="dzn-finance-settings-section" id="teacher-statement-workflow"><h3>Generate teacher statements</h3>';
        echo '<p>Generate drafts for a completed Persian month or a completed custom range. The preview shows eligible teachers, Lesson lines, applied rate versions, golden-hour bonuses, totals, missing payability evidence and existing statements before anything is written. Draft generation never issues and never sends email.</p>';
        echo '<form method="get" class="dzn-finance-filter"><input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '"><input type="hidden" name="tab" value="' . esc_attr(self::TAB_STATEMENTS) . '"><input type="hidden" name="workflow_preview" value="1">';
        echo '<label for="workflow-mode">Period</label><select id="workflow-mode" name="period_mode"><option value="persian_month"' . selected($mode, 'persian_month', false) . '>Completed Persian month (Asia/Tehran)</option><option value="custom"' . selected($mode, 'custom', false) . '>Custom completed date range</option></select>';
        echo '<label for="workflow-month">Persian month <span class="dzn-finance-muted">(YYYY-MM)</span><input id="workflow-month" type="text" name="persian_month" value="' . esc_attr($requestedMonth !== '' ? $requestedMonth : $defaultMonth) . '" pattern="\d{3,4}-(0[1-9]|1[0-2])" placeholder="1405-08"></label>';
        echo '<label for="workflow-start">Custom start<input id="workflow-start" type="date" name="period_start" value="' . esc_attr($requestedStart) . '"></label>';
        echo '<label for="workflow-end">Custom end<input id="workflow-end" type="date" name="period_end" value="' . esc_attr($requestedEnd) . '"></label>';
        echo '<button class="button button-primary">Preview eligible teachers</button></form>';
        if ($previewError !== '') echo '<div class="notice notice-error inline"><p>Preview unavailable: ' . esc_html($previewError) . '.</p></div>';
        if ($preview !== null) {
            echo '<h4>Preview · ' . esc_html((string)$preview['period_label']) . '</h4>';
            echo '<p class="description">' . esc_html((string)$preview['timezone']) . ' · ' . esc_html((string)$preview['period_start']) . ' → ' . esc_html((string)$preview['period_end']) . ' UTC. Existing live statements are reported, never silently replaced.</p>';
            echo '<table class="widefat striped dzn-finance-table"><thead><tr><th></th><th>Teacher</th><th>Lessons<br><small>payable / pending / non-payable / archived</small></th><th>Rate versions</th><th>Golden-hour bonus</th><th>Total</th><th>Missing evidence</th><th>Existing statement</th></tr></thead><tbody>';
            if (!$preview['teachers']) echo '<tr><td colspan="8">No Teacher has active Lesson work in this period.</td></tr>';
            foreach ($preview['teachers'] as $teacher) {
                $rateText = array();
                foreach ((array)$teacher['rates'] as $rate) $rateText[] = 'v' . (int)$rate['rate_version'] . ' · ' . PricingMoney::format((int)$rate['amount_minor'], (string)$rate['currency']) . ' · ' . (int)$rate['lessons'] . ' line(s)';
                $currency = (string)($teacher['currency'] ?? '');
                echo '<tr><td><input type="checkbox" name="teacher_ids[]" value="' . (int)$teacher['teacher_id'] . '" checked form="workflow-generate"></td>';
                echo '<td><strong>' . esc_html($teacher['teacher_name']) . '</strong><br><small>' . esc_html($teacher['teacher_reference']) . ' · ' . esc_html($teacher['teacher_email'] ?: 'No recorded email') . '</small></td>';
                echo '<td>' . (int)$teacher['payable_lessons'] . ' / ' . (int)$teacher['pending_lessons'] . ' / ' . (int)$teacher['non_payable_lessons'] . ' / ' . (int)$teacher['archived_lessons'] . '</td>';
                echo '<td>' . ($rateText ? esc_html(implode('; ', $rateText)) : '—') . '</td>';
                echo '<td>' . esc_html($currency !== '' ? PricingMoney::format((int)$teacher['bonus_minor'], $currency) : '—') . '</td>';
                echo '<td><strong>' . esc_html($currency !== '' ? PricingMoney::format((int)$teacher['payable_amount_minor'], $currency) : '—') . '</strong></td>';
                echo '<td>' . ((int)$teacher['missing_count'] > 0 ? '<span class="dzn-finance-pill dzn-finance-pill--danger">' . (int)$teacher['missing_count'] . ' missing</span><br><small>' . esc_html(implode(', ', array_values((array)$teacher['missing']))) . '</small>' : 'Complete') . '</td>';
                echo '<td>' . ((int)($teacher['existing_statement_id'] ?? 0) > 0 ? '#' . (int)$teacher['existing_statement_id'] . ' · ' . esc_html((string)$teacher['existing_state']) : '—') . '</td></tr>';
            }
            echo '</tbody></table>';
            if ($canManage && $preview['teachers']) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="workflow-generate" class="dzn-finance-inline-form" onsubmit="return confirm(\'Generate draft statements for the selected Teachers? This never issues or emails.\')">';
                echo '<input type="hidden" name="action" value="dzn_finance_statement_workflow"><input type="hidden" name="workflow_operation" value="generate"><input type="hidden" name="command_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
                echo '<input type="hidden" name="period_mode" value="' . esc_attr((string)$preview['calendar'] === 'custom' ? 'custom' : 'persian_month') . '"><input type="hidden" name="persian_month" value="' . esc_attr((string)$preview['month']) . '"><input type="hidden" name="period_start" value="' . esc_attr(substr((string)$preview['period_start'], 0, 10)) . '"><input type="hidden" name="period_end" value="' . esc_attr(substr((string)$preview['period_end'], 0, 10)) . '">';
                wp_nonce_field('dzn_finance_statement_workflow');
                echo '<button class="button button-primary">Generate drafts</button><span class="description">Drafts only. Review below, then issue and send separately.</span></form>';
            }
        }
        if (is_array($result)) self::renderStatementWorkflowResult($result, $canManage);
        echo '</section>';
    }

    /** Per-Teacher result and the separate issue/send/staff-summary actions. */
    private static function renderStatementWorkflowResult(array $result, bool $canManage): void {
        $summary = (array)($result['summary'] ?? array());
        $teachers = (array)($result['teachers'] ?? array());
        $period = array(
            'period_key' => (string)($result['period_key'] ?? ''),
            'period_label' => (string)($result['period_label'] ?? ''),
            'calendar' => (string)($result['calendar'] ?? ''),
            'timezone' => (string)($result['timezone'] ?? ''),
            'period_start' => (string)($result['period_start'] ?? ''),
            'period_end' => (string)($result['period_end'] ?? ''),
        );
        echo '<h4>Workflow result · ' . esc_html((string)($result['period_label'] ?? '')) . '</h4>';
        echo '<p><strong>' . (int)($summary['teachers'] ?? 0) . '</strong> Teacher(s) · drafted ' . (int)($summary['drafted'] ?? 0) . ' · issued ' . (int)($summary['issued'] ?? 0) . ' · skipped ' . (int)($summary['skipped'] ?? 0) . ' · failed ' . (int)($summary['failed'] ?? 0) . ' · queued ' . (int)($summary['queued'] ?? 0) . ' · transport accepted ' . (int)($summary['transport_accepted'] ?? 0) . '.</p>';
        $draftIds = array_values(array_filter(array_map(static fn(array $row): int => (string)($row['state'] ?? '') === 'drafted' ? (int)($row['statement_id'] ?? 0) : 0, $teachers)));
        $issuedIds = array_values(array_filter(array_map(static fn(array $row): int => (string)($row['state'] ?? '') === 'issued' ? (int)($row['statement_id'] ?? 0) : 0, $teachers)));
        echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>Teacher</th><th>State</th><th>Statement</th><th>Amount</th><th>Reason / send state</th></tr></thead><tbody>';
        if (!$teachers) echo '<tr><td colspan="5">No Teacher result was recorded.</td></tr>';
        foreach ($teachers as $row) {
            echo '<tr><td>' . esc_html((string)($row['teacher_name'] ?? '')) . '<br><small>' . esc_html((string)($row['teacher_reference'] ?? '')) . '</small></td>';
            echo '<td><span class="dzn-finance-pill' . ((string)($row['state'] ?? '') === 'failed' ? ' dzn-finance-pill--danger' : '') . '">' . esc_html(ucfirst((string)($row['state'] ?? ''))) . '</span></td>';
            echo '<td>' . ((int)($row['statement_id'] ?? 0) > 0 ? '#' . (int)$row['statement_id'] : '—') . '</td>';
            echo '<td>' . esc_html((string)($row['currency'] ?? '') !== '' ? PricingMoney::format((int)($row['payable_amount_minor'] ?? 0), (string)$row['currency']) : '—') . '</td>';
            echo '<td>' . esc_html((string)($row['reason'] ?? '')) . ((string)($row['email_state'] ?? '') !== '' ? ' · email ' . esc_html((string)$row['email_state']) : '') . '</td></tr>';
        }
        echo '</tbody></table>';
        if (!$canManage) return;
        if ($draftIds !== array()) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-inline-form" onsubmit="return confirm(\'Issue the selected drafts? Each must pass the existing issuance gate independently.\')">';
            echo '<input type="hidden" name="action" value="dzn_finance_statement_workflow"><input type="hidden" name="workflow_operation" value="issue"><input type="hidden" name="command_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
            self::workflowPeriodFields($period);
            wp_nonce_field('dzn_finance_statement_workflow');
            foreach ($draftIds as $id) echo '<input type="hidden" name="statement_ids[]" value="' . (int)$id . '">';
            echo '<button class="button button-primary">Issue / publish reviewed drafts (' . count($draftIds) . ')</button></form>';
        }
        if ($issuedIds !== array()) {
            echo '<div class="dzn-finance-split"><div class="dzn-finance-region-card"><h4>Send to teachers</h4><p>Publish each issued statement to that Teacher’s own portal and send it to that Teacher’s recorded, verified address. This never sends every statement to one address.</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Send these issued statements to their own recorded Teacher addresses?\')">';
            echo '<input type="hidden" name="action" value="dzn_finance_statement_workflow"><input type="hidden" name="workflow_operation" value="send"><input type="hidden" name="command_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
            self::workflowPeriodFields($period);
            wp_nonce_field('dzn_finance_statement_workflow');
            foreach ($issuedIds as $id) echo '<input type="hidden" name="statement_ids[]" value="' . (int)$id . '">';
            echo '<button class="button button-primary">Send to teachers (' . count($issuedIds) . ')</button></form></div>';
            echo '<div class="dzn-finance-region-card"><h4>Send batch summary to staff</h4><p>Send an administrative summary only — counts, currency totals and individual failures. It does not attach or expose every Teacher’s private statement.</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="dzn_finance_statement_staff_summary"><input type="hidden" name="command_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
            self::workflowPeriodFields($period);
            wp_nonce_field('dzn_finance_statement_staff_summary');
            echo '<label>Staff email<input type="email" name="staff_summary_email" required placeholder="finance@example.com"></label> <button class="button">Send batch summary to staff</button></form></div></div>';
        }
    }

    private static function workflowPeriodFields(array $period): void {
        $key = (string)($period['period_key'] ?? '');
        $month = str_starts_with($key, 'persian:') ? substr($key, 8) : substr($key, 0, 7);
        echo '<input type="hidden" name="period_mode" value="' . esc_attr((string)($period['calendar'] ?? '') === 'custom' ? 'custom' : 'persian_month') . '">';
        echo '<input type="hidden" name="persian_month" value="' . esc_attr($month) . '">';
        echo '<input type="hidden" name="period_start" value="' . esc_attr(substr((string)($period['period_start'] ?? ''), 0, 10)) . '">';
        echo '<input type="hidden" name="period_end" value="' . esc_attr(substr((string)($period['period_end'] ?? ''), 0, 10)) . '">';
    }

    /** Collapsible history of generated and custom-period batches. */
    private static function renderStatementHistory(TeacherStatementBatchWorkflowService $workflow): void {
        $history = array();
        try { $history = $workflow->history(12); } catch (\Throwable) { $history = array(); }
        echo '<details class="dzn-finance-settings-section" id="statement-batch-history"><summary><strong>Statement batch history</strong> · ' . count($history) . ' recent run(s)</summary>';
        echo '<p>Each run records its period, counts, currency totals, draft/issue/send state and individual failures. Preview again and retry from the original period; a completed month is never silently drafted twice.</p>';
        echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>Run</th><th>Period</th><th>Mode</th><th>Counts</th><th>Totals</th><th>State / failures</th><th>Action</th></tr></thead><tbody>';
        if (!$history) echo '<tr><td colspan="7">No statement batch run has been recorded.</td></tr>';
        foreach ($history as $run) {
            $totals = array();
            foreach ((array)$run['totals_by_currency'] as $total) $totals[] = PricingMoney::format((int)$total['amount_minor'], (string)$total['currency']);
            $summary = (array)$run['summary'];
            $counts = 'teachers ' . (int)($summary['teachers'] ?? 0) . ' · drafted ' . (int)($summary['drafted'] ?? 0) . ' · issued ' . (int)($summary['issued'] ?? 0) . ' · skipped ' . (int)($summary['skipped'] ?? 0) . ' · failed ' . (int)($summary['failed'] ?? 0);
            $retry = '';
            if ((string)($run['retry_params']['period_key'] ?? '') !== '') {
                $params = array('page' => self::MENU_SLUG, 'tab' => self::TAB_STATEMENTS, 'workflow_preview' => '1');
                if (str_starts_with((string)$run['retry_params']['period_key'], 'persian:')) {
                    $params['period_mode'] = 'persian_month';
                    $params['persian_month'] = substr((string)$run['retry_params']['period_key'], 8);
                } else {
                    $params['period_mode'] = 'custom';
                    $params['period_start'] = substr((string)$run['retry_params']['period_start'], 0, 10);
                    $params['period_end'] = substr((string)$run['retry_params']['period_end'], 0, 10);
                }
                $retry = '<a class="button button-small" href="' . esc_url(add_query_arg($params, admin_url('admin.php')) . '#teacher-statement-workflow') . '">Preview / retry</a>';
            }
            $failures = '';
            foreach ((array)$run['failures'] as $failure) $failures .= '<br><small>' . esc_html((string)($failure['teacher_name'] ?? '')) . ': ' . esc_html((string)($failure['reason'] ?? 'failed')) . '</small>';
            echo '<tr><td>#' . (int)$run['id'] . '<br><small>' . esc_html($run['completed_at']) . '</small></td><td>' . esc_html($run['period_label']) . '<br><small>' . esc_html($run['period_month']) . '</small></td><td>' . esc_html($run['mode']) . '</td><td>' . esc_html($counts) . '</td><td>' . ($totals ? esc_html(implode(' · ', $totals)) : '—') . '</td><td><span class="dzn-finance-pill">' . esc_html($run['state']) . '</span>' . $failures . '</td><td>' . $retry . '</td></tr>';
        }
        echo '</tbody></table></details>';
    }

    /** Individual Teacher statements, retained for review and the Teachers-profile link. */
    private static function renderIndividualStatements(FinanceAdminReadService $read): void {
        $teachers = $read->selectableTeachers();
        $teacherId = absint($_GET['teacher_id'] ?? 0);
        if ($teacherId < 1 && $teachers) $teacherId = (int) $teachers[0]->id;
        $canManage = current_user_can('dzn_manage_finance_statements');
        $statementTimezone = $read->statementTimezone();
        echo '<details class="dzn-finance-settings-section" id="individual-statement-review"><summary><strong>Individual statement review</strong> · Teacher rate and statement management also remains in the Teachers profile</summary>';
        echo '<form method="get" class="dzn-finance-inline-form"><input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '"><input type="hidden" name="tab" value="' . esc_attr(self::TAB_STATEMENTS) . '"><label for="finance-statement-teacher">Teacher</label><select id="finance-statement-teacher" name="teacher_id">';
        foreach ($teachers as $teacher) echo '<option value="' . (int) $teacher->id . '"' . selected($teacherId, (int) $teacher->id, false) . '>' . esc_html((string) $teacher->display_name) . ' · ' . esc_html((string) $teacher->reference_code) . '</option>';
        echo '</select><button class="button">View statements</button></form>';
        if ($teacherId < 1) { echo '<p>No active Teacher is available.</p></details>'; return; }
        if (!$statementTimezone['set']) {
            echo '<div class="notice notice-warning inline"><p><strong>Statement timezone policy is unset.</strong> A draft records the timezone version in force at drafting. Until a timezone is recorded in Finance → Settings → Finance policies, a draft cannot be issued. <a href="' . esc_url(self::tabUrl(self::TAB_SETTINGS) . '#finance-policies') . '">Record the timezone policy</a>.</p></div>';
        }
        try {
            $statements = (new TeacherStatementReadService())->statements($teacherId);
        } catch (\Throwable) { $statements = array(); }
        echo '<table class="widefat striped"><thead><tr><th>Statement</th><th>Period</th><th>State</th><th>Currency</th><th>Payable</th><th>Lines</th><th>Issued</th><th>Action</th></tr></thead><tbody>';
        if (!$statements) echo '<tr><td colspan="8">No statement has been drafted for this Teacher.</td></tr>';
        foreach ($statements as $statement) {
            $url = add_query_arg(array('page' => self::MENU_SLUG, 'tab' => self::TAB_STATEMENTS, 'teacher_id' => $teacherId, 'statement_id' => (int) $statement['statement_id']), admin_url('admin.php')) . '#individual-statement-review';
            $state = (string) $statement['state'];
            $action = '—';
            if ($canManage && in_array($state, array('draft', 'issued'), true)) {
                $action = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
                    . '<input type="hidden" name="action" value="dzn_finance_statement"><input type="hidden" name="teacher_id" value="' . (int) $teacherId . '"><input type="hidden" name="statement_id" value="' . (int) $statement['statement_id'] . '"><input type="hidden" name="command_key" value="' . esc_attr(wp_generate_uuid4()) . '">'
                    . wp_nonce_field('dzn_finance_statement', '_wpnonce', true, false);
                if ($state === 'draft') {
                    $action .= '<input type="hidden" name="statement_operation" value="issue"><button class="button button-small button-primary" onclick="return confirm(\'Issue this statement? The issuance gate must pass.\')">Issue</button>'
                        . '</form><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
                        . '<input type="hidden" name="action" value="dzn_finance_statement"><input type="hidden" name="teacher_id" value="' . (int) $teacherId . '"><input type="hidden" name="statement_id" value="' . (int) $statement['statement_id'] . '"><input type="hidden" name="command_key" value="' . esc_attr(wp_generate_uuid4()) . '">'
                        . wp_nonce_field('dzn_finance_statement', '_wpnonce', true, false)
                        . '<input type="hidden" name="statement_operation" value="withdraw"><button class="button button-small" onclick="return confirm(\'Withdraw this draft? It becomes terminal and cannot be issued later.\')">Withdraw</button></form>';
                } else {
                    $action .= '<input type="hidden" name="statement_operation" value="supersede"><button class="button button-small" onclick="return confirm(\'Supersede this issued statement? A new draft version is created from current recorded facts.\')">Supersede</button></form>'
                        . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-inline-form" style="margin-top:6px">'
                        . '<input type="hidden" name="action" value="dzn_finance_statement"><input type="hidden" name="teacher_id" value="' . (int) $teacherId . '"><input type="hidden" name="statement_id" value="' . (int) $statement['statement_id'] . '"><input type="hidden" name="command_key" value="' . esc_attr(wp_generate_uuid4()) . '">'
                        . wp_nonce_field('dzn_finance_statement', '_wpnonce', true, false)
                        . '<input type="hidden" name="statement_operation" value="record_payment">'
                        . '<label>Paid on <input type="date" name="payment_date" value="' . esc_attr(gmdate('Y-m-d')) . '" required></label>'
                        . '<label>Method <select name="payment_method"><option value="manual_bank_transfer">Bank transfer</option><option value="cash">Cash</option><option value="other">Other</option></select></label>'
                        . '<label>Reference <input type="text" name="payment_reference" maxlength="191" required></label>'
                        . '<button class="button button-small" onclick="return confirm(\'Mark this statement as paid? This records an append-only settlement event.\')">Mark paid</button></form>';
                }
            }
            echo '<tr><td><a href="' . esc_url($url) . '">' . esc_html((string) $statement['statement_id']) . '</a></td><td>' . esc_html((string) $statement['period_start_utc'] . ' → ' . (string) $statement['period_end_utc']) . '</td><td>' . esc_html($state) . '</td><td>' . esc_html((string) $statement['currency']) . '</td><td>' . esc_html(PricingMoney::format((int) $statement['payable_amount_minor'], (string) $statement['currency'])) . '</td><td>' . esc_html((string) $statement['total_line_count']) . '</td><td>' . esc_html((string) ($statement['issued_at'] ?? '—')) . '</td><td>' . $action . '</td></tr>';
        }
        echo '</tbody></table>';
        $statementId = absint($_GET['statement_id'] ?? 0);
        $selected = null;
        foreach ($statements as $statement) if ((int) $statement['statement_id'] === $statementId) { $selected = $statement; break; }
        $template = (new FinanceSettingsService())->template();
        $renderer = new StatementTemplateService();
        if ($selected) {
            $identity = $read->teacherIdentity($teacherId);
            $selected['teacher_name'] = $identity['name'] ?? '';
            $selected['teacher_reference'] = $identity['reference'] ?? '';
            $html = $renderer->render($selected, $read->statementLines($statementId), $template, self::academyName());
            echo '<div class="dzn-finance-statement-preview"><div class="dzn-finance-statement-preview__head"><h3>Preview · statement ' . esc_html((string) $statementId) . '</h3><div>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" target="_blank" style="display:inline">' . self::exportFields($statementId, false) . '<button class="button">Print / open</button></form> ';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">' . self::exportFields($statementId, true) . '<button class="button">Download HTML</button></form>';
            $emailIntent = null;
            try { $emailIntent = (new TeacherStatementEmailService())->intent($statementId); } catch (\Throwable) { $emailIntent = null; }
            if ($canManage && (string) $selected['state'] === 'issued') {
                $retry = $emailIntent !== null && in_array((string) ($emailIntent['state'] ?? ''), array('failed', 'retryable'), true);
                $alreadyAccepted = $emailIntent !== null && (string) ($emailIntent['state'] ?? '') === 'transport_accepted';
                if (!$alreadyAccepted) {
                    echo ' <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
                        . '<input type="hidden" name="action" value="dzn_finance_statement_email"><input type="hidden" name="statement_id" value="' . (int) $statementId . '"><input type="hidden" name="teacher_id" value="' . (int) $teacherId . '">'
                        . ($retry ? '<input type="hidden" name="retry_email" value="1">' : '')
                        . wp_nonce_field('dzn_finance_statement_email', '_wpnonce', true, false)
                        . '<button class="button">' . ($retry ? 'Retry statement email' : 'Queue statement email') . '</button></form>';
                }
            }
            if ($emailIntent !== null) {
                $delivery = (string) $emailIntent['state'] === 'transport_accepted'
                    ? 'transport accepted at ' . esc_html((string) ($emailIntent['transport_accepted_at'] ?? '—')) . ' UTC; delivery is not confirmed'
                    : 'state ' . esc_html((string) $emailIntent['state']) . '; ' . esc_html((string) ($emailIntent['attempt_count'] ?? 0)) . '/' . esc_html((string) ($emailIntent['max_attempts'] ?? 0)) . ' attempts';
                echo '<p class="description">Email intent #' . (int) $emailIntent['intent_id'] . ': ' . $delivery . '.';
                if (!empty($emailIntent['last_error_code'])) echo ' Last error: ' . esc_html((string) $emailIntent['last_error_code']) . '.';
                echo '</p>';
            }
            echo '</div></div><iframe class="dzn-finance-statement-frame" sandbox title="Teacher statement preview" srcdoc="' . esc_attr($html) . '"></iframe></div>';
        } else {
            echo '<details class="dzn-finance-subsection"><summary>Sample statement and placeholders</summary><p class="description">Choose a statement above to preview recorded facts. The sample below uses illustrative values only.</p><iframe class="dzn-finance-statement-frame" sandbox title="Sample teacher statement" srcdoc="' . esc_attr($renderer->renderSample($template, self::academyName())) . '"></iframe></details>';
        }
        echo '</details>';
    }

    /** Recoverable monthly draft-only schedule and job history. */
    private static function renderStatementSchedule(): void {
        $canManage = current_user_can('dzn_manage_finance_statements');
        $schedule = new TeacherStatementScheduleService();
        $enabled = $schedule->enabled();
        $history = array();
        try { $history = $schedule->history(10); } catch (\Throwable) { $history = array(); }
        echo '<details class="dzn-finance-settings-section" id="statement-schedule"><summary><strong>Persian-month draft-only schedule</strong></summary>';
        echo '<p>When enabled, a daily worker prepares drafts for the <strong>previous completed Persian month</strong> using Asia/Tehran half-open month boundaries. It never issues or emails a statement automatically; the administrator reviews the ready batch, then issues and sends. Disabled by default. One Persian month is never drafted twice.</p>';
        echo '<p><strong>Status:</strong> ' . ($enabled ? 'Enabled' : 'Disabled') . '</p>';
        if ($canManage) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-inline-form" style="display:inline">'
                . '<input type="hidden" name="action" value="dzn_finance_statement_schedule"><input type="hidden" name="schedule_operation" value="' . ($enabled ? 'disable' : 'enable') . '">'
                . wp_nonce_field('dzn_finance_statement_schedule', '_wpnonce', true, false)
                . '<button class="button">' . ($enabled ? 'Disable monthly scheduling' : 'Enable monthly scheduling') . '</button></form> ';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-inline-form" style="display:inline">'
                . '<input type="hidden" name="action" value="dzn_finance_statement_schedule"><input type="hidden" name="schedule_operation" value="run">'
                . wp_nonce_field('dzn_finance_statement_schedule', '_wpnonce', true, false)
                . '<button class="button button-primary" onclick="return confirm(\'Draft the previous completed month now? This never issues or emails statements.\')">Draft previous month now</button></form>';
        }
        echo '<table class="widefat striped dzn-finance-table" style="margin-top:14px"><thead><tr><th>Run</th><th>Month</th><th>State</th><th>Attempts</th><th>Started</th><th>Completed</th><th>Error</th></tr></thead><tbody>';
        if (!$history) echo '<tr><td colspan="7">No scheduled draft run has been recorded.</td></tr>';
        foreach ($history as $run) {
            echo '<tr><td>#' . (int) $run->id . '<br><small>' . esc_html((string) $run->run_key) . '</small></td>'
                . '<td>' . esc_html((string) $run->period_month) . '</td>'
                . '<td><span class="dzn-finance-pill">' . esc_html((string) $run->state) . '</span></td>'
                . '<td>' . esc_html((string) (int) $run->attempt_count) . '</td>'
                . '<td>' . esc_html((string) ($run->started_at ?? '—')) . '</td>'
                . '<td>' . esc_html((string) ($run->completed_at ?? '—')) . '</td>'
                . '<td>' . esc_html((string) ($run->last_error_code ?? '—')) . '</td></tr>';
        }
        echo '</tbody></table></details>';
    }

    /** Monthly batch generation, preview and per-Teacher results. */
    private static function renderStatementBatch(FinanceAdminReadService $read): void {
        $canManage = current_user_can('dzn_manage_finance_statements');
        $requested = self::query('batch_month') !== '';
        $month = $requested ? self::query('batch_month') : gmdate('Y-m', strtotime('first day of last month UTC'));
        $previousMonth = gmdate('Y-m', strtotime('first day of last month UTC'));
        echo '<details class="dzn-finance-settings-section" id="statement-batch"><summary><strong>Generate statements for every Teacher who worked in a month</strong></summary>';
        echo '<p>Choose a completed month. The batch finds every Teacher with at least one active Lesson in that UTC month, drafts one statement from the recorded Lesson snapshots and payability evaluations, and can issue and email each statement. One Teacher\'s failure never blocks another Teacher.</p>';
        echo '<form method="get" class="dzn-finance-inline-form"><input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '"><input type="hidden" name="tab" value="' . esc_attr(self::TAB_STATEMENTS) . '"><label>Completed month<input type="month" name="batch_month" value="' . esc_attr($month) . '" max="' . esc_attr($previousMonth) . '" required></label><button class="button">Preview Teachers</button></form>';
        if (!$requested) {
            echo '<p class="description">Preview a month to see which Teachers have active work and whether an email address is recorded.</p></details>';
            return;
        }
        try {
            $preview = (new TeacherStatementBatchService())->preview($month);
        } catch (\Throwable $error) {
            $message = trim($error->getMessage());
            $label = $message === 'statement_period_not_elapsed' ? 'Choose a month that has already ended.' : ($message === 'statement_batch_month_invalid' ? 'Choose a valid month.' : 'The batch preview could not be read. No statement was changed.');
            echo '<div class="notice notice-error inline"><p>' . esc_html($label) . '</p></div></details>';
            return;
        }
        if (!$preview['teachers']) {
            echo '<p>No Teacher has active Lesson work in ' . esc_html($month) . '.</p></details>';
            return;
        }
        echo '<p class="description">' . esc_html((string) count($preview['teachers'])) . ' Teacher(s) have active Lesson work from ' . esc_html($preview['period_start']) . ' to ' . esc_html($preview['period_end']) . ' UTC. Existing live statements are reported and never replaced.</p>';
        echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>Teacher</th><th>Active lessons</th><th>Archived</th><th>Existing statement</th><th>Email</th></tr></thead><tbody>';
        foreach ($preview['teachers'] as $teacher) {
            $existing = (int) ($teacher['existing_statement_id'] ?? 0) > 0
                ? '#' . (int) $teacher['existing_statement_id'] . ' · ' . (string) $teacher['existing_state']
                : '—';
            $email = trim((string) ($teacher['teacher_email'] ?? ''));
            echo '<tr><td>' . esc_html((string) $teacher['teacher_name']) . '<br><small>' . esc_html((string) $teacher['teacher_reference']) . '</small></td>'
                . '<td>' . esc_html((string) (int) $teacher['active_lessons']) . '</td>'
                . '<td>' . esc_html((string) (int) $teacher['archived_lessons']) . '</td>'
                . '<td>' . esc_html($existing) . '</td>'
                . '<td>' . ($email !== '' ? esc_html($email) : '<span class="dzn-finance-muted">Missing — statement can be issued but not emailed</span>') . '</td></tr>';
        }
        echo '</tbody></table>';
        if ($canManage) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-inline-form" onsubmit="return confirm(\'Generate statements for this month? Each Teacher is drafted and optionally issued and emailed independently.\')">';
            echo '<input type="hidden" name="action" value="dzn_finance_statement_batch"><input type="hidden" name="batch_month" value="' . esc_attr($month) . '"><input type="hidden" name="command_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
            wp_nonce_field('dzn_finance_statement_batch');
            echo '<label class="dzn-finance-checkbox"><input type="checkbox" name="batch_issue" value="1" checked> Issue each statement when the gate passes</label>';
            echo '<label class="dzn-finance-checkbox"><input type="checkbox" name="batch_email" value="1" checked> Email each issued statement to the Teacher\'s recorded address</label>';
            echo '<button class="button button-primary">Generate monthly statements</button></form>';
        }
        $result = get_transient('dzn_finance_statement_batch_' . get_current_user_id());
        if (is_array($result)) {
            delete_transient('dzn_finance_statement_batch_' . get_current_user_id());
            $summary = (array) ($result['summary'] ?? array());
            echo '<h4>Batch result · ' . esc_html((string) ($result['month'] ?? $month)) . '</h4>';
            echo '<p><strong>' . esc_html((string) ($summary['teachers'] ?? 0)) . '</strong> Teacher(s) · drafted ' . esc_html((string) ($summary['drafted'] ?? 0)) . ' · issued ' . esc_html((string) ($summary['issued'] ?? 0)) . ' · skipped ' . esc_html((string) ($summary['skipped'] ?? 0)) . ' · failed ' . esc_html((string) ($summary['failed'] ?? 0)) . ' · email queued ' . esc_html((string) ($summary['email_queued'] ?? 0)) . ' · email failed ' . esc_html((string) ($summary['email_failed'] ?? 0)) . '.</p>';
            echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>Teacher</th><th>Draft</th><th>Issue</th><th>Statement</th><th>Email</th><th>Reason</th></tr></thead><tbody>';
            foreach ((array) ($result['teachers'] ?? array()) as $row) {
                $state = (string) ($row['state'] ?? '');
                $reason = (string) ($row['reason'] ?? '');
                $email = (string) ($row['email_state'] ?? 'not_requested');
                $sid = (int) ($row['statement_id'] ?? 0);
                $statementCell = $sid > 0 ? '#' . $sid : '—';
                echo '<tr><td>' . esc_html((string) ($row['teacher_name'] ?? '')) . '<br><small>' . esc_html((string) ($row['teacher_reference'] ?? '')) . '</small></td>'
                    . '<td><span class="dzn-finance-pill">' . esc_html((string) ($row['draft_state'] ?? '—')) . '</span></td>'
                    . '<td><span class="dzn-finance-pill">' . esc_html((string) ($row['issue_state'] ?? '—')) . '</span></td>'
                    . '<td>' . esc_html($statementCell) . '</td>'
                    . '<td><span class="dzn-finance-pill">' . esc_html($email) . '</span>' . ((string) ($row['email_reason'] ?? '') !== '' ? '<br><small>' . esc_html((string) $row['email_reason']) . '</small>' : '') . '</td>'
                    . '<td>' . esc_html($reason !== '' ? $reason : '—') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</details>';
    }

    private static function statementTemplateSettings(array $template, StatementTemplateService $renderer): void {
        $canManage = current_user_can(FinanceSettingsService::MANAGE_TEMPLATE_CAPABILITY);
        echo '<details class="dzn-finance-settings-section" id="statement-template"><summary><strong>Statement template and sample</strong></summary>';
        echo '<p class="description">Template version ' . esc_html((string) ($template['template_version'] ?? 'default')) . ' · previous versions remain in finance settings history. The delivered logo is the approved Delnavazan theme logo; a saved custom https logo is preserved.</p>';
        $sample = $renderer->renderSample($template, self::academyName());
        echo '<details class="dzn-finance-subsection"><summary>Sample statement and placeholders</summary><p class="description">Illustrative values only. The web/print statement is editable; financial values always come from the recorded statement authority.</p><iframe class="dzn-finance-statement-frame" sandbox title="Sample teacher statement" srcdoc="' . esc_attr($sample) . '"></iframe>';
        echo '<table class="widefat striped"><thead><tr><th>Placeholder</th><th>Meaning</th></tr></thead><tbody>';
        foreach (StatementTemplateRule::PLACEHOLDERS as $placeholder => $meaning) echo '<tr><td><code>{{' . esc_html($placeholder) . '}}</code></td><td>' . esc_html($meaning) . '</td></tr>';
        foreach (StatementTemplateRule::LESSON_PLACEHOLDERS as $placeholder => $meaning) echo '<tr><td><code>{{' . esc_html($placeholder) . '}}</code> <small>(inside lessons block)</small></td><td>' . esc_html($meaning) . '</td></tr>';
        echo '<tr><td><code>{{#lessons}}…{{/lessons}}</code></td><td>Repeats the block for every statement line</td></tr><tr><td><code>{{#logo_url}}…{{/logo_url}}</code></td><td>Shows the block only when a logo URL is configured</td></tr></tbody></table></details>';
        if (!$canManage) { echo '<p class="description">You can preview the template but need the statement-management capability to change it.</p></details>'; return; }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-template-form">';
        echo '<input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="save_template">';
        wp_nonce_field('dzn_finance_settings');
        echo '<div class="dzn-finance-template-grid"><div>';
        self::templateTextarea('header_html', 'Header HTML', (string) $template['header_html']);
        self::templateTextarea('body_html', 'Lesson table HTML', (string) $template['body_html']);
        self::templateTextarea('footer_html', 'Footer HTML', (string) $template['footer_html']);
        self::templateTextarea('css', 'CSS', (string) $template['css']);
        echo '</div><div class="dzn-finance-template-preview"><p><strong>Live preview</strong></p><iframe id="dzn-finance-live-preview" sandbox title="Live statement template preview" srcdoc="' . esc_attr($sample) . '"></iframe><p class="description">Preview is sandboxed; scripts cannot run.</p></div></div>';
        echo '<table class="form-table"><tbody><tr><th><label for="finance-template-logo">Logo URL</label></th><td><input class="regular-text" type="url" id="finance-template-logo" name="logo_url" value="' . esc_attr((string) $template['logo_url']) . '" placeholder="https://…"><p class="description">The approved theme logo is used when this is empty. Only https image URLs are accepted.</p></td></tr><tr><th><label for="finance-template-locale">Default direction</label></th><td><select id="finance-template-locale" name="locale"><option value="fa"' . selected($template['locale'], 'fa', false) . '>Persian (RTL)</option><option value="en"' . selected($template['locale'], 'en', false) . '>English (LTR)</option></select></td></tr></tbody></table>';
        echo '<p><button class="button button-primary">Save template</button></p></form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Reset the statement template to the delivered default? Recorded statements are not changed.\')"><input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="reset_template">';
        wp_nonce_field('dzn_finance_settings');
        echo '<button class="button">Reset to default template</button></form>';
        self::templatePreviewScript();
        echo '</details>';
    }

    /** Read-only finance review entry points. Each route keeps its own authority. */
    private static function renderReviewQueues(): void {
        if (!current_user_can('dzn_view_finance_authority')) return;
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $payability = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}finance_payability_evaluations WHERE applicable_slot=1 AND disposition='pending'");
        $exceptions = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}finance_exceptions WHERE state='open'");
        $statements = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}finance_statements WHERE state IN ('draft','issued')");
        echo '<section class="dzn-finance-settings-section" id="finance-review"><h2>Finance review</h2><p>Open the read-only views that support statement issuance and reconciliation. These screens never repair a record or change a payment.</p><div class="dzn-finance-cards">';
        echo '<a class="dzn-finance-card" href="' . esc_url(add_query_arg(array('page' => FinancePayabilityController::MENU_SLUG), admin_url('admin.php'))) . '"><strong>' . esc_html(number_format_i18n($payability)) . '</strong><span>Lessons with pending payability</span></a>';
        echo '<a class="dzn-finance-card" href="' . esc_url(add_query_arg(array('page' => FinanceReconciliationController::MENU_SLUG), admin_url('admin.php'))) . '"><strong>' . esc_html(number_format_i18n($exceptions)) . '</strong><span>Open finance exceptions</span></a>';
        echo '<a class="dzn-finance-card" href="' . esc_url(self::tabUrl(self::TAB_STATEMENTS)) . '"><strong>' . esc_html(number_format_i18n($statements)) . '</strong><span>Draft or issued statements</span></a>';
        echo '<a class="dzn-finance-card" href="' . esc_url(add_query_arg(array('page' => FinanceRateController::MENU_SLUG), admin_url('admin.php'))) . '"><strong>→</strong><span>Teacher rate timelines</span></a>';
        echo '</div></section>';
    }

    private static function renderSettings(FinanceAdminReadService $read): void {
        if (!current_user_can('dzn_view_finance_authority') && !current_user_can(RegionalPricingService::MANAGE_CAPABILITY)) { self::denied('Finance settings authority is required.'); return; }
        $pricing = new RegionalPricingService();
        $settings = new FinanceSettingsService();
        $regions = array();
        foreach ($pricing->activeAll() as $row) $regions[$row['region_code']] = $row;
        $conflicts = array();
        try { $conflicts = $pricing->conflicts(); } catch (\Throwable) { $conflicts = array(); }
        $summary = array();
        try { $summary = (new FinanceAdminRedesignReadService())->financeSummary(); } catch (\Throwable) { $summary = array(); }
        if ($summary !== array()) {
            self::renderFinanceSummaryStrip($summary, 'Finance summary');
            echo '<p class="description">Receipts are accepted settlement evidence. Outstanding and expected due amounts come from existing canonical obligations, not a sales forecast. Each currency is shown separately.</p>';
        }
        self::renderReviewQueues();
        $sharedMode = RegionalPricingRule::INTRO_FREE;
        foreach ($regions as $region) { if ((string)$region['introductory_mode'] === RegionalPricingRule::INTRO_PAID) { $sharedMode = RegionalPricingRule::INTRO_PAID; break; } }
        $effectiveFrom = gmdate('Y-m-d H:i:s');
        echo '<section class="dzn-finance-settings-section" id="regional-pricing"><h2>Regional pricing</h2><p><strong>One standard lesson price applies to every standard Course in a currency region.</strong> One shared introductory mode and one effective date apply to the whole form. Prices are entered in normal currency units and stored as exact minor units. Changing a price preserves every historical offer, payment and statement.</p>';
        if ($conflicts) {
            echo '<div class="notice notice-warning"><p><strong>Existing course prices need explicit resolution.</strong> Choose one amount in the affected row; existing offers keep their original price snapshot.</p><ul>';
            foreach ($conflicts as $conflict) echo '<li><strong>' . esc_html((string) $conflict['region_code']) . '</strong> · ' . esc_html((string) $conflict['kind']) . ' · ' . esc_html(implode(', ', array_map(static fn($amount) => PricingMoney::format((int) $amount, (string) $conflict['currency']), $conflict['amounts']))) . '</li>';
            echo '</ul></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="regional-pricing-form" onsubmit="return confirm(\'Save this whole regional pricing configuration? Every listed region is validated first. Historical offers keep their original price.\')">';
        echo '<input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="save_regional_pricing_bundle">';
        wp_nonce_field('dzn_finance_settings');
        echo '<div class="dzn-finance-inline-form"><fieldset><legend>Introductory lesson mode</legend><label class="dzn-finance-checkbox"><input type="radio" name="introductory_mode" value="free"' . checked($sharedMode, RegionalPricingRule::INTRO_FREE, false) . '> Free — no charge</label><label class="dzn-finance-checkbox"><input type="radio" name="introductory_mode" value="paid"' . checked($sharedMode, RegionalPricingRule::INTRO_PAID, false) . '> Paid</label></fieldset>';
        echo '<label>Effective from (UTC)<input type="text" name="effective_from" value="' . esc_attr($effectiveFrom) . '" pattern="\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}" required></label></div>';
        echo '<p class="description">Free means the existing introductory booking, teacher acceptance, Calendar and Meet workflow runs without a charge. Paid reveals a price field for each region. This setting does not disable introductory lesson availability, and it is separate from whether an introductory lesson is payable to the Teacher.</p>';
        echo '<table class="widefat striped dzn-finance-table dzn-finance-pricing-table"><thead><tr><th>Country / region</th><th>Currency</th><th>Standard course price</th><th class="dzn-finance-intro-price">Introductory price <small>(paid mode)</small></th><th>Current</th></tr></thead><tbody>';
        foreach (RegionalPricingRule::REGIONS as $code => $currency) {
            $active = $regions[$code] ?? null;
            $label = RegionalPricingRule::REGION_LABELS[$code] ?? array('fa' => $code, 'en' => $code);
            $mode = $active['introductory_mode'] ?? $sharedMode;
            echo '<tr><td><strong>' . esc_html($label['en']) . '</strong><br><small>' . esc_html($code) . '</small></td><td>' . esc_html($currency) . '</td>';
            echo '<td><input class="dzn-finance-price-input" name="regions[' . esc_attr($code) . '][standard_price]" inputmode="decimal" value="' . esc_attr($active ? PricingMoney::fromMinor((int)$active['standard_amount_minor'], $currency) : '') . '" placeholder="40.00"></td>';
            echo '<td class="dzn-finance-intro-price"><input class="dzn-finance-price-input" name="regions[' . esc_attr($code) . '][introductory_price]" inputmode="decimal" value="' . esc_attr($mode === RegionalPricingRule::INTRO_PAID && $active && $active['introductory_amount_minor'] !== null ? PricingMoney::fromMinor((int)$active['introductory_amount_minor'], $currency) : '') . '" placeholder="0.00"></td>';
            echo '<td><small>' . ($active ? esc_html(PricingMoney::format((int)$active['standard_amount_minor'], $currency)) . ' · ' . esc_html(RegionalPricingRule::introLabel((string)$active['introductory_mode'], $active['introductory_amount_minor'] === null ? null : (int)$active['introductory_amount_minor'], $currency)) . '<br>since ' . esc_html((string)$active['effective_from']) : 'Not configured') . '</small></td></tr>';
        }
        echo '</tbody></table>';
        echo '<div class="dzn-finance-preview-card" id="dzn-finance-pricing-preview"><strong>Preview</strong><p>Edit a row to preview what will take effect. No value is saved until the form is submitted.</p></div>';
        echo '<p><button class="button button-primary">Save regional pricing</button></p></form>';
        echo '<script>(function(){var form=document.getElementById("regional-pricing-form");if(!form)return;var table=form.querySelector(".dzn-finance-pricing-table"),preview=document.getElementById("dzn-finance-pricing-preview");if(!table||!preview)return;function render(){var paid=form.querySelector("[name=introductory_mode]:checked")&&form.querySelector("[name=introductory_mode]:checked").value==="paid";table.classList.toggle("dzn-finance-pricing-paid",paid);form.classList.toggle("dzn-finance-pricing-paid",paid);var rows=[];table.querySelectorAll("tbody tr").forEach(function(tr){var cells=tr.querySelectorAll("td"),std=cells[2]?cells[2].querySelector("input"):null,intro=cells[3]?cells[3].querySelector("input"):null;if(!std||std.value.trim()==="")return;rows.push((cells[0]?cells[0].innerText.trim():"Region")+" · "+(cells[1]?cells[1].innerText.trim():"")+" · standard "+std.value+(paid&&intro&&intro.value.trim()!==""?" · introductory "+intro.value:" · introductory free"));});preview.innerHTML="<strong>Preview</strong><p>"+(rows.length?rows.join("<br>"):"No region has a standard price yet.")+"</p>";}form.addEventListener("input",render);form.addEventListener("change",render);render();})();</script></section>';

        self::renderTeacherSettings($read, $settings);
        self::renderPolicySettings();
        echo '<details class="dzn-finance-settings-section" id="stripe"><summary><strong>Stripe integration and safeguards</strong></summary><p>Existing Stripe test/live separation, encrypted credentials, account binding, webhook verification and payment-worker restrictions are preserved. Secret values are never displayed or changed by this redesign.</p>';
        if (current_user_can(PaymentExecutionController::CAPABILITY)) {
            try { IntegrationControlPlaneController::stripeSettingsContent(true); } catch (\Throwable $error) { echo '<div class="notice notice-error"><p>Stripe configuration is unavailable in this request.</p></div>'; }
        } else {
            echo '<p class="description">Stripe configuration requires payment provider authority.</p>';
        }
        echo '</details>';
        echo '<p class="description">Booking horizon is an academy scheduling rule and now lives in <a href="' . esc_url(admin_url('admin.php?page=dzn-policies') . '#booking-horizon') . '">Settings → Policies</a>. The manual ledger remains independent from every setting on this page.</p>';
    }

    private static function renderTeacherSettings(FinanceAdminReadService $read, FinanceSettingsService $settings): void {
        $global = $settings->globalTeacherRate();
        $teachers = $read->activeTeachers();
        $selectedTeacher = absint($_GET['teacher_id'] ?? 0);
        if ($selectedTeacher < 1 && $teachers) $selectedTeacher = (int) $teachers[0]->id;
        $overrides = $settings->overrides();
        $introPayability = array('set' => false, 'value' => null, 'version' => null);
        try { $introPayability = (new FinancePolicyService())->resolve('INTRO_PAYABILITY_POLICY', gmdate('Y-m-d H:i:s')); } catch (\Throwable) { $introPayability = array('set' => false, 'value' => null, 'version' => null); }
        echo '<section class="dzn-finance-settings-section" id="teacher-rates"><h2>Teacher compensation</h2><p>Teacher pay is separate from student prices and is always denominated in <strong>Iranian toman (TOM)</strong>. The global default applies to Teachers without an explicit rate. A teacher-specific rate overrides it from its effective date. Course-specific overrides, audit evidence and completed lesson snapshots are preserved in the existing rate authority.</p>';
        if (current_user_can(FinanceSettingsService::MANAGE_TEACHER_RATE_CAPABILITY)) {
            echo '<div class="dzn-finance-split"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-region-card" onsubmit="return confirm(\'Save the global teacher-rate default? Eligible Teachers will receive a new effective rate version.\')"><input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="save_global_teacher_rate">';
            wp_nonce_field('dzn_finance_settings');
            echo '<h3>Global default</h3><p><label>Amount per session (Toman)<input name="teacher_global_amount" inputmode="numeric" required value="' . esc_attr($global ? $global['display'] : '') . '" placeholder="450000"></label></p><p><label>Effective from (UTC)<input type="text" name="teacher_global_effective_from" value="' . esc_attr($global['effective_from'] ?? gmdate('Y-m-d H:i:s')) . '" pattern="\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}"></label></p><p><label><input type="checkbox" name="apply_to_teachers" value="1" checked> Apply to active Teachers without an explicit override</label></p><button class="button button-primary">Save global default</button>';
            if ($global) echo '<p><small>Current default: ' . esc_html($global['display']) . ' Toman · applied to ' . esc_html((string) $global['applied_teachers']) . ' Teacher(s).</small></p>';
            if ($global && $global['errors']) { echo '<div class="notice notice-warning inline"><p>Some Teachers were not updated because their rate timeline or snapshots require review.</p><ul>'; foreach ($global['errors'] as $failure) echo '<li>Teacher #' . esc_html((string) ($failure['teacher_id'] ?? '')) . ': ' . esc_html((string) ($failure['reason'] ?? 'review required')) . '</li>'; echo '</ul></div>'; }
            echo '</form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-region-card" onsubmit="return confirm(\'Record this teacher-specific rate override? It will take precedence over the global default from its effective date.\')"><input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="save_teacher_rate">';
            wp_nonce_field('dzn_finance_settings');
            echo '<h3>Teacher-specific rate</h3><p><label>Teacher<select name="teacher_id">';
            foreach ($teachers as $teacher) echo '<option value="' . (int) $teacher->id . '"' . selected($selectedTeacher, (int) $teacher->id, false) . '>' . esc_html((string) $teacher->display_name) . '</option>';
            echo '</select></label></p><p><label>Amount per session (Toman)<input name="teacher_amount" inputmode="numeric" required placeholder="500000"></label></p><p><label>Effective from (UTC)<input type="text" name="teacher_effective_from" value="' . esc_attr(gmdate('Y-m-d H:i:s')) . '" pattern="\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}"></label></p><p><label>Evidence reference<input name="rate_evidence_reference" required placeholder="Agreement or signed rate record"></label></p><button class="button">Record teacher rate</button><p class="description">A Teacher-specific rate is marked as an override; the global default will not replace it.</p>';
            echo '<details class="dzn-finance-subsection"><summary>Advanced · course-specific override</summary><p><label>Scope<select name="scope_kind"><option value="teacher">All courses for this Teacher</option><option value="teacher_course">One Course</option></select></label></p><p><label>Course (only for one-course scope)<select name="course_scope_id"><option value="0">—</option>';
            foreach ($read->activeCourses() as $course) echo '<option value="' . (int) $course->id . '">' . esc_html((string) $course->name) . '</option>';
            echo '</select></label></p><p class="description">Course-specific rates keep their existing precedence and audit trail. They are not rewritten by this form.</p></details></form></div>';
        } else {
            echo '<p class="description">Teacher rate management requires teacher-rate authority.</p>';
        }
        if ($selectedTeacher > 0) {
            $teacherName = self::teacherName($teachers, $selectedTeacher);
            echo '<h3>Current rate and timeline · ' . esc_html($teacherName) . '</h3>';
            try {
                $timeline = (new TeacherRateReadService())->timeline($selectedTeacher);
                $active = null;
                foreach ($timeline['rates'] as $rate) if ((string)$rate['status'] === 'active') { $active = $rate; break; }
                if ($active) echo '<p><strong>Current:</strong> ' . esc_html(PricingMoney::format((int)$active['amount_minor'], (string)$active['currency'])) . ' per session · version ' . (int)$active['rate_version'] . ' · effective ' . esc_html((string)$active['effective_from']) . '</p>';
                echo '<table class="widefat striped"><thead><tr><th>Version</th><th>Scope</th><th>Source</th><th>Effective from</th><th>Effective until</th><th>Status</th><th>Amount</th><th>Currency</th></tr></thead><tbody>';
                if (!$timeline['rates']) echo '<tr><td colspan="8">No effective-dated rate recorded.</td></tr>';
                foreach ($timeline['rates'] as $rate) {
                    $source = ($rate['rate_origin'] ?? null) === 'global_inherited' ? 'Global default' : (($rate['rate_origin'] ?? null) === 'explicit' ? 'Explicit' : 'Unknown / legacy');
                    echo '<tr><td>' . esc_html((string) $rate['rate_version']) . '</td><td>' . esc_html((string) $rate['scope_kind'] . ((int) $rate['course_scope_id'] > 0 ? ' · #' . (int) $rate['course_scope_id'] : '')) . '</td><td>' . esc_html($source) . '</td><td>' . esc_html((string) $rate['effective_from']) . '</td><td>' . esc_html((string) ($rate['effective_until'] ?? '—')) . '</td><td>' . esc_html((string) $rate['status']) . '</td><td>' . esc_html(PricingMoney::format((int) $rate['amount_minor'], (string) $rate['currency'])) . '</td><td>' . esc_html((string) $rate['currency']) . '</td></tr>';
                }
                echo '</tbody></table>';
            } catch (\Throwable) { echo '<p class="description">Rate history is unavailable for this Teacher.</p>'; }
            if (in_array($selectedTeacher, $overrides, true) && current_user_can(FinanceSettingsService::MANAGE_TEACHER_RATE_CAPABILITY)) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Resume the global teacher-rate default for this Teacher? A new effective rate version will be recorded.\')"><input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="resume_global_teacher_rate"><input type="hidden" name="teacher_id" value="' . $selectedTeacher . '">';
                wp_nonce_field('dzn_finance_settings');
                echo '<button class="button">Resume global default from now</button></form>';
            }
        }
        echo '<section class="dzn-finance-subsection" id="intro-payability"><h3>Teacher payability for introductory lessons</h3><p>This controls whether an introductory lesson is compensable to the Teacher. It is separate from whether a Student is charged for an introductory lesson; changing either setting never changes the other.</p>';
        if (current_user_can('dzn_manage_finance_policies')) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Record a new introductory-lesson teacher-payability policy? Existing snapshots are not rewritten.\')"><input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="save_intro_payability">';
            wp_nonce_field('dzn_finance_settings');
            echo '<label class="dzn-finance-checkbox"><input type="checkbox" name="intro_payability" value="1"' . checked((string)($introPayability['value'] ?? '') === 'payable', true, false) . '> Introductory lessons are payable to the Teacher</label>';
            echo '<p class="description">Current policy: ' . esc_html($introPayability['set'] ? (string)$introPayability['value'] : 'Unset') . ' · version ' . esc_html($introPayability['version'] === null ? '—' : (string)$introPayability['version']) . '. Student introductory pricing is configured only in Regional pricing above.</p><button class="button">Save payability policy</button></form>';
        } else {
            echo '<p class="description">Introductory teacher payability requires finance-policy authority. Current: ' . esc_html($introPayability['set'] ? (string)$introPayability['value'] : 'Unset') . '.</p>';
        }
        echo '</section>';
        self::renderGoldenHourSettings($global);
        echo '</section>';
    }

    /**
     * Golden-hour teacher pay configuration.
     *
     * The narrow teacher-rate capability is required; the coverage dashboard capability alone can
     * never edit remuneration. A saved version is effective-dated and never rewrites a committed,
     * completed or paid snapshot.
     */
    private static function renderGoldenHourSettings(?array $global): void {
        $canManage = current_user_can(FinanceSettingsService::MANAGE_TEACHER_RATE_CAPABILITY);
        $currency = FinanceSettingsService::TEACHER_CURRENCY;
        $exampleMinor = $global ? (int) $global['amount_minor'] : 0;
        $service = new GoldenHourPolicyService();
        $current = $service->latest(GoldenHourRule::GLOBAL_IRAN_KEY);
        $rules = self::goldenRules($current);
        $history = (new \Delnavazan\Platform\Core\Infrastructure\Repository\FinanceSettingsRepository())->history(GoldenHourRule::POLICY_KEY_PREFIX . GoldenHourRule::GLOBAL_IRAN_KEY);
        $maxBonus = (int)($current['bonus_basis_points'] ?? 0);
        foreach ($rules as $rule) $maxBonus = max($maxBonus, (int)($rule['bonus_basis_points'] ?? 0));
        echo '<section id="golden-hour-pay" class="dzn-finance-golden"><h3>Golden-hour pay bonus · Iran</h3>';
        echo '<p>Configure editable local-time rows in <strong>Asia/Tehran</strong>. Each row has weekday(s), a start and finish time, a bonus percentage and an effective date. A row may cross midnight, for example <code>22:00–02:00</code>. Overlaps are refused. The policy applies to otherwise payable lessons only; completed, approved or paid compensation is never rewritten.</p>';
        if (!$canManage) {
            echo '<p class="description">Golden-hour changes require teacher-rate authority. Coverage-dashboard access alone is not sufficient.</p>';
        } else {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-golden-card" onsubmit="return confirm(\'Save these effective-dated Iran golden-hour rules? Committed compensation will not be rewritten.\')">';
            echo '<input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="save_golden_hour_policy"><input type="hidden" name="golden_area" value="' . esc_attr(GoldenHourRule::GLOBAL_IRAN_KEY) . '"><input type="hidden" name="golden_timezone" value="' . esc_attr(GoldenHourRule::GLOBAL_IRAN_TIMEZONE) . '"><input type="hidden" name="golden_effective_from" value="' . esc_attr(gmdate('Y-m-d H:i:s')) . '">';
            wp_nonce_field('dzn_finance_settings');
            echo '<p><label class="dzn-finance-checkbox"><input type="checkbox" name="golden_enabled" value="1"' . checked(!empty($current['enabled']), true, false) . '> Enable the global Iran golden-hour bonus</label></p>';
            echo '<table class="widefat striped dzn-finance-table dzn-finance-golden-table"><thead><tr><th>Days</th><th>Local start</th><th>Local finish</th><th>Bonus %</th><th>Effective from (UTC)</th><th>Action</th></tr></thead><tbody>';
            $weekdayLabels = array(1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun');
            foreach ($rules as $index => $rule) {
                $selectedDays = array_map('intval', (array)($rule['weekdays'] ?? array()));
                echo '<tr><td class="dzn-finance-golden-days-cell">';
                foreach ($weekdayLabels as $day => $label) echo '<label class="dzn-finance-checkbox"><input type="checkbox" name="golden_rules[' . $index . '][weekdays][]" value="' . $day . '"' . checked(in_array($day, $selectedDays, true), true, false) . '> ' . esc_html($label) . '</label>';
                echo '</td><td><input name="golden_rules[' . $index . '][start]" value="' . esc_attr((string)$rule['start']) . '" pattern="[0-2][0-9]:[0-5][0-9]" placeholder="22:00" required></td>';
                echo '<td><input name="golden_rules[' . $index . '][end]" value="' . esc_attr((string)$rule['end']) . '" pattern="([0-2][0-9]:[0-5][0-9]|24:00)" placeholder="02:00" required></td>';
                echo '<td><input type="number" name="golden_rules[' . $index . '][bonus_percentage]" min="0" max="100" step="0.01" value="' . esc_attr(number_format(((int)$rule['bonus_basis_points']) / 100, 2, '.', '')) . '" required></td>';
                echo '<td><input name="golden_rules[' . $index . '][effective_from]" value="' . esc_attr((string)($rule['effective_from'] ?? gmdate('Y-m-d H:i:s'))) . '" pattern="\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}" required></td>';
                echo '<td><button class="button button-small" type="submit" form="retire-golden-' . $index . '" onclick="return confirm(\'Retire this row in a new policy version?\')">Retire</button></td></tr>';
                echo '<tr class="dzn-finance-golden-retire"><td colspan="6"><form id="retire-golden-' . $index . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="retire_golden_hour_rule"><input type="hidden" name="golden_rule_index" value="' . $index . '">' . wp_nonce_field('dzn_finance_settings', '_wpnonce', true, false) . '</form></td></tr>';
            }
            $newIndex = count($rules);
            echo '<tr data-dzn-golden-new-row><td class="dzn-finance-golden-days-cell">';
            foreach ($weekdayLabels as $day => $label) echo '<label class="dzn-finance-checkbox"><input type="checkbox" name="golden_rules[' . $newIndex . '][weekdays][]" value="' . $day . '"> ' . esc_html($label) . '</label>';
            echo '</td><td><input name="golden_rules[' . $newIndex . '][start]" pattern="[0-2][0-9]:[0-5][0-9]" placeholder="06:00"></td><td><input name="golden_rules[' . $newIndex . '][end]" pattern="([0-2][0-9]:[0-5][0-9]|24:00)" placeholder="10:00"></td><td><input type="number" name="golden_rules[' . $newIndex . '][bonus_percentage]" min="0" max="100" step="0.01" placeholder="25"></td><td><input name="golden_rules[' . $newIndex . '][effective_from]" value="' . esc_attr(gmdate('Y-m-d H:i:s')) . '" pattern="\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}"></td><td><span class="description">New row</span></td></tr>';
            echo '</tbody></table>';
            echo '<p class="description">Time zone: <strong>' . esc_html(GoldenHourRule::GLOBAL_IRAN_TIMEZONE) . '</strong>. Each row’s effective date is honoured; earlier committed lesson details are never rewritten. A blank row is ignored.</p>';
            echo '<p><button class="button button-primary">Save golden-hour rules</button></p></form>';
            echo '<div class="dzn-finance-split"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-region-card"><h4>Copy a row to selected days</h4><input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="copy_golden_hour_rule">';
            wp_nonce_field('dzn_finance_settings');
            echo '<label>Source row<select name="golden_rule_index">';
            foreach ($rules as $index => $rule) echo '<option value="' . $index . '">Row ' . ($index + 1) . ' · ' . implode(',', array_map(static fn($d) => $weekdayLabels[(int)$d] ?? (string)$d, (array)$rule['weekdays'])) . ' · ' . esc_html((string)$rule['start'] . '–' . (string)$rule['end']) . '</option>';
            echo '</select></label><fieldset><legend>Copy to days</legend>';
            foreach ($weekdayLabels as $day => $label) echo '<label class="dzn-finance-checkbox"><input type="checkbox" name="copy_days[]" value="' . $day . '"> ' . esc_html($label) . '</label>';
            echo '</fieldset><button class="button">Copy row</button></form>';
            echo '<div class="dzn-finance-region-card"><h4>Preview</h4><p>' . esc_html(GoldenHourRule::previewText($exampleMinor, $currency, $maxBonus, GoldenHourRule::GLOBAL_IRAN_TIMEZONE)) . '</p><p class="description">The preview uses the global teacher rate. Actual bonuses use each Teacher’s effective snapshotted rate.</p></div></div>';
        }
        if ($history) {
            echo '<details class="dzn-finance-golden-history"><summary>Policy history · Iran (' . count($history) . ' versions)</summary><table class="widefat striped"><thead><tr><th>Version</th><th>Status</th><th>Rules (Asia/Tehran)</th><th>Effective from</th><th>Effective until</th><th>Recorded</th><th>Actions</th></tr></thead><tbody>';
            foreach (array_slice($history, 0, 20) as $row) {
                $decoded = json_decode((string) $row->setting_value, true);
                $decoded = is_array($decoded) ? $decoded : array();
                $isActive = (string) $row->status === 'active';
                echo '<tr><td>v' . (int)$row->setting_version . '</td><td>' . esc_html((string)$row->status) . '</td><td>' . esc_html(self::goldenRulesText($decoded)) . '</td><td>' . esc_html((string)($decoded['effective_from'] ?? '—')) . '</td><td>' . esc_html((string)($decoded['effective_until'] ?? '—')) . '</td><td>' . esc_html((string)$row->created_at) . '</td><td>';
                if (!$isActive) echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Remove this previous policy version? Committed lesson details are retained.\')"><input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="remove_golden_hour_policy"><input type="hidden" name="golden_setting_id" value="' . (int)$row->id . '">' . wp_nonce_field('dzn_finance_settings', '_wpnonce', true, false) . '<button class="button button-small">Remove</button></form>';
                else echo 'Active';
                echo '</td></tr>';
            }
            echo '</tbody></table></details>';
        }
        echo '<h4>Golden-hour coverage</h4><p class="description">The existing coverage calculation, shown immediately below the active rules. Coverage is not bookable capacity and never changes a pay rate.</p>';
        if (current_user_can('dzn_view_booking_requests')) {
            AcademyCoverageController::renderAtlas(self::MENU_SLUG);
        } else {
            echo '<p class="description">Coverage requires the Overview view authority.</p>';
        }
        echo '</section>';
    }

    /** @return list<array<string,mixed>> */
    private static function goldenRules(array $policy): array {
        $rules = array_values((array)($policy['rules'] ?? array()));
        if ($rules !== array()) return $rules;
        $periods = (array)($policy['periods'] ?? array());
        if ($periods === array()) $periods = array(array('start' => (string)($policy['start'] ?? '16:00'), 'end' => (string)($policy['end'] ?? '21:00')));
        $out = array();
        foreach ($periods as $period) {
            $out[] = array(
                'weekdays' => array_values(array_map('intval', (array)($policy['weekdays'] ?? array(1,2,3,4,5,6,7)))),
                'start' => (string)($period['start'] ?? '16:00'),
                'end' => (string)($period['end'] ?? '21:00'),
                'bonus_basis_points' => (int)($policy['bonus_basis_points'] ?? 0),
                'effective_from' => (string)($policy['effective_from'] ?? GoldenHourRule::DEFAULT_EFFECTIVE_FROM),
            );
        }
        return $out;
    }

    private static function goldenRulesText(array $policy): string {
        $rules = self::goldenRules($policy);
        $labels = array(1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun');
        $parts = array();
        foreach ($rules as $rule) {
            $days = implode(',', array_map(static fn($d) => $labels[(int)$d] ?? (string)$d, (array)($rule['weekdays'] ?? array())));
            $parts[] = $days . ' ' . (string)$rule['start'] . '–' . (string)$rule['end'] . ' @ ' . number_format(((int)$rule['bonus_basis_points']) / 100, 2, '.', '') . '%';
        }
        return implode(' | ', $parts);
    }

    private static function goldenPeriodsText(array $policy): string {
        $periods = (array) ($policy['periods'] ?? array());
        if ($periods === array()) $periods = array(array('start' => (string) ($policy['start'] ?? ''), 'end' => (string) ($policy['end'] ?? '')));
        $lines = array();
        foreach ($periods as $period) {
            $start = substr((string) ($period['start'] ?? ''), 0, 5);
            $end = substr((string) ($period['end'] ?? ''), 0, 5);
            if ($start !== '' && $end !== '') $lines[] = $start . '–' . $end;
        }
        return implode("\n", $lines);
    }

    private static function goldenHourInput(): array {
        $rules = array();
        foreach ((array)($_POST['golden_rules'] ?? array()) as $row) {
            if (!is_array($row)) continue;
            $days = array_values(array_filter(array_map('absint', (array)($row['weekdays'] ?? array())), static fn(int $day): bool => $day >= 1 && $day <= 7));
            $start = trim((string)($row['start'] ?? ''));
            $end = trim((string)($row['end'] ?? ''));
            $bonus = trim((string)($row['bonus_percentage'] ?? ''));
            $effectiveFrom = trim((string)($row['effective_from'] ?? ''));
            if ($days === array() && $start === '' && $end === '' && $bonus === '') continue;
            if ($days === array() || $start === '' || $end === '' || $bonus === '') throw new \InvalidArgumentException('golden_hour_window_invalid');
            $rules[] = array(
                'weekdays' => $days,
                'start' => $start,
                'end' => $end,
                'bonus_percentage' => $bonus,
                'effective_from' => $effectiveFrom !== '' ? $effectiveFrom : gmdate('Y-m-d H:i:s'),
            );
        }
        if ($rules === array()) {
            // Compatibility with a pre-redesign form submission.
            $periods = array();
            $text = (string) ($_POST['golden_periods_text'] ?? '');
            foreach (preg_split('/\r\n|\r|\n/', $text) ?: array() as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (preg_match('/^(\d{1,2}:\d{2})\s*(?:-|–|—|to)\s*(\d{1,2}:\d{2})$/i', $line, $matches) !== 1) {
                    throw new \InvalidArgumentException('golden_hour_window_invalid');
                }
                $periods[] = array('start' => $matches[1], 'end' => $matches[2]);
            }
            if ($periods === array()) throw new \InvalidArgumentException('golden_hour_period_required');
            $weekdays = isset($_POST['golden_weekdays']) && is_array($_POST['golden_weekdays']) ? array_map('absint', $_POST['golden_weekdays']) : array();
            foreach ($periods as $period) {
                $rules[] = array(
                    'weekdays' => $weekdays,
                    'start' => (string)$period['start'],
                    'end' => (string)$period['end'],
                    'bonus_percentage' => (string)($_POST['golden_bonus_percentage'] ?? ''),
                    'effective_from' => (string)($_POST['golden_effective_from'] ?? gmdate('Y-m-d H:i:s')),
                );
            }
        }
        return array(
            'enabled' => isset($_POST['golden_enabled']) ? '1' : '',
            'timezone' => (string) ($_POST['golden_timezone'] ?? GoldenHourRule::GLOBAL_IRAN_TIMEZONE),
            'rules' => $rules,
            'effective_from' => (string) ($_POST['golden_effective_from'] ?? gmdate('Y-m-d H:i:s')),
            'effective_until' => (string) ($_POST['golden_effective_until'] ?? ''),
        );
    }

    /** The preview follows the global teacher-rate setting; actual pay uses the teacher's snapshotted rate. */
    private static function goldenHourPreviewScript(): void {
        echo '<script>(function(){var forms=document.querySelectorAll("[data-dzn-golden-form]");forms.forEach(function(form){var pct=form.querySelector("[name=golden_bonus_percentage]"),tz=form.querySelector("[name=golden_timezone]"),out=form.querySelector("[data-dzn-golden-preview]");if(!pct||!out)return;var update=function(){var p=Math.max(0,Math.min(100,parseFloat(String(pct.value||"0"))||0)),b=Math.max(0,parseFloat(String(form.getAttribute("data-dzn-example-base")||"0").replace(/,/g,""))||0),c=form.getAttribute("data-dzn-currency")||"";var bonus=b*p/100,total=b+bonus;var f=function(n){return n.toLocaleString(undefined,{minimumFractionDigits:c==="TOM"?0:2,maximumFractionDigits:c==="TOM"?0:2});};out.textContent="A base rate of "+f(b)+" "+c+"/hour becomes "+f(total)+" "+c+"/hour during an eligible window ("+p+"% · "+(tz?tz.value:"Asia/Tehran")+"). A 60-minute lesson entirely inside a window earns a "+f(bonus)+" "+c+" bonus.";};[pct,tz].forEach(function(el){if(el)el.addEventListener("input",update);});update();});})();</script>';
    }

    private static function renderPolicySettings(): void {
        $finance = new FinancePolicyService();
        $commercial = new CommercialPolicyService();
        echo '<section class="dzn-finance-settings-section" id="finance-policies"><h2>Finance policies</h2><p>Existing policy rules are consolidated here. Structural accounting invariants are not configurable. Recording a new version never changes a completed transaction or statement.</p>';
        $financeDescriptions = array(
            'INTRO_PAYABILITY_POLICY' => 'Whether an introductory lesson is compensable to the Teacher.',
            'STUDENT_NO_SHOW_COMPENSATION_POLICY' => 'Whether a student no-show remains compensable.',
            'INTERRUPTION_COMPENSATION_POLICY' => 'Whether an interrupted lesson remains compensable.',
            'FINANCE_STATEMENT_TIMEZONE' => 'IANA timezone used to present statement periods.',
        );
        echo '<h3>Compensation and statement policies</h3><table class="widefat striped"><thead><tr><th>Key</th><th>Meaning</th><th>Active value</th><th>Version</th><th>Effective from</th></tr></thead><tbody>';
        if (current_user_can('dzn_view_finance_authority')) {
            foreach (FinanceRule::POLICY_KEYS as $key) {
                $history = $finance->history($key);
                $active = null;
                foreach ($history as $row) if ((string) $row['status'] === 'active' && $active === null) $active = $row;
                echo '<tr><td><code>' . esc_html($key) . '</code></td><td>' . esc_html($financeDescriptions[$key] ?? '') . '</td><td>' . esc_html($active ? (string) $active['policy_value'] : 'Unset') . '</td><td>' . esc_html($active ? (string) $active['policy_version'] : '—') . '</td><td>' . esc_html($active ? (string) $active['effective_from'] : '—') . '</td></tr>';
            }
        } else {
            echo '<tr><td colspan="5">Finance policy history requires finance view authority.</td></tr>';
        }
        echo '</tbody></table>';
        if (current_user_can('dzn_manage_finance_policies')) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-inline-form" onsubmit="return confirm(\'Record a new finance policy version? Existing transactions and statements are not rewritten.\')"><input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="save_finance_policy">';
            wp_nonce_field('dzn_finance_settings');
            echo '<label>Policy<select name="policy_key">';
            foreach (FinanceRule::POLICY_KEYS as $key) echo '<option value="' . esc_attr($key) . '">' . esc_html($key) . '</option>';
            echo '</select></label><label>Value<input name="policy_value" required></label><label>Value type<select name="value_type"><option value="policy_reference">Policy reference</option><option value="timezone">IANA timezone</option></select></label><label>Effective from UTC<input name="effective_from" value="' . esc_attr(gmdate('Y-m-d H:i:s')) . '" pattern="\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}"></label><button class="button">Record policy version</button></form>';
        }
        $commercialDescriptions = array(
            'INTRO_BOOKING_HORIZON' => 'How far ahead an introductory booking may be requested.',
            'MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS' => 'How long a renewal slot is held before collection.',
            'AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME' => 'Lead time before an automatic renewal collection attempt.',
            'PAYMENT_RECOVERY_POLICY' => 'Reference policy for payment recovery and lapse handling.',
            'INSTALMENT_DUE_DATE_POLICY' => 'Second-instalment deadline. Approved value: 1 week or 604800 seconds (168 hours before Lesson 7); unset means no deadline.',
        );
        echo '<h3>Commercial timing and settlement policies</h3><table class="widefat striped"><thead><tr><th>Key</th><th>Meaning</th><th>Active value</th><th>Version</th></tr></thead><tbody>';
        foreach (CommercialRule::POLICY_KEYS as $key) {
            if ($key === 'INTRO_BOOKING_HORIZON') continue;
            try { $current = current_user_can('dzn_view_commercial_authority') ? $commercial->current($key) : array('set' => false, 'value' => null, 'version' => null); } catch (\Throwable) { $current = array('set' => false, 'value' => null, 'version' => null); }
            echo '<tr><td><code>' . esc_html($key) . '</code></td><td>' . esc_html($commercialDescriptions[$key] ?? '') . '</td><td>' . esc_html($current['set'] ? (string) $current['value'] : 'Unset') . '</td><td>' . esc_html($current['version'] === null ? '—' : (string) $current['version']) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description"><strong>Booking horizon</strong> has moved to <a href="' . esc_url(admin_url('admin.php?page=dzn-policies') . '#booking-horizon') . '">Settings → Policies</a> because it is an academy scheduling rule, not a Finance rule.</p>';
        if (current_user_can('dzn_manage_commercial_policies')) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-inline-form" onsubmit="return confirm(\'Record a new commercial policy version? Existing purchases and payments are not rewritten.\')"><input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="save_commercial_policy">';
            wp_nonce_field('dzn_finance_settings');
            echo '<label>Policy<select name="policy_key">';
            foreach (CommercialRule::POLICY_KEYS as $key) if ($key !== 'INTRO_BOOKING_HORIZON') echo '<option value="' . esc_attr($key) . '">' . esc_html($key) . '</option>';
            echo '</select></label><label>Value<input name="policy_value" required></label><label>Value type<select name="value_type"><option value="weeks">Weeks</option><option value="duration">Duration seconds</option><option value="policy_reference">Policy reference</option></select></label><button class="button">Record policy version</button></form>';
        }
        echo '<p class="description">Payment timing, refund review, cancellation and settlement rules remain enforced by their existing services. This page exposes their recorded configuration, not a second rule engine.</p></section>';
    }

    private static function saveFinancePolicy(): void {
        if (!current_user_can('dzn_manage_finance_policies')) throw new \RuntimeException('Unauthorized');
        $key = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string) ($_POST['policy_key'] ?? '')) ?? '');
        $service = new FinancePolicyService();
        $prior = current_user_can('dzn_view_finance_authority') ? $service->history($key) : array();
        $now = gmdate('Y-m-d H:i:s');
        $service->record($key, array(
            'policy_value' => sanitize_text_field((string) ($_POST['policy_value'] ?? '')),
            'value_type' => sanitize_key((string) ($_POST['value_type'] ?? 'policy_reference')),
            'effective_from' => sanitize_text_field((string) ($_POST['effective_from'] ?? $now)),
            'evidence_channel' => 'authenticated_platform',
            'evidence_reference' => 'finance-settings:' . $key . ':' . $now,
            'evidence_at' => $now,
        ), 'finance-settings-policy-' . $key . '-' . $now . '-' . wp_generate_uuid4());
        foreach ($prior as $row) {
            if ((string) $row['status'] === 'active') {
                try {
                    $service->supersede($key, (int) $row['policy_version'], array(
                        'evidence_channel' => 'authenticated_platform',
                        'evidence_reference' => 'finance-settings-supersede:' . $key . ':' . $now,
                        'evidence_at' => $now,
                    ), 'finance-settings-supersede-' . $key . '-' . (int) $row['policy_version'] . '-' . wp_generate_uuid4());
                } catch (\Throwable) { /* A supersession conflict leaves the recorded version intact. */ }
            }
        }
    }

    private static function saveCommercialPolicy(): void {
        if (!current_user_can('dzn_manage_commercial_policies')) throw new \RuntimeException('Unauthorized');
        $key = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string) ($_POST['policy_key'] ?? '')) ?? '');
        $now = gmdate('Y-m-d H:i:s');
        (new CommercialPolicyService())->set($key, array(
            'policy_value' => sanitize_text_field((string) ($_POST['policy_value'] ?? '')),
            'value_type' => sanitize_key((string) ($_POST['value_type'] ?? 'policy_reference')),
            'evidence_channel' => 'authenticated_platform',
            'evidence_reference' => 'finance-settings:commercial:' . $key . ':' . $now,
            'evidence_at' => $now,
        ), 'finance-settings-commercial-' . $key . '-' . $now . '-' . wp_generate_uuid4());
    }

    private static function manualEntryForm(): void {
        echo '<details class="dzn-finance-settings-section"><summary><strong>Record a manual ledger entry</strong></summary><p>This records bookkeeping only. It does not collect a student payment or change a teacher statement.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="dzn_cashbook_record"><input type="hidden" name="cashbook_operation" value="record"><input type="hidden" name="command_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
        wp_nonce_field('cashbook_record');
        echo '<table class="form-table"><tbody><tr><th><label for="finance-ledger-account">Account</label></th><td><select id="finance-ledger-account" name="account_key"><option value="australia">Australia account (AUD)</option><option value="iran">Iran account (TOM)</option></select></td></tr><tr><th><label for="finance-ledger-kind">Type</label></th><td><select id="finance-ledger-kind" name="transaction_kind"><option value="income">Money received</option><option value="expense">Money spent</option><option value="opening_balance">Opening balance</option><option value="balance_adjustment">Balance adjustment</option></select></td></tr><tr><th><label for="finance-ledger-date">Date</label></th><td><input id="finance-ledger-date" type="date" name="transaction_date" required max="' . esc_attr(gmdate('Y-m-d')) . '" value="' . esc_attr(gmdate('Y-m-d')) . '"></td></tr><tr><th><label for="finance-ledger-amount">Amount</label></th><td><input id="finance-ledger-amount" name="amount" inputmode="decimal" required></td></tr><tr><th><label for="finance-ledger-category">Category</label></th><td><input id="finance-ledger-category" name="category" maxlength="100"></td></tr><tr><th><label for="finance-ledger-description">Description</label></th><td><input id="finance-ledger-description" class="large-text" name="description" required maxlength="500"></td></tr><tr><th><label for="finance-ledger-direction">Adjustment direction</label></th><td><select id="finance-ledger-direction" name="adjustment_direction"><option value="increase">Increase balance</option><option value="decrease">Decrease balance</option></select> <label for="finance-ledger-reason">Reason</label> <select id="finance-ledger-reason" name="reason_code"><option value="data_entry_correction">Data entry correction</option><option value="opening_balance">Opening balance correction</option><option value="balance_reconciliation">Statement review</option><option value="other_reviewed_correction">Other reviewed correction</option></select><p class="description">Used only for a balance adjustment.</p></td></tr><tr><th><label for="finance-ledger-evidence">Evidence reference</label></th><td><input id="finance-ledger-evidence" class="large-text" name="evidence_reference" required maxlength="191"><input type="hidden" name="evidence_channel" value="staff_record"></td></tr></tbody></table><button class="button button-primary">Record ledger entry</button></form></details>';
    }

    private static function exportButton(string $type, string $label): void {
        if (!current_user_can('dzn_view_finance_authority') && !current_user_can('dzn_view_payment_execution_authority') && !current_user_can('dzn_view_recurring_authority')) return;
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;margin-inline-start:8px">'
            . '<input type="hidden" name="action" value="dzn_finance_export"><input type="hidden" name="export_type" value="' . esc_attr($type) . '">'
            . wp_nonce_field('dzn_finance_export', '_wpnonce', true, false)
            . '<button class="button">' . esc_html($label) . '</button></form>';
    }

    private static function filterForm(string $tab, array $fields, array $values): void {
        echo '<form method="get" class="dzn-finance-filter"><input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
        foreach ($fields as $name => $field) {
            $id = 'finance-filter-' . $name;
            echo '<label for="' . esc_attr($id) . '">' . esc_html((string) $field['label']) . '</label>';
            if (($field['type'] ?? 'text') === 'select') {
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">';
                foreach ($field['options'] as $value => $label) echo '<option value="' . esc_attr((string) $value) . '"' . selected((string) ($values[$name] ?? ''), (string) $value, false) . '>' . esc_html((string) $label) . '</option>';
                echo '</select>';
            } else {
                echo '<input id="' . esc_attr($id) . '" type="' . esc_attr((string) ($field['type'] ?? 'text')) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) ($values[$name] ?? '')) . '">';
            }
        }
        echo '<button class="button">Apply filters</button><a class="button-link" href="' . esc_url(self::tabUrl($tab)) . '">Reset</a></form>';
    }

    private static function pagination(array $data, string $tab, array $filters): void {
        if ((int) $data['pages'] <= 1) return;
        $base = array('page' => self::MENU_SLUG, 'tab' => $tab) + array_filter($filters, static fn($value) => $value !== '');
        echo '<p class="tablenav">Page ' . esc_html((string) $data['page']) . ' of ' . esc_html((string) $data['pages']);
        if ((int) $data['page'] > 1) echo ' <a class="button" href="' . esc_url(add_query_arg($base + array('paged' => (int) $data['page'] - 1), admin_url('admin.php'))) . '">Previous</a>';
        if ((int) $data['page'] < (int) $data['pages']) echo ' <a class="button" href="' . esc_url(add_query_arg($base + array('paged' => (int) $data['page'] + 1), admin_url('admin.php'))) . '">Next</a>';
        echo '</p>';
    }

    private static function exportFields(int $statementId, bool $download): string {
        $html = '<input type="hidden" name="action" value="dzn_finance_statement_export"><input type="hidden" name="statement_id" value="' . $statementId . '">' . ($download ? '<input type="hidden" name="download" value="1">' : '');
        ob_start();
        wp_nonce_field('dzn_finance_statement_export');
        return $html . (string) ob_get_clean();
    }

    private static function templateTextarea(string $name, string $label, string $value): void {
        echo '<p><label for="finance-template-' . esc_attr($name) . '"><strong>' . esc_html($label) . '</strong></label><textarea class="large-text code" rows="' . ($name === 'css' ? '12' : '6') . '" id="finance-template-' . esc_attr($name) . '" name="' . esc_attr($name) . '">' . esc_textarea($value) . '</textarea></p>';
    }

    private static function templatePreviewScript(): void {
        ?>
        <script>
        (function () {
            const preview = document.getElementById('dzn-finance-live-preview');
            if (!preview) return;
            const fields = ['header_html', 'body_html', 'footer_html', 'css'].map((name) => document.getElementById('finance-template-' + name)).filter(Boolean);
            const values = <?php echo wp_json_encode(array('academy_name' => self::academyName(), 'teacher_name' => 'نمونه استاد', 'teacher_reference' => 'DZN-TCH-0001', 'period_label' => 'October 2026', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'statement_id' => 'SAMPLE-1', 'statement_version' => '1', 'statement_state' => 'draft', 'payable_amount' => '4,500,000 Toman', 'total_lines' => '2', 'payable_lines' => '1', 'generated_at' => '2026-10-26 12:00 UTC', 'currency' => 'TOM', 'direction' => 'rtl', 'locale' => 'fa', 'logo_url' => '')); ?>;
            const lessons = <?php echo wp_json_encode(array(array('lesson_id' => 'DZN-LSN-001', 'lesson_date' => '2026-10-05 15:00:00', 'course' => 'کلاس تار', 'disposition' => 'payable', 'basis' => 'delivered_occurrence', 'amount' => '4,500,000 Toman', 'status' => 'payable'), array('lesson_id' => 'DZN-LSN-002', 'lesson_date' => '2026-10-12 15:00:00', 'course' => 'کلاس تار', 'disposition' => 'non_payable', 'basis' => 'academy_obligation', 'amount' => '0 Toman', 'status' => 'non_payable'))); ?>;
            const escape = (value) => String(value).replace(/[&<>"']/g, (char) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char]));
            function render() {
                const get = (name) => { const field = document.getElementById('finance-template-' + name); return field ? field.value : ''; };
                let body = get('body_html');
                const block = body.match(/\{\{#lessons\}\}([\s\S]*?)\{\{\/lessons\}\}/);
                if (block) {
                    const row = lessons.map((lesson) => block[1].replace(/\{\{([a-z0-9_]+)\}\}/gi, (all, key) => escape(lesson[key.toLowerCase()] ?? ''))).join('');
                    body = body.replace(block[0], row);
                }
                let html = get('header_html') + body + get('footer_html');
                html = html.replace(/\{\{([a-z0-9_]+)\}\}/gi, (all, key) => escape(values[key.toLowerCase()] ?? ''));
                preview.srcdoc = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><style>' + get('css') + '</style></head><body><main class="statement">' + html + '</main></body></html>';
            }
            fields.forEach((field) => field.addEventListener('input', render));
        })();
        </script>
        <?php
    }

    private static function notices(): void {
        $state = self::query('finance_result');
        $messages = array(
            'price_saved' => array('success', 'The authoritative regional price was saved. Historical offers and statements were not changed.'),
            'price_bundle_saved' => array('success', 'The whole regional pricing configuration was validated and saved. Historical offers, payments and statements were not changed.'),
            'intro_payability_saved' => array('success', 'The introductory-lesson teacher-payability policy was recorded as a new version. Student introductory pricing was not changed.'),
            'golden_hour_rule_copied' => array('success', 'The golden-hour row was copied to the selected days in a new policy version.'),
            'golden_hour_rule_retired' => array('success', 'The golden-hour row was retired in a new policy version. Committed compensation was not rewritten.'),
            'statement_workflow_generated' => array('success', 'Draft statements were generated. No statement was issued and no email was sent. Review the drafts, then issue and send separately.'),
            'statement_workflow_issued' => array('success', 'Each selected draft passed its own issuance gate or reported its own controlled failure. No email was sent.'),
            'statement_workflow_sent' => array('success', 'Issued statements were queued for their own recorded Teacher addresses. Transport acceptance is not the same as confirmed delivery.'),
            'statement_staff_summary_sent' => array('success', 'The administrative batch summary was sent to the staff address. Individual teacher statements were not attached.'),
            'statement_staff_summary_failed' => array('error', 'The administrative batch summary was not sent. No statement or teacher email was changed.'),
            'teacher_default_saved' => array('success', 'The global Toman teacher-rate default was saved as a new effective version.'),
            'teacher_rate_saved' => array('success', 'The teacher-specific Toman rate was recorded and marked as an override.'),
            'teacher_default_resumed' => array('success', 'A new effective rate version now resumes the global teacher default.'),
            'golden_hour_saved' => array('success', 'The effective-dated Iran golden-hour policy was saved. Committed, completed and paid compensation was not rewritten.'),
            'golden_hour_updated' => array('success', 'The previous Iran golden-hour policy version was updated. Committed lesson details were retained.'),
            'golden_hour_removed' => array('success', 'The previous Iran golden-hour policy version was removed. Committed lesson details were retained.'),
            'template_saved' => array('success', 'The statement template was saved. Financial calculations were not changed.'),
            'template_reset' => array('success', 'The statement template was reset to the delivered default.'),
            'policy_saved' => array('success', 'A new policy version was recorded.'),
            'promotion_created' => array('success', 'The discount code was created and is active.'),
            'promotion_deactivated' => array('success', 'The discount code was deactivated. Existing offers keep their recorded snapshot.'),
            'payment_marked_paid' => array('success', 'The offline payment was recorded through the canonical settlement authority. The purchase, entitlement and academic funding were materialised exactly once.'),
            'statement_drafted' => array('success', 'The statement draft was recorded from the current Lesson facts and effective payability evaluations.'),
            'statement_issued' => array('success', 'The statement passed its issuance gate and is now issued.'),
            'statement_withdrawn' => array('success', 'The draft statement was withdrawn. Its lines remain recorded and cannot be reused.'),
            'statement_superseded' => array('success', 'A new statement draft version was created and the previous issued version was superseded.'),
            'statement_paid' => array('success', 'The statement was recorded as paid. The append-only settlement event is now visible to the Teacher and the statement remains immutable.'),
            'statement_emailed' => array('success', 'The issued statement was emailed to the Teacher\'s recorded address.'),
            'statement_email_queued' => array('success', 'A durable statement-email intent was queued. The worker will record transport acceptance or a retryable failure; delivery is not yet confirmed.'),
            'statement_email_already_accepted' => array('info', 'This statement already has a transport-accepted email intent. No duplicate email was queued.'),
            'statement_email_failed' => array('error', 'The statement email was not sent. The statement itself was not changed.'),
            'statement_batch_complete' => array('success', 'The monthly batch finished. Review each Teacher\'s result below; no failed Teacher blocked another.'),
            'refund_recorded' => array('success', 'The manual refund evidence and review case were recorded. No settlement, funding, Lesson or statement was changed; complete the money movement outside this page.'),
            'refund_review_routed' => array('success', 'The refund review was routed for a human decision.'),
            'refund_review_resolved' => array('success', 'The refund review was resolved and the decision note was recorded.'),
            'refund_review_dismissed' => array('success', 'The refund review was dismissed and the decision was recorded.'),
            'refund_reservation_released' => array('success', 'The interrupted refund reservation was released. No evidence or review was changed.'),
            'statement_schedule_enabled' => array('success', 'Monthly draft-only statement scheduling is enabled. It will never issue or email statements automatically.'),
            'statement_schedule_disabled' => array('success', 'Monthly statement scheduling is disabled.'),
            'statement_schedule_ran' => array('success', 'The previous completed month was drafted. Review the batch result before issuing.'),
            'statement_schedule_failed' => array('error', 'The monthly draft run did not complete. Review the run history for the controlled error.'),
            'use_settings' => array('info', 'Student pricing is configured once per region in Finance → Settings. No technical catalogue fields were changed.'),
            'error' => array('error', 'The change was not saved. Review the highlighted values, permissions and effective dates, then try again.'),
        );
        if (isset($messages[$state])) {
            $detail = '';
            if ($state === 'promotion_created') {
                $createdId = absint(self::query('finance_promotion_id'));
                if ($createdId > 0) $detail = 'Promotion #' . $createdId . '.';
            }
            if ($state === 'error') {
                $detailMap = array(
                    'invalid_amount' => 'Enter a valid amount.',
                    'standard_price_required' => 'A standard lesson price is required.',
                    'introductory_price_required' => 'A paid introductory lesson requires a valid amount.',
                    'amount_has_too_many_decimals' => 'This currency does not accept that many decimal places.',
                    'amount_requires_whole_units' => 'This currency accepts whole units only.',
                    'invalid_effective_from' => 'Enter the effective instant as YYYY-MM-DD HH:MM:SS in UTC.',
                    'teacher_global_rate_required' => 'Enter the global teacher rate in Toman.',
                    'teacher_rate_required' => 'Enter the teacher rate in Toman.',
                    'rate_scope_violation' => 'Choose a Course when the rate scope is one Course.',
                    'teacher_rate_timeline_overlap' => 'That rate interval overlaps an existing interval.',
                    'rate_effective_from_precedes_snapshot' => 'That rate effective date precedes a recorded snapshot. Choose a later effective date.',
                    'golden_hour_percentage_invalid' => 'Enter a finite bonus percentage from 0 to 100 with at most two decimal places.',
                    'golden_hour_timezone_invalid' => 'Enter a valid IANA time zone such as Australia/Brisbane.',
                    'golden_hour_weekdays_invalid' => 'Choose at least one valid day of the week.',
                    'golden_hour_window_invalid' => 'Enter valid local start and end times.',
                    'golden_hour_period_required' => 'Add at least one golden-hour row.',
                    'golden_hour_periods_too_many' => 'Add no more than 12 golden-hour rows.',
                    'golden_hour_period_overlap' => 'Two golden-hour rows overlap on the same day. Adjust the times or days before saving.',
                    'golden_hour_rule_required' => 'Choose an existing golden-hour row.',
                    'golden_hour_rule_copied' => 'The golden-hour row was copied.',
                    'report_month_invalid' => 'Choose a valid calendar month.',
                    'report_date_invalid' => 'Enter a valid custom start and end date.',
                    'report_period_invalid' => 'The custom end date must not be before the start date.',
                    'report_period_too_long' => 'Choose a report range of no more than 366 days.',
                    'statement_workflow_operation_invalid' => 'The statement workflow operation was not recognised.',
                    'statement_workflow_result_required' => 'Generate or issue a batch before sending a staff summary.',
                    'staff_summary_email_invalid' => 'Enter a valid staff email address for the administrative summary.',
                    'staff_summary_transport_failed' => 'The mail transport did not accept the administrative summary. No teacher statement was sent.',
                    'statement_workflow_failed' => 'The statement workflow did not complete. No financial record was changed by the failed step.',
                    'golden_hour_active_version_requires_replacement' => 'The active policy version cannot be removed. Create a replacement version first.',
                    'golden_hour_policy_version_not_found' => 'That policy version no longer exists.',
                    'golden_hour_effective_from_invalid' => 'Enter the effective-from instant as YYYY-MM-DD HH:MM:SS in UTC.',
                    'golden_hour_effective_until_invalid' => 'The optional effective-until instant must be valid and later than effective-from.',
                    'golden_hour_effective_from_before_current' => 'A new policy version cannot be effective before the current version.',
                    'golden_hour_effective_from_conflict' => 'Change the effective date when recording a materially different policy version.',
                    'golden_hour_area_invalid' => 'Only the global Iran golden-hour policy is supported.',
                    'unsafe_template_url' => 'The logo URL must use https.',
                    'template_html_too_large' => 'The template HTML is too large.',
                    'template_css_too_large' => 'The template CSS is too large.',
                    'promotion_code_invalid' => 'Use 3–32 letters, numbers, dots, underscores or hyphens for the code.',
                    'promotion_percentage_invalid' => 'Enter a discount percentage from 0.01 to 100.',
                    'promotion_limit_invalid' => 'Enter a whole number within the allowed usage range.',
                    'promotion_validity_invalid' => 'Enter a valid start and end date. The end date cannot be before the start date.',
                    'promotion_code_exists' => 'That code already exists. Choose a different code.',
                    'promotion_required' => 'Choose a discount code to deactivate.',
                    'commercial_promotion_required' => 'The discount code was not found.',
                    'promotion_operation_invalid' => 'The requested discount-code operation is not supported.',
                    'promotion_operation_failed' => 'The discount-code change failed. No financial record was changed.',
                    'manual_payment_offer_required' => 'The selected offer no longer exists. Refresh the outstanding payments list.',
                    'manual_payment_obligation_required' => 'The selected obligation no longer exists. Refresh the outstanding payments list.',
                    'manual_payment_already_settled' => 'That obligation is already settled. No second settlement was recorded.',
                    'manual_payment_evidence_reference_required' => 'Enter the bank, receipt or other evidence reference for this payment.',
                    'manual_payment_received_on_invalid' => 'Enter a valid received date that is not in the future.',
                    'manual_payment_received_on_future' => 'The received date cannot be in the future.',
                    'manual_payment_received_before_offer' => 'The received date is earlier than the offer. Record the actual receipt date or issue the current offer first.',
                    'manual_payment_obligation_sequence_invalid' => 'The instalment identifier is invalid. Refresh the list.',
                    'manual_payment_kind_invalid' => 'The payment type was not recognised.',
                    'manual_payment_amount_required' => 'A zero-value obligation is settled by its promotion, not by an offline payment.',
                    'manual_payment_failed' => 'The offline payment was not accepted. No settlement was recorded.',
                    'amount_mismatch' => 'The recorded payment amount does not exactly match the obligation.',
                    'currency_mismatch' => 'The recorded payment currency does not match the obligation.',
                    'late_payment_after_offer_window' => 'The offer window had already closed before the recorded payment date. Issue a current offer.',
                    'conflicting_payment_evidence' => 'Other payment evidence already exists for this obligation. Review it before retrying.',
                    'invalid_or_expired_offer' => 'The offer is no longer payable. Issue a current offer.',
                    'intro_payment_state_conflict' => 'This introductory obligation can no longer be marked paid in its current state.',
                    'intro_payment_manual_evidence_required' => 'Enter the bank, receipt or other evidence reference for this introductory payment.',
                    'intro_payment_manual_occurred_at_invalid' => 'Enter a valid past-or-present received date.',
                    'statement_period_invalid' => 'Enter a valid start and end date in UTC.',
                    'statement_required' => 'Choose a statement first.',
                    'statement_operation_invalid' => 'The statement operation was not recognised.',
                    'statement_operation_failed' => 'The statement operation failed. No financial record was changed.',
                    'statement_period_too_long' => 'A statement period may not exceed 62 days.',
                    'statement_period_not_elapsed' => 'A statement can only cover a period that has already ended.',
                    'statement_period_overlap' => 'This Teacher already has a live statement overlapping that period.',
                    'lesson_not_finalised_in_period' => 'At least one Lesson in the period is not finalised. Complete attendance first.',
                    'payability_pending' => 'At least one Lesson has pending payability. Resolve the payability review before issuing.',
                    'policy_unset_for_statement_period' => 'The statement timezone policy was unset when the draft was created. Record it before drafting, or draft again after recording it.',
                    'snapshot_missing_for_lesson' => 'At least one Lesson in the period has no Finance snapshot.',
                    'lesson_stated_twice' => 'A live statement already states one of these Lessons.',
                    'statement_state_transition_conflict' => 'The statement state changed. Refresh before retrying.',
                    'statement_derivation_mismatch' => 'The statement derivation no longer matches the period. Draft a fresh statement.',
                    'statement_batch_month_invalid' => 'Choose a valid completed month in YYYY-MM form.',
                    'statement_batch_key_required' => 'The batch command key was missing. Retry the batch.',
                    'statement_batch_failed' => 'The batch could not start. No statement was changed.',
                    'statement_email_missing' => 'The Teacher has no recorded email address. Update the Teacher profile and try again.',
                    'statement_email_transport_failed' => 'The email transport did not accept the message. The statement remains issued and unchanged.',
                    'already_queued' => 'A statement-email intent is already queued. No duplicate intent was created.',
                    'statement_email_processing_failed' => 'The email worker could not prepare the statement. The intent remains retryable.',
                    'statement_not_issued' => 'Only an issued statement can be emailed. Issue the draft first.',
                    'manual_refund_command_required' => 'The refund command key was missing. Retry the refund.',
                    'manual_refund_offer_required' => 'The selected offer no longer exists. Refresh the settlements list.',
                    'manual_refund_obligation_required' => 'The selected obligation no longer exists. Refresh the settlements list.',
                    'manual_refund_purchase_required' => 'The obligation has no accepted purchase. A refund cannot be recorded yet.',
                    'manual_refund_settlement_required' => 'Only a settled obligation can be refunded.',
                    'manual_refund_before_settlement' => 'The refund date is earlier than the settlement date.',
                    'manual_refund_already_complete' => 'This obligation has already been fully refunded.',
                    'manual_refund_amount_required' => 'Enter a refund amount greater than zero.',
                    'manual_refund_exceeds_remaining' => 'The refund amount is more than the remaining settled balance.',
                    'manual_refund_evidence_reference_required' => 'Enter the refund/bank evidence reference.',
                    'manual_refund_date_invalid' => 'Enter a valid past-or-present refund date.',
                    'manual_refund_note_too_long' => 'The refund note is too long.',
                    'refund_failed' => 'The manual refund was not recorded. No settlement or review case was changed.',
                    'refund_review_required' => 'Choose a refund review case first.',
                    'refund_review_operation_invalid' => 'The refund review operation was not recognised.',
                    'refund_review_already_exists' => 'This refund evidence already has a review case.',
                    'refund_review_evidence_conflict' => 'The refund evidence does not match the purchase and obligation.',
                    'accepted_payment_evidence_required' => 'Only accepted refund evidence can open a review case.',
                    'reversal_evidence_not_supported' => 'A reversal cannot be represented by this manual refund path.',
                    'invalid_refund_review_state' => 'The refund review state changed. Refresh before retrying.',
                    'refund_review_not_found' => 'The refund review case no longer exists.',
                    'refund_review_failed' => 'The refund review operation failed. No decision was recorded.',
                    'statement_schedule_operation_invalid' => 'The statement schedule operation was not recognised.',
                    'manual_refund_ownership_conflict' => 'The offer, purchase and Student ownership do not match. No refund was reserved.',
                    'manual_refund_reservation_required' => 'The refund reservation no longer exists.',
                    'manual_refund_reservation_not_releasable' => 'Only a pre-evidence reservation can be released.',
                    'manual_refund_reservation_released' => 'This reservation was released; use a new evidence reference to request another refund.',
                    'manual_refund_request_conflict' => 'This idempotency key or evidence reference was already used with a different refund payload.',
                    'manual_refund_reservation_release_failed' => 'The reservation was not released. No evidence, review or balance was changed.',
                );
                $detail = $detailMap[self::query('finance_error')] ?? '';
            }
            echo '<div class="notice notice-' . esc_attr($messages[$state][0]) . ' is-dismissible"><p>' . esc_html(trim($messages[$state][1] . ' ' . $detail)) . '</p></div>';
        }
        $cashbook = self::query('cashbook_result');
        $cashbookMessages = array(
            'recorded' => array('success', 'The manual ledger entry was recorded.'),
            'already-recorded' => array('success', 'This exact ledger entry was already recorded.'),
            'invalid' => array('error', self::query('cashbook_message') ?: 'The ledger entry was not recorded. Check the values and try again.'),
            'not-recorded' => array('error', 'The ledger entry was not recorded. Review the account history before retrying.'),
        );
        if (isset($cashbookMessages[$cashbook])) echo '<div class="notice notice-' . esc_attr($cashbookMessages[$cashbook][0]) . ' is-dismissible"><p>' . esc_html($cashbookMessages[$cashbook][1]) . '</p></div>';
        $stripe = self::query('stripe_result');
        if ($stripe !== '') {
            $stripeMessages = array(
                'saved' => 'Stripe test configuration saved.',
                'success' => 'Stripe accepted the test key and confirmed sandbox mode.',
                'failed' => 'Stripe test connection failed. Check the test key and try again.',
                'blocked' => 'The Stripe operation is not ready. Review the readiness checks below.',
            );
            $stripeText = $stripeMessages[$stripe] ?? (str_starts_with($stripe, 'invalid_') ? 'A Stripe field was rejected. Review the test key format; credential values were not saved.' : 'The Stripe operation did not complete. Review the readiness checks and try again.');
            $stripeClass = in_array($stripe, array('saved', 'success'), true) ? 'info' : (in_array($stripe, array('failed', 'blocked'), true) ? 'warning' : 'error');
            echo '<div class="notice notice-' . esc_attr($stripeClass) . ' is-dismissible"><p>' . esc_html($stripeText) . '</p></div>';
        }
    }

    private static function denied(string $message): void {
        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    }

    private static function tab(): string {
        $tab = strtolower(self::query('tab'));
        if (isset(self::TAB_ALIASES[$tab])) $tab = self::TAB_ALIASES[$tab];
        return isset(self::TABS[$tab]) ? $tab : self::TAB_PAYMENTS;
    }

    public static function tabUrl(string $tab): string {
        return admin_url('admin.php?' . http_build_query(array('page' => self::MENU_SLUG, 'tab' => $tab)));
    }

    private static function query(string $key): string {
        return isset($_GET[$key]) && is_scalar($_GET[$key]) ? sanitize_text_field(wp_unslash((string) $_GET[$key])) : '';
    }

    private static function academyName(): string {
        return function_exists('get_bloginfo') ? (string) get_bloginfo('name') : 'Delnavazan';
    }

    private static function teacherName(array $teachers, int $teacherId): string {
        foreach ($teachers as $teacher) if ((int) $teacher->id === $teacherId) return (string) $teacher->display_name;
        return 'Teacher #' . $teacherId;
    }

    private static function safeError(\Throwable $error): string {
        $message = trim($error->getMessage());
        $map = array(
            'regional_price_authority_conflict' => 'The requested per-course price contradicts the configured regional authority. Change the regional price in Settings instead.',
            'amount_requires_whole_units' => 'This currency does not accept decimal minor units. Enter a whole amount.',
            'amount_has_too_many_decimals' => 'Too many decimal places for this currency.',
            'invalid_effective_from' => 'Enter the effective instant as YYYY-MM-DD HH:MM:SS in UTC.',
            'unsafe_template_url' => 'The logo URL must use https.',
            'template_html_too_large' => 'The template HTML is too large.',
            'template_css_too_large' => 'The template CSS is too large.',
            'Unauthorized' => 'You are not allowed to perform this operation.',
        );
        if (isset($map[$message])) return $map[$message];
        return 'The Finance view could not be completed. No financial record was changed.';
    }

    private static function styles(): void {
        echo '<style>
        .dzn-finance-admin__intro{max-width:980px}.dzn-finance-admin__tabs{margin-block:16px 0}
        .dzn-finance-panel{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px;margin-block-start:0}
        .dzn-finance-settings-section{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px;margin-block-start:18px}
        .dzn-finance-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-block:14px}
        .dzn-finance-card{display:flex;flex-direction:column;gap:4px;padding:12px;border:1px solid #dcdcde;border-radius:6px;background:#f6f7f7;text-decoration:none;color:#1d2327}
        .dzn-finance-card strong{font-size:22px}.dzn-finance-filter{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-block:14px}
        .dzn-finance-filter label{display:flex;flex-direction:column;gap:4px;font-weight:600;font-size:12px}
        .dzn-finance-table td,.dzn-finance-table th{vertical-align:top}.dzn-finance-pill{display:inline-block;padding:2px 8px;border-radius:999px;background:#f0f0f1;text-transform:capitalize}
        .dzn-finance-region-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}
        .dzn-finance-region-card{border:1px solid #dcdcde;border-radius:6px;padding:14px;background:#fff}
        .dzn-finance-region-card h3{margin-block-start:0}.dzn-finance-region-card label{display:block;margin-block:8px}.dzn-finance-region-card input:not([type=radio]):not([type=checkbox]),.dzn-finance-region-card select{width:100%;margin-block-start:4px}
        .dzn-finance-split{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px}
        .dzn-finance-inline-form{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-block:12px}.dzn-finance-inline-form label{display:flex;flex-direction:column;gap:4px;font-weight:600;font-size:12px}
        .dzn-finance-promotion-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:end;margin-block:14px;padding:14px;border:1px solid #dcdcde;border-radius:6px;background:#f6f7f7}
        .dzn-finance-promotion-form label{display:flex;flex-direction:column;gap:4px;font-weight:600;font-size:12px}
        .dzn-finance-promotion-form input[type=text],.dzn-finance-promotion-form input[type=number]{width:100%;margin-block-start:0}
        .dzn-finance-promotion-form .dzn-finance-checkbox{flex-direction:row;align-items:center;gap:8px;min-block-size:30px;font-weight:600}
        .dzn-finance-manual-pay{display:flex;flex-direction:column;gap:4px;min-width:190px}.dzn-finance-manual-pay label{display:flex;flex-direction:column;gap:2px;font-size:11px;font-weight:600}.dzn-finance-manual-pay input{width:100%;margin:0}.dzn-finance-manual-pay button{align-self:flex-start}
        .dzn-finance-promotion-form .dzn-finance-checkbox input{margin:0}
        .dzn-finance-statement-preview{margin-block-start:20px}.dzn-finance-statement-preview__head{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
        .dzn-finance-statement-frame{width:100%;min-height:620px;border:1px solid #dcdcde;border-radius:6px;background:#fff}.dzn-finance-template-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,1fr);gap:18px;align-items:start}
        .dzn-finance-template-grid textarea{width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.dzn-finance-template-preview{position:sticky;top:40px}
        .dzn-finance-template-preview iframe{width:100%;min-height:420px;border:1px solid #dcdcde;border-radius:6px;background:#fff}.dzn-finance-muted{color:#646970;font-weight:400;font-size:13px}
        .dzn-finance-golden{margin-block-start:24px;border-block-start:1px solid #dcdcde;padding-block-start:16px}
        .dzn-finance-golden-card{border:1px solid #dcdcde;border-radius:6px;padding:14px;background:#fff;margin-block-start:14px}
        .dzn-finance-golden-card h4{margin-block-start:0}.dzn-finance-golden-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px}
        .dzn-finance-golden-grid label{display:flex;flex-direction:column;gap:4px;font-weight:600;font-size:12px}.dzn-finance-golden-grid input{width:100%}
        .dzn-finance-golden-days{border:1px solid #dcdcde;border-radius:6px;padding:10px;margin-block:12px}.dzn-finance-golden-days div{display:flex;flex-wrap:wrap;gap:12px}
        .dzn-finance-golden-days .dzn-finance-checkbox,.dzn-finance-golden-card>.dzn-finance-checkbox{display:inline-flex;align-items:center;gap:6px;font-weight:600}
        .dzn-finance-golden-preview{padding:10px 12px;border-inline-start:4px solid #dba617;background:#fcf9e8;font-weight:600}
        .dzn-finance-golden-periods{display:block;margin-block:12px;font-weight:600}.dzn-finance-golden-periods textarea{display:block;width:100%;margin-block-start:4px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
        .dzn-finance-golden-history{margin-block:12px}
        .dzn-finance-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;margin-block:14px}
        .dzn-finance-summary__block{border:1px solid #dcdcde;border-radius:8px;background:#fff;padding:14px}
        .dzn-finance-summary__block h4{margin-block-start:0;margin-block-end:8px}
        .dzn-finance-summary dl{display:grid;gap:6px;margin:0}
        .dzn-finance-summary dl>div{display:flex;justify-content:space-between;gap:10px;border-block-end:1px solid #f0f0f1;padding-block-end:4px}
        .dzn-finance-summary dd{margin:0;font-weight:700}
        .dzn-finance-summary__heading{margin-block-start:18px}
        .dzn-finance-attention{border:1px solid #dcdcde;border-radius:8px;padding:16px;background:#fff;margin-block:18px}
        .dzn-finance-attention h3{margin-block-start:0}
        .dzn-finance-cards--attention{grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}
        .dzn-finance-card--urgent{border-color:#d63638;background:#fcf0f1}
        .dzn-finance-pill--danger{background:#fcf0f1;color:#b32d2e;border:1px solid #f0b7b9}
        .dzn-finance-toolbar{display:flex;flex-wrap:wrap;gap:8px;margin-block:12px}
        .dzn-finance-subsection{border-block-start:1px solid #f0f0f1;margin-block-start:14px;padding-block-start:12px}
        .dzn-finance-table--students td{min-width:92px}.dzn-finance-table--students th,.dzn-finance-table--students td{font-size:12px}
        .dzn-finance-table-scroll{max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
        .dzn-finance-pricing-table input{width:100%;margin:0}
        .dzn-finance-preview-card{border-inline-start:4px solid #1E7B70;background:#f6f7f7;padding:10px 12px;margin-block:12px}
        .dzn-finance-preview-card p{margin:4px 0 0}
        .dzn-finance-golden-table input[type=number],.dzn-finance-golden-table input:not([type]){max-width:110px}
        .dzn-finance-golden-days-cell{min-width:260px}
        .dzn-finance-golden-days-cell .dzn-finance-checkbox{display:inline-flex;margin-inline-end:6px}
        .dzn-finance-golden-retire td{padding-block:0;border:0}
        .dzn-finance-golden-retire form{margin:0}
        .dzn-finance-intro-price{display:none}
        .dzn-finance-pricing-paid .dzn-finance-intro-price{display:table-cell}
        @media(max-width:900px){.dzn-finance-template-grid{grid-template-columns:1fr}.dzn-finance-template-preview{position:static}}
        @media(max-width:782px){.dzn-finance-admin__tabs .nav-tab{font-size:13px}.dzn-finance-panel,.dzn-finance-settings-section{padding:12px}.dzn-finance-inline-form,.dzn-finance-filter,.dzn-finance-promotion-form{display:grid;grid-template-columns:1fr;gap:8px;align-items:stretch}.dzn-finance-inline-form label,.dzn-finance-filter label,.dzn-finance-promotion-form label,.dzn-finance-inline-form select,.dzn-finance-inline-form input,.dzn-finance-filter select,.dzn-finance-filter input,.dzn-finance-promotion-form input{width:100%;max-width:100%;min-width:0}body.dzn-admin-page .dzn-finance-admin table.widefat,body.dzn-admin-page .dzn-finance-admin .dzn-finance-table{display:block;overflow-x:auto!important;overflow-y:hidden!important;max-width:100%;-webkit-overflow-scrolling:touch}.dzn-finance-summary{grid-template-columns:1fr}.dzn-finance-summary__block{min-width:0}.dzn-finance-summary dl>div{flex-wrap:wrap}.dzn-finance-pricing-table th:last-child,.dzn-finance-pricing-table td:last-child{display:none}body.dzn-admin-page table.dzn-finance-pricing-table{display:table;table-layout:fixed;width:100%;overflow:visible!important}body.dzn-admin-page .dzn-finance-pricing-table th,body.dzn-admin-page .dzn-finance-pricing-table td{padding:4px;word-break:break-word}.dzn-finance-pricing-table input{min-width:0!important;width:100%!important}.dzn-finance-toolbar .button{white-space:normal}}
        </style>';
    }
}
