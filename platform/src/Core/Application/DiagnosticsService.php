<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CoreStatusRepository;

final class DiagnosticsService {
    private const REQUIRED_MIGRATION = '001_initial_core_schema';

    public function status(): array {
        if (!current_user_can('dzn_view_diagnostics')) throw new \RuntimeException('Unauthorized');
        $snapshot = (new CoreStatusRepository())->snapshot();
        $schemaVersion = (string) get_option('dzn_platform_schema_version', 'not installed');
        $migrationState = (array) get_option('dzn_platform_completed_migrations', []);
        $tablesHealthy = !in_array(false, array_column($snapshot['tables'], 'exists'), true);
        $schemaCurrent = $schemaVersion === DZN_PLATFORM_SCHEMA_VERSION;
        $migrationComplete = in_array(self::REQUIRED_MIGRATION, $migrationState, true);
        return [
            'platform_version' => DZN_PLATFORM_VERSION,
            'schema_version' => $schemaVersion,
            'migration_state' => $migrationState,
            'tables' => $snapshot['tables'],
            'exception_counts' => $snapshot['exception_counts'],
            'tables_healthy' => $tablesHealthy,
            'schema_current' => $schemaCurrent,
            'required_migration_complete' => $migrationComplete,
            'healthy' => $tablesHealthy && $schemaCurrent && $migrationComplete,
        ];
    }
}
