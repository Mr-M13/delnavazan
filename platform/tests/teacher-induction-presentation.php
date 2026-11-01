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
    $steps = array();
    $ids = array( 'today' => 'کلاس‌های امروز', 'join' => 'ورود به کلاس', 'attendance' => 'حضور و پایان کلاس', 'absence' => 'هنرجو غیبت کرد', 'reschedule' => 'تغییر زمان کلاس', 'settings' => 'تغییر اطلاعات', 'finance' => 'مالی و ساعت‌ها', 'golden-hour' => 'ساعت طلایی', 'new-enrolment' => 'درخواست کلاس آشنایی' );
    $order = 0;
    foreach ( $ids as $id => $title ) {
        ++$order;
        $steps[] = array( 'id' => $id, 'order' => $order, 'title' => $title, 'short_title' => $title, 'description' => 'توضیح کوتاه و مستقیم دربارهٔ ' . $title, 'highlight_selector' => '#target-' . $id, 'portal_screen' => 'today' === $id ? 'home' : 'classes', 'action_label' => 'نمایش ' . $title, 'state' => 'today' === $id ? 'completed' : 'not_started', 'needs_reacknowledgement' => false, 'last_visited_at' => null, 'points' => array( 'نکتهٔ کوتاه ' . $title ) );
    }
    $hub = array( 'modules' => $steps, 'completed_count' => 1, 'started_count' => 0, 'total_count' => 9, 'percent' => 11, 'state' => 'in_progress', 'next_module_id' => 'join' );
    $base = array(
        'available' => true, 'screen' => 'induction', 'source' => 'platform',
        'teacher' => array( 'first_name' => 'سارا', 'full_name' => 'سارا نمونه' ), 'navigation' => $nav,
        'induction' => $hub,
        'induction_hub_url' => 'https://example.invalid/?teacher-view=induction',
        'induction_continue_url' => 'https://example.invalid/?teacher-view=induction',
        'induction_actions' => array( 'complete_url' => 'https://example.invalid/admin-post.php?action=dzn_teacher_induction_complete', 'complete_nonce' => 'nonce-induction' ),
        'induction_support_url' => 'https://wa.me/000',
    );
    $hub_html = $render( 'induction', $base );
    $fail( 'quick-start renders the short Persian heading', str_contains( $hub_html, '۹ نکته برای شروع تدریس' ) );
    $fail( 'quick-start shows the 1 of 9 progress copy', str_contains( $hub_html, '1 از 9 دیده شده' ) );
    $fail( 'quick-start renders all nine steps as one page', substr_count( $hub_html, 'dzn-tp-quickstart-card is-' ) === 9 );
    $fail( 'every step posts its own nonce-protected action', substr_count( $hub_html, 'name="module_id"' ) === 9 && substr_count( $hub_html, 'dzn_teacher_induction_complete' ) === 9 );
    $fail( 'steps use short purpose copy and action labels', str_contains( $hub_html, 'کلاس‌های امروز' ) && str_contains( $hub_html, 'نمایش ورود به کلاس' ) );
    $fail( 'hub does not render long sections or quizzes', ! str_contains( $hub_html, 'سناریوهای عملیاتی' ) && ! str_contains( $hub_html, 'تمرین کوتاه' ) && ! str_contains( $hub_html, 'در این بخش یاد می‌گیرید' ) );
    $fail( 'shell is RTL and exposes real navigation targets', str_contains( $hub_html, 'dir="rtl"' ) && str_contains( $hub_html, 'data-dzn-nav-screen="home"' ) && str_contains( $hub_html, 'dzn-tp-tabs' ) );
    $fail( 'progress is an accessible progressbar', str_contains( $hub_html, 'role="progressbar"' ) && str_contains( $hub_html, 'aria-valuenow="1"' ) && str_contains( $hub_html, 'aria-valuemax="9"' ) );

    ob_start();
    dzn_theme_teacher_portal_component( 'induction-highlight', array( 'step' => array( 'title' => 'ورود به کلاس', 'description' => 'دکمهٔ ورود و گزینهٔ پشتیبان Google Meet.', 'highlight_selector' => '[data-dzn-highlight="join"]', 'points' => array( 'ورود فقط با دسترسی معتبر فعال می‌شود.' ), 'back_url' => 'https://example.invalid/?teacher-view=induction' ) ) );
    $highlight_html = (string) ob_get_clean();
    $fail( 'highlight banner is short and explains the purpose', str_contains( $highlight_html, 'ورود به کلاس' ) && str_contains( $highlight_html, 'Google Meet' ) );
    $fail( 'highlight banner marks an existing selector for the jump', str_contains( $highlight_html, 'data-dzn-auto-highlight="[data-dzn-highlight=&quot;join&quot;]"' ) );
    $fail( 'highlight banner can return to the quick-start', str_contains( $highlight_html, 'بازگشت به راهنمای سریع' ) );

    ob_start();
    dzn_theme_teacher_portal_component( 'help-tip', array( 'title' => 'راهنمای کوتاه', 'text' => 'توضیح کوتاه.', 'module' => 'absence' ) );
    $tip_html = (string) ob_get_clean();
    $fail( 'contextual help is a native dismissible disclosure', str_contains( $tip_html, '<details' ) && str_contains( $tip_html, '<summary' ) );
    $fail( 'contextual help links to the quick-start step', str_contains( $tip_html, 'tp-step-absence' ) );

    echo "Teacher induction presentation passed\n";
}
