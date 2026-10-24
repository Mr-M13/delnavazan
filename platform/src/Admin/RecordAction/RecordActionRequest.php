<?php
namespace Delnavazan\Platform\Admin\RecordAction;

/**
 * Testable request-boundary checks for a contextual record action.
 *
 * Capability checks run before CSRF, CSRF runs before the signed context, and
 * the record type/id/page/idempotency metadata must match the signed form.
 */
final class RecordActionRequest {
    /** @return array{action_key:string,record_type:string,record_id:int,page:string,idempotency_key:string,issued_at:int,user_id:int} */
    public static function fromPost(string $actionKey, array $definition, array $post, int $userId, callable $can, callable $nonce): array {
        if ($userId < 1) throw new RecordActionException('authenticated_actor_required');
        if (!$can((string) $definition['capability']) || !$can((string) $definition['record_capability'])) {
            throw new RecordActionException('capability_denied');
        }
        if (!$nonce($actionKey)) throw new RecordActionException('nonce_failed');

        $recordType = self::key($post['record_type'] ?? '');
        $recordId = absint($post['record_id'] ?? 0);
        $page = self::key($post['page'] ?? '');
        $idempotencyKey = self::text($post['idempotency_key'] ?? '');
        $issuedAt = absint($post['issued_at'] ?? 0);
        $contextToken = self::text($post['context_token'] ?? '');
        if ($recordType !== (string) $definition['record_type'] || $recordId < 1 || $page === '' || strlen($idempotencyKey) < 24) {
            throw new RecordActionException('context_incomplete');
        }
        $context = array(
            'action_key' => $actionKey,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'page' => $page,
            'idempotency_key' => $idempotencyKey,
            'issued_at' => $issuedAt,
            'user_id' => $userId,
        );
        if (!RecordActionToken::verify($contextToken, $context, $userId)) throw new RecordActionException('context_invalid');
        return $context;
    }

    private static function key(mixed $value): string {
        return is_string($value) ? sanitize_key(wp_unslash($value)) : '';
    }

    private static function text(mixed $value): string {
        return is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
    }
}
