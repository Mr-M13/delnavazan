<?php
declare(strict_types=1);

require __DIR__ . '/../../platform/src/Core/Application/Recording/RecordingSignature.php';
require __DIR__ . '/../recorder-archiver/src/autoload.php';

use Delnavazan\Platform\Core\Application\Recording\RecordingSignature as WordPressSignature;
use Delnavazan\Recorder\Signer as ServiceSigner;

$secret = str_repeat('x', 64);
$payload = ['z' => 1, 'a' => ['list' => [3, 2, 1], 'nested' => ['b' => true, 'a' => 'متن']]];
$now = time();
$service = ServiceSigner::sign($payload, $secret, 'contract-key', 'recording-service-command', $now, 300);
$wordpress = WordPressSignature::sign($payload, $secret, 'contract-key', 'recording-service-command', $now, 300);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$assert(ServiceSigner::verify($wordpress, $secret, 'recording-service-command', $now)['payload'] === $payload, 'service must verify the WordPress envelope');
$assert(WordPressSignature::verify($service, $secret, 'recording-service-command', $now)['payload'] === $payload, 'WordPress must verify the service envelope');
$assert($service['meta']['digest'] === $wordpress['meta']['digest'], 'payload digests must be identical');

$tampered = $wordpress;
$tampered['payload']['z'] = 2;
try { ServiceSigner::verify($tampered, $secret, 'recording-service-command', $now); $assert(false, 'tampered payload accepted'); } catch (RuntimeException $error) { $assert(str_contains($error->getMessage(), 'payload_mismatch'), 'tamper reason'); }
$wrongAudience = WordPressSignature::sign($payload, $secret, 'contract-key', 'other-audience', $now, 300);
try { ServiceSigner::verify($wrongAudience, $secret, 'recording-service-command', $now); $assert(false, 'wrong audience accepted'); } catch (RuntimeException $error) { $assert(str_contains($error->getMessage(), 'audience_mismatch'), 'audience reason'); }
try { WordPressSignature::verify($service, $secret, 'recording-service-command', $now + 1000); $assert(false, 'expired envelope accepted'); } catch (RuntimeException $error) { $assert(str_contains($error->getMessage(), 'expired'), 'expiry reason'); }
echo "Cross-boundary signature contract: PASS\n";
