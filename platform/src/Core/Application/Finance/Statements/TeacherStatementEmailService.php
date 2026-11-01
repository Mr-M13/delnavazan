<?php
namespace Delnavazan\Platform\Core\Application\Finance\Statements;

use Delnavazan\Platform\Core\Application\Finance\{FinanceIdempotency,FinanceSettingsService,FinanceSupport};
use Delnavazan\Platform\Core\Application\Finance\Read\{FinanceAdminReadService,TeacherStatementReadService};
use Delnavazan\Platform\Core\Infrastructure\Repository\{FinanceStatementRepository,StatementEmailOutboxRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Durable teacher-statement email delivery.
 *
 * Delivery is a two-phase fact: one intent per statement is queued, then a worker claims it under a
 * lease and records an attempt. `wp_mail()` returning true is recorded as **transport accepted**, not
 * delivered: this platform has no downstream delivery callback, so `delivered_at` stays NULL unless a
 * future provider integration proves it. Interrupted workers are reclaimable after lease expiry.
 * A retry reuses the same intent and never creates a second financial or statement fact.
 */
final class TeacherStatementEmailService {
    public const CAPABILITY = 'dzn_manage_finance_statements';
    public const STATE_PENDING = 'pending';
    public const STATE_LEASED = 'leased';
    public const STATE_TRANSPORT_ACCEPTED = 'transport_accepted';
    public const STATE_RETRYABLE = 'retryable';
    public const STATE_FAILED = 'failed';

    public function __construct(
        private ?TeacherStatementReadService $statements = null,
        private ?FinanceAdminReadService $read = null,
        private ?FinanceStatementRepository $statementsRepository = null,
        private ?StatementEmailOutboxRepository $outbox = null,
        private ?TeacherStatementEmailRenderer $renderer = null,
        private ?FinanceSettingsService $settings = null,
        private ?\Closure $statementLoader = null,
        private ?\Closure $lineLoader = null,
        private ?\Closure $teacherLoader = null
    ) {
        $this->statements ??= new TeacherStatementReadService();
        $this->read ??= new FinanceAdminReadService();
        $this->statementsRepository ??= new FinanceStatementRepository();
        $this->outbox ??= new StatementEmailOutboxRepository();
        $this->renderer ??= new TeacherStatementEmailRenderer();
        $this->settings ??= new FinanceSettingsService();
    }

    /**
     * Queue one email intent for an issued statement. The statement_id unique key prevents duplicate
     * intents regardless of how many administrators or batch retries submit the request.
     *
     * @return array<string,mixed>
     */
    public function enqueue(int $statementId, bool $retry = false): array {
        FinanceSupport::requireCapability(self::CAPABILITY);
        $statement = $this->loadStatement($statementId);
        if ((string) ($statement['state'] ?? '') !== 'issued') {
            return $this->outcome(false, 'statement_not_issued', null, null);
        }
        $teacher = $this->loadTeacher((int) $statement['teacher_id']);
        $to = trim((string) ($teacher['email'] ?? ''));
        $valid = function_exists('is_email') ? (bool) is_email($to) : filter_var($to, FILTER_VALIDATE_EMAIL) !== false;
        if ($to === '' || !$valid) {
            return $this->outcome(false, 'statement_email_missing', null, null);
        }
        $template = $this->settings->template();
        $locale = (string) ($teacher['locale'] ?: ($template['locale'] ?? 'fa'));
        $existing = $this->outbox->byStatement($statementId);
        if ($existing) {
            $state = (string) $existing->state;
            if (!$retry || !in_array($state, array(self::STATE_FAILED, self::STATE_RETRYABLE), true)) {
                return $this->outcome(true, $state === self::STATE_TRANSPORT_ACCEPTED ? 'already_transport_accepted' : 'already_queued', (int) $existing->id, $existing);
            }
            $now = gmdate('Y-m-d H:i:s');
            $actor = $this->actor();
            $this->outbox->updateIntent((int) $existing->id, (int) $existing->intent_version, array(
                'state' => self::STATE_PENDING, 'next_attempt_at' => $now, 'lease_token_digest' => null,
                'lease_expires_at' => null, 'last_error_code' => null, 'updated_at' => $now, 'updated_by' => $actor,
            ));
            $existing = $this->outbox->find((int) $existing->id);
            $this->recordEvent($statementId, 'email_queued', 'retry', $actor);
            $this->schedule();
            return $this->outcome(true, 'queued_retry', (int) $existing->id, $existing);
        }
        $now = gmdate('Y-m-d H:i:s');
        $actor = $this->actor();
        try {
            $id = $this->outbox->insertIntent(array(
                'uid' => Identifier::uid(), 'statement_id' => $statementId, 'teacher_id' => (int) $statement['teacher_id'],
                'recipient_email' => $to, 'locale' => $locale, 'state' => self::STATE_PENDING,
                'attempt_count' => 0, 'max_attempts' => 5, 'next_attempt_at' => $now,
                'lease_token_digest' => null, 'lease_expires_at' => null, 'last_error_code' => null,
                'transport_accepted_at' => null, 'delivered_at' => null, 'intent_version' => 1,
                'created_at' => $now, 'updated_at' => $now, 'created_by' => $actor, 'updated_by' => $actor,
            ));
        } catch (\Throwable $error) {
            $existing = $this->outbox->byStatement($statementId);
            if ($existing) return $this->outcome(true, 'already_queued', (int) $existing->id, $existing);
            throw $error;
        }
        $this->recordEvent($statementId, 'email_queued', null, $actor);
        $this->schedule();
        return $this->outcome(true, 'queued', $id, $this->outbox->find($id));
    }

    /** Process due intents. Called by cron and by the administrator action after enqueue. */
    public function processDue(int $limit = 20): array {
        $limit = max(1, min(100, $limit));
        $processed = 0; $accepted = 0; $retryable = 0; $failed = 0;
        while ($processed < $limit) {
            $claim = $this->claimNext();
            if ($claim === null) break;
            $processed++;
            $result = $this->processClaim($claim['intent'], (int) $claim['attempt_id']);
            if (!empty($result['transport_accepted'])) $accepted++;
            elseif ((string) ($result['state'] ?? '') === self::STATE_FAILED) $failed++;
            else $retryable++;
        }
        return array('processed' => $processed, 'transport_accepted' => $accepted, 'retryable' => $retryable, 'failed' => $failed);
    }

    public function intent(int $statementId): ?array {
        FinanceSupport::requireCapability('dzn_view_finance_authority');
        $row = $this->outbox->byStatement($statementId);
        if (!$row) return null;
        return array(
            'intent_id' => (int) $row->id, 'state' => (string) $row->state, 'attempt_count' => (int) $row->attempt_count,
            'max_attempts' => (int) $row->max_attempts, 'next_attempt_at' => (string) $row->next_attempt_at,
            'last_error_code' => $row->last_error_code === null ? null : (string) $row->last_error_code,
            'transport_accepted_at' => $row->transport_accepted_at === null ? null : (string) $row->transport_accepted_at,
            'delivered_at' => $row->delivered_at === null ? null : (string) $row->delivered_at,
            'attempts' => array_map(static fn(object $attempt): array => array(
                'attempt_sequence' => (int) $attempt->attempt_sequence, 'state' => (string) $attempt->state,
                'started_at' => (string) $attempt->started_at, 'finished_at' => $attempt->finished_at === null ? null : (string) $attempt->finished_at,
                'outcome_code' => $attempt->outcome_code === null ? null : (string) $attempt->outcome_code,
                'transport_accepted' => (bool) $attempt->transport_accepted,
            ), $this->outbox->attempts((int) $row->id)),
        );
    }

    private function loadStatement(int $id): array {
        return $this->statementLoader !== null ? (array) ($this->statementLoader)($id) : $this->statements->statement($id);
    }
    private function loadLines(int $id): array {
        return $this->lineLoader !== null ? (array) ($this->lineLoader)($id) : $this->read->statementLines($id);
    }
    private function loadTeacher(int $id): array {
        return $this->teacherLoader !== null ? (array) ($this->teacherLoader)($id) : (array) $this->read->teacherIdentity($id);
    }

    private function claimNext(): ?array {
        $this->outbox->begin();
        try {
            $now = gmdate('Y-m-d H:i:s');
            $candidates = $this->outbox->claimable($now, 1);
            if ($candidates === array()) { $this->outbox->commit(); return null; }
            $row = $this->outbox->find((int) $candidates[0]->id, true);
            if (!$row) { $this->outbox->commit(); return null; }
            $state = (string) $row->state;
            $expiredLease = $state === self::STATE_LEASED && $row->lease_expires_at !== null && (string) $row->lease_expires_at <= $now;
            if (!in_array($state, array(self::STATE_PENDING, self::STATE_RETRYABLE), true) && !$expiredLease) {
                $this->outbox->commit(); return null;
            }
            if ($expiredLease) {
                foreach (array_reverse($this->outbox->attempts((int) $row->id)) as $attempt) {
                    if ((string) $attempt->state === 'leased') {
                        $this->outbox->updateAttempt((int) $attempt->id, array('state' => 'lease_expired', 'finished_at' => $now, 'outcome_code' => 'lease_expired'));
                        break;
                    }
                }
            }
            $actor = (int) $row->created_by;
            $token = Identifier::uid();
            $tokenDigest = hash('sha256', 'statement-email-lease:' . $token);
            $attempt = (int) $row->attempt_count + 1;
            $this->outbox->updateIntent((int) $row->id, (int) $row->intent_version, array(
                'state' => self::STATE_LEASED, 'attempt_count' => $attempt,
                'lease_token_digest' => $tokenDigest, 'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 300),
                'updated_at' => $now, 'updated_by' => $actor,
            ));
            $attemptId = $this->outbox->insertAttempt(array(
                'uid' => Identifier::uid(), 'intent_id' => (int) $row->id, 'attempt_sequence' => $attempt,
                'state' => 'leased', 'lease_token_digest' => $tokenDigest, 'started_at' => $now,
                'finished_at' => null, 'outcome_code' => null, 'transport_accepted' => 0,
                'created_at' => $now, 'created_by' => $actor,
            ));
            $this->outbox->commit();
            return array('intent' => $this->outbox->find((int) $row->id), 'attempt_id' => $attemptId);
        } catch (\Throwable $error) {
            $this->outbox->rollback();
            throw $error;
        }
    }

    private function processClaim(object $intent, int $attemptId): array {
        $previous = (int) get_current_user_id();
        $actor = (int) $intent->created_by;
        $now = gmdate('Y-m-d H:i:s');
        $transportAccepted = false;
        $reason = 'statement_email_transport_failed';
        try {
            if ($actor > 0) wp_set_current_user($actor);
            $statement = $this->loadStatement((int) $intent->statement_id);
            $teacher = $this->loadTeacher((int) $statement['teacher_id']);
            $lines = $this->loadLines((int) $intent->statement_id);
            $template = $this->settings->template();
            $academy = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : 'Delnavazan';
            $academy = $academy !== '' ? $academy : 'Delnavazan';
            $statement['teacher_name'] = (string) ($teacher['name'] ?? '');
            $statement['teacher_reference'] = (string) ($teacher['reference'] ?? '');
            $transportAccepted = $this->renderer->send((string) $intent->recipient_email, $statement, $lines, array(
                'name' => (string) ($teacher['name'] ?? ''), 'reference' => (string) ($teacher['reference'] ?? ''),
                'email' => (string) $intent->recipient_email, 'locale' => (string) $intent->locale,
            ), array('locale' => (string) $intent->locale, 'logo_url' => (string) ($template['logo_url'] ?? ''), 'academy_name' => $academy, 'timezone' => (string) ($teacher['timezone'] ?? ''), 'calendar' => (string) ($teacher['calendar_preference'] ?? '')));
            if (!$transportAccepted) $reason = 'statement_email_transport_failed';
        } catch (\Throwable $error) {
            $message = trim($error->getMessage());
            $reason = preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'statement_email_processing_failed';
        } finally {
            wp_set_current_user($previous > 0 ? $previous : 0);
        }

        $this->outbox->begin();
        try {
            $fresh = $this->outbox->find((int) $intent->id, true);
            if (!$fresh) throw new \RuntimeException('statement_email_intent_required');
            $attemptCount = (int) $fresh->attempt_count;
            $maxAttempts = (int) $fresh->max_attempts;
            if ($transportAccepted) {
                $this->outbox->updateIntent((int) $fresh->id, (int) $fresh->intent_version, array(
                    'state' => self::STATE_TRANSPORT_ACCEPTED, 'transport_accepted_at' => $now, 'delivered_at' => null,
                    'lease_token_digest' => null, 'lease_expires_at' => null, 'last_error_code' => null,
                    'next_attempt_at' => $now, 'updated_at' => $now, 'updated_by' => $actor,
                ));
                $state = self::STATE_TRANSPORT_ACCEPTED; $outcome = 'transport_accepted';
            } elseif ($attemptCount >= $maxAttempts) {
                $this->outbox->updateIntent((int) $fresh->id, (int) $fresh->intent_version, array(
                    'state' => self::STATE_FAILED, 'lease_token_digest' => null, 'lease_expires_at' => null,
                    'last_error_code' => $reason, 'next_attempt_at' => $now, 'updated_at' => $now, 'updated_by' => $actor,
                ));
                $state = self::STATE_FAILED; $outcome = $reason;
            } else {
                $delay = min(3600, 300 * (2 ** max(0, $attemptCount - 1)));
                $this->outbox->updateIntent((int) $fresh->id, (int) $fresh->intent_version, array(
                    'state' => self::STATE_RETRYABLE, 'lease_token_digest' => null, 'lease_expires_at' => null,
                    'last_error_code' => $reason, 'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                    'updated_at' => $now, 'updated_by' => $actor,
                ));
                $state = self::STATE_RETRYABLE; $outcome = $reason;
            }
            $this->outbox->updateAttempt($attemptId, array(
                'state' => $transportAccepted ? 'transport_accepted' : 'failed', 'finished_at' => $now,
                'outcome_code' => $outcome, 'transport_accepted' => $transportAccepted ? 1 : 0,
            ));
            $this->outbox->commit();
        } catch (\Throwable $error) {
            $this->outbox->rollback();
            throw $error;
        }
        $this->recordEvent((int) $intent->statement_id, $transportAccepted ? 'email_transport_accepted' : 'email_failed', $transportAccepted ? null : $reason, $actor);
        return array('state' => $state, 'transport_accepted' => $transportAccepted, 'reason' => $transportAccepted ? null : $reason);
    }

    private function recordEvent(int $statementId, string $eventType, ?string $reason, int $actor): bool {
        try {
            $now = gmdate('Y-m-d H:i:s');
            $this->statementsRepository->insertEvent(array(
                'statement_id' => $statementId,
                'event_sequence' => $this->statementsRepository->nextEventSequence($statementId),
                'event_type' => $eventType, 'from_state' => 'issued', 'to_state' => 'issued', 'superseded_by_statement_id' => null,
                'reason_code' => $reason ?? 'operator_decision', 'evidence_channel' => 'authenticated_platform',
                'evidence_reference_digest' => FinanceIdempotency::evidence('statement-email:' . $statementId . ':' . $eventType . ':' . $now . ':' . Identifier::uid()),
                'evidence_at' => $now, 'occurred_at' => $now, 'recorded_at' => $now, 'recorded_by' => $actor,
                'created_at' => $now, 'created_by' => $actor,
            ));
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function schedule(): void {
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')) {
            wp_schedule_single_event(time() + 60, 'dzn_teacher_statement_email_dispatch');
        }
    }

    private function actor(): int { return max(0, (int) get_current_user_id()); }

    private function outcome(bool $queued, string $reason, ?int $intentId, ?object $intent): array {
        return array(
            'queued' => $queued, 'reason' => $reason, 'intent_id' => $intentId,
            'state' => $intent === null ? null : (string) $intent->state,
            'attempt_count' => $intent === null ? 0 : (int) $intent->attempt_count,
            'transport_accepted_at' => $intent === null || $intent->transport_accepted_at === null ? null : (string) $intent->transport_accepted_at,
            'delivered_at' => $intent === null || $intent->delivered_at === null ? null : (string) $intent->delivered_at,
        );
    }
}
