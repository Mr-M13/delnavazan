<?php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';
use Delnavazan\Recorder\ResourcePolicy;
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$assert(ResourcePolicy::evaluate(0.2, 2000000000, 800000000, 0.85)['allowed'] === true, 'healthy host admitted');
$assert(ResourcePolicy::evaluate(0.9, 2000000000, 800000000, 0.85)['reason'] === 'host_load_above_threshold', 'load refusal');
$assert(ResourcePolicy::evaluate(0.2, 700000000, 800000000, 0.85)['reason'] === 'host_memory_below_threshold', 'memory refusal');
echo "Recorder resource admission unit: PASS\n";
