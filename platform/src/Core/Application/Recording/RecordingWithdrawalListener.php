<?php
namespace Delnavazan\Platform\Core\Application\Recording;

use Delnavazan\Platform\Core\Application\ConsentPurposeRegistry;

/**
 * Connects an append-only recording withdrawal to the recording lifecycle.
 *
 * This listener never creates consent evidence. It only stops future requests, queues a
 * server-side stop when capture is active, quarantines affected media and revokes playback.
 */
final class RecordingWithdrawalListener {
    public static function register():void{
        add_action('dzn_recording_withdrawal_requested',array(__CLASS__,'withdrawal'),10,5);
    }

    public static function withdrawal(string $subjectType,int $subjectId,string $purpose,int $decisionId,int $actor):void{
        if(!in_array($purpose,array(ConsentPurposeRegistry::TEACHER_RECORDING,ConsentPurposeRegistry::STUDENT_RECORDING),true))return;
        try{
            (new RecordingControlPlane())->withdrawForSubject($subjectType,$subjectId,max(0,$actor),'recording_consent_withdrawn');
        }catch(\Throwable $error){
            do_action('dzn_recording_withdrawal_failed',$subjectType,$subjectId,$decisionId,get_class($error));
        }
    }
}
