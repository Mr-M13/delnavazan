<?php
/** Focused unit proof for the repeat-safe additive column installer. */
require_once dirname(__DIR__) . '/src/Core/Infrastructure/Migration/Migrator.php';

use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;

final class RenewalUpgradeWpdbStub {
	public string $prefix = 'wp_';
	public array $queries = array();
	public function __construct(private bool $columnExists, private bool $queryFails = false) {}
	public function get_row(string $sql): ?object {
		return $this->columnExists ? (object) array('Field' => 'automatic_charge_at') : null;
	}
	public function query(string $sql): int|false {
		$this->queries[] = $sql;
		return $this->queryFails ? false : 1;
	}
}

$assert = static function (bool $condition, string $message): void {
	if (!$condition) throw new RuntimeException($message);
};
$method = new ReflectionMethod(Migrator::class, 'install_renewal_cycle_automatic_charge_at');

$wpdb = new RenewalUpgradeWpdbStub(false);
$method->invoke(null);
$assert($wpdb->queries === array('ALTER TABLE wp_dzn_renewal_cycles ADD COLUMN automatic_charge_at datetime NULL AFTER boundary_derived_at'), 'A historical table must receive exactly the nullable additive column');

$wpdb = new RenewalUpgradeWpdbStub(true);
$method->invoke(null);
$assert($wpdb->queries === array(), 'A repeat migration must not alter an already repaired table');

$wpdb = new RenewalUpgradeWpdbStub(false, true);
$failed = false;
try { $method->invoke(null); } catch (ReflectionException $error) { throw $error; } catch (Throwable $error) { $failed = str_contains($error->getMessage(), 'Renewal automatic-charge instant migration failed'); }
$assert($failed, 'An ALTER failure must fail closed and remain unrecorded');

echo "Historical renewal upgrade unit test passed\n";
