<?php
/** Bounded regression for old Migrator failure paths; no WordPress or database required. */
$root = sys_get_temp_dir() . '/dzn-migrator-runtimeexception-' . bin2hex(random_bytes(6));
if ( ! mkdir( $root . '/wp-admin/includes', 0700, true ) ) { throw new RuntimeException( 'Temporary upgrade fixture creation failed.' ); }
file_put_contents( $root . '/wp-admin/includes/upgrade.php', "<?php\n" );
define( 'ABSPATH', $root . '/' );
if ( ! function_exists( 'dbDelta' ) ) { function dbDelta( string $sql ): void {} }

final class DznMigratorFailureWpdb {
    public string $prefix = 'wp_';
    public string $last_error = 'synthetic migration failure';
    public function get_charset_collate(): string { return ''; }
    public function prepare( string $query, mixed ...$args ): string { return $query; }
    public function get_var( string $query ): string { return ''; }
}

require dirname( __DIR__ ) . '/src/Core/Infrastructure/Migration/Migrator.php';

function dzn_migrator_runtimeexception_assert( bool $value, string $message ): void { if ( ! $value ) { throw new RuntimeException( $message ); } }
function dzn_migrator_runtimeexception_rejects( string $method ): void {
    $reflection = new ReflectionMethod( Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::class, $method );
    $caught = null;
    try { $reflection->invoke( null ); } catch ( Throwable $exception ) { $caught = $exception; }
    dzn_migrator_runtimeexception_assert( $caught instanceof RuntimeException, $method . ' did not throw RuntimeException.' );
}

global $wpdb;
$wpdb = new DznMigratorFailureWpdb();
dzn_migrator_runtimeexception_rejects( 'install_enrolment_conversion_authority' );
dzn_migrator_runtimeexception_rejects( 'install_teacher_assignment_foundation' );
$wpdb->last_error = '';
dzn_migrator_runtimeexception_rejects( 'verify_enrolment_conversion_schema' );
dzn_migrator_runtimeexception_rejects( 'verify_teacher_assignment_schema' );

unlink( $root . '/wp-admin/includes/upgrade.php' );
rmdir( $root . '/wp-admin/includes' );
rmdir( $root . '/wp-admin' );
rmdir( $root );
echo "Migrator RuntimeException failure-path regression passed\n";
