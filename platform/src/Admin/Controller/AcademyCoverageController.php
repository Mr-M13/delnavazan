<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\View\AcademyCoverageView;
use Delnavazan\Platform\Admin\View\InstrumentCoverageClockView;
use Delnavazan\Platform\Core\Application\AcademyCoverageReadService;
use Delnavazan\Platform\Core\Application\AcademyDemandWindowRule;
use Delnavazan\Platform\Core\Application\InstrumentCoverageClockReadService;

/** Read-only Golden Hour Coverage Atlas on the Academy Overview. */
final class AcademyCoverageController {
    private const CAP='dzn_view_booking_requests';
    /** Coverage is rendered inside the canonical Teachers page. */
    public static function register():void{}
    public static function menu():void{}
    public static function screen():void{
        if(!current_user_can(self::CAP))wp_die('Forbidden',403);
        echo '<div class="wrap">';
        self::renderAtlas('dzn-academy-coverage');
        echo '</div>';
    }

    public static function renderAtlas(string $page='dzn-teachers-menu'):void{
        if(!current_user_can(self::CAP))return;
        $clockFilters=self::clockFilters($page);
        try{
            $clock=(new InstrumentCoverageClockReadService())->admin($clockFilters);
        }catch(\Throwable){
            $clock=array('status'=>'unavailable','message'=>'Instrument coverage could not be calculated. The booking-time scheduling authority is unchanged.','instruments'=>array());
        }
        InstrumentCoverageClockView::render($clock,array('page'=>$page));
        $legacy=isset($_GET['coverage_legacy'])&&is_scalar($_GET['coverage_legacy'])?sanitize_key(wp_unslash((string)$_GET['coverage_legacy'])):'';
        if($legacy==='1'||(string)($clock['status']??'')==='unavailable'){
            self::renderLegacyAtlas($page);
        }else{
            $args=array('page'=>$page,'coverage_legacy'=>'1');
            if(isset($_GET['tab'])&&is_scalar($_GET['tab']))$args['tab']=sanitize_key(wp_unslash((string)$_GET['tab']));
            foreach(array('coverage_mode','coverage_date','coverage_weekday','coverage_instrument','coverage_calendar') as $key){
                if(isset($_GET[$key])&&is_scalar($_GET[$key]))$args[$key]=sanitize_text_field(wp_unslash((string)$_GET[$key]));
            }
            echo '<p class="dzn-clock__legacy-link"><a href="'.esc_url(add_query_arg($args,admin_url('admin.php')).'#dzn-regional-demand-atlas').'">Open the regional demand-window atlas (legacy, finance window review only)</a></p>';
        }
    }

    /**
     * The regional demand-window atlas is retained as an explicitly labelled
     * secondary detail. Its shared finance window authority and historical
     * calculation are not deleted; the instrument clock is now the primary
     * coverage presentation.
     */
    private static function renderLegacyAtlas(string $page):void{
        $filters=self::filters($page);
        try{
            $matrix=(new AcademyCoverageReadService())->matrix($filters['week_date'],$filters['sort']);
        }catch(\Throwable){
            echo '<div class="notice notice-warning inline"><p>'.esc_html('The regional demand-window atlas could not be read. The instrument clock above is unaffected.').'</p></div>';
            return;
        }
        $canViewTeacherNames=current_user_can('dzn_manage_teachers')||current_user_can('dzn_manage_teacher_availability');
        echo '<details class="dzn-coverage-legacy" id="dzn-regional-demand-atlas"><summary><strong>Regional demand-window atlas</strong><span>Legacy Golden Hour coverage by service area. Kept for finance window review; not used for bookable capacity.</span></summary>';
        AcademyCoverageView::render($matrix,$filters,$canViewTeacherNames);
        echo '</details>';
    }

    /** @return array<string,mixed> */
    private static function clockFilters(string $page):array{
        $mode='date';
        $modeInput=isset($_GET['coverage_mode'])&&is_scalar($_GET['coverage_mode'])?sanitize_key(wp_unslash((string)$_GET['coverage_mode'])):'';
        if(in_array($modeInput,array('weekly','date'),true))$mode=$modeInput;
        $dateInput=isset($_GET['coverage_date'])&&is_scalar($_GET['coverage_date'])?sanitize_text_field(wp_unslash((string)$_GET['coverage_date'])):'';
        $weekdayInput=isset($_GET['coverage_weekday'])&&is_scalar($_GET['coverage_weekday'])?absint($_GET['coverage_weekday']):0;
        $instrument=isset($_GET['coverage_instrument'])&&is_scalar($_GET['coverage_instrument'])?absint($_GET['coverage_instrument']):0;
        $calendarInput=isset($_GET['coverage_calendar'])&&is_scalar($_GET['coverage_calendar'])?sanitize_key(wp_unslash((string)$_GET['coverage_calendar'])):'gregorian';
        return array(
            'mode'=>$mode,
            'date'=>$dateInput,
            'weekday'=>$weekdayInput>=1&&$weekdayInput<=7?$weekdayInput:0,
            'instrument_id'=>$instrument,
            'calendar'=>in_array($calendarInput,array('persian','gregorian'),true)?$calendarInput:'gregorian',
            'page'=>$page,
        );
    }

    private static function filters(string $page):array{
        $regions=AcademyDemandWindowRule::regions();
        $week=null;
        $weekInput=isset($_GET['coverage_week'])&&is_scalar($_GET['coverage_week'])?sanitize_text_field(wp_unslash((string)$_GET['coverage_week'])):'';
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$weekInput)){
            try{$candidate=new \DateTimeImmutable($weekInput.' 00:00:00',new \DateTimeZone('UTC'));if($candidate->format('Y-m-d')===$weekInput)$week=$candidate;}catch(\Throwable){$week=null;}
        }
        $weekStart=AcademyDemandWindowRule::weekStart($week);

        $area='';
        $areaInput=isset($_GET['coverage_area'])&&is_scalar($_GET['coverage_area'])?strtoupper(sanitize_key(wp_unslash((string)$_GET['coverage_area']))):'';
        if(isset($regions[$areaInput]))$area=$areaInput;

        $instrument=isset($_GET['coverage_instrument'])&&is_scalar($_GET['coverage_instrument'])?absint($_GET['coverage_instrument']):0;
        $sort=isset($_GET['coverage_sort'])&&is_scalar($_GET['coverage_sort'])&&sanitize_key(wp_unslash((string)$_GET['coverage_sort']))==='alpha'?'alpha':'gap';

        $detail=null;
        $detailInput=isset($_GET['coverage_detail'])&&is_scalar($_GET['coverage_detail'])?sanitize_text_field(wp_unslash((string)$_GET['coverage_detail'])):'';
        if(preg_match('/^([1-9][0-9]*):([A-Za-z_]+)$/D',$detailInput,$matches)&&isset($regions[strtoupper($matches[2])]))$detail=array('instrument'=>(int)$matches[1],'area'=>strtoupper($matches[2]));

        return array(
            'page'=>$page,
            'week_date'=>$weekStart,
            'week_value'=>$weekStart->format('Y-m-d'),
            'instrument'=>$instrument,
            'area'=>$area,
            'sort'=>$sort,
            'detail'=>$detail,
        );
    }
}
