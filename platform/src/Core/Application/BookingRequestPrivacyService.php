<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestIntakeRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\CoordinationCaseRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherAvailabilityAssentRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\StudentIdentityAuthorityRepository;
use Delnavazan\Platform\Core\Application\ExceptionService;
use Delnavazan\Platform\Core\Support\Identifier;

/** High-trust privacy action; it closes request authority before clearing PII. */
final class BookingRequestPrivacyService {
    public function __construct(private ?BookingRequestRepository $requests=null, private ?BookingRequestIntakeRepository $intake=null, private ?CoordinationCaseRepository $coordination=null, private ?TeacherAvailabilityAssentRepository $assents=null, private ?StudentIdentityAuthorityRepository $identity=null) { $this->requests ??= new BookingRequestRepository(); $this->intake ??= new BookingRequestIntakeRepository(); $this->coordination ??= new CoordinationCaseRepository(); $this->assents ??= new TeacherAvailabilityAssentRepository(); $this->identity ??= new StudentIdentityAuthorityRepository(); }
    public function erase(int $requestId, int $actorId, string $reason): void {
        if (!preg_match('/^[a-z0-9_]{3,64}$/D',$reason)) throw new \InvalidArgumentException('Invalid erasure reason');
        $now=gmdate('Y-m-d H:i:s'); $this->requests->begin(); try { $request=$this->intake->requestForUpdate($requestId); if(!$request || $request->lifecycle_status!=='submitted' || $request->resolution_state!=='unresolved' || !$this->identity->privacyResolutionConsistent($request))throw new \InvalidArgumentException('Booking Request cannot be erased'); do_action('dzn_phase_2a2e_privacy_locks_held'); $this->coordination->terminateForPrivacyErasure($requestId,$actorId,$now,static fn(string $scope):string=>hash_hmac('sha256','coordination-privacy-erasure:'.$requestId.':'.$scope.':'.Identifier::uid(),wp_salt('dzn_coordination_audit'))); $this->assents->invalidateForPrivacyErasure($requestId,$actorId,$now,static fn(string $scope):string=>hash_hmac('sha256','teacher-assent-privacy-erasure:'.$requestId.':'.$scope.':'.Identifier::uid(),wp_salt('dzn_teacher_assent_audit'))); $this->intake->eraseRequestPii($requestId,$actorId,$reason,BookingRequestIdempotency::tombstoneDigest($requestId,$now),$now); (new ExceptionService())->removeTrustedBookingRequestMatchAttention($requestId); $this->requests->commit(); } catch(\Throwable $e){$this->requests->rollback();throw $e;}
    }
}
