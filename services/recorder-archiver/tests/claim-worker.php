<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Delnavazan\Recorder\Config;
use Delnavazan\Recorder\Database;
use Delnavazan\Recorder\Repository;

$dsn = $argv[1] ?? '';
$output = $argv[2] ?? '';
$limit = max(1, (int) ($argv[3] ?? 5));
$table = (string) ($argv[4] ?? 'service_inbox');
if ($dsn === '' || $output === '') exit(2);
$config = new Config(['database_dsn' => $dsn, 'database_user' => '', 'database_password' => '']);
$repo = new Repository(new Database($config));
$rows = $table === 'transfer_jobs' ? $repo->transferClaim($limit) : ($table === 'deletion_jobs' ? $repo->deletionClaim($limit) : $repo->inboxClaim($limit));
$handle = fopen($output, 'ab');
foreach ($rows as $row) fwrite($handle, json_encode(['id' => (int) $row['id'], 'token' => $row['_lease_token']]) . "\n");
fclose($handle);
