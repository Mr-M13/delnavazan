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
            $leaseDigest = hash('sha256', $token);
            if (!$repository->claimJob((int) $job->id, $leaseDigest, $now, $leaseUntil)) continue;
            $claimed = $repository->job((int) $job->id);
            if (!$claimed) continue;
            if ((int) $claimed->attempt_count > self::MAX_ATTEMPTS) {
                $preparation = $repository->preparation((int) $claimed->preparation_id);
                if ($preparation && (string) $preparation->state !== 'activated') {
                    $repository->updatePreparation((int) $preparation->id, array(
                        'state' => 'exception',
                        'exception_code' => 'post_intro_attempt_limit_reached',
                        'exception_detail' => 'post_intro_attempt_limit_reached',
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                    ));
                }
                $repository->releaseJob((int) $claimed->id, 'failed', array(
                    'next_attempt_at' => null,
                    'last_error_code' => 'post_intro_attempt_limit_reached',
                    'last_error_detail' => 'post_intro_attempt_limit_reached',
                ), $leaseDigest);
                continue;
            }
            self::execute($repository, $claimed, $now, $leaseDigest);
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

    private static function execute(PostIntroRepository $repository, object $job, string $now, string $leaseDigest): void {
        try {
            (new PostIntroActivationService($repository))->activate((int) $job->id);
            $repository->releaseJob((int) $job->id, 'completed', array(
                'attempt_count' => (int) $job->attempt_count,
                'checkpoint' => 'activated',
                'completed_at' => gmdate('Y-m-d H:i:s'),
                'next_attempt_at' => null,
                'last_error_code' => null,
                'last_error_detail' => null,
            ), $leaseDigest);
        } catch (\Throwable $error) {
            if ($error->getMessage() === 'post_intro_job_lease_lost') return;
            $attempt = (int) $job->attempt_count;
            $code = self::code($error);
            do_action('dzn_post_intro_activation_failed', (int) $job->id, get_class($error));
            $retryable = self::retryable($error) && $attempt < self::MAX_ATTEMPTS;
            $preparation = $repository->preparation((int) $job->preparation_id);
            if (!$retryable && $preparation && (string) $preparation->state !== 'activated') {
                $repository->updatePreparation((int) $preparation->id, array(
                    'state' => 'exception',
                    'exception_code' => $code,
                    'exception_detail' => $code,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ));
            }
            try {
                $repository->releaseJob((int) $job->id, $retryable ? 'retryable' : 'failed', array(
                    'attempt_count' => $attempt,
                    'next_attempt_at' => $retryable ? gmdate('Y-m-d H:i:s', time() + min(1800, 30 * (2 ** ($attempt - 1)))) : null,
                    'last_error_code' => $code,
                    'last_error_detail' => $code,
                ), $leaseDigest);
            } catch (\Throwable $releaseError) {
                if ($releaseError->getMessage() !== 'post_intro_job_lease_lost') throw $releaseError;
            }
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
