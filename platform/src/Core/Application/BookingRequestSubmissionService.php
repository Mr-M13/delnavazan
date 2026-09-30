<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestIntakeRepository;
use Delnavazan\Platform\Core\Support\Identifier;

final class BookingRequestSubmissionService {
    public function __construct( private ?BookingRequestRepository $repo = null, private ?BookingRequestValidationService $validator = null, private ?BookingRequestIntakeRepository $intake = null ) { $this->repo ??= new BookingRequestRepository(); $this->validator ??= new BookingRequestValidationService(); $this->intake ??= new BookingRequestIntakeRepository(); }
    /** @return array{success:bool,request_reference:string} */
    /**
     * The optional gate is evaluated only after the idempotency operation is
     * locked and established replays/conflicts have been resolved. This keeps
     * secondary abuse controls from changing the authoritative retry outcome.
     */
    public function submitPublic(array $input, string $idempotencyKey, ?callable $newSubmissionGate = null): array {
        $data = $this->validator->normalizePublic( $input ); $now = gmdate( 'Y-m-d H:i:s' ); $retention = gmdate( 'Y-m-d H:i:s', strtotime( '+24 months', strtotime( $now . ' UTC' ) ) ); $keyDigest = BookingRequestIdempotency::keyDigest($idempotencyKey); $payloadDigest = BookingRequestIdempotency::payloadDigest($data); $expires = gmdate('Y-m-d H:i:s', strtotime('+' . BookingRequestIdempotency::WINDOW_SECONDS . ' seconds', strtotime($now . ' UTC')));
        $uid = Identifier::uid(); $reference = 'REQ-' . $uid;
        $this->repo->begin(); try {
            $operation = $this->intake->reserveSubmissionKey($keyDigest, $payloadDigest, $expires, $now);
            if ( $operation->state === 'completed' && strtotime((string)$operation->expires_at . ' UTC') < strtotime($now . ' UTC') ) { $this->intake->restartExpiredSubmissionKey((int)$operation->id,$payloadDigest,$expires,$now); $operation->state='processing'; $operation->payload_digest=$payloadDigest; $operation->created_new=true; }
            if ( ! hash_equals((string)$operation->payload_digest, $payloadDigest) ) throw new IdempotencyConflictException('Idempotency conflict');
            if ( $operation->state === 'completed' && $operation->response_reference ) { $this->repo->commit(); return array('success'=>true,'request_reference'=>(string)$operation->response_reference,'replayed'=>true); }
            if ( ! $operation->created_new || $operation->state !== 'processing' ) throw new IdempotencyConflictException('Idempotency operation unavailable');
            if ( $newSubmissionGate !== null && ! $newSubmissionGate() ) throw new BookingRequestRateLimitException('Booking Request rate limited');
            $instrument = $this->repo->instrumentForUpdate( $data['instrument_id'] ); $course = $data['course_id'] ? $this->repo->courseForUpdate( $data['course_id'] ) : null; $data = $this->validator->resolveLockedCatalogue( $data, $instrument, $course );
            $id = $this->repo->createRequest( array( 'uid' => $uid, 'reference_code' => null, 'student_id' => null, 'requested_instrument_id' => $data['instrument_id'], 'selected_intro_course_id' => $data['course_id'], 'lifecycle_status' => 'submitted', 'resolution_state' => 'unresolved', 'retention_due_at' => $retention, 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => null, 'updated_by' => null ) );
            $this->repo->assignReference( $id, $reference ); $digests = array('email_digest'=>BookingRequestIdempotency::privateDigest($data['contact']['email']),'mobile_digest'=>BookingRequestIdempotency::privateDigest($data['contact']['mobile']),'whatsapp_digest'=>BookingRequestIdempotency::privateDigest($data['contact']['whatsapp_number'])); $this->repo->createSnapshot( $id, $data['contact'], $now, $digests ); foreach ( $data['times'] as $sequence => $time ) $this->repo->createTime( $id, $sequence + 1, $time, $now );
            foreach($this->intake->matchingContactRequests($digests,$id) as $match)$this->intake->createDuplicateFlag($id,$match['candidate_id'],$match['type'],$match['digest'],$now);
            $this->intake->completeSubmissionKey((int)$operation->id,$id,$reference,$now); $this->repo->commit(); return array( 'success' => true, 'request_reference' => $reference, 'replayed' => false );
        } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }
}
