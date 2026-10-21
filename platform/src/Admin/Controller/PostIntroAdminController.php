<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\PostIntroPreparationService;
use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository;
use Delnavazan\Platform\Integrations\PostIntroActivationWorker;

/** Exception-only surface for post-intro continuation automation. */
final class PostIntroAdminController {
    public const CAPABILITY = 'dzn_manage_post_intro_automation';
    public const MENU_SLUG = 'dzn-post-intro';
    public const ACTION = 'dzn_post_intro_admin_command';

    public static function register(): void {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'command'));
    }

    public static function menu(): void {
        if (function_exists('add_submenu_page')) {
            add_submenu_page(Menu::ROOT_SLUG, 'Post-intro automation', 'Post-intro automation', self::CAPABILITY, self::MENU_SLUG, array(__CLASS__, 'render'));
        }
    }

    public static function render(): void {
        if (!current_user_can(self::CAPABILITY)) wp_die(esc_html__('Unauthorized', 'delnavazan-platform'), '', array('response' => 403));
        $repository = new PostIntroRepository();
        $preparations = $repository->exceptionPreparations();
        $jobs = $repository->failedJobs();
        $pending = $repository->pendingOutcomeConfirmations();
        $status = sanitize_key((string) ($_GET['post_intro_admin'] ?? ''));
        ?>
        <div class="wrap">
            <h1>Post-intro automation</h1>
            <?php if ($status !== '') : ?><div class="notice notice-info"><p><?php echo esc_html($status); ?></p></div><?php endif; ?>
            <p>Only genuine exceptions are listed here. Canonical Lesson, Enrolment, Term, payment, and Teacher-assignment truth must be repaired in its owning authority before retrying.</p>

            <h2>Preparation exceptions</h2>
            <table class="widefat striped"><thead><tr><th>Booking</th><th>State</th><th>Reason</th><th>Updated</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($preparations as $row) : ?>
                <tr>
                    <td><?php echo esc_html((string) $row->booking_request_id); ?></td>
                    <td><?php echo esc_html((string) $row->state); ?></td>
                    <td><code><?php echo esc_html((string) $row->exception_code); ?></code><br><?php echo esc_html((string) $row->exception_detail); ?></td>
                    <td><?php echo esc_html((string) $row->updated_at); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                            <input type="hidden" name="operation" value="retry_preparation">
                            <input type="hidden" name="preparation_id" value="<?php echo esc_attr((string) $row->id); ?>">
                            <?php wp_nonce_field(self::ACTION); ?>
                            <button class="button">Retry preparation</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$preparations) : ?><tr><td colspan="5">No preparation exceptions.</td></tr><?php endif; ?>
            </tbody></table>

            <h2>Activation jobs</h2>
            <table class="widefat striped"><thead><tr><th>Purchase</th><th>Preparation</th><th>State</th><th>Attempts</th><th>Last error</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($jobs as $row) : ?>
                <tr>
                    <td><?php echo esc_html((string) $row->purchase_id); ?></td>
                    <td><?php echo esc_html((string) $row->preparation_id); ?></td>
                    <td><?php echo esc_html((string) $row->state); ?></td>
                    <td><?php echo esc_html((string) $row->attempt_count); ?></td>
                    <td><code><?php echo esc_html((string) $row->last_error_code); ?></code><br><?php echo esc_html((string) $row->last_error_detail); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                            <input type="hidden" name="operation" value="retry_activation">
                            <input type="hidden" name="job_id" value="<?php echo esc_attr((string) $row->id); ?>">
                            <?php wp_nonce_field(self::ACTION); ?>
                            <button class="button">Retry activation</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$jobs) : ?><tr><td colspan="6">No failed activation jobs.</td></tr><?php endif; ?>
            </tbody></table>

            <h2>Intro outcomes awaiting a Teacher</h2>
            <p>Use this only when the Teacher cannot record the outcome in the Teacher Portal.</p>
            <table class="widefat striped"><thead><tr><th>Confirmation</th><th>Student</th><th>Teacher</th><th>Ended (UTC)</th><th>Record outcome</th></tr></thead><tbody>
            <?php foreach ($pending as $row) : ?>
                <tr>
                    <td><?php echo esc_html((string) $row->confirmation_uid); ?></td>
                    <td><?php echo esc_html((string) $row->student_name); ?></td>
                    <td><?php echo esc_html((string) $row->teacher_name); ?></td>
                    <td><?php echo esc_html((string) $row->ends_at_utc); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                            <input type="hidden" name="operation" value="record_outcome">
                            <input type="hidden" name="confirmation_uid" value="<?php echo esc_attr((string) $row->confirmation_uid); ?>">
                            <select name="outcome">
                                <option value="delivered">Delivered</option>
                                <option value="student_no_show">Student no-show</option>
                                <option value="teacher_cancelled">Teacher cancelled</option>
                                <option value="student_cancelled">Student cancelled</option>
                            </select>
                            <?php wp_nonce_field(self::ACTION); ?>
                            <button class="button">Record</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pending) : ?><tr><td colspan="5">No outcomes awaiting a Teacher.</td></tr><?php endif; ?>
            </tbody></table>
        </div>
        <?php
    }

    public static function command(): void {
        if (!current_user_can(self::CAPABILITY)) wp_die(esc_html__('Unauthorized', 'delnavazan-platform'), '', array('response' => 403));
        check_admin_referer(self::ACTION);
        $operation = sanitize_key((string) ($_POST['operation'] ?? ''));
        try {
            if ($operation === 'retry_preparation') {
                (new PostIntroPreparationService())->retry((int) ($_POST['preparation_id'] ?? 0));
            } elseif ($operation === 'retry_activation') {
                PostIntroActivationWorker::requeue((int) ($_POST['job_id'] ?? 0));
                PostIntroActivationWorker::run();
            } elseif ($operation === 'record_outcome') {
                (new PostIntroPreparationService())->recordAdministrativeOutcome(
                    sanitize_text_field((string) ($_POST['confirmation_uid'] ?? '')),
                    (int) get_current_user_id(),
                    sanitize_key((string) ($_POST['outcome'] ?? '')),
                    sanitize_textarea_field((string) ($_POST['note'] ?? ''))
                );
            } else {
                throw new \InvalidArgumentException('unsupported_operation');
            }
            self::redirect('done');
        } catch (\Throwable $error) {
            self::redirect(preg_match('/^[a-z0-9_]{3,64}$/D', trim($error->getMessage())) === 1 ? trim($error->getMessage()) : 'operation_failed');
        }
    }

    private static function redirect(string $status): void {
        wp_safe_redirect(add_query_arg('post_intro_admin', $status, admin_url('admin.php?page=' . self::MENU_SLUG)), 303);
        exit;
    }
}
