<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository;

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
        foreach ($this->repository->preparationsForStudent($studentId) as $preparation) {
            $confirmation = $this->repository->confirmationByBooking((int) $preparation->booking_request_id);
            if (!$confirmation) continue;
            $state = $this->displayState((string) $preparation->state, $preparation);
            $actionable = in_array($state, array('ready', 'checkout_pending'), true);
            $attestation = $classification !== 'adult' && $classification !== 'minor';
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
            );
        }
        return $rows;
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
