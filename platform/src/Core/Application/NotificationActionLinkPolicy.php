<?php
namespace Delnavazan\Platform\Core\Application;

/** Builds notification action links from the active site's canonical URL policy. */
final class NotificationActionLinkPolicy {
    public static function teacherInvitation(string $environment, string $siteUrl): string {
        if (!in_array($environment, array('production', 'staging'), true)) {
            throw new \RuntimeException('notification_action_link_unavailable');
        }
        $parts = parse_url(trim($siteUrl));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('notification_action_link_unavailable');
        }
        // WP_ENVIRONMENT_TYPE identifies this runtime, while home_url() is the canonical
        // site authority. Catch an obvious environment/canonical-site mismatch in production.
        if ($environment === 'production' && preg_match('/(^|[.-])(staging|stage|development|dev|testing|test|local)([.-]|$)/i', $host)) {
            throw new \RuntimeException('production_notification_site_url_not_canonical');
        }
        return rtrim(trim($siteUrl), '/') . '/teacher-invitation/';
    }

    public static function currentTeacherInvitation(): string {
        $environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : '';
        $siteUrl = function_exists('home_url') ? home_url('/') : '';
        return self::teacherInvitation((string) $environment, (string) $siteUrl);
    }
}
