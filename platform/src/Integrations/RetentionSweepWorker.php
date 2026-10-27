<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\RetentionSweepService;

final class RetentionSweepWorker {
    public const HOOK = 'dzn_retention_sweep_daily';
    public static function register(): void {
        add_filter('cron_schedules', static function (array $s): array {
            $s['dzn_daily'] ??= array('interval' => 86400, 'display' => 'Delnavazan daily');
            return $s;
        });
        add_action(self::HOOK, array(__CLASS__, 'run'));
        add_action('init', array(__CLASS__, 'ensureScheduled'));
    }
    public static function ensureScheduled(): void {
        if (!wp_next_scheduled(self::HOOK)) wp_schedule_event(strtotime('02:30:00'), 'dzn_daily', self::HOOK);
    }
    public static function run(): void {
        $service = new RetentionSweepService();
        foreach (array('raw_audio', 'mixed_recording', 'metadata', 'labels', 'dataset_membership') as $dc) {
            try { $service->run($dc, 0); } catch (\Throwable $e) { error_log($e->getMessage()); }
        }
    }
}
