<?php
require_once dirname(__DIR__) . '/src/Integrations/LiveKit/LiveKitJwtIssuer.php';
$assert = static function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
$issuer = new \Delnavazan\Platform\Integrations\LiveKit\LiveKitJwtIssuer();
$token = $issuer->issue('devkey', 'not-a-real-secret', 'dzn-room_1', 'dzn-t-99', 1700000000);
[$header, $payload, $signature] = explode('.', $token);
$decode = static fn(string $value): array => json_decode(base64_decode(strtr($value, '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);
$claims = $decode($payload);
$assert($decode($header) === array('alg' => 'HS256', 'typ' => 'JWT'), 'Issuer did not create an HS256 JWT.');
$assert($claims['iss'] === 'devkey' && $claims['sub'] === 'dzn-t-99', 'Issuer lost token identity claims.');
$assert($claims['exp'] === 1700000300 && $claims['nbf'] === 1699999995, 'Token lifetime is not bounded to five minutes.');
$assert($claims['video']['roomJoin'] === true && $claims['video']['room'] === 'dzn-room_1', 'Token is not room scoped.');
$assert($claims['video']['canPublish'] === true && $claims['video']['canSubscribe'] === true && $claims['video']['canPublishData'] === true, 'The 1:1 classroom needs reliable room data messages for shared music mode.');
$assert($claims['video']['roomCreate'] === false && $claims['video']['roomAdmin'] === false && $claims['video']['roomRecord'] === false, 'Participant token must not create, administer, or record a room.');
$assert($claims['video']['canPublishSources'] === array('camera', 'microphone'), 'Participant token may publish an unexpected source.');
$assert(!str_contains($payload, 'not-a-real-secret'), 'API secret leaked into the token payload.');
$expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $header . '.' . $payload, 'not-a-real-secret', true)), '+/', '-_'), '=');
$assert(hash_equals($expected, $signature), 'JWT signature cannot be verified.');
try { $issuer->issue('key', 'secret', 'not valid', 'dzn-t-99', 1); throw new RuntimeException('Unsafe room name was accepted.'); }
catch (InvalidArgumentException $error) { $assert($error->getMessage() === 'livekit_token_input_invalid', 'Invalid token input did not fail closed.'); }

$named = $issuer->issueParticipant('devkey', 'not-a-real-secret', 'dzn-room_1', 'dzn-t-99', 'مدرس نمونه', 1700000000, 120);
$namedClaims = $decode(explode('.', $named)[1]);
$assert(($namedClaims['name'] ?? '') === 'مدرس نمونه', 'Participant token lost the display name.');
$assert($namedClaims['exp'] === 1700000120, 'Participant TTL override was not applied.');

$admin = $issuer->issueRoomAdmin('devkey', 'not-a-real-secret', 'dzn-room_1', 1700000000);
$adminClaims = $decode(explode('.', $admin)[1]);
$assert(($adminClaims['video']['roomAdmin'] ?? false) === true && !isset($adminClaims['video']['roomJoin']), 'RoomService token must be admin-only.');
$assert($adminClaims['exp'] === 1700000060, 'RoomService token is not short-lived.');
echo "LiveKit JWT issuer unit passed\n";
