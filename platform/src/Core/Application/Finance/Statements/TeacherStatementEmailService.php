<?php
namespace Delnavazan\Platform\Core\Application\Finance\Statements;

use Delnavazan\Platform\Core\Application\Finance\{FinanceIdempotency,FinanceSettingsService,FinanceSupport};
use Delnavazan\Platform\Core\Application\Finance\Read\{FinanceAdminReadService,TeacherStatementReadService};
use Delnavazan\Platform\Core\Infrastructure\Repository\FinanceStatementRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Deliver an issued teacher statement by email.
 *
 * The email is a presentation of recorded facts only. It never changes a statement state, a total,
 * a line or a payment. A send attempt is appended to the statement history as `emailed` or
 * `email_failed`, so the administrator can see what was delivered without a separate mail log.
 */
final class TeacherStatementEmailService {
    public const CAPABILITY = 'dzn_manage_finance_statements';

    public function __construct(
        private ?TeacherStatementReadService $statements = null,
        private ?FinanceAdminReadService $read = null,
        private ?FinanceStatementRepository $repository = null,
        private ?TeacherStatementEmailRenderer $renderer = null,
        private ?FinanceSettingsService $settings = null
    ) {
        $this->statements ??= new TeacherStatementReadService();
        $this->read ??= new FinanceAdminReadService();
        $this->repository ??= new FinanceStatementRepository();
        $this->renderer ??= new TeacherStatementEmailRenderer();
        $this->settings ??= new FinanceSettingsService();
    }

    /** @return array{emailed:bool,reason:?string,to:?string,event_recorded:bool} */
    public function send(int $statementId, string $toOverride = ''): array {
        FinanceSupport::requireCapability(self::CAPABILITY);
        $statement = $this->statements->statement($statementId);
        if ((string) ($statement['state'] ?? '') !== 'issued') {
            return array('emailed' => false, 'reason' => 'statement_not_issued', 'to' => null, 'event_recorded' => false);
        }
        $teacher = $this->read->teacherIdentity((int) $statement['teacher_id']);
        $to = trim($toOverride) !== '' ? trim($toOverride) : trim((string) ($teacher['email'] ?? ''));
        $validEmail = function_exists('is_email') ? (bool) is_email($to) : filter_var($to, FILTER_VALIDATE_EMAIL) !== false;
        if ($to === '' || !$validEmail) {
            $this->record($statementId, 'email_failed', 'statement_email_missing', $to);
            return array('emailed' => false, 'reason' => 'statement_email_missing', 'to' => $to !== '' ? $to : null, 'event_recorded' => true);
        }
        $lines = $this->read->statementLines($statementId);
        $template = $this->settings->template();
        $academy = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : 'Delnavazan';
        $academy = $academy !== '' ? $academy : 'Delnavazan';
        $statement['teacher_name'] = (string) ($teacher['name'] ?? '');
        $statement['teacher_reference'] = (string) ($teacher['reference'] ?? '');
        $sent = $this->renderer->send($to, $statement, $lines, array(
            'name' => (string) ($teacher['name'] ?? ''),
            'reference' => (string) ($teacher['reference'] ?? ''),
            'email' => $to,
            'locale' => (string) ($teacher['locale'] ?: ($template['locale'] ?? 'fa')),
        ), array(
            'locale' => (string) ($template['locale'] ?? 'fa'),
            'logo_url' => (string) ($template['logo_url'] ?? ''),
            'academy_name' => $academy,
        ));
        $eventRecorded = $this->record($statementId, $sent ? 'emailed' : 'email_failed', $sent ? null : 'statement_email_transport_failed', $to);
        return array('emailed' => $sent, 'reason' => $sent ? null : 'statement_email_transport_failed', 'to' => $to, 'event_recorded' => $eventRecorded);
    }

    private function record(int $statementId, string $eventType, ?string $reason, string $to): bool {
        try {
            $now = gmdate('Y-m-d H:i:s');
            $actor = FinanceSupport::actor();
            $sequence = $this->repository->nextEventSequence($statementId);
            $this->repository->insertEvent(array(
                'statement_id' => $statementId,
                'event_sequence' => $sequence,
                'event_type' => $eventType,
                'from_state' => 'issued',
                'to_state' => 'issued',
                'superseded_by_statement_id' => null,
                'reason_code' => $reason ?? 'operator_decision',
                'evidence_channel' => 'authenticated_platform',
                'evidence_reference_digest' => FinanceIdempotency::evidence('statement-email:' . $statementId . ':' . $to . ':' . $now . ':' . Identifier::uid()),
                'evidence_at' => $now,
                'occurred_at' => $now,
                'recorded_at' => $now,
                'recorded_by' => $actor,
                'created_at' => $now,
                'created_by' => $actor,
            ));
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
