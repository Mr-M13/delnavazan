<?php
namespace Delnavazan\Platform\Core\Application\Finance\Read;

use Delnavazan\Platform\Core\Application\Finance\FinanceSupport;

/** Read-only Teacher selector for Finance views; never exposes credentials or personal contact data. */
final class FinanceTeacherOptionsReadService {
    public const CAPABILITY = 'dzn_view_finance_authority';

    /** @return array<int,object> */
    public function options(): array {
        FinanceSupport::requireCapability(self::CAPABILITY);
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $rows = $wpdb->get_results("SELECT id,COALESCE(NULLIF(display_name,''),NULLIF(english_name,''),persian_name,CONCAT('Teacher #',id)) label FROM {$p}teachers WHERE archived_at IS NULL ORDER BY label,id");
        if ($wpdb->last_error !== '') throw new \RuntimeException('Finance teacher selector unavailable');
        return is_array($rows) ? $rows : array();
    }

    public function isValid(int $teacherId): bool {
        if ($teacherId < 1) return false;
        foreach ($this->options() as $option) if ((int) $option->id === $teacherId) return true;
        return false;
    }
}
