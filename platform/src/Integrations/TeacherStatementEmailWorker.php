<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\Finance\Statements\TeacherStatementEmailService;

/** Cron/one-shot worker for durable teacher-statement email intents. */
final class TeacherStatementEmailWorker {
    public const HOOK = 'dzn_teacher_statement_email_dispatch';
    public static function register(): void {
        add_action(self::HOOK, array(__CLASS__, 'run'));
        add_action('init', array(__CLASS__, 'schedule'), 20);
    }
    public static function schedule(): void {
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event') && !wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::HOOK);
        }
    }
    public static function run(): void {
        try { (new TeacherStatementEmailService())->processDue(20); } catch (\Throwable) { /* the next cron run retries; no financial fact is reversed */ }
    }
}
