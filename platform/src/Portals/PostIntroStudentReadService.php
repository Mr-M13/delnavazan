<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\CanonicalContinuationRule;
use Delnavazan\Platform\Core\Infrastructure\Repository\{PostIntroFeedbackRepository,PostIntroRepository};

/**
 * Read-only projection of post-intro continuation state for the Student Portal.
 *
 * Internal identifiers, exception details and commercial lineage never cross this boundary.
 */
final class PostIntroStudentReadService {
    public function __construct(private ?PostIntroRepository $repository = null) {
        $this->repository = $repository ?? new PostIntroRepository();
    }

    /** @return list<array<string,mixed>> */
    public function forStudent(int $studentId): array {
        $rows = array();
        $capacity = $this->repository->studentCapacity($studentId);
        $classification = $capacity ? (string) $capacity->classification : 'unknown';
        $feedbackRepository = new PostIntroFeedbackRepository();
        foreach ($this->repository->preparationsForStudent($studentId) as $preparation) {
            $confirmation = $this->repository->confirmationByBooking((int) $preparation->booking_request_id);
            if (!$confirmation) continue;
            $state = $this->displayState((string) $preparation->state, $preparation);
            $continuationCase = (int) ($preparation->intro_lesson_id ?? 0) > 0
                ? $this->repository->continuationCaseForIntro((int) $preparation->intro_lesson_id)
                : null;
            $holdExpiresAt = $this->holdExpiresAt($preparation, $confirmation);
            $holdSeconds = $holdExpiresAt !== null ? strtotime($holdExpiresAt . ' UTC') : false;
            if ($holdSeconds !== false && $holdSeconds <= time() && in_array($state, array('ready', 'checkout_pending'), true)) {
                $state = 'expired';
            }
            $actionable = in_array($state, array('ready', 'checkout_pending'), true);
            // The follow-up is offered on the last day of the hold and after it, but only while the
            // Student has not recorded any continuation decision. Once a decision exists the
            // canonical continuation authority (different Teacher, contact, or stop) owns the path.
            $surveyAvailable = $continuationCase === null
                && $holdSeconds !== false
                && in_array((string) $preparation->state, array('prepared', 'checkout_pending', 'expired'), true)
                && in_array($state, array('ready', 'checkout_pending', 'expired'), true)
                && $holdSeconds <= time() + 86400
                && $feedbackRepository->byPreparation((int) $preparation->id) === null;
            $attestation = $classification !== 'adult' && $classification !== 'minor';
            $offer = (int) ($preparation->offer_id ?? 0) > 0 ? $this->repository->offer((int) $preparation->offer_id) : null;
            $selectedPlan = $offer ? (string) $offer->plan_kind : '';
            if ($selectedPlan === '') {
                $choice = $this->repository->activePlanChoice((int) $preparation->id);
                if ($choice) $selectedPlan = (string) $choice->plan_kind;
            }
            $quote = null;
            if ($actionable && !$offer) {
                try {
                    $quote = (new \Delnavazan\Platform\Core\Application\PostIntroPlanQuoteService())->forPreparation($preparation, $studentId);
                } catch (\Throwable $error) {
                    do_action('dzn_post_intro_plan_quote_failed', get_class($error), (int) $preparation->id);
                }
            }
            $rows[] = array(
                'reference' => (string) $preparation->uid,
                'state' => $state,
                'actionable' => $actionable,
                'adult_attestation_required' => $actionable && $attestation,
                'guardian_required' => $classification === 'minor',
                'teacher_name' => (string) ($confirmation->teacher_name ?? ''),
                'course_name' => (string) ($confirmation->course_name ?? ''),
                'intro_date' => (string) ($confirmation->local_wall_date ?? ''),
                'intro_time' => substr((string) ($confirmation->local_wall_time ?? ''), 0, 5),
                'timezone' => (string) ($confirmation->schedule_timezone ?? ''),
                'title' => $this->title($state),
                'message' => $this->message($state, $classification),
                'action_label' => $state === 'ready' ? 'تکمیل پرداخت و تأیید ثبت‌نام' : 'ادامهٔ پرداخت',
                'action_url' => $actionable ? admin_url('admin-post.php?action=dzn_post_intro_continue') : null,
                'nonce' => $actionable ? wp_create_nonce('dzn_post_intro_continue') : null,
                'term_number' => 1,
                'term_label' => 'ترم ۱',
                'selected_plan_kind' => $selectedPlan,
                'plan_locked' => $offer !== null,
                'plan_options' => is_array($quote['options'] ?? null) ? $quote['options'] : array(),
                'quote_token' => is_array($quote) ? (string) ($quote['quote_token'] ?? '') : '',
                'currency' => is_array($quote) ? (string) ($quote['currency'] ?? '') : '',
                'committed_sessions' => is_array($quote) ? (int) ($quote['committed_sessions'] ?? 12) : 12,
                'hold_expires_at' => $holdExpiresAt,
                'survey_available' => $surveyAvailable,
                'survey_action_url' => $surveyAvailable ? admin_url('admin-post.php?action=dzn_post_intro_survey') : null,
                'survey_nonce' => $surveyAvailable ? wp_create_nonce('dzn_post_intro_survey') : null,
                'survey_options' => $surveyAvailable ? $this->surveyOptions() : array(),
                'survey_choices' => $surveyAvailable ? $this->surveyChoices() : array(),
            );
        }
        return $rows;
    }

