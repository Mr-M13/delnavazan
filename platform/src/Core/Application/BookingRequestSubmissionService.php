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
        $this->repo->begin(); try { $result = $this->submitWithinTransaction( $input, $idempotencyKey, $newSubmissionGate ); $this->repo->commit(); unset( $result['request_id'] ); return $result; } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    /** Internal composition seam for an owner that already holds the transaction. */
    public function submitWithinTransaction(array $input, string $idempotencyKey, ?callable $newSubmissionGate = null): array {
        $data = $this->validator->normalizePublic( $input ); $now = gmdate( 'Y-m-d H:i:s' ); $retention = gmdate( 'Y-m-d H:i:s', strtotime( '+24 months', strtotime( $now . ' UTC' ) ) ); $keyDigest = BookingRequestIdempotency::keyDigest($idempotencyKey); $payloadDigest = BookingRequestIdempotency::payloadDigest($data); $expires = gmdate('Y-m-d H:i:s', strtotime('+' . BookingRequestIdempotency::WINDOW_SECONDS . ' seconds', strtotime($now . ' UTC')));
        $uid = Identifier::uid(); $reference = 'REQ-' . $uid;
        $operation = $this->intake->reserveSubmissionKey($keyDigest, $payloadDigest, $expires, $now);
            if ( $operation->state === 'completed' && strtotime((string)$operation->expires_at . ' UTC') < strtotime($now . ' UTC') ) { $this->intake->restartExpiredSubmissionKey((int)$operation->id,$payloadDigest,$expires,$now); $operation->state='processing'; $operation->payload_digest=$payloadDigest; $operation->created_new=true; }
            if ( ! hash_equals((string)$operation->payload_digest, $payloadDigest) ) throw new IdempotencyConflictException('Idempotency conflict');
            if ( $operation->state === 'completed' && $operation->response_reference && $operation->booking_request_id ) { return array('success'=>true,'request_reference'=>(string)$operation->response_reference,'request_id'=>(int)$operation->booking_request_id,'replayed'=>true); }
            if ( ! $operation->created_new || $operation->state !== 'processing' ) throw new IdempotencyConflictException('Idempotency operation unavailable');
            if ( $newSubmissionGate !== null && ! $newSubmissionGate() ) throw new BookingRequestRateLimitException('Booking Request rate limited');
            $instrument = $this->repo->instrumentForUpdate( $data['instrument_id'] ); $course = $data['course_id'] ? $this->repo->courseForUpdate( $data['course_id'] ) : null; $data = $this->validator->resolveLockedCatalogue( $data, $instrument, $course );
            $id = $this->repo->createRequest( array( 'uid' => $uid, 'reference_code' => null, 'student_id' => null, 'requested_instrument_id' => $data['instrument_id'], 'selected_intro_course_id' => $data['course_id'], 'lifecycle_status' => 'submitted', 'resolution_state' => 'unresolved', 'retention_due_at' => $retention, 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => null, 'updated_by' => null ) );
            $this->repo->assignReference( $id, $reference ); $digests = array('email_digest'=>BookingRequestIdempotency::privateDigest($data['contact']['email']),'mobile_digest'=>BookingRequestIdempotency::privateDigest($data['contact']['mobile']),'whatsapp_digest'=>BookingRequestIdempotency::privateDigest($data['contact']['whatsapp_number'])); $this->repo->createSnapshot( $id, $data['contact'], $now, $digests );
            $persistedTimes=array();foreach ( $data['times'] as $sequence => $time ){$timeId=$this->repo->createTime( $id, $sequence + 1, $time, $now );$persistedTimes[]=array('id'=>$timeId,'sequence'=>$sequence+1,'starts_at_utc'=>$time['starts_at_utc'],'occupied_ends_at_utc'=>$time['occupied_ends_at_utc']);}
            // Delivery is a pending Teacher inbox request only. No lesson, reservation,
            // confirmation, email, Calendar event or Meet space is created at intake.
            if($data['course_id']) (new BookingTeacherDeliveryService())->deliver($id,(int)$data['course_id'],$persistedTimes,$now);
            foreach($this->intake->matchingContactRequests($digests,$id) as $match)$this->intake->createDuplicateFlag($id,$match['candidate_id'],$match['type'],$match['digest'],$now);
            $this->intake->completeSubmissionKey((int)$operation->id,$id,$reference,$now); return array( 'success' => true, 'request_reference' => $reference, 'request_id' => $id, 'replayed' => false );
    }
}
