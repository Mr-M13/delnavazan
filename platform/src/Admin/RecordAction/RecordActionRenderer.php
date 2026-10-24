<?php
namespace Delnavazan\Platform\Admin\RecordAction;

/**
 * Accessible, RTL-aware focused action forms.
 *
 * The form contains only signed context metadata and the fields declared by the
 * action. The target record is re-resolved by RecordActionController.
 */
final class RecordActionRenderer {
    private static $scriptPrinted = false;

    public static function render(string $recordType, object $record, string $page): void {
        self::renderSelected($recordType, $record, $page, array_keys(RecordActionRegistry::forRecord($recordType, $record)));
    }

    /** @param array<int, string> $keys */
    public static function renderSelected(string $recordType, object $record, string $page, array $keys): void {
        $actions = RecordActionRegistry::forRecord($recordType, $record);
        if (!$actions) return;
        $recordId = (int) ($record->id ?? 0);
        if ($recordId < 1) return;
        foreach ($keys as $key) {
            if (!isset($actions[$key])) continue;
            $definition = $actions[$key];
            if (!current_user_can((string) $definition['capability']) || !current_user_can((string) $definition['record_capability'])) continue;
            self::renderAction((string) $key, $definition, $recordType, $recordId, $page, $record);
        }
        self::script();
    }

    public static function renderNotices(): void {
        if (!isset($_GET['dzn_record_action_notice'])) return;
        $message = sanitize_text_field(wp_unslash((string) $_GET['dzn_record_action_notice']));
        if ($message === '') return;
        $error = (string) ($_GET['dzn_record_action_error'] ?? '') === '1';
        echo '<div class="notice ' . esc_attr($error ? 'notice-error' : 'notice-success') . ' dzn-record-action-notice" role="' . esc_attr($error ? 'alert' : 'status') . '"><p>' . esc_html($message) . '</p></div>';
    }

