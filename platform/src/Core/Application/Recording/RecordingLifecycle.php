<?php
namespace Delnavazan\Platform\Core\Application\Recording;

/**
 * Pure canonical recording lifecycle.
 *
 * No WordPress, database, provider or clock is read here. Callers must supply the expected prior
 * state and optimistic version resolved from durable storage. The table below is the single
 * transition authority used by the WordPress control plane, the recorder service and their tests.
 */
final class RecordingLifecycle {
    public const AUTHORISED='authorised';
    public const RECORDING_REQUESTED='recording_requested';
    public const RECORDING_ACTIVE='recording_active';
    public const RECORDING_FINALISING='recording_finalising';
    public const RECORDING_VERIFIED='recording_verified';
    public const AWAITING_ARCHIVE='awaiting_archive';
    public const TRANSFERRING='transferring';
    public const TRANSFER_RETRY_PENDING='transfer_retry_pending';
    public const ARCHIVE_VERIFICATION='archive_verification';
    public const ARCHIVED='archived';
    public const VPS_DELETION_PENDING='vps_deletion_pending';
    public const ARCHIVED_VPS_DELETED='archived_vps_deleted';
    public const FAILED='failed';
    public const QUARANTINED='quarantined';
    public const DELETION_REQUIRED='deletion_required';

    public const STATES=array(
        self::AUTHORISED,self::RECORDING_REQUESTED,self::RECORDING_ACTIVE,self::RECORDING_FINALISING,
        self::RECORDING_VERIFIED,self::AWAITING_ARCHIVE,self::TRANSFERRING,self::TRANSFER_RETRY_PENDING,
        self::ARCHIVE_VERIFICATION,self::ARCHIVED,self::VPS_DELETION_PENDING,self::ARCHIVED_VPS_DELETED,
        self::FAILED,self::QUARANTINED,self::DELETION_REQUIRED,
    );

    public const EVENTS=array(
        'request_start','egress_started','egress_completed','media_verified','transfer_queued',
        'transfer_started','transfer_verified','transfer_failed','transfer_retry','archive_confirmed',
        'archive_mismatch','deletion_queued','source_deleted','withdrawal_stop','invalidate',
        'fail','quarantine','require_deletion','reconcile_recovered','provider_duplicate','provider_out_of_order',
    );

    /** From state => event => destination state. A missing pair is refused. */
    public const TRANSITIONS=array(
        self::AUTHORISED=>array(
            'request_start'=>self::RECORDING_REQUESTED,
            'invalidate'=>self::FAILED,
            'withdrawal_stop'=>self::DELETION_REQUIRED,
        ),
        self::RECORDING_REQUESTED=>array(
            'egress_started'=>self::RECORDING_ACTIVE,
            'withdrawal_stop'=>self::DELETION_REQUIRED,
            'fail'=>self::FAILED,
            'invalidate'=>self::FAILED,
        ),
        self::RECORDING_ACTIVE=>array(
            'egress_completed'=>self::RECORDING_FINALISING,
            'withdrawal_stop'=>self::RECORDING_FINALISING,
            'fail'=>self::FAILED,
        ),
        self::RECORDING_FINALISING=>array(
            'media_verified'=>self::RECORDING_VERIFIED,
            'withdrawal_stop'=>self::QUARANTINED,
            'quarantine'=>self::QUARANTINED,
            'fail'=>self::FAILED,
        ),
        self::RECORDING_VERIFIED=>array(
            'transfer_queued'=>self::AWAITING_ARCHIVE,
            'withdrawal_stop'=>self::QUARANTINED,
            'quarantine'=>self::QUARANTINED,
            'fail'=>self::FAILED,
        ),
        self::AWAITING_ARCHIVE=>array(
            'transfer_started'=>self::TRANSFERRING,
            'withdrawal_stop'=>self::DELETION_REQUIRED,
            'fail'=>self::FAILED,
        ),
        self::TRANSFERRING=>array(
            'transfer_verified'=>self::ARCHIVE_VERIFICATION,
            'transfer_failed'=>self::TRANSFER_RETRY_PENDING,
            'withdrawal_stop'=>self::DELETION_REQUIRED,
            'fail'=>self::FAILED,
        ),
        self::TRANSFER_RETRY_PENDING=>array(
            'transfer_started'=>self::TRANSFERRING,
            'withdrawal_stop'=>self::DELETION_REQUIRED,
            'require_deletion'=>self::DELETION_REQUIRED,
            'fail'=>self::FAILED,
        ),
        self::ARCHIVE_VERIFICATION=>array(
            'archive_confirmed'=>self::ARCHIVED,
            'archive_mismatch'=>self::QUARANTINED,
            'withdrawal_stop'=>self::QUARANTINED,
            'fail'=>self::FAILED,
        ),
        self::ARCHIVED=>array(
            'deletion_queued'=>self::VPS_DELETION_PENDING,
            'withdrawal_stop'=>self::DELETION_REQUIRED,
            'quarantine'=>self::QUARANTINED,
        ),
        self::VPS_DELETION_PENDING=>array(
            'source_deleted'=>self::ARCHIVED_VPS_DELETED,
            'withdrawal_stop'=>self::DELETION_REQUIRED,
            'fail'=>self::FAILED,
        ),
        self::ARCHIVED_VPS_DELETED=>array(
            'withdrawal_stop'=>self::DELETION_REQUIRED,
            'quarantine'=>self::QUARANTINED,
        ),
        self::FAILED=>array(
            'reconcile_recovered'=>self::AWAITING_ARCHIVE,
            'withdrawal_stop'=>self::DELETION_REQUIRED,
            'require_deletion'=>self::DELETION_REQUIRED,
        ),
        self::QUARANTINED=>array(
            'require_deletion'=>self::DELETION_REQUIRED,
            'withdrawal_stop'=>self::DELETION_REQUIRED,
        ),
        self::DELETION_REQUIRED=>array(
            'source_deleted'=>self::ARCHIVED_VPS_DELETED,
            'fail'=>self::FAILED,
        ),
    );

