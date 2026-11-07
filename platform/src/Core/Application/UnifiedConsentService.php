<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Single-line bundled consent: one tick records every positive backend purpose at once.
 *
 * The append-only ledger still stores one decision per purpose with its own notice evidence.
 * Bundling only unifies the interaction; it does not merge purposes. Recording and research
 * gates continue to require their own positive decisions.
 *
 * Teacher recording consent is mandatory (they cannot work without it). Student consent is
 * optional and may be withdrawn at any time from Account Settings.
 */
final class UnifiedConsentService {
    public const TEACHER_PURPOSES=array(
        ConsentPurposeRegistry::TEACHER_TERMS=>'accepted',
        ConsentPurposeRegistry::TEACHER_RECORDING=>'allow',
        ConsentPurposeRegistry::TEACHER_RESEARCH=>'opt_in',
        ConsentPurposeRegistry::PRIVACY_TERMS=>'accepted',
    );
    public const STUDENT_PURPOSES=array(
        ConsentPurposeRegistry::STUDENT_RECORDING=>'allow',
        ConsentPurposeRegistry::STUDENT_RESEARCH=>'opt_in',
        ConsentPurposeRegistry::PRIVACY_TERMS=>'accepted',
    );

    private RecordingDecisionAuthorityService $authority;
    private ConsentWithdrawalService $withdrawals;

    public function __construct(?RecordingDecisionAuthorityService $authority=null,?ConsentWithdrawalService $withdrawals=null){
        $this->authority=$authority??new RecordingDecisionAuthorityService();
        $this->withdrawals=$withdrawals??new ConsentWithdrawalService($this->authority);
    }

    public function acceptTeacherUnified(string $baseKey,string $preferredLanguage='en',string $channel='portal_unified',string $workflow='teacher_unified'):array{
        $language=$this->language($preferredLanguage);
        $notices=$this->resolveNotices(array_keys(self::TEACHER_PURPOSES),$language);
        $out=array();
        foreach(self::TEACHER_PURPOSES as $purpose=>$choice){
            $notice=$notices[$purpose];
            if($purpose===ConsentPurposeRegistry::TEACHER_TERMS){
                $out[$purpose]=$this->authority->recordTeacherChoice($purpose,$choice,$notice['uid'],$notice['language'],$this->key($baseKey,$purpose),$channel,$workflow);
            }else{
                $out[$purpose]=$this->authority->recordTeacherChoice($purpose,$choice,$notice['uid'],$notice['language'],$this->key($baseKey,$purpose),$channel,$workflow);
            }
        }
        return $out;
    }

    public function acceptStudentUnified(string $baseKey,string $preferredLanguage='en',string $channel='portal_unified',string $workflow='student_unified'):array{
        $language=$this->language($preferredLanguage);
        $notices=$this->resolveNotices(array_keys(self::STUDENT_PURPOSES),$language);
        $out=array();
        foreach(self::STUDENT_PURPOSES as $purpose=>$choice){
            $notice=$notices[$purpose];
            $out[$purpose]=$this->authority->recordOwnStudentChoice($purpose,$choice,$notice['uid'],$notice['language'],$this->key($baseKey,$purpose),$channel,$workflow);
        }
        return $out;
    }

    /** Withdraw every withdrawable student purpose. Privacy acknowledgement is not withdrawable. */
    public function withdrawStudentAll(string $baseKey,string $preferredLanguage='en'):array{
        $language=$this->language($preferredLanguage);
        $notices=$this->resolveNotices(array(ConsentPurposeRegistry::STUDENT_RECORDING,ConsentPurposeRegistry::STUDENT_RESEARCH),$language);
        $recordingNotice=$notices[ConsentPurposeRegistry::STUDENT_RECORDING];
        $researchNotice=$notices[ConsentPurposeRegistry::STUDENT_RESEARCH];
        $out=array();
        $out[ConsentPurposeRegistry::STUDENT_RESEARCH]=$this->withdrawals->withdrawStudentResearch($researchNotice['uid'],$researchNotice['language'],$this->key($baseKey,ConsentPurposeRegistry::STUDENT_RESEARCH));
        $out[ConsentPurposeRegistry::STUDENT_RECORDING]=$this->withdrawals->withdrawStudentRecording($recordingNotice['uid'],$recordingNotice['language'],$this->key($baseKey,ConsentPurposeRegistry::STUDENT_RECORDING));
        return $out;
    }

    /** Whether the current teacher has both mandatory positive decisions on current notices. */
    public function teacherMandatoryAccepted():bool{
        try{$state=$this->authority->teacherConsentState();}
        catch(\Throwable){return false;}
        return !empty($state['terms']['positive'])&&!empty($state['recording']['positive']);
    }

    /** Whether the current student has both recording and research positive on current notices. */
    public function studentConsentActive():bool{
        try{$state=$this->authority->studentDecisionState();}
        catch(\Throwable){return false;}
        return !empty($state['recording']['positive'])&&!empty($state['research']['positive']);
    }

    private function resolveNotices(array $purposes,string $language):array{
        $out=array();
        foreach($purposes as $purpose){
            $audience=ConsentPurposeRegistry::audience($purpose);
            $notice=$this->authority->publishedNotice($audience,$language);
            if($notice===null){
                $other=$language==='fa'?'en':'fa';
                $notice=$this->authority->publishedNotice($audience,$other);
            }
            if($notice===null)throw new \RuntimeException('recording_notice_unavailable');
            $out[$purpose]=$notice;
        }
        return $out;
    }

    private function language(string $value):string{return str_starts_with(strtolower($value),'fa')?'fa':'en';}
    private function key(string $base,string $purpose):string{return $base.':'.$purpose;}
}
