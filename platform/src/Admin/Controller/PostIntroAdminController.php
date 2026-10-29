<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\{PostIntroFulfilmentReadService,PostIntroPreparationService,PostIntroRecoveryService,PostIntroSystemActor};
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
        $fulfilmentService = new PostIntroFulfilmentReadService();
        $recentPreparations = $repository->recentPreparations(20);
        $refusals = $fulfilmentService->webhookRefusals(8);
        $inspected = null;
        $inspectId = absint($_GET['enrolment_id'] ?? 0);
        $status = sanitize_key((string) ($_GET['post_intro_admin'] ?? ''));
        if ($status === 'inspect' && $inspectId > 0) {
            try { $inspected = (new PostIntroRecoveryService())->inspect($inspectId); }
            catch (\Throwable $error) { $inspected = array('error' => $error->getMessage()); }
        }
        $actorState = 'unconfigured';
        try {
            $actorState = 'ready (#' . PostIntroSystemActor::ensure() . ')';
        } catch (\Throwable $error) {
            $actorState = preg_match('/^[a-z0-9_]{3,64}$/D', trim($error->getMessage())) === 1 ? trim($error->getMessage()) : 'invalid';
        }
        ?>
        <div class="wrap">
            <h1>Post-intro automation</h1>
            <?php if ($status !== '') : ?><div class="notice notice-info"><p><?php echo esc_html($status); ?></p></div><?php endif; ?>
            <p>Only genuine exceptions are listed here. Canonical Lesson, Enrolment, Term, payment, and Teacher-assignment truth must be repaired in its owning authority before retrying.</p>

            <h2>Automation identity</h2>
            <p>Status: <code><?php echo esc_html($actorState); ?></code></p>
            <?php if (current_user_can('manage_options')) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                    <input type="hidden" name="operation" value="provision_actor">
                    <?php wp_nonce_field(self::ACTION); ?>
                    <button class="button">Provision or repair automation identity</button>
                </form>
                <p>This explicit administrator action creates or repairs the dedicated role and identity. Ordinary Teacher, Student, worker and webhook requests never do this.</p>
            <?php endif; ?>

            <h2>Paid enrolment fulfilment</h2>
            <p>Inspect the authoritative payment, Term, Teacher Assignment and Lesson state before recovering. Inspection is read-only. Recovery uses the existing Stripe reconciliation, settlement hook and activation worker; it never charges again or invents a schedule.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                <?php wp_nonce_field(self::ACTION); ?>
                <p><label>Enrolment ID <input name="enrolment_id" type="number" min="1" required></label></p>
                <p><button class="button" name="operation" value="inspect_fulfilment">Inspect (dry run)</button>
                <?php if (current_user_can('dzn_manage_payment_execution')) : ?>
                    <button class="button button-primary" name="operation" value="recover_fulfilment" onclick="return confirm('Recover this paid enrolment through the existing reconciliation and activation services?')">Recover paid enrolment</button>
                <?php endif; ?></p>
            </form>
            <?php if (is_array($inspected)) : ?>
                <?php if (isset($inspected['error'])) : ?>
                    <div class="notice notice-error inline"><p>Inspection failed: <code><?php echo esc_html((string) $inspected['error']); ?></code></p></div>
                <?php else : ?>
                    <h3>Dry-run · Enrolment #<?php echo esc_html((string) ($inspected['enrolment_id'] ?? $inspectId)); ?></h3>
                    <table class="widefat striped"><tbody>
                        <tr><th>Fulfilment state</th><td><strong><?php echo esc_html(self::fulfilmentLabel((string) ($inspected['fulfilment_state'] ?? 'unknown'))); ?></strong><br><code><?php echo esc_html((string) ($inspected['fulfilment_state'] ?? '')); ?></code></td></tr>
                        <tr><th>Preparation</th><td><?php echo esc_html((string) ($inspected['preparation_state'] ?? '')); ?> · #<?php echo esc_html((string) ($inspected['preparation_id'] ?? 0)); ?></td></tr>
                        <tr><th>Payment verified</th><td><?php echo !empty($inspected['payment_verified']) ? 'Yes' : 'No'; ?></td></tr>
                        <tr><th>Offer / purchase / settlement</th><td>#<?php echo esc_html((string) ($inspected['offer_id'] ?? 0)); ?> / #<?php echo esc_html((string) ($inspected['purchase_id'] ?? 0)); ?> / #<?php echo esc_html((string) ($inspected['settlement_id'] ?? 0)); ?></td></tr>
                        <tr><th>Checkout</th><td><code><?php echo esc_html((string) ($inspected['checkout_session_state'] ?? '')); ?></code> · <?php echo esc_html((string) ($inspected['checkout_session_uid'] ?? '')); ?></td></tr>
                        <tr><th>Activation job</th><td><code><?php echo esc_html((string) ($inspected['job_state'] ?? '')); ?></code> · #<?php echo esc_html((string) ($inspected['job_id'] ?? 0)); ?><?php if (!empty($inspected['job_last_error_code'])) : ?> · <code><?php echo esc_html((string) $inspected['job_last_error_code']); ?></code><?php endif; ?></td></tr>
                        <tr><th>Term / assignment</th><td>#<?php echo esc_html((string) ($inspected['term_id'] ?? 0)); ?> / #<?php echo esc_html((string) ($inspected['assignment_id'] ?? 0)); ?></td></tr>
                        <tr><th>Lessons</th><td><?php echo esc_html((string) ($inspected['lesson_count'] ?? 0)); ?> / <?php echo esc_html((string) ($inspected['expected_lesson_count'] ?? 0)); ?></td></tr>
                        <tr><th>Schedule</th><td><?php echo esc_html((string) ($inspected['weekday'] ?? '')); ?> · <?php echo esc_html((string) ($inspected['local_wall_time'] ?? '')); ?> · <?php echo esc_html((string) ($inspected['schedule_timezone'] ?? '')); ?></td></tr>
                        <tr><th>Blockers</th><td><?php echo esc_html(implode(', ', (array) ($inspected['blockers'] ?? array()))); ?></td></tr>
                        <tr><th>Planning basis</th><td><code><?php echo esc_html((string) ($inspected['planning_basis'] ?? 'none')); ?></code></td></tr>
                        <tr><th>Planned records</th><td><?php echo esc_html(implode(', ', (array) ($inspected['planned'] ?? array()))); ?></td></tr>
                        <tr><th>Recovery actions</th><td><?php echo esc_html(implode(', ', (array) ($inspected['recovery_actions'] ?? array()))); ?></td></tr>
                    </tbody></table>
                    <?php if (!empty($inspected['recent_webhook_refusals'])) : ?>
                        <h4>Recent refused webhook receipts</h4>
                        <table class="widefat striped"><thead><tr><th>Provider</th><th>State</th><th>Reason</th><th>Bytes</th><th>Received</th></tr></thead><tbody>
                        <?php foreach ((array) $inspected['recent_webhook_refusals'] as $refusal) : ?>
                            <tr><td><?php echo esc_html((string) ($refusal->provider_key ?? '')); ?></td><td><?php echo esc_html((string) ($refusal->verification_state ?? '')); ?></td><td><code><?php echo esc_html((string) ($refusal->refusal_reason_code ?? '')); ?></code></td><td><?php echo esc_html((string) ($refusal->body_bytes ?? '')); ?></td><td><?php echo esc_html((string) ($refusal->received_at ?? '')); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody></table>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

            <h3>Recent fulfilment states</h3>
            <table class="widefat striped"><thead><tr><th>Booking</th><th>Enrolment</th><th>Preparation</th><th>Payment</th><th>Fulfilment</th><th>Term / lessons</th><th>Updated</th><th>Inspect</th></tr></thead><tbody>
            <?php foreach ($recentPreparations as $row) : ?>
                <?php
                $enrolmentId = (int) ($row->enrolment_id ?? 0);
                $summary = null;
                if ($enrolmentId > 0) {
                    try { $summary = $fulfilmentService->forEnrolment($enrolmentId); } catch (\Throwable) { $summary = null; }
                }
                ?>
                <tr>
                    <td><?php echo esc_html((string) $row->booking_request_id); ?></td>
                    <td><?php echo esc_html((string) ($enrolmentId ?: '—')); ?></td>
                    <td><code><?php echo esc_html((string) $row->state); ?></code></td>
                    <td><?php echo $summary && !empty($summary['payment_verified']) ? 'Verified' : 'Not verified'; ?></td>
                    <td><?php echo esc_html(self::fulfilmentLabel((string) ($summary['fulfilment_state'] ?? 'unknown'))); ?><br><code><?php echo esc_html((string) ($summary['fulfilment_state'] ?? '')); ?></code></td>
                    <td><?php echo esc_html((string) ($summary['term_id'] ?? 0)); ?> / <?php echo esc_html((string) ($summary['lesson_count'] ?? 0)); ?> of <?php echo esc_html((string) ($summary['expected_lesson_count'] ?? 0)); ?></td>
                    <td><?php echo esc_html((string) $row->updated_at); ?></td>
                    <td><?php if ($enrolmentId > 0) : ?><a class="button" href="<?php echo esc_url(add_query_arg(array('page' => self::MENU_SLUG, 'post_intro_admin' => 'inspect', 'enrolment_id' => $enrolmentId), admin_url('admin.php'))); ?>">Inspect</a><?php else : ?>—<?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentPreparations) : ?><tr><td colspan="8">No post-intro preparations.</td></tr><?php endif; ?>
            </tbody></table>

            <h3>Refused provider webhook receipts</h3>
            <?php if ($refusals) : ?>
                <table class="widefat striped"><thead><tr><th>Provider</th><th>State</th><th>Reason</th><th>Bytes</th><th>Received</th></tr></thead><tbody>
                <?php foreach ($refusals as $refusal) : ?>
                    <tr><td><?php echo esc_html((string) $refusal->provider_key); ?></td><td><?php echo esc_html((string) $refusal->verification_state); ?></td><td><code><?php echo esc_html((string) $refusal->refusal_reason_code); ?></code></td><td><?php echo esc_html((string) $refusal->body_bytes); ?></td><td><?php echo esc_html((string) $refusal->received_at); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php if (in_array('signature_invalid', array_map(static fn($row): string => (string) $row->refusal_reason_code, $refusals), true)) : ?>
                    <div class="notice notice-warning inline"><p><strong>Stripe webhook signature is failing.</strong> Verify the signing secret configured for the production webhook endpoint, or recover the paid Checkout through the reconciliation action above. Do not disable signature verification.</p></div>
                <?php endif; ?>
            <?php else : ?>
                <p>No refused provider webhook receipts are recorded.</p>
            <?php endif; ?>

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
            if ($operation === 'provision_actor') {
                if (!current_user_can('manage_options')) throw new \RuntimeException('Unauthorized');
                PostIntroSystemActor::provision();
            } elseif ($operation === 'retry_preparation') {
                (new PostIntroPreparationService())->retry((int) ($_POST['preparation_id'] ?? 0));
            } elseif ($operation === 'retry_activation') {
                PostIntroActivationWorker::requeue((int) ($_POST['job_id'] ?? 0));
                PostIntroActivationWorker::run();
            } elseif ($operation === 'inspect_fulfilment') {
                $enrolmentId = absint($_POST['enrolment_id'] ?? 0);
                if ($enrolmentId < 1) throw new \InvalidArgumentException('enrolment_id_required');
                self::redirect('inspect', array('enrolment_id' => $enrolmentId));
            } elseif ($operation === 'recover_fulfilment') {
                $result = (new PostIntroRecoveryService())->recover(absint($_POST['enrolment_id'] ?? 0));
                self::redirect('recovered_' . sanitize_key((string) ($result['state'] ?? 'done')));
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

    private static function fulfilmentLabel(string $state): string {
        return match ($state) {
            'completed' => 'Provisioning completed',
            'payment_received', 'provisioning' => 'Payment received — provisioning pending',
            'partial' => 'Provisioning partial — retry available',
            'awaiting_next_instalment' => 'Awaiting second instalment — funded Lessons are active',
            'failed' => 'Provisioning failed — action required',
            'awaiting_payment' => 'Awaiting verified payment',
            'invited' => 'Student action ready',
            'not_applicable' => 'No post-intro preparation',
            default => 'Unknown / review required',
        };
    }

    private static function redirect(string $status, array $extra = array()): void {
        wp_safe_redirect(add_query_arg(array_merge(array('post_intro_admin' => $status), $extra), admin_url('admin.php?page=' . self::MENU_SLUG)), 303);
        exit;
    }
}
