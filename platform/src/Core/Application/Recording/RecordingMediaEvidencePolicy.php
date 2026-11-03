<?php
namespace Delnavazan\Platform\Core\Application\Recording;

/**
 * Fail-closed validation of the recorder's signed media evidence.
 *
 * The WordPress control plane never verifies an Egress job merely because a webhook says
 * "complete". It requires a closed MP4 file, both expected streams, codec compatibility, a
 * plausible duration and a SHA-256 calculated from the final source file.
 */
final class RecordingMediaEvidencePolicy {
    public const REASONS=array(
        'verified','egress_not_complete','file_not_closed','format_unsupported','video_stream_missing',
        'audio_stream_missing','video_codec_unsupported','audio_codec_unsupported','duration_missing',
        'duration_implausible','size_missing','size_unstable','digest_invalid','streams_mismatch',
    );

    /**
     * @param array<string,mixed> $evidence
     * @return array{verified:bool,reason:string}
     */
    public function evaluate(array $evidence,int $scheduledDurationSeconds):array{
        if(($evidence['egress_complete']??false)!==true)return self::deny('egress_not_complete');
        if(($evidence['file_closed']??false)!==true)return self::deny('file_not_closed');
        if(strtolower((string)($evidence['format']??''))!=='mp4')return self::deny('format_unsupported');
        if((int)($evidence['video_streams']??0)<1)return self::deny('video_stream_missing');
        if((int)($evidence['audio_streams']??0)<1)return self::deny('audio_stream_missing');
        if(strtolower((string)($evidence['video_codec']??''))!=='h264')return self::deny('video_codec_unsupported');
        if(strtolower((string)($evidence['audio_codec']??''))!=='aac')return self::deny('audio_codec_unsupported');
        $durationMs=(int)($evidence['duration_ms']??0);
        if($durationMs<1)return self::deny('duration_missing');
        if($scheduledDurationSeconds>0){
            $scheduledMs=$scheduledDurationSeconds*1000;
            $lower=(int)floor($scheduledMs*0.90);
            $upper=(int)ceil($scheduledMs*1.10)+5000;
            if($durationMs<$lower||$durationMs>$upper)return self::deny('duration_implausible');
        }
        $bytes=(int)($evidence['source_bytes']??0);
        if($bytes<1024)return self::deny('size_missing');
        if(($evidence['size_stable']??false)!==true)return self::deny('size_unstable');
        $digest=strtolower((string)($evidence['sha256']??''));
        if(preg_match('/^[a-f0-9]{64}$/D',$digest)!==1)return self::deny('digest_invalid');
        if((int)($evidence['expected_video_streams']??1)!==(int)($evidence['video_streams']??0))return self::deny('streams_mismatch');
        if((int)($evidence['expected_audio_streams']??1)!==(int)($evidence['audio_streams']??0))return self::deny('streams_mismatch');
        return array('verified'=>true,'reason'=>'verified');
    }

    public static function deny(string $reason):array{
        if(!in_array($reason,self::REASONS,true))$reason='streams_mismatch';
        return array('verified'=>false,'reason'=>$reason);
    }
}
