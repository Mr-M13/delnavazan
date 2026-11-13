<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\View\InstrumentCoverageClockView;
use Delnavazan\Platform\Core\Application\InstrumentCoverageClockReadService;

/** Read-only seven-ring instrument coverage clock on the Academy Overview. */
final class AcademyCoverageController {
    private const CAP='dzn_view_booking_requests';
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
        try{
            $clock=(new InstrumentCoverageClockReadService())->admin(self::clockFilters($page));
        }catch(\Throwable){
            $clock=array('status'=>'unavailable','message'=>'Instrument coverage could not be calculated. The booking-time scheduling authority is unchanged.','instruments'=>array());
        }
        InstrumentCoverageClockView::render($clock,array('page'=>$page,'focus'=>isset($_GET['coverage_focus'])&&is_scalar($_GET['coverage_focus'])?sanitize_text_field(wp_unslash((string)$_GET['coverage_focus'])):''));
    }

    /** @return array<string,mixed> */
    private static function clockFilters(string $page):array{
        $dateInput=isset($_GET['coverage_date'])&&is_scalar($_GET['coverage_date'])?sanitize_text_field(wp_unslash((string)$_GET['coverage_date'])):'';
        $instrument=isset($_GET['coverage_instrument'])&&is_scalar($_GET['coverage_instrument'])?absint($_GET['coverage_instrument']):0;
        $calendarInput=isset($_GET['coverage_calendar'])&&is_scalar($_GET['coverage_calendar'])?sanitize_key(wp_unslash((string)$_GET['coverage_calendar'])):'gregorian';
        return array(
            'date'=>$dateInput,
            'instrument_id'=>$instrument,
            'calendar'=>in_array($calendarInput,array('persian','gregorian'),true)?$calendarInput:'gregorian',
            'page'=>$page,
        );
    }
}
