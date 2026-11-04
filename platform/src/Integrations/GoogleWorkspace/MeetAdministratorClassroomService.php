<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

use Delnavazan\Platform\Core\Application\ProviderReferenceVault;

/**
 * Administrator read of the central Meet classroom. It returns a join URI only to a caller holding
 * lesson-management authority; the URI is still not rendered into a public page.
 */
final class MeetAdministratorClassroomService {
    public function __construct(
        private ?GoogleWorkspaceRepository $repository=null,
        private ?ProviderReferenceVault $vault=null
    ){
        $this->repository??=new GoogleWorkspaceRepository();
        $this->vault??=new ProviderReferenceVault();
    }

    public function forLesson(int $lessonId):array{
        $this->authorise();
        $version=$this->repository->currentScheduleVersion($lessonId);
        if(!$version)return $this->missing('missing_schedule',array('kind'=>'lesson','lesson_id'=>$lessonId));
        $classroom=$this->repository->activeClassroomForLesson($lessonId);
        if(!$classroom)return $this->missing('not_found',array('kind'=>'lesson','lesson_id'=>$lessonId,'schedule_version_uid'=>(string)$version->uid));
        return $this->context($classroom,'lesson',array('lesson_id'=>$lessonId,'schedule_version_uid'=>(string)$version->uid));
    }

    public function forBooking(string $confirmationUid):array{
        $this->authorise();
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $row=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$p}booking_request_confirmations WHERE uid=%s LIMIT 1",trim($confirmationUid)));
        if(!$row)return $this->missing('not_found',array('kind'=>'booking','confirmation_uid'=>$confirmationUid));
        $classroom=$this->repository->activeClassroomForBooking((int)$row->id);
        if(!$classroom)return $this->missing('not_found',array('kind'=>'booking','confirmation_uid'=>$confirmationUid));
        return $this->context($classroom,'booking',array('confirmation_uid'=>$confirmationUid));
    }

    /** Resolve the URI for the POST join boundary after all checks. */
    public function joinUriForLesson(int $lessonId):string{
        $context=$this->forLesson($lessonId);
        if(empty($context['join_uri']))throw new \RuntimeException('meet_classroom_unavailable');
        return (string)$context['join_uri'];
    }

    public function joinUriForBooking(string $confirmationUid):string{
        $context=$this->forBooking($confirmationUid);
        if(empty($context['join_uri']))throw new \RuntimeException('meet_classroom_unavailable');
        return (string)$context['join_uri'];
    }

    private function context(object $classroom,string $kind,array $extra):array{
        $ready=in_array((string)$classroom->classroom_state,array('ready','rescheduled'),true);
        $uri=$ready?$this->openUri($classroom):null;
        return array_merge($extra,array(
            'kind'=>$kind,'state'=>$ready?'ready':'not_found','available'=>$uri!==null,
            'livekit_available'=>$uri!==null,'meet_available'=>$uri!==null,
            'classroom_id'=>(int)$classroom->id,'join_uri'=>$uri,
        ));
    }

    private function missing(string $state,array $extra):array{
        return array_merge($extra,array('state'=>$state,'available'=>false,'livekit_available'=>false,'meet_available'=>false,'join_uri'=>null));
    }

    private function openUri(object $classroom):?string{
        $row=$this->repository->reference((int)$classroom->id);
        if(!$row||(string)$row->state!=='active')return null;
        try{
            $refs=$this->vault->open('meet_classroom',(int)$classroom->id,$row);
            return GoogleWorkspaceClassroomRule::meetUri($refs['join_uri_reference']??'');
        }catch(\Throwable){return null;}
    }

    private function authorise():void{
        if(function_exists('current_user_can')&&(current_user_can('manage_options')||current_user_can('dzn_manage_lessons')||current_user_can('dzn_manage_canonical_attendance_review')))return;
        throw new \RuntimeException('Unauthorized');
    }
}
