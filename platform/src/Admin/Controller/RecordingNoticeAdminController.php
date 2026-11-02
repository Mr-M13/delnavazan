<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\RecordingNoticePublicationService;

/**
 * Administrator-only publication surface for recording and research notices.
 *
 * The browser supplies only an operation, an audience, a language and the approved body text.
 * The version number, UID, publication state, actor, digest and timestamps are derived server-side.
 */
final class RecordingNoticeAdminController {
    public const CAPABILITY = 'manage_options';
    public const MENU_SLUG = 'dzn-recording-notices';
    public const ACTION = 'dzn_recording_notice_command';

    private const AUDIENCE_LABELS = array(
        'teacher_terms' => 'Teacher recording terms',
        'student_lesson_recording' => 'Student lesson recording',
        'student_research_model_training' => 'Student research and model training',
    );
    private const LANGUAGE_LABELS = array('en' => 'English', 'fa' => 'Persian (فارسی)');

    public static function register(): void {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'command'));
    }

    public static function menu(): void {
        if (!function_exists('add_submenu_page')) return;
        add_submenu_page(Menu::ROOT_SLUG, 'Recording notices', 'Recording notices', self::CAPABILITY, self::MENU_SLUG, array(__CLASS__, 'render'));
    }

    public static function command(): never {
        if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) wp_die('Method not allowed.', 'Delnavazan', array('response' => 405));
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden', 'Delnavazan', array('response' => 403));
        check_admin_referer(self::ACTION);
        $operation = sanitize_key((string) wp_unslash($_POST['operation'] ?? ''));
        $audience = sanitize_key((string) wp_unslash($_POST['audience'] ?? ''));
        $language = sanitize_key((string) wp_unslash($_POST['language'] ?? ''));
        $service = new RecordingNoticePublicationService();
        try {
            if ('publish' === $operation) {
                $result = $service->publish($audience, $language, (string) wp_unslash($_POST['body_text'] ?? ''), get_current_user_id());
                self::go('published', array('dzn_version' => (int) $result['version'], 'dzn_audience' => $audience, 'dzn_language' => $language));
            }
            if ('withdraw' === $operation) {
                $count = $service->withdrawAll($audience, $language, get_current_user_id());
                self::go('withdrawn', array('dzn_count' => $count, 'dzn_audience' => $audience, 'dzn_language' => $language));
            }
            self::go('invalid_operation');
        } catch (\Throwable $error) {
            $reason = (string) $error->getMessage();
            self::go(preg_match('/^[a-z0-9_]{3,64}$/D', $reason) === 1 ? $reason : 'recording_notice_unavailable');
        }
    }

    private static function go(string $status, array $extra = array()): never {
        wp_safe_redirect(add_query_arg(array_merge(array('page' => self::MENU_SLUG, 'dzn_notice' => $status), $extra), admin_url('admin.php')));
        exit;
    }

    public static function render(): void {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden', 'Delnavazan', array('response' => 403));
        $service = new RecordingNoticePublicationService();
        $notice = sanitize_key((string) ($_GET['dzn_notice'] ?? ''));
        $audience = sanitize_key((string) ($_GET['dzn_audience'] ?? ''));
        $language = sanitize_key((string) ($_GET['dzn_language'] ?? ''));
        $version = absint($_GET['dzn_version'] ?? 0);
        $count = absint($_GET['dzn_count'] ?? 0);
        ?>
        <div class="wrap">
            <h1>Recording notices</h1>
            <p>Publish an owner- and legal-approved notice for one audience and language. Publishing always creates a new immutable version and never edits an earlier one. Teacher acceptance and Student choices become available only for the newest published version of each language.</p>
            <?php self::renderNotice($notice, $audience, $language, $version, $count); ?>

            <h2>Publish a new version</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                <input type="hidden" name="operation" value="publish">
                <?php wp_nonce_field(self::ACTION); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="dzn-recording-audience">Audience</label></th>
                        <td>
                            <select id="dzn-recording-audience" name="audience" required>
                                <?php foreach (self::AUDIENCE_LABELS as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dzn-recording-language">Language</label></th>
                        <td>
                            <select id="dzn-recording-language" name="language" required>
                                <?php foreach (self::LANGUAGE_LABELS as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dzn-recording-body">Approved notice text</label></th>
                        <td>
                            <textarea id="dzn-recording-body" name="body_text" rows="12" class="large-text code" required></textarea>
                            <p class="description">Paste the exact approved text. Safe post HTML is allowed; the stored digest is calculated from the stored text.</p>
                        </td>
                    </tr>
                </table>
                <p><button type="submit" class="button button-primary">Publish new version</button></p>
            </form>

            <h2>Current publications</h2>
            <table class="widefat striped">
                <thead><tr><th>Audience</th><th>Language</th><th>Current version</th><th>Published</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach (self::AUDIENCE_LABELS as $audience_key => $audience_label) : ?>
                    <?php foreach (self::LANGUAGE_LABELS as $language_key => $language_label) : ?>
                        <?php
                        try { $current = $service->current($audience_key, $language_key); }
                        catch (\Throwable) { $current = null; }
                        ?>
                        <tr>
                            <td><?php echo esc_html($audience_label); ?></td>
                            <td><?php echo esc_html($language_label); ?></td>
                            <td>
                                <?php if ($current) : ?>
                                    <strong>version <?php echo esc_html((string) ($current['version_number'] ?? '')); ?></strong>
                                    <details>
                                        <summary>Preview</summary>
                                        <div><?php echo wp_kses_post(wpautop((string) ($current['body_text'] ?? ''))); ?></div>
                                    </details>
                                <?php else : ?>
                                    <em>Not published</em>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $current ? esc_html((string) ($current['published_at'] ?? '')) : '—'; ?></td>
                            <td>
                                <?php if ($current) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Withdraw every published version for this audience and language? Acceptance and choices will become unavailable.');">
                                        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                                        <input type="hidden" name="operation" value="withdraw">
                                        <input type="hidden" name="audience" value="<?php echo esc_attr($audience_key); ?>">
                                        <input type="hidden" name="language" value="<?php echo esc_attr($language_key); ?>">
                                        <?php wp_nonce_field(self::ACTION); ?>
                                        <button type="submit" class="button">Withdraw all published versions</button>
                                    </form>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Recent publication history</h2>
            <table class="widefat striped">
                <thead><tr><th>Audience</th><th>Language</th><th>Version</th><th>State</th><th>Published</th><th>Withdrawn</th><th>UID</th></tr></thead>
                <tbody>
                <?php $history = $service->recent(30); ?>
                <?php if (!$history) : ?>
                    <tr><td colspan="7"><em>No notice version has been recorded.</em></td></tr>
                <?php endif; ?>
                <?php foreach ($history as $row) : ?>
                    <tr>
                        <td><?php echo esc_html(self::AUDIENCE_LABELS[(string) $row->audience_code] ?? (string) $row->audience_code); ?></td>
                        <td><?php echo esc_html(self::LANGUAGE_LABELS[(string) $row->language_code] ?? (string) $row->language_code); ?></td>
                        <td><?php echo esc_html((string) $row->version_number); ?></td>
                        <td><?php echo esc_html((string) $row->publication_state . ($row->withdrawn_at ? ' · withdrawn' : '')); ?></td>
                        <td><?php echo esc_html((string) ($row->published_at ?? '—')); ?></td>
                        <td><?php echo esc_html((string) ($row->withdrawn_at ?? '—')); ?></td>
                        <td><code><?php echo esc_html((string) $row->uid); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function renderNotice(string $notice, string $audience, string $language, int $version, int $count): void {
        $audienceLabel = self::AUDIENCE_LABELS[$audience] ?? $audience;
        $languageLabel = self::LANGUAGE_LABELS[$language] ?? $language;
        if ('published' === $notice) {
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf('Published %s · %s · version %d.', $audienceLabel, $languageLabel, $version)) . '</p></div>';
            return;
        }
        if ('withdrawn' === $notice) {
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf('Withdrew %d published version(s) for %s · %s.', $count, $audienceLabel, $languageLabel)) . '</p></div>';
            return;
        }
        $errors = array(
            'recording_notice_body_required' => 'Enter the approved notice text. The text cannot be empty.',
            'recording_notice_actor_required' => 'The current administrator account could not be resolved.',
            'recording_notice_audience_invalid' => 'Choose a supported audience.',
            'recording_decision_language_invalid' => 'Choose a supported language.',
            'recording_notice_persistence_failed' => 'The notice was not published. No version was created; try again.',
            'invalid_operation' => 'The requested operation was not accepted.',
            'recording_notice_unavailable' => 'The notice command was not accepted. No version was created.',
        );
        if (isset($errors[$notice])) {
            echo '<div class="notice notice-error"><p>' . esc_html($errors[$notice]) . '</p></div>';
        }
    }
}
