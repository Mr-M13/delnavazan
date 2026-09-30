<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

final class CoreStatusRepository {
    private const TABLES = [
        'teachers', 'students', 'instruments', 'courses', 'enrolments',
        'terms', 'lessons', 'lesson_schedule_versions', 'operational_exceptions',
        'teacher_profile_links',
    ];

    public function snapshot(): array {
        global $wpdb;
        $tables = [];
        foreach (self::TABLES as $suffix) {
            $table = $wpdb->prefix . 'dzn_' . $suffix;
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
            $tables[$suffix] = ['exists' => $exists, 'count' => $exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") : null];
        }

        $exceptions = $tables['operational_exceptions']['exists'] ? $wpdb->prefix . 'dzn_operational_exceptions' : null;
        return [
            'tables' => $tables,
            'exception_counts' => [
                'open' => $exceptions ? $this->exceptionCount($exceptions, 'open') : null,
                'acknowledged' => $exceptions ? $this->exceptionCount($exceptions, 'acknowledged') : null,
            ],
        ];
    }

    private function exceptionCount(string $table, string $status): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", $status));
    }
}
