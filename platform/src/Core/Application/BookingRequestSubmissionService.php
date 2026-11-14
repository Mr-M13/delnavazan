<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestIntakeRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository;
use Delnavazan\Platform\Core\Support\Identifier;

final class BookingRequestSubmissionService {
    public function __construct( private ?BookingRequestRepository $repo = null, private ?BookingRequestValidationService $validator = null, private ?BookingRequestIntakeRepository $intake = null ) { $this->repo ??= new BookingRequestRepository(); $this->validator ??= new BookingRequestValidationService(); $this->intake ??= new BookingRequestIntakeRepository(); }
    /** @return array{success:bool,request_reference:string} */
    /**
     * The optional gate is evaluated only after the idempotency operation is
     * locked and established replays/conflicts have been resolved. This keeps
     * secondary abuse controls from changing the authoritative retry outcome.
     */
    public function submitPublic(array $input, string $idempotencyKey, ?callable $newSubmissionGate = null, ?int $studentId = null): array {
        $this->repo->begin(); try { $result = $this->submitWithinTransaction( $input, $idempotencyKey, $newSubmissionGate, $studentId ); $this->repo->commit(); unset( $result['request_id'] ); return $result; } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    /**
     * Compensate a failed administrator-created intro. The public intake commits
     * before the Teacher match, so a later refusal must not leave an orphan
     * request/delivery in the queue. Only an unconfirmed, still-unresolved
     * request created by the same command is removable.
     */
    public function discardCreatedRequest(int $requestId, string $reasonCode): void {
        if ($requestId < 1) return;
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $this->repo->begin();
        try {
            $request = $wpdb->get_row($wpdb->prepare("SELECT id,lifecycle_status,resolution_state,privacy_erased_at FROM {$p}booking_requests WHERE id=%d FOR UPDATE", $requestId));
            if (!$request || (string) $request->lifecycle_status !== 'submitted' || (string) $request->resolution_state !== 'unresolved' || $request->privacy_erased_at !== null) { $this->repo->commit(); return; }
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_request_confirmations WHERE booking_request_id=%d LIMIT 1", $requestId)) > 0) { $this->repo->commit(); return; }
            $deliveryIds = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}booking_request_teacher_deliveries WHERE booking_request_id=%d", $requestId)));
            $offerIds = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}booking_allocation_offers WHERE booking_request_id=%d", $requestId)));
            if ($deliveryIds) {
                $in = implode(',', $deliveryIds);
                $wpdb->query("DELETE FROM {$p}booking_request_student_notifications WHERE delivery_id IN ({$in})");
                $wpdb->query("DELETE FROM {$p}booking_request_teacher_commands WHERE delivery_id IN ({$in})");
                $wpdb->query("DELETE FROM {$p}booking_request_confirmations WHERE delivery_id IN ({$in})");
            }
            if ($offerIds) {
                $in = implode(',', $offerIds);
                $wpdb->query("DELETE FROM {$p}booking_allocation_notifications WHERE offer_id IN ({$in})");
            }
            foreach (array(
                'booking_allocation_offers' => 'booking_request_id',
                'booking_allocation_alerts' => 'booking_request_id',
                'booking_request_teacher_declines' => 'booking_request_id',
                'booking_request_teacher_deliveries' => 'booking_request_id',
                'booking_request_intro_outcomes' => 'booking_request_id',
                'booking_request_contact_snapshots' => 'booking_request_id',
                'booking_request_requested_times' => 'booking_request_id',
                'booking_request_identity_resolution_events' => 'booking_request_id',
                'booking_request_submission_keys' => 'booking_request_id',
            ) as $table => $column) {
                if ($wpdb->delete($p . $table, array($column => $requestId)) === false) throw new \RuntimeException('Booking Request compensation failed: ' . $table);
            }
            foreach (array('booking_request_id', 'candidate_booking_request_id') as $column) {
                if ($wpdb->delete($p . 'booking_request_duplicate_flags', array($column => $requestId)) === false) throw new \RuntimeException('Booking Request compensation failed: duplicate flags');
            }
            $detail = wp_json_encode(array('request_id' => $requestId, 'reason_code' => sanitize_key($reasonCode)));
            $wpdb->insert($p . 'platform_audit_events', array(
                'aggregate_type' => 'booking_request', 'aggregate_id' => $requestId,
                'event_type' => 'booking_request.admin_intro_compensated', 'actor_type' => 'administrator',
                'actor_id' => (int) get_current_user_id(), 'reason_code' => sanitize_key($reasonCode),
                'safe_detail' => is_string($detail) ? $detail : null,
                'idempotency_key' => hash_hmac('sha256', 'admin-intro-compensate:' . $requestId, wp_salt('dzn_platform_audit')),
                'occurred_at' => gmdate('Y-m-d H:i:s'),
            ));
            if ($wpdb->delete($p . 'booking_requests', array('id' => $requestId)) === false) throw new \RuntimeException('Booking Request compensation failed: request');
            $this->repo->commit();
        } catch (\Throwable $error) {
            $this->repo->rollback();
            throw $error;
        }
    }

    /** Internal composition seam for an owner that already holds the transaction. */
    public function submitWithinTransaction(array $input, string $idempotencyKey, ?callable $newSubmissionGate = null, ?int $studentId = null): array {
        $data = $this->validator->normalizePublic( $input ); $now = gmdate( 'Y-m-d H:i:s' ); $retention = gmdate( 'Y-m-d H:i:s', strtotime( '+24 months', strtotime( $now . ' UTC' ) ) ); $keyDigest = BookingRequestIdempotency::keyDigest($idempotencyKey); $payloadDigest = BookingRequestIdempotency::payloadDigest($data); $expires = gmdate('Y-m-d H:i:s', strtotime('+' . BookingRequestIdempotency::WINDOW_SECONDS . ' seconds', strtotime($now . ' UTC')));
        $uid = Identifier::uid(); $reference = 'REQ-' . $uid;
        $operation = $this->intake->reserveSubmissionKey($keyDigest, $payloadDigest, $expires, $now);
            if ( $operation->state === 'completed' && strtotime((string)$operation->expires_at . ' UTC') < strtotime($now . ' UTC') ) { $this->intake->restartExpiredSubmissionKey((int)$operation->id,$payloadDigest,$expires,$now); $operation->state='processing'; $operation->payload_digest=$payloadDigest; $operation->created_new=true; }
            if ( ! hash_equals((string)$operation->payload_digest, $payloadDigest) ) throw new IdempotencyConflictException('Idempotency conflict');
            if ( $operation->state === 'completed' && $operation->response_reference && $operation->booking_request_id ) { return array('success'=>true,'request_reference'=>(string)$operation->response_reference,'request_id'=>(int)$operation->booking_request_id,'replayed'=>true); }
            if ( ! $operation->created_new || $operation->state !== 'processing' ) throw new IdempotencyConflictException('Idempotency operation unavailable');
            if ( $newSubmissionGate !== null && ! $newSubmissionGate() ) throw new BookingRequestRateLimitException('Booking Request rate limited');
            // The Student scheduling root is acquired before catalogue locks and the
            // occupancy read, and is held until the account owner commits.
            if ( $studentId !== null && $studentId > 0 ) {
                $this->repo->ensureAndLockStudentRoot( $studentId, $now, max( 0, (int) get_current_user_id() ) );
                do_action( 'dzn_booking_student_root_held', 'submission', $studentId );
            }
            $instrument = $this->repo->instrumentForUpdate( $data['instrument_id'] ); $course = $data['course_id'] ? $this->repo->courseForUpdate( $data['course_id'] ) : null;
            if ( ! $data['course_id'] ) {
                $defaultCourse = $this->repo->defaultIntroCourseForUpdate( (int) $data['instrument_id'] );
                if ( ! $defaultCourse ) throw new \InvalidArgumentException( 'Valid active Intro Course for Instrument required' );
                $data['course_id'] = (int) $defaultCourse->id;
                $course = $defaultCourse;
            }
            $data = $this->validator->resolveLockedCatalogue( $data, $instrument, $course );
            if ( $studentId !== null && $studentId > 0 && $this->repo->studentHasActiveInstrumentEnrolment( $studentId, (int) $data['instrument_id'] ) ) {
                throw new BookingRequestInstrumentEnrollmentException( 'student_instrument_already_enrolled' );
            }
            if ( $studentId !== null && $studentId > 0 && $this->repo->studentOccupancyConflicts( $studentId, $data['times'], array( 'include_open_requests' => true ) ) ) throw new BookingRequestSlotConflictException('student_slot_conflict');
            $id = $this->repo->createRequest( array( 'uid' => $uid, 'reference_code' => null, 'student_id' => null, 'requested_instrument_id' => $data['instrument_id'], 'selected_intro_course_id' => $data['course_id'], 'lifecycle_status' => 'submitted', 'resolution_state' => 'unresolved', 'retention_due_at' => $retention, 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => null, 'updated_by' => null ) );
            $this->repo->assignReference( $id, $reference ); $digests = array('email_digest'=>BookingRequestIdempotency::privateDigest($data['contact']['email']),'mobile_digest'=>BookingRequestIdempotency::privateDigest($data['contact']['mobile']),'whatsapp_digest'=>BookingRequestIdempotency::privateDigest($data['contact']['whatsapp_number'])); $this->repo->createSnapshot( $id, $data['contact'], $now, $digests );
            $persistedTimes=array();foreach ( $data['times'] as $sequence => $time ){$timeId=$this->repo->createTime( $id, $sequence + 1, $time, $now );$persistedTimes[]=array('id'=>$timeId,'sequence'=>$sequence+1,'starts_at_utc'=>$time['starts_at_utc'],'occupied_ends_at_utc'=>$time['occupied_ends_at_utc']);}
            // Delivery is a pending Teacher inbox request only. No lesson, reservation,
            // confirmation, Calendar event or Meet space is created at intake.  Notification
            // jobs are persisted here but handed off later by their leased worker.
            if($data['course_id']) {
                $delivery=(new BookingTeacherDeliveryService())->deliver($id,(int)$data['course_id'],$persistedTimes,$now);
                // The student receipt stays in the leased booking queue. The Teacher's
                // notice is already queued per offer by the allocation ledger, including
                // later rotations; never enqueue a second initial Teacher notice here.
                if($delivery!==null)(new BookingTeacherResponseRepository())->queueRequestedNotifications((int)$delivery['delivery_id'],$now);
            }
            foreach($this->intake->matchingContactRequests($digests,$id) as $match)$this->intake->createDuplicateFlag($id,$match['candidate_id'],$match['type'],$match['digest'],$now);
            $this->intake->completeSubmissionKey((int)$operation->id,$id,$reference,$now); return array( 'success' => true, 'request_reference' => $reference, 'request_id' => $id, 'replayed' => false );
    }
}
