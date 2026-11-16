<?php
/**
 * Unit harness for the go-live purge payload.
 *
 * Runs the real payload against a deliberately small WordPress stub so the
 * report path, the guarded execute path and its pre-commit verification can be
 * exercised without a database. It never talks to WordPress, a provider or a
 * network.
 *
 * Usage:
 *   php purge-test-data-unit.php report
 *   php purge-test-data-unit.php execute <fingerprint> <backup> <sha256>
 *   php purge-test-data-unit.php bad-confirm <fingerprint> <backup> <sha256>
 *   php purge-test-data-unit.php bad-fingerprint <backup> <sha256>
 *   php purge-test-data-unit.php bad-backup <fingerprint>
 */
declare(strict_types=1);

define('WP_CLI', true);

$root    = dirname(__DIR__, 2);
$payload = __DIR__ . '/purge-test-data.php';
$plan    = require __DIR__ . '/purge-test-data-tables.php';

$tmp = sys_get_temp_dir() . '/dzn-purge-unit-' . bin2hex(random_bytes(4));
mkdir($tmp . '/wp-admin/includes', 0700, true);
mkdir($tmp . '/uploads', 0700, true);
file_put_contents($tmp . '/wp-admin/includes/user.php', "<?php\n");
define('ABSPATH', $tmp . '/');

$tables = array();
foreach (array_merge($plan['preserve'], $plan['purge']) as $short) {
    $tables[] = 'wp_dzn_' . $short;
}
$tables = array_merge($tables, array('wp_posts', 'wp_users', 'wp_usermeta', 'wp_options'));

final class WP_User
{
    public int $ID;
    public array $roles;
    public function __construct(int $id, array $roles)
    {
        $this->ID    = $id;
        $this->roles = $roles;
    }
    public function remove_role(string $role): void
    {
        $this->roles = array_values(array_diff($this->roles, array($role)));
    }
}

$GLOBALS['dzn_unit_users'] = array(1 => new WP_User(1, array('administrator')));

function get_users(array $args = array()): array
{
    return array_keys($GLOBALS['dzn_unit_users']);
}
function get_userdata(int $id)
{
    return $GLOBALS['dzn_unit_users'][$id] ?? false;
}
function get_user_by(string $field, string $value)
{
    if ('email' === $field && 'hamed.moqadasi@yahoo.com' === $value) {
        return $GLOBALS['dzn_unit_users'][1];
    }
    return false;
}
function user_can($user, string $cap): bool
{
    return in_array('administrator', $user->roles, true);
}
function get_option(string $key, $default = false)
{
    return 'dzn_platform_schema_version' === $key ? '74' : $default;
}
function update_option(string $key, $value, $autoload = null): bool
{
    return true;
}
function wp_json_encode($value, int $flags = 0)
{
    return json_encode($value, $flags);
}
function home_url(string $path = ''): string
{
    return 'https://delnavazan.test' . $path;
}
function wp_get_environment_type(): string
{
    return 'production';
}
function trailingslashit(string $value): string
{
    return rtrim($value, '/\\') . '/';
}
function wp_upload_dir(): array
{
    return array('basedir' => ABSPATH . 'uploads');
}
function wp_mkdir_p(string $dir): bool
{
    return is_dir($dir) || @mkdir($dir, 0700, true);
}
function wp_cache_flush(): void
{
}
function delete_user_meta(int $userId, string $key): bool
{
    return true;
}
function wp_delete_post(int $postId, bool $force = false): bool
{
    return true;
}
function wp_delete_user(int $userId, $reassign = null): bool
{
    return true;
}

final class Dzn_Unit_Wpdb
{
    public string $prefix     = 'wp_';
    public string $posts      = 'wp_posts';
    public string $users      = 'wp_users';
    public string $usermeta   = 'wp_usermeta';
    public string $comments   = 'wp_comments';
    public string $commentmeta = 'wp_commentmeta';
    public string $last_error = '';
    private array $tables;
    public function __construct(array $tables)
    {
        $this->tables = $tables;
    }
    public function esc_like(string $value): string
    {
        return addcslashes($value, '_%\\');
    }
    public function prepare(string $query, ...$args): string
    {
        return $query;
    }
    public function get_col(string $query): array
    {
        if (0 === strpos($query, 'SHOW TABLES LIKE')) {
            return array_values(array_filter($this->tables, static fn (string $table): bool => 0 === strpos($table, 'wp_dzn_')));
        }
        if (0 === strpos($query, 'SHOW TABLES')) {
            return $this->tables;
        }
        if (0 === strpos($query, 'SELECT ID FROM wp_users')) {
            return array('1');
        }
        return array();
    }
    public function get_var(string $query)
    {
        return 0;
    }
    public function tables(string $scope = 'all'): array
    {
        return array('wp_posts', 'wp_users', 'wp_usermeta', 'wp_options');
    }
    public function query(string $query)
    {
        return 0;
    }
}

$GLOBALS['wpdb'] = new Dzn_Unit_Wpdb($tables);

$mode        = $argv[1] ?? 'report';
$fingerprint = (string) ($argv[2] ?? '');
$backup      = (string) ($argv[3] ?? '');
$backupSha   = (string) ($argv[4] ?? '');
$confirm     = 'PURGE_ALL_TEST_DATA';

// The payload requires a file literally named purge-test-data-tables.php.
$planCopy = $tmp . '/purge-test-data-tables.php';
file_put_contents($planCopy, "<?php return " . var_export($plan, true) . ";\n");

$payloadArgs = match ($mode) {
    'report'          => array('report', 'none', 'none', 'none', 'none', $planCopy),
    'execute'         => array('execute', $confirm, $fingerprint, $backup, $backupSha, $planCopy),
    'bad-confirm'     => array('execute', 'WRONG', $fingerprint, $backup, $backupSha, $planCopy),
    'bad-fingerprint' => array('execute', $confirm, str_repeat('0', 64), $backup, $backupSha, $planCopy),
    'bad-backup'      => array('execute', $confirm, $fingerprint, '/nonexistent/backup.sql.gz', str_repeat('a', 64), $planCopy),
    default           => array($mode),
};

register_shutdown_function(static function () use ($tmp, $planCopy): void {
    @unlink($planCopy);
    if (is_dir($tmp)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($tmp);
    }
});

$run = static function (array $args) use ($payload): void {
    include $payload;
};
$run($payloadArgs);
