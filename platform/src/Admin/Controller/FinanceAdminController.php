<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\CommercialPolicyService;
use Delnavazan\Platform\Core\Application\CommercialRule;
use Delnavazan\Platform\Core\Application\Finance\FinancePolicyService;
use Delnavazan\Platform\Core\Application\Finance\FinanceRule;
use Delnavazan\Platform\Core\Application\Finance\FinanceSettingsService;
use Delnavazan\Platform\Core\Application\Finance\OperationalCashRule;
use Delnavazan\Platform\Core\Application\Finance\Pricing\{PricingMoney,RegionalPricingRule,RegionalPricingService};
use Delnavazan\Platform\Core\Application\Finance\Read\FinanceAdminReadService;
use Delnavazan\Platform\Core\Application\Finance\Read\TeacherRateReadService;
use Delnavazan\Platform\Core\Application\Finance\Read\TeacherStatementReadService;
use Delnavazan\Platform\Core\Application\Finance\Statements\{StatementTemplateRule,StatementTemplateService};

/**
 * One Finance administration page with exactly four top-level tabs.
 *
 * Existing routes remain protected direct routes for compatibility, but ordinary finance work
 * happens here: Payments, Central Ledger, Teacher Statements and Settings. Each write keeps
 * its own narrow capability and nonce; the page capability is only a navigation gate.
 */
final class FinanceAdminController {
    public const MENU_SLUG = 'dzn-finance';
    public const PARENT_SLUG = Menu::ROOT_SLUG;
    public const TAB_PAYMENTS = 'payments';
    public const TAB_LEDGER = 'ledger';
    public const TAB_STATEMENTS = 'statements';
    public const TAB_SETTINGS = 'settings';
    public const TABS = array(
        self::TAB_PAYMENTS => 'Payments',
        self::TAB_LEDGER => 'Central Ledger',
        self::TAB_STATEMENTS => 'Teacher Statements',
        self::TAB_SETTINGS => 'Settings',
    );

