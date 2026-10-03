<?php
namespace Delnavazan\Platform\Admin\Controller;
use Delnavazan\Platform\Core\Application\AcademyCoverageReadService;
final class AcademyCoverageController {
    private const CAP='dzn_view_booking_requests';
    public static function register():void{add_action('admin_menu',[self::class,'menu']);}
    public static function menu():void{add_submenu_page('dzn-platform','Academy Coverage','Academy Coverage',self::CAP,'dzn-academy-coverage',[self::class,'screen']);}
    public static function screen():void{
        if(!current_user_can(self::CAP))wp_die('Forbidden',403);$rows=(new AcademyCoverageReadService())->matrix();
        echo '<div class="wrap"><h1>Academy golden-hour coverage</h1><p>Read-only planning view. Coverage uses only active, ready, accepting/limited teachers with active course eligibility and declared preferred/requestable availability. Regional windows are converted with timezone/DST rules for the current week.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Instrument</th><th>Region</th><th>Coverage</th><th>Uncovered demand</th></tr></thead><tbody>';
        foreach($rows as$r){$g=array_slice($r['gaps'],0,6);$labels=[];foreach($g as$x)$labels[]='day '.$x['weekday'].' '.sprintf('%02d:%02d',intdiv($x['minute'],60),$x['minute']%60);$more=count($r['gaps'])>6?' +'.(count($r['gaps'])-6).' more':'';echo '<tr><td>'.esc_html($r['instrument']).'</td><td>'.esc_html($r['region_label']).'</td><td><strong>'.(int)$r['coverage_percent'].'%</strong> · '.(int)$r['covered_slots'].'/'.(int)$r['total_slots'].' half-hour slots</td><td>'.esc_html($labels?implode(', ',$labels).$more:'No uncovered golden-hour slots').'</td></tr>';}
        echo '</tbody></table><p><em>This is capacity guidance, not a booking promise. Dated exceptions, existing bookings and student-specific constraints are evaluated later by the scheduling authority.</em></p></div>';
    }
}
