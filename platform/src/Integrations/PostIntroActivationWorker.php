<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\PostIntroActivationService;
use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository;

/** Leased WP-Cron dispatch for verified post-intro activation jobs. */
final class PostIntroActivationWorker {
    public const HOOK = 'dzn_post_intro_activation_dispatch';
    private const MAX_ATTEMPTS = 8;
    private const LEASE_SECONDS = 900;

    public static function register(): void {
        add_filter('cron_schedules', static function (array $schedules): array {
            $schedules['dzn_two_minutes'] ??= array('interval' => 120, 'display' => 'Delnavazan every two minutes');
            return $schedules;
        });
        add_action(self::HOOK, array(__CLASS__, 'run'));
        add_action('init', array(__CLASS__, 'ensureScheduled'));
    }

    public static function ensureScheduled(): void {
        if (!wp_next_scheduled(self::HOOK)) wp_schedule_event(time() + 60, 'dzn_two_minutes', self::HOOK);
    }

    public static function run(): void {
        $repository = new PostIntroRepository();
        foreach ($repository->dueJobs(10) as $job) {
            $token = bin2hex(random_bytes(16));
            $now = gmdate('Y-m-d H:i:s');
            $leaseUntil = gmdate('Y-m-d H:i:s', time() + self::LEASE_SECONDS);
            if (!$repository->claimJob((int) $job->id, hash('sha256', $token), $now, $leaseUntil)) continue;
            self::execute($repository, $job, $now);
        }
    }

    /** Explicit retry used by the admin exception surface. */
    public static function requeue(int $jobId): void {
        $repository = new PostIntroRepository();
        $repository->releaseJob($jobId, 'queued', array(
            'attempt_count' => 0,
            'next_attempt_at' => gmdate('Y-m-d H:i:s'),
            'last_error_code' => null,
            'last_error_detail' => null,
        ));
    }

    private static function execute(PostIntroRepository $repository, object $job, string $now): void {
        try {
            (new PostIntroActivationService($repository))->activate((int) $job->id);
            $repository->releaseJob((int) $job->id, 'completed', array(
                'attempt_count' => (int) $job->attempt_count + 1,
                'checkpoint' => 'activated',
                'completed_at' => gmdate('Y-m-d H:i:s'),
                'next_attempt_at' => null,
                'last_error_code' => null,
                'last_error_detail' => null,
            ));
        } catch (\Throwable $error) {
            $attempt = (int) $job->attempt_count + 1;
            $code = self::code($error);
            $retryable = self::retryable($error) && $attempt < self::MAX_ATTEMPTS;
            $preparation = $repository->preparation((int) $job->preparation_id);
            if (!$retryable && $preparation && (string) $preparation->state !== 'activated') {
                $repository->updatePreparation((int) $preparation->id, array(
                    'state' => 'exception',
                    'exception_code' => $code,
                    'exception_detail' => mb_substr($error->getMessage(), 0, 500),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ));
            }
            $repository->releaseJob((int) $job->id, $retryable ? 'retryable' : 'failed', array(
                'attempt_count' => $attempt,
                'next_attempt_at' => $retryable ? gmdate('Y-m-d H:i:s', time() + min(1800, 30 * (2 ** ($attempt - 1)))) : null,
                'last_error_code' => $code,
                'last_error_detail' => mb_substr($error->getMessage(), 0, 500),
            ));
        }
    }

    private static function retryable(\Throwable $error): bool {
        $message = trim($error->getMessage());
        if (str_starts_with($message, 'provider_')) return true;
        if (str_contains($message, 'Deadlock') || str_contains($message, 'Lock wait timeout')) return true;
        return in_array($message, array(
            'commercial_entitlement_required',
            'teacher_notification_failed',
        ), true);
    }

    private static function code(\Throwable $error): string {
        $message = trim($error->getMessage());
        return preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'post_intro_activation_failed';
    }
}
