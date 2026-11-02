<?php
declare(strict_types=1);

define('ARRAY_A', 'ARRAY_A');
function wp_json_encode($value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
function get_userdata(int $id): ?object { return $id === 17 ? (object) array('roles' => array('subscriber')) : null; }
function is_multisite(): bool { return false; }

final class RemovalFakeDb {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $comments = 'wp_comments';
    public string $last_error = '';
    public array $queries = array();
    public array $counts = array();
    public array $tables = array('wp_dzn_students', 'wp_dzn_teachers', 'wp_dzn_student_principal_links', 'wp_dzn_finance_statements');
    public function prepare(string $query, ...$args): string {
        foreach ($args as $arg) $query = preg_replace('/%[ds]/', is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'", $query, 1);
        return $query;
    }
    public function esc_like(string $s): string { return $s; }
    public function get_row(string $query, $format = null): ?array {
        $this->assertRead($query);
        return array('id' => 4, 'uid' => 'TESTACCOUNT000000000000000', 'wordpress_user_id' => 17, 'status' => 'inactive');
    }
    public function get_col(string $query): array {
        $this->assertRead($query);
        if (str_contains($query, 'information_schema.TABLES')) return $this->tables;
        if (str_contains($query, "'wp_dzn_student_principal_links'")) return array('id', 'student_id', 'wordpress_user_id');
        if (str_contains($query, "'wp_dzn_finance_statements'")) return array('id', 'teacher_id');
        return array('id', 'uid', 'wordpress_user_id');
    }
    public function get_var(string $query): int {
        $this->assertRead($query);
        foreach ($this->counts as $fragment => $count) if (str_contains($query, $fragment)) return $count;
        return 0;
    }
    private function assertRead(string $query): void {
        if (!preg_match('/^SELECT /', $query)) throw new RuntimeException('Mutation attempted');
        $this->queries[] = $query;
    }
}

require_once __DIR__ . '/../src/Core/Application/AccountRemovalDiscoveryService.php';
use Delnavazan\Platform\Core\Application\AccountRemovalDiscoveryService;

$wpdb = new RemovalFakeDb();
$service = new AccountRemovalDiscoveryService();
foreach (array('student', 'teacher') as $kind) {
    $manifest = $service->discover($kind, 4);
    if ($manifest['kind'] !== $kind || !in_array('disposable_test_provenance_unverified', $manifest['blockers'], true)) throw new RuntimeException('Unsafe manifest');
    if (!in_array('indirect_and_external_dependencies_unreviewed', $manifest['blockers'], true)) throw new RuntimeException('External dependencies omitted');
    if (count($wpdb->queries) < 4) throw new RuntimeException('Dependencies not inspected');
}
$wpdb->counts = array('student_principal_links` WHERE `student_id`=4' => 1);
$manifest = $service->discover('student', 4);
if (!in_array('related_record:student_principal_links.student_id', $manifest['blockers'], true)) throw new RuntimeException('Principal link missed');
$wpdb->counts = array('`wp_dzn_teachers` WHERE wordpress_user_id=17' => 1);
$manifest = $service->discover('student', 4);
if (!in_array('shared_wordpress_identity', $manifest['blockers'], true)) throw new RuntimeException('Shared identity missed');
foreach (array(array('student', 0), array('invalid', 4)) as [$kind, $id]) {
    try { $service->discover($kind, $id); throw new RuntimeException('Invalid selector accepted'); }
    catch (InvalidArgumentException $expected) {}
}
echo "account_removal_discovery_unit_ok\n";
