<?php
namespace Delnavazan\Platform\Public;

/** Private WordPress inbox for project names; unrelated to recording decisions. */
final class ResearchNameSuggestionController {
    public const POST_TYPE = 'dzn_research_name';
    public const ACTION = 'dzn_research_name';

    public static function register(): void {
        add_action('init', [self::class, 'registerInbox']);
        add_action('admin_post_' . self::ACTION, [self::class, 'submit']);
        add_action('admin_post_nopriv_' . self::ACTION, [self::class, 'submit']);
    }

    public static function registerInbox(): void {
        register_post_type(self::POST_TYPE, [
            'labels' => ['name' => 'Research name suggestions', 'singular_name' => 'Name suggestion'],
            'public' => false, 'publicly_queryable' => false, 'exclude_from_search' => true,
            'show_ui' => true, 'show_in_rest' => false, 'rewrite' => false, 'query_var' => false,
            'menu_icon' => 'dashicons-lightbulb', 'supports' => ['title'],
            'map_meta_cap' => false,
            'capabilities' => array_fill_keys([
                'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts',
                'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts',
                'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts',
            ], 'manage_options') + ['create_posts' => 'do_not_allow'],
        ]);
    }

    /** Return a status only after durable storage succeeds; never send mail. */
    public static function receive(array $input, string $address, string $method): string {
        if ('POST' !== $method) return 'invalid';
        $nonce = $input['_wpnonce'] ?? null;
        if (!is_string($nonce) || !wp_verify_nonce($nonce, self::ACTION)) return 'expired';
        if (!isset($input['website']) || !is_string($input['website']) || '' !== $input['website']) return 'invalid';
        $raw = $input['project_name'] ?? null;
        if (!is_string($raw) || strlen($raw) > 600) return 'invalid';
        $name = trim(sanitize_text_field($raw));
        // Reject invisible-only names as well as overlong Unicode input.
        $visible = preg_replace('/[\p{Z}\p{C}]/u', '', $name);
        $length = preg_match_all('/./us', $name);
        if (!$visible || false === $length || $length > 120) return 'invalid';

        // Only a keyed digest is retained; do not trust forwarded IP headers.
        $key = 'dzn_research_name_' . hash_hmac('sha256', $address, wp_salt('nonce'));
        $digest = hash('sha256', $name);
        $recent = get_transient($key);
        if (is_array($recent)) return ($recent['name'] ?? '') === $digest ? 'received' : 'limited';
        // Atomic short-lived lock prevents concurrent double submissions.
        $lock = $key . '_lock';
        if (!add_option($lock, time(), '', false)) {
            $started = (int) get_option($lock);
            if ($started > 0 && $started < time() - 120) delete_option($lock);
            return 'limited';
        }
        try {
            // Another request may have completed between our first read and lock acquisition.
            $recent = get_transient($key);
            if (is_array($recent)) return ($recent['name'] ?? '') === $digest ? 'received' : 'limited';
            $post = wp_insert_post([
                'post_type' => self::POST_TYPE, 'post_status' => 'private',
                'post_title' => wp_slash($name), 'post_author' => 0,
            ], true);
            if (is_wp_error($post) || !$post) return 'error';
            set_transient($key, ['name' => $digest], 60);
            return 'received';
        } finally {
            delete_option($lock);
        }
    }

    public static function submit(): void {
        $status = self::receive(wp_unslash($_POST), (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        wp_safe_redirect(add_query_arg('name-status', $status, home_url('/recording-and-research/')) . '#project-name', 303);
        exit;
    }
}
