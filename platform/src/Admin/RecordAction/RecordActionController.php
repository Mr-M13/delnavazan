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
        try {
            $request = RecordActionRequest::fromPost(
                $key,
                $definition,
                $_POST,
                (int) get_current_user_id(),
                static fn(string $capability): bool => current_user_can($capability),
                static fn(string $actionKey): bool => wp_verify_nonce(
                    isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce']) ? wp_unslash($_POST['_wpnonce']) : '',
                    'dzn_record_action_' . $actionKey
                ) !== false
            );
        } catch (RecordActionException $exception) {
            self::requestFailure($exception);
        }

        try {
            $record = (new RecordActionResolver())->resolve($request['record_type'], $request['record_id']);
            $visible = $definition['visible'] ?? null;
            if (is_callable($visible) && !$visible($record)) throw new RecordActionException('record_action_unavailable');
            if (!empty($definition['require_confirmation']) && (string) ($_POST['confirm'] ?? '') !== '1') {
                throw new RecordActionException('record_action_confirmation_required');
            }
            $input = array();
            foreach (RecordActionRegistry::fieldsFor($key, $record) as $field) {
                $name = sanitize_key((string) ($field['name'] ?? ''));
                if ($name === '') continue;
                $input[$name] = self::fieldValue($field);
            }
            $context = new RecordActionContext(
                $key,
                $request['record_type'],
                $request['record_id'],
                $request['page'],
                $request['idempotency_key'],
                $record
            );
            $result = RecordActionRegistry::handle($key, $context, $input);
            $target = self::redirectTarget($key, $request);
            self::redirect($target['page'], $target['record_id'], (string) ($result['message'] ?? 'Record action completed.'), false, $target['extra']);
        } catch (\Throwable $exception) {
            error_log('[Delnavazan record action] ' . $key . ' ' . get_class($exception));
            $target = self::redirectTarget($key, $request);
            self::redirect($target['page'], $target['record_id'], self::safeMessage($exception), true, $target['extra']);
        }
    }

    private static function requestFailure(RecordActionException $exception): never {
        if (in_array($exception->getMessage(), array('capability_denied', 'authenticated_actor_required'), true)) {
            self::abort(403, 'Access denied.');
        }
        if ($exception->getMessage() === 'nonce_failed') {
            self::abort(403, 'The action could not be verified. Reload the record and try again.');
        }
        self::redirect('dzn-platform', 0, 'This action expired or was altered. Reload the record and try again.', true);
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
        $message = $exception->getMessage();
        if (str_starts_with($message, 'Archive conflict')) return 'This record cannot be archived while dependent records or schedules exist.';
        return match ($message) {
            'record_not_found' => 'The record no longer exists.',
            'record_read_forbidden' => 'You no longer have permission to open this record.',
            'record_action_unavailable' => 'This action is no longer available for the record’s current state.',
            'record_action_confirmation_required' => 'Confirm the consequence before continuing.',
            'Record is not archived' => 'This record is not archived.',
            'Record is not archivable' => 'This record can no longer be archived.',
            'active_future_schedule_exists' => 'This Teacher has an active future schedule. Reschedule or release it before archiving.',
            default => 'The action could not be completed; no change was saved.',
        };
    }

    /** @return array{page:string,record_id:int,extra:array<string,string>} */
    private static function redirectTarget(string $key, array $request): array {
        if (in_array($key, array('teacher-approve-onboarding', 'teacher-return-onboarding', 'teacher-reject-onboarding'), true)) {
            return array('page' => 'dzn-teachers-menu', 'record_id' => 0, 'extra' => array('view' => 'onboarding'));
        }
        return array('page' => (string) $request['page'], 'record_id' => (int) $request['record_id'], 'extra' => array());
    }

    private static function redirect(string $page, int $recordId, string $message, bool $error, array $extra = array()): never {
        $args = array_merge(array(
            'page' => $page,
            'dzn_record_action_notice' => $message,
            'dzn_record_action_error' => $error ? '1' : '0',
        ), $extra);
        if ($recordId > 0) $args['id'] = $recordId;
        $url = add_query_arg($args, admin_url('admin.php'));
        nocache_headers();
        if (wp_safe_redirect($url)) exit;
        wp_die(esc_html($message), 'Delnavazan', array('response' => $error ? 400 : 200, 'back_link' => true));
    }

    private static function postKey(string $name): string {
        return isset($_POST[$name]) && is_string($_POST[$name]) ? sanitize_key(wp_unslash($_POST[$name])) : '';
    }

    private static function abort(int $status, string $message): never {
        wp_die(esc_html($message), 'Delnavazan', array('response' => $status));
    }
}
