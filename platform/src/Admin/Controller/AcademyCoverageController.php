<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\View\AcademyCoverageView;
use Delnavazan\Platform\Core\Application\AcademyCoverageReadService;
use Delnavazan\Platform\Core\Application\AcademyDemandWindowRule;

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
        $filters=self::filters($page);
        $matrix=(new AcademyCoverageReadService())->matrix($filters['week_date'],$filters['sort']);
        $canViewTeacherNames=current_user_can('dzn_manage_teachers')||current_user_can('dzn_manage_teacher_availability');
        AcademyCoverageView::render($matrix,$filters,$canViewTeacherNames);
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
