<?php
/** Render-level contract for the read-only Teacher quick-start on desktop and mobile shells. */
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
        array( 'screen' => 'classes', 'label' => 'کلاس‌ها', 'url' => 'https://example.invalid/?teacher-view=classes', 'current' => false ),
        array( 'screen' => 'finance', 'label' => 'مالی', 'url' => 'https://example.invalid/?teacher-view=finance', 'current' => false ),
        array( 'screen' => 'settings', 'label' => 'تنظیمات', 'url' => 'https://example.invalid/?teacher-view=settings', 'current' => false ),
    );
    $steps = array();
    $ids = array( 'today' => 'کلاس‌های امروز', 'new-enrolment' => 'درخواست کلاس آشنایی', 'settings' => 'اطلاعات و زمان‌های تدریس', 'join' => 'ورود به کلاس', 'attendance' => 'حضور و پایان کلاس', 'absence' => 'اطلاع غیبت هنرجو', 'reschedule' => 'تغییر زمان کلاس', 'finance' => 'مالی و ساعت‌ها', 'golden-hour' => 'ساعت طلایی' );
    $order = 0;
    foreach ( $ids as $id => $title ) {
        ++$order;
        $steps[] = array( 'id' => $id, 'order' => $order, 'title' => $title, 'short_title' => $title, 'description' => 'توضیح کوتاه و مستقیم دربارهٔ ' . $title, 'student_side' => 'هنرجو گام مربوط به ' . $title . ' را در پرتال دنبال می‌کند.', 'highlight_selector' => '#target-' . $id, 'portal_screen' => 'classes', 'action_label' => 'نمایش ' . $title, 'state' => 'not_started', 'needs_reacknowledgement' => false, 'last_visited_at' => null, 'points' => array( 'نکتهٔ کوتاه ' . $title ) );
    }
    $hub = array( 'modules' => $steps, 'completed_count' => 1, 'started_count' => 0, 'total_count' => 9, 'percent' => 11, 'state' => 'in_progress', 'next_module_id' => 'join' );
    $base = array(
        'available' => true, 'screen' => 'induction', 'source' => 'platform',
        'teacher' => array( 'first_name' => 'سارا', 'full_name' => 'سارا نمونه' ), 'navigation' => $nav,
        'induction' => $hub,
        'induction_hub_url' => 'https://example.invalid/?teacher-view=induction',
        'induction_continue_url' => 'https://example.invalid/?teacher-view=induction',
        'induction_support_url' => 'https://wa.me/000',
    );
    $fail( 'complete student perspectives satisfy the display contract', dzn_theme_teacher_portal_validate_model( $base, 'induction' ) );
    $missing_student_side = $base;
    unset( $missing_student_side['induction']['modules'][0]['student_side'] );
    $fail( 'missing student perspective fails the display contract', ! dzn_theme_teacher_portal_validate_model( $missing_student_side, 'induction' ) );
    $hub_html = $render( 'induction', $base );
    $fail( 'quick-start renders the journey heading', str_contains( $hub_html, 'شروع همکاری و تدریس در دلنوازان' ) );
    $fail( 'quick-start renders all nine steps as one read-only page', substr_count( $hub_html, 'class="dzn-tp-quickstart-phase"' ) === 7 );
    $fail( 'steps are grouped by the daily workflow', str_contains( $hub_html, 'tp-phase-0' ) && str_contains( $hub_html, 'tp-phase-6' ) );
    $fail( 'the guide explains both sides', str_contains( $hub_html, 'هنرجو' ) && str_contains( $hub_html, 'حساب کاربری' ) );
    $fail( 'the guide shows no step buttons or completion forms', ! str_contains( $hub_html, '<form' ) && ! str_contains( $hub_html, 'name="module_id"' ) && ! str_contains( $hub_html, 'دیده شده' ) );
    $fail( 'the guide shows no progress or highlight machinery', ! str_contains( $hub_html, 'role="progressbar"' ) && ! str_contains( $hub_html, 'data-dzn-auto-highlight' ) );
    $fail( 'stable anchors remain for contextual help', str_contains( $hub_html, 'id="tp-step-today"' ) && str_contains( $hub_html, 'id="tp-step-join"' ) );
    $fail( 'the guide returns to the portal and keeps support', str_contains( $hub_html, 'بازگشت به حساب کاربری' ) && str_contains( $hub_html, 'نیاز به کمک دارم' ) );
    $fail( 'shell is RTL and exposes real navigation targets', str_contains( $hub_html, 'dir="rtl"' ) && str_contains( $hub_html, 'data-dzn-nav-screen="classes"' ) && str_contains( $hub_html, 'dzn-tp-tabs' ) );

    ob_start();
    dzn_theme_teacher_portal_component( 'induction-card', array( 'model' => $base ) );
    $card_html = (string) ob_get_clean();
    $fail( 'the compact card links to the guide', str_contains( $card_html, 'مرور راهنمای تدریس' ) );
    $fail( 'the compact card does not show progress or completion state', ! str_contains( $card_html, 'role="progressbar"' ) && ! str_contains( $card_html, 'دیده شده' ) );

    ob_start();
    dzn_theme_teacher_portal_component( 'help-tip', array( 'title' => 'راهنمای کوتاه', 'text' => 'توضیح کوتاه.', 'module' => 'absence' ) );
    $tip_html = (string) ob_get_clean();
    $fail( 'contextual help is a native dismissible disclosure', str_contains( $tip_html, '<details' ) && str_contains( $tip_html, '<summary' ) );
    $fail( 'contextual help links to the quick-start step', str_contains( $tip_html, 'tp-step-absence' ) );

    echo "Teacher induction presentation passed\n";
}
