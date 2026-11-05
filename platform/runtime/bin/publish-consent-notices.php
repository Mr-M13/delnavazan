<?php
/**
 * Guarded consent-notice publisher.
 *
 * Usage:
 *   DZN_CONSENT_NOTICE_FILE=platform/content/consent-notices/2026-11-04-draft.json \
 *   DZN_CONSENT_NOTICE_CONFIRM=PUBLISH_CONSENT_NOTICES \
 *   wp eval-file platform/runtime/bin/publish-consent-notices.php --user=1
 *
 * The script refuses unresolved placeholders, draft markers, unsupported audiences/languages,
 * unsafe markup and short bodies. It publishes through the existing append-only notice authority
 * and never edits or deletes an earlier version. It cannot run without the literal confirmation.
 */
if(!defined('WP_CLI')||!WP_CLI){fwrite(STDERR,"This publisher must run through WP-CLI.\n");exit(1);}
if((string)getenv('DZN_CONSENT_NOTICE_CONFIRM')!=='PUBLISH_CONSENT_NOTICES'){fwrite(STDERR,"Refused: set DZN_CONSENT_NOTICE_CONFIRM=PUBLISH_CONSENT_NOTICES after explicit owner/legal approval.\n");exit(1);}
$file=(string)getenv('DZN_CONSENT_NOTICE_FILE');
if($file===''||!is_readable($file)){fwrite(STDERR,"Refused: --file must name a readable JSON notice set.\n");exit(1);}
if(!current_user_can('manage_options')||get_current_user_id()<1){fwrite(STDERR,"Refused: an authenticated administrator is required.\n");exit(1);}
$raw=(string)file_get_contents($file);
$data=json_decode($raw,true);
if(!is_array($data)||!isset($data['notices'])||!is_array($data['notices'])){fwrite(STDERR,"Refused: invalid notice JSON.\n");exit(1);}
$validation=(new Delnavazan\Platform\Core\Application\ConsentNoticeDraftValidator())->validateSet($data['notices']);
if(!$validation['valid']){
    fwrite(STDERR,"Refused: the notice set is not publishable.\n");
    foreach($validation['errors']as$index=>$errors)fwrite(STDERR,'  notice['.$index.']: '.implode(',',$errors)."\n");
    exit(1);
}
$service=new Delnavazan\Platform\Core\Application\RecordingNoticePublicationService();
$published=array();
foreach($data['notices']as$notice){
    $result=$service->publish((string)$notice['audience'],(string)$notice['language'],(string)$notice['body'],get_current_user_id());
    $published[]=$result;
    fwrite(STDOUT,'published '.$result['audience'].'/'.$result['language'].' v'.$result['version'].' '.$result['uid']."\n");
}
fwrite(STDOUT,'PUBLISHED_COUNT='.count($published)."\n");