    /** The frozen post-intro hold, derived from the authoritative intro end and first-slot start. */
    private function holdExpiresAt(object $preparation, object $confirmation): ?string {
        $introLessonId = (int) ($preparation->intro_lesson_id ?? 0);
        if ($introLessonId < 1) return null;
        $slot = $this->repository->slotAuthorityForIntro($introLessonId);
        if (!$slot) return null;
        try {
            return CanonicalContinuationRule::expiresAt((string) $confirmation->ends_at_utc, (string) $slot->starts_at_utc);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Controlled feedback options; the canonical authority validates the selected value. */
    private function surveyOptions(): array {
        return array(
            array('value' => 'teacher_fit', 'label' => 'مایل هستم با مدرس دیگری ادامه دهم.'),
            array('value' => 'schedule', 'label' => 'زمان کلاس برای من مناسب نیست.'),
            array('value' => 'price', 'label' => 'هزینهٔ کلاس یا شرایط پرداخت مناسب نیست.'),
            array('value' => 'other', 'label' => 'دلیل دیگری دارم.'),
            array('value' => 'prefer_not_to_say', 'label' => 'ترجیح می‌دهم اعلام نکنم.'),
        );
    }

    private function surveyChoices(): array {
        return array(
            array('value' => 'contact_me', 'label' => 'مایل هستم با من تماس بگیرید.'),
            array('value' => 'not_continuing', 'label' => 'فعلاً قصد ادامه ندارم.'),
            array('value' => 'feedback_only', 'label' => 'فقط می‌خواهم نظرم را ثبت کنم.'),
        );
    }

    private function displayState(string $state, object $preparation): string {
        if ($state === 'expired') return 'expired';
        if ($state === 'activated') return 'activated';
        if ($state === 'payment_received') return 'payment_received';
        if ($state === 'exception') {
            return (string) $preparation->exception_code === 'continuation_reservation_expired' ? 'expired' : 'exception';
        }
        if ($state === 'checkout_pending') return 'checkout_pending';
        if ($state === 'prepared') return 'ready';
        return 'preparing';
    }

    private function title(string $state): string {
        return match ($state) {
            'ready' => 'کلاس آشنایی شما انجام شد',
            'checkout_pending' => 'پرداخت شما در انتظار تکمیل است',
            'payment_received' => 'پرداخت شما تأیید شد',
            'activated' => 'ثبت‌نام شما فعال شد',
            'expired' => 'مهلت تکمیل ثبت‌نام گذشته است',
            'exception' => 'نیاز به بررسی همکاران ما',
            default => 'ثبت‌نام شما در حال آماده‌سازی است',
        };
    }

    private function message(string $state, string $classification): string {
        return match ($state) {
            'ready' => 'برای ادامهٔ کلاس با همین مدرس، پرداخت را تکمیل کنید تا ثبت‌نام، برنامهٔ هفتگی و کلاس‌های آینده به‌طور خودکار فعال شوند.',
            'checkout_pending' => 'اگر پرداخت را نیمه‌کاره رها کرده‌اید، از همین‌جا ادامه دهید. پس از تأیید پرداخت، ثبت‌نام شما خودکار فعال می‌شود.',
            'payment_received' => 'پرداخت شما تأیید شده است و ثبت‌نام و برنامهٔ کلاس‌ها در حال فعال‌سازی است. نیازی به اقدام دیگری نیست.',
            'activated' => 'ثبت‌نام شما فعال است. برنامهٔ کلاس‌های آینده در بخش کلاس‌ها نمایش داده می‌شود.',
            'expired' => 'مهلت نگه‌داشتن زمان کلاس شما گذشته است. برای هماهنگی زمان جدید با پشتیبانی تماس بگیرید.',
            'exception' => 'برای تکمیل ثبت‌نام، همکاران ما در حال بررسی هستند. اگر تا ۲۴ ساعت آینده وضعیت تغییر نکرد، با پشتیبانی تماس بگیرید.',
            default => 'پس از تأیید برگزاری کلاس آشنایی، امکان ادامه با همین مدرس در همین بخش نمایش داده می‌شود.',
        } . ($classification === 'minor' && $state === 'ready' ? ' برای هنرجوی زیر ۱۸ سال، تأیید ولی یا سرپرست لازم است.' : '');
    }
}
