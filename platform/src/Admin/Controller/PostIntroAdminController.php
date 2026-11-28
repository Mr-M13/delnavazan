<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\{PostIntroAttentionReadService,PostIntroFeedbackRule,PostIntroFulfilmentReadService,PostIntroPreparationService,PostIntroRecoveryService,PostIntroSystemActor};
use Delnavazan\Platform\Core\Infrastructure\Repository\{PostIntroFeedbackRepository,PostIntroRepository};
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
        // One merged list feeds both this page and the Overview card. Each row carries the single
        // action that can move it forward; terminal or already-provisioned preparations are excluded
        // by the repository before this merge.
        $preparations = $repository->exceptionPreparations();
        $jobs = $repository->failedJobs();
        $paidWithoutJob = $repository->paidPreparationsWithoutActivationJob();
        $attention = PostIntroAttentionReadService::compose($preparations, $jobs, $paidWithoutJob);
        $pending = $repository->pendingOutcomeConfirmations();
        $focusPreparation = absint($_GET['preparation_id'] ?? 0);
        $canOpenWorkspace = current_user_can(Menu::ENROLMENT_WORKSPACE_CAP);
        $fulfilmentService = new PostIntroFulfilmentReadService();
        $recentPreparations = $repository->recentPreparations(20);
        $feedbackRepository = new PostIntroFeedbackRepository();
        $feedbackFilters = array(
            'status' => sanitize_key((string) ($_GET['feedback_status'] ?? '')),
            'reason' => sanitize_key((string) ($_GET['feedback_reason'] ?? '')),
            'contact' => sanitize_key((string) ($_GET['feedback_contact'] ?? '')),
        );
        $feedbackRows = $feedbackRepository->recent($feedbackFilters, 50);
        $feedbackStaff = get_users(array('capability' => self::CAPABILITY, 'orderby' => 'display_name', 'order' => 'ASC'));
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
            <?php if ($status !== '') : ?><div class="notice notice-info"><p><?php echo esc_html(self::statusMessage($status)); ?></p></div><?php endif; ?>
            <p>Only genuine exceptions are listed here. Canonical Lesson, Enrolment, Term, payment, and Teacher-assignment truth must be repaired in its owning authority before retrying.</p>

            <h2 id="dzn-post-intro-attention">Needs attention now</h2>
            <p>Only preparations whose academic provisioning is still incomplete and that have a resolvable action are listed. A delivered outcome that already owns a canonical Term and Teacher Assignment is complete; a non-delivered introduction is a closed outcome, not a retry.</p>
            <?php if ($attention) : ?>
                <table class="widefat striped dzn-post-intro-attention"><thead><tr><th>Booking</th><th>Learner</th><th>Preparation</th><th>Reason</th><th>Updated (UTC)</th><th>Action</th></tr></thead><tbody>
                <?php foreach ($attention as $row) : ?>
                    <?php $rowId = (int) $row['preparation_id']; ?>
                    <tr id="dzn-post-intro-preparation-<?php echo esc_attr((string) $rowId); ?>"<?php echo $focusPreparation === $rowId ? ' class="is-focused"' : ''; ?>>
                        <td><?php $bookingLabel = (string) ($row['reference'] !== '' ? $row['reference'] : ('Request ' . (int) $row['booking_request_id'])); ?><?php if ($canOpenWorkspace) : ?><a href="<?php echo esc_url(add_query_arg(array('page' => 'dzn-enrolment-workspace', 'request_id' => (int) $row['booking_request_id']), admin_url('admin.php'))); ?>"><?php echo esc_html($bookingLabel); ?></a><?php else : ?><?php echo esc_html($bookingLabel); ?><?php endif; ?></td>
                        <td><?php echo esc_html((string) $row['learner_name']); ?></td>
                        <td><code><?php echo esc_html((string) $row['state']); ?></code><?php if ((string) $row['enrolment_reference'] !== '') : ?><br><small><?php echo esc_html((string) $row['enrolment_reference']); ?></small><?php endif; ?></td>
                        <td><strong><?php echo esc_html((string) $row['reason_label']); ?></strong><?php if ((string) $row['exception_detail'] !== '') : ?><br><small><?php echo esc_html((string) $row['exception_detail']); ?></small><?php endif; ?></td>
                        <td><?php echo esc_html((string) $row['updated_at']); ?></td>
                        <td>
                            <?php if ((string) $row['action'] === 'retry_preparation') : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                                    <input type="hidden" name="operation" value="retry_preparation">
                                    <input type="hidden" name="preparation_id" value="<?php echo esc_attr((string) $rowId); ?>">
                                    <?php wp_nonce_field(self::ACTION); ?>
                                    <button class="button">Retry preparation</button>
                                </form>
                            <?php elseif ((string) $row['action'] === 'retry_activation') : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                                    <input type="hidden" name="operation" value="retry_activation">
                                    <input type="hidden" name="job_id" value="<?php echo esc_attr((string) $row['job_id']); ?>">
                                    <?php wp_nonce_field(self::ACTION); ?>
                                    <button class="button">Retry activation</button>
                                </form>
                            <?php elseif ((string) $row['action'] === 'queue_activation') : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                                    <input type="hidden" name="operation" value="queue_activation">
                                    <input type="hidden" name="preparation_id" value="<?php echo esc_attr((string) $rowId); ?>">
                                    <?php wp_nonce_field(self::ACTION); ?>
                                    <button class="button">Queue activation</button>
                                </form>
                            <?php endif; ?>
                            <?php if ((int) $row['enrolment_id'] > 0) : ?>
                                <a class="button" href="<?php echo esc_url(add_query_arg(array('page' => self::MENU_SLUG, 'post_intro_admin' => 'inspect', 'enrolment_id' => (int) $row['enrolment_id']), admin_url('admin.php'))); ?>">Inspect</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php else : ?>
                <p class="dzn-all-clear"><span aria-hidden="true">✓</span> No post-intro preparation needs an operator action.</p>
            <?php endif; ?>

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

            <h3>Post-intro feedback and follow-up</h3>
            <p>Students who reached the end of the post-intro hold without enrolling and submitted feedback. Feedback is separate from the canonical continuation decision. Use this list to follow up and resolve the concern; do not contact a student who explicitly declined further contact.</p>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="dzn-feedback-filters">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">
                <label>Status
                    <select name="feedback_status">
                        <option value="">All</option>
                        <option value="unresolved" <?php selected((string) ($feedbackFilters['status'] ?? ''), 'unresolved'); ?>>Unresolved</option>
                        <?php foreach (PostIntroFeedbackRule::FOLLOW_UP_STATUSES as $status_option) : ?>
                            <option value="<?php echo esc_attr($status_option); ?>" <?php selected((string) ($feedbackFilters['status'] ?? ''), $status_option); ?>><?php echo esc_html(ucfirst(str_replace('_', ' ', $status_option))); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Reason
                    <select name="feedback_reason">
                        <option value="">All</option>
                        <?php foreach (PostIntroFeedbackRule::REASONS as $reason_option) : ?>
                            <option value="<?php echo esc_attr($reason_option); ?>" <?php selected((string) ($feedbackFilters['reason'] ?? ''), $reason_option); ?>><?php echo esc_html(self::feedbackLabel($reason_option)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Contact requested
                    <select name="feedback_contact">
                        <option value="">All</option>
                        <option value="1" <?php selected((string) ($feedbackFilters['contact'] ?? ''), '1'); ?>>Yes</option>
                        <option value="0" <?php selected((string) ($feedbackFilters['contact'] ?? ''), '0'); ?>>No</option>
                    </select>
                </label>
                <button class="button">Filter</button>
            </form>
            <table class="widefat striped"><thead><tr><th>Student</th><th>Contact</th><th>Course / Teacher</th><th>Reason</th><th>Continuation</th><th>Follow-up</th><th>Submitted</th></tr></thead><tbody>
            <?php foreach ($feedbackRows as $feedback) : ?>
                <?php
                $studentEmail = trim((string) ($feedback->student_email ?? ''));
                $studentWhatsapp = preg_replace('/\D+/', '', (string) ($feedback->student_whatsapp ?? ''));
                $currentDecision = trim((string) ($feedback->canonical_current_decision ?? '')) !== '' ? (string) $feedback->canonical_current_decision : (string) ($feedback->continuation_decision ?? '');
                $contactRequested = !empty($feedback->contact_requested) || in_array($currentDecision, array('contact_me', 'different_teacher'), true);
                $doNotContact = $currentDecision === 'not_continuing' && !$contactRequested;
                ?>
                <tr>
                    <td><?php echo esc_html((string) ($feedback->student_name ?? '')); ?></td>
                    <td>
                        <?php if ($studentEmail !== '') : ?><a href="<?php echo esc_url('mailto:' . $studentEmail); ?>"><?php echo esc_html($studentEmail); ?></a><?php endif; ?>
                        <?php if ($studentWhatsapp !== '') : ?><br><a href="<?php echo esc_url('https://wa.me/' . $studentWhatsapp); ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a><?php endif; ?>
                        <?php if ($studentEmail === '' && $studentWhatsapp === '') : ?>—<?php endif; ?>
                    </td>
                    <td><?php echo esc_html((string) ($feedback->course_name ?? '')); ?><br><small><?php echo esc_html((string) ($feedback->teacher_name ?? '')); ?></small></td>
                    <td><code><?php echo esc_html((string) $feedback->feedback_reason); ?></code><br><?php echo esc_html(self::feedbackLabel((string) $feedback->feedback_reason)); ?><?php if (trim((string) ($feedback->feedback_comment ?? '')) !== '') : ?><br><small><?php echo esc_html((string) $feedback->feedback_comment); ?></small><?php endif; ?></td>
                    <td>
                        <code><?php echo esc_html((string) $feedback->continuation_choice); ?></code>
                        <?php if ($currentDecision !== '') : ?><br><small><?php echo esc_html($currentDecision); ?></small><?php endif; ?>
                        <br><small><?php echo $contactRequested ? 'Contact requested' : 'No contact requested'; ?></small>
                        <?php if ($doNotContact) : ?><br><strong>Do not contact</strong><?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html((string) $feedback->follow_up_status); ?></code><?php if (!empty($feedback->assigned_staff_name)) : ?><br><small><?php echo esc_html((string) $feedback->assigned_staff_name); ?></small><?php endif; ?><?php if (!empty($feedback->last_contact_at)) : ?><br><small>Last: <?php echo esc_html((string) $feedback->last_contact_at); ?></small><?php endif; ?><?php if (trim((string) ($feedback->outcome ?? '')) !== '') : ?><br><small>Outcome: <?php echo esc_html((string) $feedback->outcome); ?></small><?php endif; ?><?php if (trim((string) ($feedback->follow_up_notes ?? '')) !== '') : ?><br><small><?php echo esc_html((string) $feedback->follow_up_notes); ?></small><?php endif; ?></td>
                    <td><?php echo esc_html((string) $feedback->submitted_at); ?></td>
                </tr>
                <tr class="dzn-feedback-followup-row"><td colspan="7">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                        <input type="hidden" name="operation" value="update_feedback_followup">
                        <input type="hidden" name="feedback_id" value="<?php echo esc_attr((string) $feedback->id); ?>">
                        <input type="hidden" name="feedback_version" value="<?php echo esc_attr((string) $feedback->feedback_version); ?>">
                        <?php wp_nonce_field(self::ACTION); ?>
                        <label>Status
                            <select name="follow_up_status">
                                <?php foreach (PostIntroFeedbackRule::FOLLOW_UP_STATUSES as $status_option) : ?>
                                    <option value="<?php echo esc_attr($status_option); ?>" <?php selected((string) $feedback->follow_up_status, $status_option); ?>><?php echo esc_html(ucfirst(str_replace('_', ' ', $status_option))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Assigned staff
                            <select name="assigned_staff_user_id">
                                <option value="0">Unassigned</option>
                                <?php foreach ($feedbackStaff as $staff_user) : ?>
                                    <option value="<?php echo esc_attr((string) $staff_user->ID); ?>" <?php selected((int) $feedback->assigned_staff_user_id, (int) $staff_user->ID); ?>><?php echo esc_html((string) $staff_user->display_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Last contact date
                            <input type="date" name="last_contact_date" value="<?php echo esc_attr(substr((string) ($feedback->last_contact_at ?? ''), 0, 10)); ?>">
                        </label>
                        <label>Outcome
                            <input type="text" name="outcome" maxlength="255" value="<?php echo esc_attr((string) ($feedback->outcome ?? '')); ?>">
                        </label>
                        <label class="dzn-feedback-notes">Notes
                            <textarea name="follow_up_notes" rows="2" maxlength="5000"><?php echo esc_textarea((string) ($feedback->follow_up_notes ?? '')); ?></textarea>
                        </label>
                        <button class="button button-primary">Save follow-up</button>
                    </form>
                </td></tr>
            <?php endforeach; ?>
            <?php if (!$feedbackRows) : ?><tr><td colspan="7">No feedback matches these filters.</td></tr><?php endif; ?>
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

            <h2 id="dzn-post-intro-outcomes">Intro outcomes awaiting a Teacher</h2>
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
                // A failed/retryable job is requeued first. A queued job that is already overdue is
                // executed directly; requeue refuses it because it was never released as failed.
                $jobId = absint($_POST['job_id'] ?? 0);
                $job = (new PostIntroRepository())->job($jobId);
                if (!$job) throw new \InvalidArgumentException('post_intro_job_not_found');
                if (in_array((string) $job->state, array('failed', 'retryable'), true)) PostIntroActivationWorker::requeue($jobId);
                $run = PostIntroActivationWorker::runJob($jobId);
                $runState = sanitize_key((string) ($run['state'] ?? ''));
                self::redirect($runState === 'completed' ? 'done' : 'activation_' . ($runState !== '' ? $runState : 'failed'));
            } elseif ($operation === 'queue_activation') {
                $result = (new PostIntroRecoveryService())->queueActivation(absint($_POST['preparation_id'] ?? 0));
                $runState = sanitize_key((string) ($result['state'] ?? 'queued'));
                self::redirect($runState === 'completed' ? 'done' : 'activation_' . ($runState !== '' ? $runState : 'queued'));
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
            } elseif ($operation === 'update_feedback_followup') {
                $feedbackId = absint($_POST['feedback_id'] ?? 0);
                $version = absint($_POST['feedback_version'] ?? 0);
                $status = sanitize_key((string) ($_POST['follow_up_status'] ?? ''));
                if ($feedbackId < 1 || $version < 1) throw new \InvalidArgumentException('feedback_id_required');
                if (!PostIntroFeedbackRule::status($status)) throw new \InvalidArgumentException('feedback_status_invalid');
                $assigned = absint($_POST['assigned_staff_user_id'] ?? 0);
                if ($assigned > 0 && (!get_userdata($assigned) || !user_can($assigned, self::CAPABILITY))) throw new \InvalidArgumentException('feedback_staff_invalid');
                $lastContact = sanitize_text_field((string) ($_POST['last_contact_date'] ?? ''));
                if ($lastContact !== '') {
                    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $lastContact, $parts) !== 1 || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                        throw new \InvalidArgumentException('feedback_last_contact_invalid');
                    }
                    $lastContact .= ' 00:00:00';
                }
                $notes = sanitize_textarea_field((string) ($_POST['follow_up_notes'] ?? ''));
                $outcome = sanitize_text_field((string) ($_POST['outcome'] ?? ''));
                (new PostIntroFeedbackRepository())->updateFollowUp($feedbackId, $version, array(
                    'follow_up_status' => $status,
                    'assigned_staff_user_id' => $assigned > 0 ? $assigned : null,
                    'last_contact_at' => $lastContact !== '' ? $lastContact : null,
                    'follow_up_notes' => $notes !== '' ? $notes : null,
                    'outcome' => $outcome !== '' ? $outcome : null,
                ), (int) get_current_user_id());
            } else {
                throw new \InvalidArgumentException('unsupported_operation');
            }
            self::redirect('done');
        } catch (\Throwable $error) {
            self::redirect(preg_match('/^[a-z0-9_]{3,64}$/D', trim($error->getMessage())) === 1 ? trim($error->getMessage()) : 'operation_failed');
        }
    }

    private static function feedbackLabel(string $reason): string {
        return match ($reason) {
            'teacher_fit' => 'Teacher fit / different Teacher requested',
            'schedule' => 'Class time / schedule',
            'price' => 'Price / payment terms',
            'changed_mind' => 'Changed mind',
            'technical_experience' => 'Online class experience',
            'other' => 'Other reason',
            'prefer_not_to_say' => 'Prefer not to say',
            default => 'Unclassified',
        };
    }

    private static function statusMessage(string $status): string {
        $messages = array(
            'done' => 'The action was completed.',
            'post_intro_preparation_not_found' => 'That preparation no longer exists.',
            'post_intro_job_not_found' => 'That activation job no longer exists.',
            'post_intro_job_not_due' => 'The activation job is not due yet; it runs on its next scheduled attempt.',
            'post_intro_job_in_progress' => 'The activation job is running now. Wait for it to finish before retrying.',
            'post_intro_job_not_requeueable' => 'That activation job cannot be requeued in its current state.',
            'post_intro_job_unavailable' => 'No activation job could be created. Verify the settlement, schedule and funding before retrying.',
            'commercial_purchase_required' => 'No verified purchase is linked to this preparation, so activation cannot be queued.',
            'post_intro_preparation_not_paid' => 'That preparation is not waiting on a verified payment, so no activation was queued.',
            'authoritative_schedule_missing' => 'The continuation has no authoritative schedule yet. Repair the schedule in its owning authority first.',
            'verified_payment_required' => 'The payment is not verified yet; no activation was queued.',
            'operation_failed' => 'The action failed. Review the preparation state and its owning records before retrying.',
            'activation_queued' => 'Activation was queued and will run on the next worker pass.',
            'activation_retryable' => 'Activation was attempted and returned to the retry queue. Its reason is listed under Needs attention now.',
            'activation_failed' => 'Activation failed again. Its reason is listed under Needs attention now.',
        );
        if (isset($messages[$status])) return $messages[$status];
        return 'The action returned ' . $status . '. Review the preparation and its owning records before retrying.';
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