    public static function register(): void {
        add_action('admin_post_dzn_finance_settings', array(__CLASS__, 'handleSettings'));
        add_action('admin_post_dzn_finance_statement_export', array(__CLASS__, 'exportStatement'));
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
        echo '<h1>Finance</h1><p class="dzn-finance-admin__intro">Payments, the manual academy ledger and teacher compensation in one place. The manual Central Ledger is independent from online payments and from the pricing fields: existing entries are never rewritten by a configuration change.</p>';
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
        $counts = $read->paymentCounts();
        $filters = array(
            'q' => self::query('q'), 'state' => self::query('state'), 'from' => self::query('from'), 'through' => self::query('through'),
        );
        $page = max(1, absint($_GET['paged'] ?? 1));
        $data = $read->payments($filters, $page);
        echo '<section class="dzn-finance-panel"><h2>Student payments</h2><p>Checkout attempts and Stripe status. This view is read-only; reconciliation uses the existing guarded action and every provider reference remains hidden.</p>';
        echo '<div class="dzn-finance-cards">';
        foreach (array('all' => 'All', 'pending' => 'Pending', 'successful' => 'Successful', 'failed' => 'Failed', 'refunded' => 'Refund evidence') as $key => $label) {
            echo '<a class="dzn-finance-card" href="' . esc_url(add_query_arg(array('page' => self::MENU_SLUG, 'tab' => self::TAB_PAYMENTS, 'state' => $key), admin_url('admin.php'))) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) ($counts[$key] ?? 0)) . '</strong></a>';
        }
        echo '</div>';
        self::filterForm(self::TAB_PAYMENTS, array(
            'q' => array('label' => 'Search student, offer, purchase or attempt', 'type' => 'search'),
            'state' => array('label' => 'Status', 'type' => 'select', 'options' => array('' => 'All statuses', 'pending' => 'Pending', 'successful' => 'Successful', 'failed' => 'Failed', 'refunded' => 'Refund evidence')),
            'from' => array('label' => 'From', 'type' => 'date'), 'through' => array('label' => 'Through', 'type' => 'date'),
        ), $filters);
        echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>Date</th><th>Student</th><th>Course / offer</th><th>Amount</th><th>Checkout state</th><th>Stripe / reconciliation</th><th>Reference</th></tr></thead><tbody>';
        if (!$data['rows']) echo '<tr><td colspan="7">No payments match these filters.</td></tr>';
        foreach ($data['rows'] as $row) {
            $rowState = (string) $row->state;
            $paid = (int) ($row->settled_evidence ?? 0) > 0 || $rowState === 'completed';
            $refund = (int) ($row->refund_evidence ?? 0) > 0;
            $reconciliation = $refund ? 'Refund evidence recorded' : ($paid ? 'Settled evidence' : ($rowState === 'failed' ? 'Failed / needs review' : 'Awaiting payment'));
            echo '<tr><td>' . esc_html((string) $row->created_at) . '</td><td>' . esc_html((string) ($row->student_name ?: '—')) . '<br><small>' . esc_html((string) ($row->student_reference ?: '')) . '</small></td><td>' . esc_html((string) ($row->course_name ?: '—')) . '<br><small>' . esc_html((string) ($row->offer_reference ?: ('#' . (int) $row->id))) . '</small></td><td>' . esc_html(PricingMoney::format((int) $row->amount_minor, (string) $row->currency)) . '</td><td><span class="dzn-finance-pill">' . esc_html($rowState) . '</span>' . ($row->close_reason ? '<br><small>' . esc_html((string) $row->close_reason) . '</small>' : '') . '</td><td>' . esc_html($reconciliation) . '<br><small>' . esc_html((string) ($row->provider_key ?: 'provider')) . '</small></td><td><code>' . esc_html((string) $row->uid) . '</code>' . ($row->purchase_reference ? '<br><small>' . esc_html((string) $row->purchase_reference) . '</small>' : '') . '</td></tr>';
        }
        echo '</tbody></table>';
        self::pagination($data, self::TAB_PAYMENTS, $filters);
        $introCounts = $read->introPaymentCounts();
        $introPage = max(1, absint($_GET['intro_paged'] ?? 1));
        $introFilters = $filters;
        if ((string) ($introFilters['state'] ?? '') === 'successful') $introFilters['state'] = 'paid';
        $intro = $read->introPayments($introFilters, $introPage);
        echo '<h3>Introductory lesson payments</h3><p>Paid introductions use the same verified Stripe webhook and test-mode adapter. A free introduction has no row here and never creates a charge.</p>';
        echo '<div class="dzn-finance-cards">';
        foreach (array('all' => 'All intro obligations', 'pending' => 'Awaiting payment', 'paid' => 'Paid', 'failed' => 'Failed attempt', 'refunded' => 'Refunded') as $key => $label) echo '<div class="dzn-finance-card"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) ($introCounts[$key] ?? 0)) . '</strong></div>';
        echo '</div><table class="widefat striped dzn-finance-table"><thead><tr><th>Created</th><th>Student</th><th>Intro course</th><th>Amount</th><th>Obligation state</th><th>Checkout</th><th>Latest provider event</th><th>Reference</th></tr></thead><tbody>';
        if (!$intro['rows']) echo '<tr><td colspan="8">No paid introductory obligations match these filters.</td></tr>';
        foreach ($intro['rows'] as $row) {
            $eventLabel = $row->last_event_kind ? (string) $row->last_event_kind . ' · ' . (string) $row->last_event_result . ($row->last_event_reason ? ' · ' . (string) $row->last_event_reason : '') : 'No provider event yet';
            echo '<tr><td>' . esc_html((string) $row->created_at) . '</td><td>' . esc_html((string) ($row->student_name ?: '—')) . '<br><small>' . esc_html((string) ($row->student_reference ?: '')) . '</small></td><td>' . esc_html((string) ($row->course_name ?: '—')) . '</td><td>' . esc_html(PricingMoney::format((int) $row->amount_minor, (string) $row->currency)) . '</td><td><span class="dzn-finance-pill">' . esc_html((string) $row->state) . '</span></td><td>' . esc_html((string) ($row->checkout_state ?: 'not started')) . '<br><small>' . esc_html((string) ($row->expires_at ?: '')) . '</small></td><td>' . esc_html($eventLabel) . '</td><td><code>' . esc_html((string) $row->reference_code) . '</code></td></tr>';
        }
        echo '</tbody></table>';
        if ((int) $intro['pages'] > 1) {
            $introBase = array('page' => self::MENU_SLUG, 'tab' => self::TAB_PAYMENTS) + array_filter($filters, static fn($value) => $value !== '');
            echo '<p class="tablenav">Intro page ' . esc_html((string) $intro['page']) . ' of ' . esc_html((string) $intro['pages']);
            if ((int) $intro['page'] > 1) echo ' <a class="button" href="' . esc_url(add_query_arg($introBase + array('intro_paged' => (int) $intro['page'] - 1), admin_url('admin.php'))) . '">Previous</a>';
            if ((int) $intro['page'] < (int) $intro['pages']) echo ' <a class="button" href="' . esc_url(add_query_arg($introBase + array('intro_paged' => (int) $intro['page'] + 1), admin_url('admin.php'))) . '">Next</a>';
            echo '</p>';
        }
        if (current_user_can('dzn_manage_payment_providers') || current_user_can(PaymentExecutionController::CAPABILITY)) {
            echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=' . self::TAB_SETTINGS . '#stripe')) . '">Open Stripe configuration and readiness</a></p>';
        }
        echo '<p class="description">Failed or unmatched Stripe events are reconciled only through the existing guarded action in the payment execution service. No provider payload or secret is shown here.</p></section>';
    }

    private static function renderLedger(FinanceAdminReadService $read): void {
        if (!current_user_can('dzn_view_cash_ledger')) { self::denied('Central Ledger view authority is required.'); return; }
        $filters = array(
            'q' => self::query('q'), 'account' => self::query('account'), 'kind' => self::query('kind'),
            'from' => self::query('from'), 'through' => self::query('through'),
        );
        $page = max(1, absint($_GET['paged'] ?? 1));
        $data = $read->ledger($filters, $page);
        echo '<section class="dzn-finance-panel"><h2>Central Ledger</h2><p>Manual academy bookkeeping only. Online checkout, Stripe settlement, teacher rates and student pricing never write to this ledger; keeping the ledger manual is intentional.</p>';
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
        echo '<table class="widefat striped dzn-finance-table"><thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Student / teacher</th><th>Gross</th><th>Fees</th><th>Net</th><th>Currency</th><th>Reconciliation</th></tr></thead><tbody>';
        if (!$data['rows']) echo '<tr><td colspan="9">No manual ledger entries match these filters.</td></tr>';
        $labels = array('income' => 'Income', 'expense' => 'Expense', 'opening_balance' => 'Opening balance', 'balance_adjustment' => 'Balance adjustment', 'transfer_out' => 'Transfer out', 'transfer_in' => 'Transfer in', 'reversal' => 'Reversal', 'conversion' => 'Conversion');
        foreach ($data['rows'] as $row) {
            $gross = (int) $row->amount_minor;
            $net = $gross * (int) $row->direction;
            $reconciled = $row->latest_reconciliation !== null && (string) $row->latest_reconciliation >= (string) $row->transaction_date;
            $reference = $row->evidence_reference ?: ('CASH-' . (int) $row->id);
            echo '<tr><td>' . esc_html((string) $row->transaction_date) . '</td><td>' . esc_html($labels[(string) $row->transaction_kind] ?? (string) $row->transaction_kind) . '</td><td><code>' . esc_html((string) $reference) . '</code></td><td>—</td><td>' . esc_html(PricingMoney::format($gross, (string) $row->currency)) . '</td><td>' . esc_html(PricingMoney::format(0, (string) $row->currency)) . '</td><td>' . esc_html(($net < 0 ? '−' : '') . PricingMoney::format(abs($net), (string) $row->currency)) . '</td><td>' . esc_html((string) $row->currency) . '</td><td>' . esc_html($reconciled ? 'Reconciled to statement' : 'Not reconciled') . '</td></tr>';
        }
        echo '</tbody></table>';
        self::pagination($data, self::TAB_LEDGER, $filters);
        if (current_user_can('dzn_manage_cash_ledger')) self::manualEntryForm();
        echo '<p class="description">Entries are append-only. Mistakes are corrected by a reversal and a corrected entry; the existing academy cashbook rules remain authoritative.</p></section>';
    }

    private static function renderStatements(FinanceAdminReadService $read): void {
        if (!current_user_can('dzn_view_finance_authority')) { self::denied('Finance authority view capability is required.'); return; }
        $teachers = $read->selectableTeachers();
        $teacherId = absint($_GET['teacher_id'] ?? 0);
        if ($teacherId < 1 && $teachers) $teacherId = (int) $teachers[0]->id;
        echo '<section class="dzn-finance-panel"><h2>Teacher Statements</h2><p>Effective-dated compensation statements. A statement records lesson compensation only; it is not a payout, invoice or bank instruction. Template changes are presentation-only and cannot alter totals.</p>';
        echo '<form method="get" class="dzn-finance-inline-form"><input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '"><input type="hidden" name="tab" value="' . esc_attr(self::TAB_STATEMENTS) . '"><label for="finance-statement-teacher">Teacher</label><select id="finance-statement-teacher" name="teacher_id">';
        foreach ($teachers as $teacher) echo '<option value="' . (int) $teacher->id . '"' . selected($teacherId, (int) $teacher->id, false) . '>' . esc_html((string) $teacher->display_name) . ' · ' . esc_html((string) $teacher->reference_code) . '</option>';
        echo '</select><button class="button">View statements</button></form>';
        if ($teacherId < 1) { echo '<p>No active Teacher is available.</p></section>'; return; }
        try {
            $statements = (new TeacherStatementReadService())->statements($teacherId);
        } catch (\Throwable) { $statements = array(); }
        echo '<table class="widefat striped"><thead><tr><th>Statement</th><th>Period</th><th>State</th><th>Currency</th><th>Payable</th><th>Lines</th><th>Issued</th></tr></thead><tbody>';
        if (!$statements) echo '<tr><td colspan="7">No statement has been drafted for this Teacher.</td></tr>';
        foreach ($statements as $statement) {
            $url = add_query_arg(array('page' => self::MENU_SLUG, 'tab' => self::TAB_STATEMENTS, 'teacher_id' => $teacherId, 'statement_id' => (int) $statement['statement_id']), admin_url('admin.php'));
            echo '<tr><td><a href="' . esc_url($url) . '">' . esc_html((string) $statement['statement_id']) . '</a></td><td>' . esc_html((string) $statement['period_start_utc'] . ' → ' . (string) $statement['period_end_utc']) . '</td><td>' . esc_html((string) $statement['state']) . '</td><td>' . esc_html((string) $statement['currency']) . '</td><td>' . esc_html(PricingMoney::format((int) $statement['payable_amount_minor'], (string) $statement['currency'])) . '</td><td>' . esc_html((string) $statement['total_line_count']) . '</td><td>' . esc_html((string) ($statement['issued_at'] ?? '—')) . '</td></tr>';
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
            echo '</div></div><iframe class="dzn-finance-statement-frame" sandbox title="Teacher statement preview" srcdoc="' . esc_attr($html) . '"></iframe></div>';
        } else {
            echo '<div class="dzn-finance-statement-preview"><h3>Sample preview</h3><p class="description">Choose a statement above to preview recorded facts. The sample below uses illustrative values only.</p><iframe class="dzn-finance-statement-frame" sandbox title="Sample teacher statement" srcdoc="' . esc_attr($renderer->renderSample($template, self::academyName())) . '"></iframe></div>';
        }
        self::statementTemplateSettings($template, $renderer);
        echo '</section>';
    }

    private static function statementTemplateSettings(array $template, StatementTemplateService $renderer): void {
        $canManage = current_user_can(FinanceSettingsService::MANAGE_TEMPLATE_CAPABILITY);
        echo '<section class="dzn-finance-settings-section" id="statement-template"><h3>Statement Template Settings</h3><p class="description">Template version ' . esc_html((string) ($template['template_version'] ?? 'default')) . ' · previous versions remain in finance settings history.</p><p>Compose the statement header, lesson table and footer with HTML and CSS. Placeholders display recorded facts; values are escaped and scripts, frames, forms and unsafe URLs are removed. This editor can never change an amount or a financial calculation.</p>';
        if (!$canManage) { echo '<p class="description">You can preview the template but need the statement-management capability to change it.</p>'; return; }
        $sample = $renderer->renderSample($template, self::academyName());
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-template-form">';
        echo '<input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="save_template">';
        wp_nonce_field('dzn_finance_settings');
        echo '<div class="dzn-finance-template-grid"><div>';
        self::templateTextarea('header_html', 'Header HTML', (string) $template['header_html']);
        self::templateTextarea('body_html', 'Lesson table HTML', (string) $template['body_html']);
        self::templateTextarea('footer_html', 'Footer HTML', (string) $template['footer_html']);
        self::templateTextarea('css', 'CSS', (string) $template['css']);
        echo '</div><div class="dzn-finance-template-preview"><p><strong>Live preview</strong></p><iframe id="dzn-finance-live-preview" sandbox title="Live statement template preview" srcdoc="' . esc_attr($sample) . '"></iframe><p class="description">Preview is sandboxed; scripts cannot run.</p></div></div>';
        echo '<table class="form-table"><tbody><tr><th><label for="finance-template-logo">Logo URL</label></th><td><input class="regular-text" type="url" id="finance-template-logo" name="logo_url" value="' . esc_attr((string) $template['logo_url']) . '" placeholder="https://…"><p class="description">Optional. Only https image URLs are accepted.</p></td></tr><tr><th><label for="finance-template-locale">Default direction</label></th><td><select id="finance-template-locale" name="locale"><option value="fa"' . selected($template['locale'], 'fa', false) . '>Persian (RTL)</option><option value="en"' . selected($template['locale'], 'en', false) . '>English (LTR)</option></select></td></tr></tbody></table>';
        echo '<p><button class="button button-primary">Save template</button></p></form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Reset the statement template to the delivered default? Recorded statements are not changed.\')"><input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="reset_template">';
        wp_nonce_field('dzn_finance_settings');
        echo '<button class="button">Reset to default template</button></form>';
        echo '<h4>Supported placeholders</h4><table class="widefat striped"><thead><tr><th>Placeholder</th><th>Meaning</th></tr></thead><tbody>';
        foreach (StatementTemplateRule::PLACEHOLDERS as $placeholder => $meaning) echo '<tr><td><code>{{' . esc_html($placeholder) . '}}</code></td><td>' . esc_html($meaning) . '</td></tr>';
        foreach (StatementTemplateRule::LESSON_PLACEHOLDERS as $placeholder => $meaning) echo '<tr><td><code>{{' . esc_html($placeholder) . '}}</code> <small>(inside lessons block)</small></td><td>' . esc_html($meaning) . '</td></tr>';
        echo '<tr><td><code>{{#lessons}}…{{/lessons}}</code></td><td>Repeats the block for every statement line</td></tr><tr><td><code>{{#logo_url}}…{{/logo_url}}</code></td><td>Shows the block only when a logo URL is configured</td></tr></tbody></table>';
        self::templatePreviewScript();
        echo '</section>';
    }

    private static function renderSettings(FinanceAdminReadService $read): void {
        if (!current_user_can('dzn_view_finance_authority') && !current_user_can(RegionalPricingService::MANAGE_CAPABILITY)) { self::denied('Finance settings authority is required.'); return; }
        $pricing = new RegionalPricingService();
        $settings = new FinanceSettingsService();
        $regions = array();
        foreach ($pricing->activeAll() as $row) $regions[$row['region_code']] = $row;
        $conflicts = array();
        try { $conflicts = $pricing->conflicts(); } catch (\Throwable) { $conflicts = array(); }
        echo '<section class="dzn-finance-settings-section" id="regional-pricing"><h2>Global regional pricing</h2><p><strong>One standard lesson price applies to every standard Course in a currency region.</strong> Prices are entered in normal currency units and stored as exact minor units. Changing a price preserves every historical offer, payment and statement.</p>';
        if ($conflicts) {
            echo '<div class="notice notice-warning"><p><strong>Existing course prices need explicit resolution.</strong> These regions have conflicting or non-authoritative course prices. Choose one amount below; existing offers keep their original price snapshot.</p><ul>';
            foreach ($conflicts as $conflict) echo '<li><strong>' . esc_html((string) $conflict['region_code']) . '</strong> · ' . esc_html((string) $conflict['kind']) . ' · ' . esc_html(implode(', ', array_map(static fn($amount) => PricingMoney::format((int) $amount, (string) $conflict['currency']), $conflict['amounts']))) . '</li>';
            echo '</ul></div>';
        }
        echo '<div class="dzn-finance-region-list">';
        foreach (RegionalPricingRule::REGIONS as $code => $currency) {
            $active = $regions[$code] ?? null;
            $label = RegionalPricingRule::REGION_LABELS[$code] ?? array('fa' => $code, 'en' => $code);
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-region-card" onsubmit="return confirm(\'Save the authoritative standard price for ' . esc_attr($code) . '? Every standard Course in this region will use this price. Historical offers keep their original price.\')">';
            echo '<input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="save_regional_price"><input type="hidden" name="region_code" value="' . esc_attr($code) . '"><input type="hidden" name="currency" value="' . esc_attr($currency) . '">';
            wp_nonce_field('dzn_finance_settings');
            echo '<h3>' . esc_html($label['en']) . ' <span class="dzn-finance-muted">' . esc_html($code . ' · ' . $currency) . '</span></h3>';
            echo '<p><label>Standard lesson price (' . esc_html($currency) . ')<input name="standard_price" inputmode="decimal" required value="' . esc_attr($active ? PricingMoney::fromMinor((int) $active['standard_amount_minor'], $currency) : '') . '" placeholder="40.00"></label></p>';
            $mode = $active['introductory_mode'] ?? RegionalPricingRule::INTRO_FREE;
            echo '<fieldset><legend>Introductory lesson</legend><label><input type="radio" name="introductory_mode" value="free"' . checked($mode, RegionalPricingRule::INTRO_FREE, false) . '> Free — no charge</label><label><input type="radio" name="introductory_mode" value="paid"' . checked($mode, RegionalPricingRule::INTRO_PAID, false) . '> Paid</label><p><label>Paid introductory price (' . esc_html($currency) . ')<input name="introductory_price" inputmode="decimal" value="' . esc_attr($mode === RegionalPricingRule::INTRO_PAID && $active && $active['introductory_amount_minor'] !== null ? PricingMoney::fromMinor((int) $active['introductory_amount_minor'], $currency) : '') . '" placeholder="0.00"></label></p><p class="description">Free means the existing introductory booking, teacher acceptance, Calendar and Meet workflow runs without a charge. Paid requires an amount. This setting does not disable introductory lesson availability.</p></fieldset>';
            echo '<p><label>Effective from (UTC)<input type="text" name="effective_from" value="' . esc_attr(gmdate('Y-m-d H:i:s')) . '" pattern="\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}"></label></p>';
            echo '<p><small>' . ($active ? 'Current since ' . esc_html((string) $active['effective_from']) : 'Not configured yet') . '</small></p><button class="button button-primary">Save ' . esc_html($code) . '</button></form>';
        }
        echo '</div></section>';

        self::renderTeacherSettings($read, $settings);
        self::renderPolicySettings();
        echo '<section class="dzn-finance-settings-section" id="stripe"><h2>Stripe integration</h2><p>Existing Stripe test/live separation, encrypted credentials, account binding, webhook verification and payment-worker restrictions are preserved. Secret values are never displayed or changed by this redesign.</p>';
        if (current_user_can(PaymentExecutionController::CAPABILITY)) {
            try { IntegrationControlPlaneController::stripeSettingsContent(true); } catch (\Throwable $error) { echo '<div class="notice notice-error"><p>Stripe configuration is unavailable in this request.</p></div>'; }
        } else {
            echo '<p class="description">Stripe configuration requires payment provider authority.</p>';
        }
        echo '</section>';
    }

    private static function renderTeacherSettings(FinanceAdminReadService $read, FinanceSettingsService $settings): void {
        $global = $settings->globalTeacherRate();
        $teachers = $read->activeTeachers();
        $selectedTeacher = absint($_GET['teacher_id'] ?? 0);
        if ($selectedTeacher < 1 && $teachers) $selectedTeacher = (int) $teachers[0]->id;
        $overrides = $settings->overrides();
        echo '<section class="dzn-finance-settings-section" id="teacher-rates"><h2>Teacher compensation</h2><p>Teacher pay is separate from student prices and is always denominated in <strong>Iranian toman (TOM)</strong>. The global default is used for Teachers without an explicit rate. A teacher-specific or teacher/course rate overrides it from its effective date; historical lesson snapshots are never rewritten.</p>';
        if (current_user_can(FinanceSettingsService::MANAGE_TEACHER_RATE_CAPABILITY)) {
            echo '<div class="dzn-finance-split"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-region-card" onsubmit="return confirm(\'Save the global teacher-rate default? Eligible Teachers will receive a new effective rate version.\')"><input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="save_global_teacher_rate">';
            wp_nonce_field('dzn_finance_settings');
            echo '<h3>Global teacher rate default</h3><p><label>Amount per session (Toman)<input name="teacher_global_amount" inputmode="numeric" required value="' . esc_attr($global ? $global['display'] : '') . '" placeholder="450000"></label></p><p><label>Effective from (UTC)<input type="text" name="teacher_global_effective_from" value="' . esc_attr($global['effective_from'] ?? gmdate('Y-m-d H:i:s')) . '" pattern="\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}"></label></p><p><label><input type="checkbox" name="apply_to_teachers" value="1" checked> Apply to active Teachers without an explicit override</label></p><p class="description">The amount is entered in toman, not minor units. This creates effective-dated rate versions through the existing teacher-rate authority. Newly activated Teachers inherit this default automatically unless they already have an explicit override.</p><button class="button button-primary">Save global teacher rate</button>';
            if ($global) echo '<p><small>Current default: ' . esc_html($global['display']) . ' Toman · applied to ' . esc_html((string) $global['applied_teachers']) . ' Teacher(s).</small></p>';
            if ($global && $global['errors']) { echo '<div class="notice notice-warning inline"><p>Some Teachers were not updated because their rate timeline or snapshots require review.</p><ul>'; foreach ($global['errors'] as $failure) echo '<li>Teacher #' . esc_html((string) ($failure['teacher_id'] ?? '')) . ': ' . esc_html((string) ($failure['reason'] ?? 'review required')) . '</li>'; echo '</ul></div>'; }
            echo '</form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-region-card" onsubmit="return confirm(\'Record this teacher-specific rate override? It will take precedence over the global default from its effective date.\')"><input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="save_teacher_rate">';
            wp_nonce_field('dzn_finance_settings');
            echo '<h3>Teacher-specific rate</h3><p><label>Teacher<select name="teacher_id">';
            foreach ($teachers as $teacher) echo '<option value="' . (int) $teacher->id . '"' . selected($selectedTeacher, (int) $teacher->id, false) . '>' . esc_html((string) $teacher->display_name) . '</option>';
            echo '</select></label></p><p><label>Scope<select name="scope_kind"><option value="teacher">All courses for this Teacher</option><option value="teacher_course">One Course</option></select></label></p><p><label>Course (only for one-course scope)<select name="course_scope_id"><option value="0">—</option>';
            foreach ($read->activeCourses() as $course) echo '<option value="' . (int) $course->id . '">' . esc_html((string) $course->name) . '</option>';
            echo '</select></label></p><p><label>Amount per session (Toman)<input name="teacher_amount" inputmode="numeric" required placeholder="500000"></label></p><p><label>Effective from (UTC)<input type="text" name="teacher_effective_from" value="' . esc_attr(gmdate('Y-m-d H:i:s')) . '" pattern="\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}"></label></p><p><label>Evidence reference<input name="rate_evidence_reference" required placeholder="Agreement or signed rate record"></label></p><button class="button">Record teacher rate</button><p class="description">A Teacher-specific rate is marked as an override; the global default will not replace it.</p></form></div>';
        } else {
            echo '<p class="description">Teacher rate management requires teacher-rate authority.</p>';
        }
        if ($selectedTeacher > 0) {
            echo '<h3>Rate history · ' . esc_html(self::teacherName($teachers, $selectedTeacher)) . '</h3>';
            try {
                $timeline = (new TeacherRateReadService())->timeline($selectedTeacher);
                echo '<table class="widefat striped"><thead><tr><th>Version</th><th>Scope</th><th>Effective from</th><th>Effective until</th><th>Status</th><th>Amount</th><th>Currency</th></tr></thead><tbody>';
                if (!$timeline['rates']) echo '<tr><td colspan="7">No effective-dated rate recorded.</td></tr>';
                foreach ($timeline['rates'] as $rate) echo '<tr><td>' . esc_html((string) $rate['rate_version']) . '</td><td>' . esc_html((string) $rate['scope_kind'] . ((int) $rate['course_scope_id'] > 0 ? ' · #' . (int) $rate['course_scope_id'] : '')) . '</td><td>' . esc_html((string) $rate['effective_from']) . '</td><td>' . esc_html((string) ($rate['effective_until'] ?? '—')) . '</td><td>' . esc_html((string) $rate['status']) . '</td><td>' . esc_html(PricingMoney::format((int) $rate['amount_minor'], (string) $rate['currency'])) . '</td><td>' . esc_html((string) $rate['currency']) . '</td></tr>';
                echo '</tbody></table>';
            } catch (\Throwable) { echo '<p class="description">Rate history is unavailable for this Teacher.</p>'; }
            if (in_array($selectedTeacher, $overrides, true) && current_user_can(FinanceSettingsService::MANAGE_TEACHER_RATE_CAPABILITY)) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Resume the global teacher-rate default for this Teacher? A new effective rate version will be recorded.\')"><input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="resume_global_teacher_rate"><input type="hidden" name="teacher_id" value="' . $selectedTeacher . '">';
                wp_nonce_field('dzn_finance_settings');
                echo '<button class="button">Resume global default from now</button></form>';
            }
        }
        echo '</section>';
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
            'INSTALMENT_DUE_DATE_POLICY' => 'Reference policy for the second-instalment due date.',
        );
        echo '<h3>Commercial timing and settlement policies</h3><table class="widefat striped"><thead><tr><th>Key</th><th>Meaning</th><th>Active value</th><th>Version</th></tr></thead><tbody>';
        foreach (CommercialRule::POLICY_KEYS as $key) {
            try { $current = current_user_can('dzn_view_commercial_authority') ? $commercial->current($key) : array('set' => false, 'value' => null, 'version' => null); } catch (\Throwable) { $current = array('set' => false, 'value' => null, 'version' => null); }
            echo '<tr><td><code>' . esc_html($key) . '</code></td><td>' . esc_html($commercialDescriptions[$key] ?? '') . '</td><td>' . esc_html($current['set'] ? (string) $current['value'] : 'Unset') . '</td><td>' . esc_html($current['version'] === null ? '—' : (string) $current['version']) . '</td></tr>';
        }
        echo '</tbody></table>';
        if (current_user_can('dzn_manage_commercial_policies')) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-inline-form" onsubmit="return confirm(\'Record a new commercial policy version? Existing purchases and payments are not rewritten.\')"><input type="hidden" name="action" value="dzn_finance_settings"><input type="hidden" name="finance_operation" value="save_commercial_policy">';
            wp_nonce_field('dzn_finance_settings');
            echo '<label>Policy<select name="policy_key">';
            foreach (CommercialRule::POLICY_KEYS as $key) echo '<option value="' . esc_attr($key) . '">' . esc_html($key) . '</option>';
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
        echo '<table class="form-table"><tbody><tr><th><label for="finance-ledger-account">Account</label></th><td><select id="finance-ledger-account" name="account_key"><option value="australia">Australia account (AUD)</option><option value="iran">Iran account (TOM)</option></select></td></tr><tr><th><label for="finance-ledger-kind">Type</label></th><td><select id="finance-ledger-kind" name="transaction_kind"><option value="income">Money received</option><option value="expense">Money spent</option><option value="opening_balance">Opening balance</option><option value="balance_adjustment">Balance adjustment</option></select></td></tr><tr><th><label for="finance-ledger-date">Date</label></th><td><input id="finance-ledger-date" type="date" name="transaction_date" required value="' . esc_attr(gmdate('Y-m-d')) . '"></td></tr><tr><th><label for="finance-ledger-amount">Amount</label></th><td><input id="finance-ledger-amount" name="amount" inputmode="decimal" required></td></tr><tr><th><label for="finance-ledger-category">Category</label></th><td><input id="finance-ledger-category" name="category" maxlength="100"></td></tr><tr><th><label for="finance-ledger-description">Description</label></th><td><input id="finance-ledger-description" class="large-text" name="description" required maxlength="500"></td></tr><tr><th><label for="finance-ledger-evidence">Evidence reference</label></th><td><input id="finance-ledger-evidence" class="large-text" name="evidence_reference" required maxlength="191"><input type="hidden" name="evidence_channel" value="staff_record"><input type="hidden" name="adjustment_direction" value="increase"><input type="hidden" name="reason_code" value="data_entry_correction"></td></tr></tbody></table><button class="button button-primary">Record ledger entry</button></form></details>';
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
            'teacher_default_saved' => array('success', 'The global Toman teacher-rate default was saved as a new effective version.'),
            'teacher_rate_saved' => array('success', 'The teacher-specific Toman rate was recorded and marked as an override.'),
            'teacher_default_resumed' => array('success', 'A new effective rate version now resumes the global teacher default.'),
            'template_saved' => array('success', 'The statement template was saved. Financial calculations were not changed.'),
            'template_reset' => array('success', 'The statement template was reset to the delivered default.'),
            'policy_saved' => array('success', 'A new policy version was recorded.'),
            'use_settings' => array('info', 'Student pricing is configured once per region in Finance → Settings. No technical catalogue fields were changed.'),
            'error' => array('error', 'The change was not saved. Review the highlighted values, permissions and effective dates, then try again.'),
        );
        if (isset($messages[$state])) {
            $detail = '';
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
                    'unsafe_template_url' => 'The logo URL must use https.',
                    'template_html_too_large' => 'The template HTML is too large.',
                    'template_css_too_large' => 'The template CSS is too large.',
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
        $tab = self::query('tab');
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
        .dzn-finance-statement-preview{margin-block-start:20px}.dzn-finance-statement-preview__head{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
        .dzn-finance-statement-frame{width:100%;min-height:620px;border:1px solid #dcdcde;border-radius:6px;background:#fff}.dzn-finance-template-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,1fr);gap:18px;align-items:start}
        .dzn-finance-template-grid textarea{width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.dzn-finance-template-preview{position:sticky;top:40px}
        .dzn-finance-template-preview iframe{width:100%;min-height:420px;border:1px solid #dcdcde;border-radius:6px;background:#fff}.dzn-finance-muted{color:#646970;font-weight:400;font-size:13px}
        @media(max-width:900px){.dzn-finance-template-grid{grid-template-columns:1fr}.dzn-finance-template-preview{position:static}}
        @media(max-width:782px){.dzn-finance-admin__tabs .nav-tab{font-size:13px}.dzn-finance-panel,.dzn-finance-settings-section{padding:12px}.dzn-finance-table{display:block;overflow-x:auto}}
        </style>';
    }
}
