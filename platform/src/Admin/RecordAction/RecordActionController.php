<?php
namespace Delnavazan\Platform\Admin\RecordAction;

/**
 * Single guarded endpoint for contextual record actions.
 *
 * Order is deliberate: authentication and capability, CSRF nonce, signed context
 * and expiry, server-side record re-resolution, state/confirmation checks, then
 * the canonical application service. Exceptions never become user-facing detail.
 */
final class RecordActionController {
    public static function register(): void {
        add_action('admin_post_dzn_record_action', array(__CLASS__, 'handle'));
        add_action('admin_head', array(RecordActionAssets::class, 'styles'));
    }

    public static function handle(): void {
        if ((string) ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        $key = self::postKey('action_key');
        $definition = RecordActionRegistry::definition($key);
        if (!$definition) self::abort(400, 'Unknown record action.');
        if (!current_user_can((string) $definition['capability']) || !current_user_can((string) $definition['record_capability'])) {
            self::abort(403, 'Access denied.');
        }
        try {
            check_admin_referer('dzn_record_action_' . $key);
        } catch (\Throwable) {
            self::abort(403, 'The action could not be verified. Reload the record and try again.');
        }

        $recordType = self::postKey('record_type');
        $recordId = absint($_POST['record_id'] ?? 0);
        $page = self::postKey('page');
        $idempotencyKey = self::postText('idempotency_key');
        $issuedAt = absint($_POST['issued_at'] ?? 0);
        $contextToken = self::postText('context_token');
        $userId = (int) get_current_user_id();
        if ($recordType !== (string) $definition['record_type'] || $recordId < 1 || $page === '' || $userId < 1) {
            self::abort(400, 'The record context is incomplete.');
        }
        if (strlen($idempotencyKey) < 24) {
            self::redirect($page, $recordId, 'The action could not be verified. Reload the record and try again.', true);
        }
        $tokenContext = array(
            'action_key' => $key,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'page' => $page,
            'idempotency_key' => $idempotencyKey,
            'issued_at' => $issuedAt,
            'user_id' => $userId,
        );
        if (!RecordActionToken::verify($contextToken, $tokenContext, $userId)) {
            self::redirect($page, $recordId, 'This action expired or was altered. Reload the record and try again.', true);
        }

        try {
            $record = (new RecordActionResolver())->resolve($recordType, $recordId);
            $visible = $definition['visible'] ?? null;
            if (is_callable($visible) && !$visible($record)) throw new RecordActionException('record_action_unavailable');
            if (!empty($definition['require_confirmation']) && (string) ($_POST['confirm'] ?? '') !== '1') {
                throw new RecordActionException('record_action_confirmation_required');
            }
            $input = array();
            foreach ((array) ($definition['fields'] ?? array()) as $field) {
                $name = sanitize_key((string) ($field['name'] ?? ''));
                if ($name === '') continue;
                $input[$name] = self::fieldValue($field);
            }
            $context = new RecordActionContext($key, $recordType, $recordId, $page, $idempotencyKey, $record);
            $result = RecordActionRegistry::handle($key, $context, $input);
            self::redirect($page, $recordId, (string) ($result['message'] ?? 'Record action completed.'), false);
        } catch (\Throwable $exception) {
            error_log('[Delnavazan record action] ' . $key . ' ' . get_class($exception));
            self::redirect($page, $recordId, self::safeMessage($exception), true);
        }
    }

    private static function fieldValue(array $field): string {
        $name = sanitize_key((string) ($field['name'] ?? ''));
        $raw = wp_unslash($_POST[$name] ?? '');
        return match ((string) ($field['type'] ?? 'text')) {
            'textarea' => sanitize_textarea_field((string) $raw),
            'select', 'checkbox' => sanitize_key((string) $raw),
            default => sanitize_text_field((string) $raw),
        };
    }

    private static function safeMessage(\Throwable $exception): string {
        return match ($exception->getMessage()) {
            'record_not_found' => 'The record no longer exists.',
            'record_read_forbidden' => 'You no longer have permission to open this record.',
            'record_action_unavailable' => 'This action is no longer available for the record’s current state.',
            'record_action_confirmation_required' => 'Confirm the consequence before continuing.',
            'Archive conflict' => 'This record cannot be archived while dependent records or schedules exist.',
            'Record is not archived' => 'This record is not archived.',
            default => 'The action could not be completed; no change was saved.',
        };
    }

    private static function redirect(string $page, int $recordId, string $message, bool $error): never {
        $url = add_query_arg(array(
            'page' => $page,
            'id' => $recordId,
            'dzn_record_action_notice' => $message,
            'dzn_record_action_error' => $error ? '1' : '0',
        ), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    private static function postKey(string $name): string {
        return isset($_POST[$name]) && is_string($_POST[$name]) ? sanitize_key(wp_unslash($_POST[$name])) : '';
    }

    private static function postText(string $name): string {
        return isset($_POST[$name]) && is_string($_POST[$name]) ? sanitize_text_field(wp_unslash($_POST[$name])) : '';
    }

    private static function abort(int $status, string $message): never {
        wp_die(esc_html($message), 'Delnavazan', array('response' => $status));
    }
}
