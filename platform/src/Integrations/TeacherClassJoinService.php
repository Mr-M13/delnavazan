<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\ProviderReferenceVault;
use Delnavazan\Platform\Core\Infrastructure\Repository\ProviderIntegrationRepository;
use Delnavazan\Platform\Portals\{PortalAccessPolicy,PortalPrincipalResolver};

final class TeacherClassJoinService {
    private const EARLY_SECONDS=3600;
    private const LATE_SECONDS=7200;
    public function __construct(private ?ProviderIntegrationRepository $repository=null,private ?ProviderReferenceVault $vault=null){
        $this->repository??=new ProviderIntegrationRepository();$this->vault??=new ProviderReferenceVault();
    }
    public function target(string $lessonUid,string $scheduleUid):string{
        $lessonUid=trim($lessonUid);$scheduleUid=trim($scheduleUid);
        if($lessonUid===''||$scheduleUid==='')throw new \InvalidArgumentException('portal_object_not_portal_visible');
        $principal=(new PortalPrincipalResolver())->resolve('teacher');
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $lesson=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$p}lessons WHERE uid=%s AND archived_at IS NULL LIMIT 1",$lessonUid));
        $schedule=$wpdb->get_row($wpdb->prepare("SELECT id,lesson_id,starts_at_utc,ends_at_utc,applicable_slot FROM {$p}canonical_lesson_schedule_versions WHERE uid=%s LIMIT 1",$scheduleUid));
        if(!$lesson||!$schedule||(int)$schedule->lesson_id!==(int)$lesson->id||(int)$schedule->applicable_slot!==1)throw new \InvalidArgumentException('portal_object_not_portal_visible');
        PortalAccessPolicy::assertObject('teacher_portal','lesson',(int)$lesson->id,$principal);
        $start=strtotime((string)$schedule->starts_at_utc.' UTC');$end=strtotime((string)$schedule->ends_at_utc.' UTC');$now=time();
        if(!$start||!$end||$now<$start-self::EARLY_SECONDS||$now>$end+self::LATE_SECONDS)throw new \InvalidArgumentException('portal_action_outside_window');
        $mapping=$this->repository->activeCalendarMapping((int)$lesson->id,(int)$schedule->id,false);
        if(!$mapping||(string)$mapping->provider_code!=='google_calendar'||(string)$mapping->projection_state!=='verified')throw new \InvalidArgumentException('portal_capability_unknown');
        $secret=$this->repository->projectionSecret('calendar_event',(int)$mapping->id,false);
        if(!$secret||(string)$secret->state!=='active')throw new \InvalidArgumentException('portal_capability_unknown');
        $references=$this->vault->open('calendar_event',(int)$mapping->id,$secret);
        $uri=trim((string)($references['join_uri_reference']??''));
        if($uri==='')throw new \InvalidArgumentException('portal_capability_unknown');
        return $uri;
    }
}
