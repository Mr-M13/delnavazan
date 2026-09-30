<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{EnrolmentRepository,TermRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/** Existing Phase 1 legacy Term creator; canonical authority is deliberately absent. */
final class TermService {
    public function create(array $data): int {
        if (!current_user_can('dzn_manage_terms')) throw new \RuntimeException('Unauthorized');
        $data['enrolment_id'] = Normalizer::id($data['enrolment_id'] ?? null);
        if (!(new EnrolmentRepository())->usable($data['enrolment_id'])) {
            throw new \InvalidArgumentException('Usable legacy Enrolment required; canonical Enrolment grants no Term authority');
        }
        $data['status'] = Normalizer::one($data['status'] ?? 'draft', array('draft', 'awaiting_payment', 'active', 'completed', 'cancelled'), 'term state');
        $data['sequence_number'] = Normalizer::count($data['sequence_number'] ?? 0, 1);
        $data['lesson_allocation'] = Normalizer::count($data['lesson_allocation'] ?? 12, 1);
        $data['replacement_allowance'] = Normalizer::count($data['replacement_allowance'] ?? 2, 0);
        $data['payment_state'] = Normalizer::one($data['payment_state'] ?? 'not_required', array('not_required', 'pending', 'paid', 'failed', 'refunded'), 'payment state');
        $data['record_model'] = 'legacy_phase1';
        $repository = new TermRepository();
        if ($repository->sequenceExists($data['enrolment_id'], $data['sequence_number'])) throw new \InvalidArgumentException('Term sequence already exists');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $repository->begin();
            try {
                $data['uid'] = Identifier::uid();
                $data['reference_code'] = null;
                $data = array_replace($data, $repository->creationAuditValues(gmdate('Y-m-d H:i:s'), get_current_user_id() ?: null));
                $id = $repository->insertLegacyBootstrap($data);
                $repository->assignReference($id, Identifier::reference('DZN-TRM-', $id));
                $repository->commit();
                return $id;
            } catch (\Throwable $exception) {
                $repository->rollback();
                if (!$repository->isUidCollision($exception)) throw $exception;
            }
        }
        throw new \RuntimeException('UID collision retry limit reached');
    }
}