    /** @param array<string, mixed> $definition */
    private static function renderAction(string $key, array $definition, string $recordType, int $recordId, string $page, object $record): void {
        $safeKey = sanitize_key($key);
        $dialogId = 'dzn-record-action-' . $safeKey . '-' . $recordId;
        $titleId = $dialogId . '-title';
        $descriptionId = $dialogId . '-description';
        $token = RecordActionToken::issue($key, $recordType, $recordId, $page, get_current_user_id());
        echo '<button type="button" class="button dzn-record-action-trigger" data-dzn-record-action-open="' . esc_attr($dialogId) . '">' . esc_html((string) $definition['label']) . '</button>';
        echo '<dialog id="' . esc_attr($dialogId) . '" class="dzn-record-action-dialog" data-dzn-record-action-dialog aria-labelledby="' . esc_attr($titleId) . '" aria-describedby="' . esc_attr($descriptionId) . '" aria-modal="true">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dzn_record_action_' . $key);
        echo '<input type="hidden" name="action" value="dzn_record_action">';
        echo '<input type="hidden" name="action_key" value="' . esc_attr($key) . '">';
        echo '<input type="hidden" name="record_type" value="' . esc_attr($recordType) . '">';
        echo '<input type="hidden" name="record_id" value="' . esc_attr((string) $recordId) . '">';
        echo '<input type="hidden" name="page" value="' . esc_attr($page) . '">';
        echo '<input type="hidden" name="idempotency_key" value="' . esc_attr($token['idempotency_key']) . '">';
        echo '<input type="hidden" name="issued_at" value="' . esc_attr((string) $token['issued_at']) . '">';
        echo '<input type="hidden" name="context_token" value="' . esc_attr($token['context_token']) . '">';
        echo '<div class="dzn-record-action-dialog__header"><h2 id="' . esc_attr($titleId) . '">' . esc_html((string) $definition['label']) . '</h2><button type="button" class="button-link dzn-record-action-dialog__close" data-dzn-record-action-close aria-label="' . esc_attr__('Close', 'delnavazan-platform') . '">×</button></div>';
        echo '<div class="dzn-record-action-dialog__body">';
        echo '<p id="' . esc_attr($descriptionId) . '">' . esc_html((string) $definition['description']) . '</p>';
        if (!empty($definition['consequence'])) echo '<div class="dzn-record-action-consequence" role="note"><strong>What changes:</strong> ' . esc_html((string) $definition['consequence']) . '</div>';
        self::renderFields(RecordActionRegistry::fieldsFor($key, $record), $dialogId);
        if (!empty($definition['require_confirmation'])) {
            echo '<p class="dzn-record-action-confirmation"><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html((string) ($definition['confirmation'] ?? 'I confirm this action.')) . '</label></p>';
        }
        echo '</div><div class="dzn-record-action-dialog__footer"><button type="button" class="button" data-dzn-record-action-close>' . esc_html__('Cancel', 'delnavazan-platform') . '</button><button type="submit" class="button button-primary">' . esc_html((string) $definition['submit_label']) . '</button></div>';
        echo '</form></dialog>';
    }

    /** @param array<int, array<string, mixed>> $fields */
    private static function renderFields(array $fields, string $dialogId): void {
        foreach ($fields as $field) {
            $name = sanitize_key((string) ($field['name'] ?? ''));
            if ($name === '') continue;
            $fieldId = $dialogId . '-field-' . $name;
            $label = (string) ($field['label'] ?? $name);
            $type = (string) ($field['type'] ?? 'text');
            $required = !empty($field['required']);
            $value = (string) ($field['value'] ?? '');
            $description = (string) ($field['description'] ?? '');
            echo '<p><label for="' . esc_attr($fieldId) . '">' . esc_html($label) . '</label>';
            if ($type === 'textarea') {
                echo '<textarea id="' . esc_attr($fieldId) . '" name="' . esc_attr($name) . '" rows="4"' . ($required ? ' required' : '') . '>' . esc_textarea($value) . '</textarea>';
            } elseif ($type === 'select') {
                echo '<select id="' . esc_attr($fieldId) . '" name="' . esc_attr($name) . '"' . ($required ? ' required' : '') . '>';
                foreach ((array) ($field['options'] ?? array()) as $optionValue => $optionLabel) {
                    echo '<option value="' . esc_attr((string) $optionValue) . '"' . selected($value, (string) $optionValue, false) . '>' . esc_html((string) $optionLabel) . '</option>';
                }
                echo '</select>';
            } elseif ($type === 'checkbox') {
                echo '<input id="' . esc_attr($fieldId) . '" type="checkbox" name="' . esc_attr($name) . '" value="1"' . checked($value, '1', false) . ($required ? ' required' : '') . '>';
            } else {
                echo '<input id="' . esc_attr($fieldId) . '" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . ($required ? ' required' : '') . '>';
            }
            if ($description !== '') echo '<br><span class="description">' . esc_html($description) . '</span>';
            echo '</p>';
        }
    }

    private static function script(): void {
        if (self::$scriptPrinted) return;
        self::$scriptPrinted = true;
        echo '<script>(function(){if(window.dznRecordActionDialogs)return;window.dznRecordActionDialogs=true;document.addEventListener("click",function(event){var trigger=event.target.closest("[data-dzn-record-action-open]");if(trigger){var dialog=document.getElementById(trigger.getAttribute("data-dzn-record-action-open"));if(dialog){event.preventDefault();if(typeof dialog.showModal==="function")dialog.showModal();else dialog.setAttribute("open","");}return;}var close=event.target.closest("[data-dzn-record-action-close]");if(close){var owner=close.closest("dialog");if(owner){event.preventDefault();if(typeof owner.close==="function")owner.close();else owner.removeAttribute("open");}return;}if(event.target.matches("dialog[data-dzn-record-action-dialog]")){var backdrop=event.target;if(typeof backdrop.close==="function")backdrop.close();else backdrop.removeAttribute("open");}});})();</script>';
    }
}
