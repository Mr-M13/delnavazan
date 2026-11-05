<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Validation guard for a proposed consent-notice publication.
 *
 * It is deliberately independent of the browser and of the publication service: an operator can
 * prepare a JSON notice set, validate it, and only then publish. Any unresolved legal placeholder,
 * draft marker or unsupported audience/language refuses the whole set.
 */
final class ConsentNoticeDraftValidator {
    public const PLACEHOLDER_PATTERN='/\[\[[A-Z0-9_]+\]\]/';
    public const DRAFT_PATTERN='/\b(?:DRAFT|TODO|TBD|PLACEHOLDER)\b/i';

    /** @return string[] */
    public static function validate(array $notice):array{
        $errors=array();
        $audience=(string)($notice['audience']??'');
        $language=(string)($notice['language']??'');
        $body=trim((string)($notice['body']??''));
        if(!in_array($audience,RecordingNoticePublicationService::AUDIENCES,true))$errors[]='audience_invalid';
        if(!in_array($language,array('en','fa'),true))$errors[]='language_invalid';
        if(strlen($body)<40)$errors[]='body_too_short';
        if(preg_match(self::PLACEHOLDER_PATTERN,$body)===1)$errors[]='unresolved_placeholder';
        if(preg_match(self::DRAFT_PATTERN,$body)===1)$errors[]='draft_marker_present';
        if(stripos($body,'<script')!==false)$errors[]='unsafe_markup';
        return array_values(array_unique($errors));
    }

    /** @return array{valid:bool,errors:array<string,string[]>} */
    public static function validateSet(array $notices):array{
        $errors=array();
        foreach($notices as$index=>$notice){
            if(!is_array($notice)){$errors[(string)$index]=array('notice_invalid');continue;}
            $found=self::validate($notice);
            if($found!==array())$errors[(string)$index]=$found;
        }
        return array('valid'=>$errors===array(),'errors'=>$errors);
    }
}
