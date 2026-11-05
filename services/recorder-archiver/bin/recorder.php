<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Delnavazan\Recorder\Archive\LocalArchiveAdapter;
use Delnavazan\Recorder\Archive\RsyncArchiveAdapter;
use Delnavazan\Recorder\Config;
use Delnavazan\Recorder\Database;
use Delnavazan\Recorder\Media\MediaVerifier;
use Delnavazan\Recorder\Provider\LiveKitEgressClient;
use Delnavazan\Recorder\Repository;
use Delnavazan\Recorder\Storage\StorageLayout;
use Delnavazan\Recorder\SystemClock;
use Delnavazan\Recorder\Worker;

$config = Config::fromEnvironment();
$database = new Database($config);
$repository = new Repository($database);
$storage = new StorageLayout($config);
$command = $argv[1] ?? 'help';

try {
    switch ($command) {
        case 'migrate':
            $database->migrate();
            $storage->ensure();
            echo json_encode(['ok' => true, 'database' => $database->isSqlite() ? 'sqlite-wal' : 'postgresql', 'storage' => $storage->describe()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            break;
        case 'pull':
            $worker = buildWorker($config, $repository, $storage);
            echo json_encode(['pulled' => $worker->pullCommands()], JSON_PRETTY_PRINT) . "\n";
            break;
        case 'once':
            $database->migrate();
            $storage->ensure();
            $worker = buildWorker($config, $repository, $storage);
            echo json_encode($worker->runOnce(true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            break;
        case 'work':
            $database->migrate();
            $storage->ensure();
            $worker = buildWorker($config, $repository, $storage);
            $interval = max(1, (int) (getenv('DZN_RECORDER_POLL_SECONDS') ?: 5));
            while (true) {
                $stats = $worker->runOnce(true);
                echo json_encode(['at' => gmdate('c'), 'stats' => $stats], JSON_UNESCAPED_SLASHES) . "\n";
                sleep($interval);
            }
        case 'health':
            $worker = buildWorker($config, $repository, $storage);
            echo json_encode($worker->health(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            break;
        case 'status':
            echo json_encode(['counts' => $repository->counts(), 'storage' => $storage->describe(), 'database' => $database->isSqlite() ? 'sqlite-wal' : 'postgresql'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            break;
        default:
            fwrite(STDERR, "Usage: recorder.php migrate|pull|once|work|health|status\n");
            exit(2);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'recorder error: ' . $error->getMessage() . "\n");
    exit(1);
}

function buildWorker(Config $config, Repository $repository, StorageLayout $storage): Worker {
    $transport = (string) $config->get('archive_transport');
    $archive = $transport === 'local' ? new LocalArchiveAdapter($config) : new RsyncArchiveAdapter($config);
    return new Worker($config, $repository, $storage, new LiveKitEgressClient($config), $archive, new MediaVerifier(), new SystemClock());
}
