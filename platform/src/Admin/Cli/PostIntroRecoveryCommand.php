<?php
namespace Delnavazan\Platform\Admin\Cli;

use Delnavazan\Platform\Core\Application\PostIntroRecoveryService;

/**
 * Read-only inspection and reviewed recovery for a paid post-intro Enrolment.
 *
 * Run as an administrator with both post-intro and payment-execution authority:
 *   wp dzn-post-intro inspect 1 --user=1
 *   wp dzn-post-intro recover 1 --user=1
 */
final class PostIntroRecoveryCommand {
    public static function register(): void {
        if (class_exists('\WP_CLI')) \WP_CLI::add_command('dzn-post-intro', __CLASS__);
    }

    /**
     * Inspect the authoritative payment, term, assignment and Lesson state without mutating anything.
     *
     * ## OPTIONS
     * <enrolment-id>
     */
    public function inspect(array $args): void {
        $this->execute('inspect', $args);
    }

    /**
     * Recover a paid-but-incomplete Enrolment through reconciliation and the existing activation worker.
     *
     * ## OPTIONS
     * <enrolment-id>
     */
    public function recover(array $args): void {
        $this->execute('recover', $args);
    }

    private function execute(string $operation, array $args): void {
        try {
            $enrolmentId = (int) ($args[0] ?? 0);
            if ($enrolmentId < 1) throw new \InvalidArgumentException('enrolment_id_required');
            $service = new PostIntroRecoveryService();
            $result = $operation === 'inspect' ? $service->inspect($enrolmentId) : $service->recover($enrolmentId);
            \WP_CLI::line((string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $error) {
            \WP_CLI::error($error->getMessage());
        }
    }
}
