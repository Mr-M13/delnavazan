<?php
namespace Delnavazan\Platform\Admin\RecordAction;

/**
 * Binds a focused record action form to its server-resolved record context.
 *
 * The record id, page, actor, action and generated idempotency key are signed
 * with a WordPress secret. A client cannot retarget the action to another
 * record or substitute its own idempotency key without invalidating the token.
 */
final class RecordActionToken {
    private const TTL_SECONDS = 7200;
    private const FUTURE_SKEW_SECONDS = 300;

    /** @return array{action_key:string,record_type:string,record_id:int,page:string,idempotency_key:string,issued_at:int,user_id:int,context_token:string} */
    public static function issue(string $actionKey, string $recordType, int $recordId, string $page, int $userId): array {
        if ($userId < 1) throw new RecordActionException('authenticated_actor_required');
        $context = array(
            'action_key' => $actionKey,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'page' => $page,
            'idempotency_key' => wp_generate_uuid4(),
            'issued_at' => time(),
            'user_id' => $userId,
        );
        return $context + array('context_token' => self::sign($context));
    }

    public static function sign(array $context): string {
        return wp_hash(self::payload($context), 'auth');
    }

    public static function verify(string $token, array $context, int $userId, ?int $now = null): bool {
        if ($token === '' || $userId < 1 || (int) ($context['user_id'] ?? 0) !== $userId) return false;
        $issuedAt = (int) ($context['issued_at'] ?? 0);
        $now = $now ?? time();
        if ($issuedAt < 1 || $issuedAt > $now + self::FUTURE_SKEW_SECONDS) return false;
        if ($now - $issuedAt > self::TTL_SECONDS) return false;
        return hash_equals(self::sign($context), $token);
    }

    private static function payload(array $context): string {
        $fields = array(
            'action_key' => (string) ($context['action_key'] ?? ''),
            'record_type' => (string) ($context['record_type'] ?? ''),
            'record_id' => (int) ($context['record_id'] ?? 0),
            'page' => (string) ($context['page'] ?? ''),
            'idempotency_key' => (string) ($context['idempotency_key'] ?? ''),
            'issued_at' => (int) ($context['issued_at'] ?? 0),
            'user_id' => (int) ($context['user_id'] ?? 0),
        );
        return implode('|', $fields);
    }
}
