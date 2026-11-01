<?php
/** Render-level contract for the Teacher induction hub on desktop and mobile shells. */
namespace {
    define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
    function esc_html( $v ): string { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
    function esc_attr( $v ): string { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
    function esc_url( $v ): string { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
    function home_url( $p = '' ): string { return 'https://example.invalid' . $p; }
    function add_query_arg( $k, $v = null, $u = null ): string {
        if ( is_array( $k ) ) { $u = (string) $v; $parts = array(); foreach ( $k as $key => $value ) { $parts[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value ); } return rtrim( $u, '/' ) . '/?' . implode( '&', $parts ); }
        return rtrim( (string) $u, '/' ) . '/?' . rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
    }
    function sanitize_key( $k ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ) ?? ''; }
    function sanitize_text_field( $v ): string { return trim( strip_tags( (string) $v ) ); }
    function wp_unslash( $v ) { return $v; }
    function is_user_logged_in(): bool { return true; }
    function wp_logout_url( $r = '' ): string { return 'https://example.invalid/logout'; }
    function wp_login_url( $r = '' ): string { return 'https://example.invalid/login'; }
    function wp_get_environment_type(): string { return 'production'; }
    function number_format_i18n( $n, $decimals = 0 ): string { return number_format( (float) $n, (int) $decimals ); }
    function selected( $a, $b = true, $echo = true ): string { return (string) $a === (string) $b ? ' selected' : ''; }
    function disabled( $a, $b = true, $echo = true ): string { return $a ? ' disabled' : ''; }
    function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ): string { return '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce">'; }
    function add_action( ...$args ): void {}
    function add_filter( ...$args ): void {}
    function get_template_part( string $slug, $name = null, array $args = array() ): void {
        $file = ABSPATH . 'theme/' . $slug . '.php';
        if ( ! is_readable( $file ) ) { throw new RuntimeException( 'Missing template ' . $slug ); }
        ( static function () use ( $file, $args ) { include $file; } )();
    }
    require_once ABSPATH . 'theme/inc/teacher-portal.php';

    $fail = static function ( string $name, bool $ok ): void { if ( ! $ok ) { throw new RuntimeException( 'Teacher induction presentation failed: ' . $name ); } };
    $render = static function ( string $screen, array $model ): string {
        ob_start();
        dzn_theme_render_teacher_portal( $screen, $model );
        return (string) ob_get_clean();
    };
    $nav = array(
        array( 'screen' => 'home', 'label' => 'امروز', 'url' => 'https://example.invalid/?teacher-view=home', 'current' => false ),
        array( 'screen' => 'classes', 'label' => 'کلاس‌ها', 'url' => 'https://example.invalid/?teacher-view=classes', 'current' => false ),
        array( 'screen' => 'finance', 'label' => 'مالی', 'url' => 'https://example.invalid/?teacher-view=finance', 'current' => false ),
        array( 'screen' => 'settings', 'label' => 'تنظیمات', 'url' => 'https://example.invalid/?teacher-view=settings', 'current' => false ),
    );
    $modules = array();
    $ids = array( 'portal' => 'آشنایی با پرتال', 'availability' => 'زمان‌های تدریس', 'responsibilities' => 'مسئولیت‌ها', 'schedule-changes' => 'تغییر زمان', 'absence-replacement' => 'غیبت و جبران', 'finance' => 'مالی', 'escalation' => 'حل مشکلات' );
    $order = 0;
    foreach ( $ids as $id => $title ) {
        ++$order;
        $modules[] = array( 'id' => $id, 'order' => $order, 'title' => $title, 'short_title' => $title, 'description' => 'توضیح کوتاه دربارهٔ ' . $title, 'url' => 'https://example.invalid/?teacher-view=induction&induction-module=' . $id, 'state' => 'portal' === $id ? 'completed' : 'not_started', 'needs_reacknowledgement' => false, 'last_visited_at' => null );
    }
    $hub = array( 'modules' => $modules, 'completed_count' => 1, 'started_count' => 0, 'total_count' => 7, 'percent' => 14, 'state' => 'in_progress', 'next_module_id' => 'availability' );
    $base = array(
        'available' => true, 'screen' => 'induction', 'source' => 'platform',
        'teacher' => array( 'first_name' => 'سارا', 'full_name' => 'سارا نمونه' ), 'navigation' => $nav,
        'induction' => $hub, 'induction_module' => null,
        'induction_hub_url' => 'https://example.invalid/?teacher-view=induction',
        'induction_continue_url' => 'https://example.invalid/?teacher-view=induction&induction-module=availability',
        'induction_actions' => array( 'complete_url' => 'https://example.invalid/admin-post.php?action=dzn_teacher_induction_complete', 'complete_nonce' => 'nonce-induction' ),
        'induction_support_url' => 'https://wa.me/000',
    );
    $hub_html = $render( 'induction', $base );
    $fail( 'hub renders the Persian heading', str_contains( $hub_html, 'راهنمای شروع تدریس در دلنوازان' ) );
    $fail( 'hub shows the 1 of 7 progress copy', str_contains( $hub_html, '1 از 7 بخش تکمیل شده' ) );
    $fail( 'hub renders all seven module links', substr_count( $hub_html, 'induction-module=' ) >= 7 );
    $fail( 'hub offers continue and skip while listing every module', str_contains( $hub_html, 'ادامهٔ یادگیری' ) && str_contains( $hub_html, 'فعلاً رد کن؛ بعداً ادامه می‌دهم' ) && substr_count( $hub_html, 'dzn-tp-induction-card is-' ) === 7 );
    $fail( 'hub does not leak the full curriculum', ! str_contains( $hub_html, 'تمرین کوتاه' ) && ! str_contains( $hub_html, 'سناریوهای عملیاتی' ) );
    $fail( 'shell is RTL and exposes real navigation targets', str_contains( $hub_html, 'dir="rtl"' ) && str_contains( $hub_html, 'data-dzn-nav-screen="home"' ) && str_contains( $hub_html, 'dzn-tp-tabs' ) );
    $fail( 'progress is an accessible progressbar', str_contains( $hub_html, 'role="progressbar"' ) && str_contains( $hub_html, 'aria-valuenow="1"' ) && str_contains( $hub_html, 'aria-valuemax="7"' ) );

    $module = array(
        'id' => 'absence-replacement', 'order' => 5, 'version' => 1, 'state' => 'in_progress', 'needs_reacknowledgement' => false,
        'title' => 'غیبت هنرجو و جلسهٔ جبرانی', 'short_title' => 'غیبت و جلسهٔ جبرانی', 'description' => 'توضیح', 'portal_screen' => 'home',
        'objectives' => array( 'فهمیدن سهمیهٔ ترم' ), 'completion_criteria' => 'بخش‌ها را بخوانید.',
        'policy' => array( 'title' => 'قاعدهٔ اصلی', 'body' => 'سهمیهٔ پیش‌فرض هر هنرجو در هر ترم دو جبران است.', 'points' => array( 'غیبت مازاد درخواست جبران قابل اقدام نمی‌سازد.' ) ),
        'sections' => array( array( 'id' => 'first', 'title' => 'نخستین غیبت', 'body' => 'هنرجو غیبت را گزارش می‌کند.', 'points' => array( 'سهمیه بررسی می‌شود.' ) ) ),
        'scenarios' => array( array( 'id' => 'q1', 'prompt' => 'غیبت مازاد چه می‌شود؟', 'options' => array( 'ثبت می‌شود اما جبران خودکار ندارد.', 'جبران دستی می‌شود.' ), 'answer' => 0, 'explanation' => 'استحقاق جدا از گزارش است.' ) ),
    );
    $module_model = $base;
    $module_model['induction_module'] = $module;
    $module_html = $render( 'induction', $module_model );
    $fail( 'module renders objectives and sections', str_contains( $module_html, 'در این بخش یاد می‌گیرید' ) && str_contains( $module_html, 'نخستین غیبت' ) );
    $fail( 'module renders the authoritative policy and excess-absence rule', str_contains( $module_html, 'سهمیهٔ پیش‌فرض' ) && str_contains( $module_html, 'غیبت مازاد' ) );
    $fail( 'module renders a safe knowledge check', str_contains( $module_html, 'data-dzn-induction-question' ) && str_contains( $module_html, 'data-dzn-induction-check' ) && str_contains( $module_html, 'تمرین کوتاه (اختیاری)' ) );
    $fail( 'completion is nonce-protected and names only the module', str_contains( $module_html, 'name="module_id" value="absence-replacement"' ) && str_contains( $module_html, 'dzn_teacher_induction_complete' ) && ! str_contains( $module_html, 'name="teacher_id"' ) );
    $fail( 'module links back to the hub and related portal screen', str_contains( $module_html, 'همهٔ بخش‌ها' ) && str_contains( $module_html, 'رفتن به بخش مرتبط پرتال' ) );

    ob_start();
    dzn_theme_teacher_portal_component( 'help-tip', array( 'title' => 'راهنمای کوتاه', 'text' => 'توضیح کوتاه.', 'module' => 'absence-replacement' ) );
    $tip_html = (string) ob_get_clean();
    $fail( 'contextual help is a native dismissible disclosure', str_contains( $tip_html, '<details' ) && str_contains( $tip_html, '<summary' ) );
    $fail( 'contextual help links to the full induction module', str_contains( $tip_html, 'induction-module=absence-replacement' ) );

    echo "Teacher induction presentation passed\n";
}