    /** States in which capture has begun and the immutable schedule/participant snapshot binds. */
    public const CAPTURE_STARTED_STATES=array(
        self::RECORDING_ACTIVE,self::RECORDING_FINALISING,self::RECORDING_VERIFIED,self::AWAITING_ARCHIVE,
        self::TRANSFERRING,self::TRANSFER_RETRY_PENDING,self::ARCHIVE_VERIFICATION,self::ARCHIVED,
        self::VPS_DELETION_PENDING,self::ARCHIVED_VPS_DELETED,self::QUARANTINED,self::DELETION_REQUIRED,
    );

    public static function isState(string $state):bool{return in_array($state,self::STATES,true);}
    public static function isTerminal(string $state):bool{return in_array($state,array(self::ARCHIVED_VPS_DELETED,self::FAILED),true);}
    public static function captureStarted(string $state):bool{return in_array($state,self::CAPTURE_STARTED_STATES,true);}
    public static function playbackCandidate(string $state):bool{return in_array($state,array(self::ARCHIVED,self::VPS_DELETION_PENDING,self::ARCHIVED_VPS_DELETED),true);}
    public static function deletionCandidate(string $state):bool{return in_array($state,array(self::DELETION_REQUIRED,self::QUARANTINED),true);}

    /**
     * Apply one event with compare-and-swap semantics.
     *
     * @return array{ok:bool,idempotent:bool,state:string,version:int,reason:string}
     */
    public static function apply(string $currentState,string $expectedState,int $currentVersion,int $expectedVersion,string $event):array{
        if(!self::isState($currentState))return self::refuse($currentState,$currentVersion,'state_unknown');
        if(!in_array($event,self::EVENTS,true))return self::refuse($currentState,$currentVersion,'event_unknown');
        if($expectedState!==''&&$currentState!==$expectedState)return self::refuse($currentState,$currentVersion,'expected_state_mismatch');
        if($expectedVersion>0&&$currentVersion!==$expectedVersion)return self::refuse($currentState,$currentVersion,'version_conflict');
        // A repeated provider event in the state it already produced is an idempotent success.
        $already=array(
            self::RECORDING_ACTIVE=>'egress_started',self::RECORDING_FINALISING=>'egress_completed',
            self::RECORDING_VERIFIED=>'media_verified',self::AWAITING_ARCHIVE=>'transfer_queued',
            self::TRANSFERRING=>'transfer_started',self::ARCHIVE_VERIFICATION=>'transfer_verified',
            self::ARCHIVED=>'archive_confirmed',self::VPS_DELETION_PENDING=>'deletion_queued',
            self::ARCHIVED_VPS_DELETED=>'source_deleted',self::FAILED=>'fail',self::QUARANTINED=>'quarantine',
            self::DELETION_REQUIRED=>'withdrawal_stop',
        );
        if(($already[$currentState]??null)===$event)return array('ok'=>true,'idempotent'=>true,'state'=>$currentState,'version'=>$currentVersion,'reason'=>'already_applied');
        $target=self::TRANSITIONS[$currentState][$event]??null;
        if($target===null)return self::refuse($currentState,$currentVersion,'transition_not_allowed');
        return array('ok'=>true,'idempotent'=>false,'state'=>$target,'version'=>$currentVersion+1,'reason'=>'applied');
    }

    private static function refuse(string $state,int $version,string $reason):array{
        return array('ok'=>false,'idempotent'=>false,'state'=>$state,'version'=>$version,'reason'=>$reason);
    }
}
