<?php
namespace Delnavazan\Platform\Admin\View;

use Delnavazan\Platform\Core\Application\AcademyDemandWindowRule;

/** Read-only presentation for the instrument-first Golden Hour Coverage Atlas. */
final class AcademyCoverageView {
    private const GLYPHS=array('gap'=>'○','fragile'=>'◐','covered'=>'●','unknown'=>'?');
    private const SHORT_STATES=array('gap'=>'Gap','fragile'=>'One','covered'=>'2+','unknown'=>'Unknown');
    private const STATE_TEXT=array('gap'=>'no eligible teacher with declared availability at this time','fragile'=>'one teacher','covered'=>'two or more teachers','unknown'=>'unavailable or unknown');

    public static function render(array $matrix,array $filters,bool $canViewTeacherNames):void{
        $status=(string)($matrix['status']??'unavailable');
        $regionFilter=(string)($filters['area']??'');
        $regions=(array)($matrix['regions']??AcademyDemandWindowRule::regions());
        if($regionFilter!==''&&!isset($regions[$regionFilter]))$regionFilter='';
        $instrumentFilter=(int)($filters['instrument']??0);
        $rows=(array)($matrix['instruments']??array());
        $visibleRows=array_values(array_filter($rows,static fn(array $row):bool=>$instrumentFilter<1||(int)$row['id']===$instrumentFilter));
        $visibleAreas=$regionFilter!==''?array($regionFilter=>$regions[$regionFilter]):$regions;
        self::sortRows($visibleRows,self::normalizeSort($filters['sort']??'gap'),array_keys($visibleAreas));

        echo '<section id="teacher-coverage" class="dzn-coverage" aria-labelledby="dzn-coverage-title">';
        self::header($matrix,$filters);
        if($status==='unavailable'){
            echo '<div class="notice notice-error inline"><p>'.esc_html((string)($matrix['message']??'Coverage could not be calculated.')).'</p></div></section>';
            return;
        }
        if($status==='empty'){
            echo '<div class="notice notice-info inline"><p>'.esc_html((string)($matrix['message']??'No active instruments are configured.')).'</p></div></section>';
            return;
        }
        if($status==='partial'){
            echo '<div class="notice notice-warning inline"><p>'.esc_html(implode(' ',array_map('strval',(array)($matrix['errors']??array())))) .'</p></div>';
        }
        if(!$visibleRows){
            echo '<p class="dzn-coverage__empty">'.($instrumentFilter>0?'No active instrument matches that filter.':($regionFilter!==''?'No instrument has coverage data for that area.':'No instruments are available for this week.')).'</p></section>';
            return;
        }

        self::atlas($visibleRows,$visibleAreas,$matrix,$filters);
        self::detail($visibleRows,$regions,(array)($filters['detail']??array()),$filters,$canViewTeacherNames);
        self::footnote();
        echo '</section>';
    }

    private static function header(array $matrix,array $filters):void{
        $week=(string)($filters['week_value']??($matrix['week_start']??''));
        $weekLabel=(string)($matrix['week_label']??$week);
        $weekStart=$week!==''?AcademyDemandWindowRule::weekStart(new \DateTimeImmutable($week.' 00:00:00',new \DateTimeZone('UTC'))):AcademyDemandWindowRule::weekStart();
        $prev=$weekStart->modify('-7 days')->format('Y-m-d');
        $next=$weekStart->modify('+7 days')->format('Y-m-d');
        $regionTotals=array();foreach((array)($matrix['regions']??array())as$region)if(isset($region['total_slots']))$regionTotals[]=(int)$region['total_slots'];
        $slotText=$regionTotals&&count(array_unique($regionTotals))===1?$regionTotals[0].' half-hour slots per area':'configured golden-hour windows';
        echo '<header class="dzn-coverage__head"><div class="dzn-coverage__intro"><p class="dzn-coverage__eyebrow">Golden hour coverage</p><h2 id="dzn-coverage-title">Coverage Atlas</h2><p class="dzn-coverage__meta"><strong>Week of '.esc_html($weekLabel).'</strong> · area-local time · '.esc_html($slotText).'</p></div>';
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" class="dzn-coverage__filters">';
        echo '<input type="hidden" name="page" value="'.esc_attr((string)($filters['page']??'dzn-teachers-menu')).'">';
        echo '<label for="dzn-coverage-instrument">Instrument<select id="dzn-coverage-instrument" name="coverage_instrument"><option value="0">All instruments</option>';
        foreach((array)($matrix['instruments']??array())as$row){echo '<option value="'.esc_attr((string)$row['id']).'"'.selected((int)($filters['instrument']??0),(int)$row['id'],false).'>'.esc_html((string)$row['name']).'</option>';}
        echo '</select></label><label for="dzn-coverage-area">Area<select id="dzn-coverage-area" name="coverage_area"><option value="">All areas</option>';
        foreach((array)($matrix['regions']??array())as$key=>$region){echo '<option value="'.esc_attr((string)$key).'"'.selected((string)($filters['area']??''),(string)$key,false).'>'.esc_html((string)$region['label']).'</option>';}
        echo '</select></label><label for="dzn-coverage-sort">Sort<select id="dzn-coverage-sort" name="coverage_sort"><option value="gap"'.selected(self::normalizeSort($filters['sort']??'gap'),'gap',false).'>Largest gaps first</option><option value="alpha"'.selected(self::normalizeSort($filters['sort']??'gap'),'alpha',false).'>Alphabetical</option></select></label>';
        echo '<label for="dzn-coverage-week">Week<input id="dzn-coverage-week" type="date" name="coverage_week" value="'.esc_attr($week).'"></label><button class="button button-primary" type="submit">Show coverage</button></form></header>';
        echo '<div class="dzn-coverage__week-nav"><a href="'.esc_url(self::url($filters,array('coverage_week'=>$prev,'coverage_detail'=>null))).'#teacher-coverage">‹ Previous week</a><span aria-hidden="true">·</span><a href="'.esc_url(self::url($filters,array('coverage_week'=>$next,'coverage_detail'=>null))).'#teacher-coverage">Next week ›</a></div>';
        echo '<div class="dzn-coverage__legend" role="group" aria-label="Coverage indicator legend"><span><span class="dzn-coverage__legend-glyph" aria-hidden="true">○</span> Gap</span><span><span class="dzn-coverage__legend-glyph" aria-hidden="true">◐</span> One teacher</span><span><span class="dzn-coverage__legend-glyph" aria-hidden="true">●</span> 2+ teachers</span><span><span class="dzn-coverage__legend-glyph" aria-hidden="true">?</span> Unknown</span></div>';
    }

    private static function atlas(array $rows,array $areas,array $matrix,array $filters):void{
        $week=(string)($matrix['week_label']??($filters['week_value']??''));
        echo '<div class="dzn-coverage__scroll" role="region" aria-label="Golden hour coverage by instrument and area" tabindex="0"><table class="dzn-coverage__table"><caption class="screen-reader-text">Golden hour coverage for the week of '.esc_html($week).'. Each area cell shows the percentage of golden-hour slots covered and whether the weakest covered slot relies on one teacher, two or more teachers, or has no coverage.</caption><thead><tr><th scope="col">Instrument</th>';
        foreach($areas as$key=>$region)echo '<th scope="col"><span class="dzn-coverage__area-name">'.esc_html((string)$region['label']).'</span><span class="dzn-coverage__area-zone">'.esc_html((string)($region['timezone_label']??$region['timezone'])).'</span></th>';
        echo '</tr></thead><tbody>';
        foreach($rows as$row){
            echo '<tr><th scope="row" class="dzn-coverage__instrument"><span>'.esc_html((string)$row['name']).'</span><span class="dzn-coverage__inst-meta">'.esc_html(self::teacherCountLabel((int)$row['eligible_teacher_count'])).'</span></th>';
            foreach($areas as$key=>$region){$cell=$row['areas'][$key];echo '<td>'.self::cell($row,$region,$key,$cell,$filters).'</td>';}
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        $noTeacherRows=0;foreach($rows as$row)if((int)$row['eligible_teacher_count']===0)$noTeacherRows++;
        if($noTeacherRows>0)echo '<p class="dzn-coverage__notice">'.esc_html($noTeacherRows===1?'One displayed instrument has no eligible active teacher with declared availability. Those cells are shown as “No eligible”, not as a measured 0%.':$noTeacherRows.' displayed instruments have no eligible active teacher with declared availability. Those cells are shown as “No eligible”, not as a measured 0%.').'</p>';
    }

    private static function cell(array $row,array $region,string $regionKey,array $cell,array $filters):string{
        $metric=(string)$cell['metric_state'];
        $resilience=(string)$cell['resilience'];
        $stateClass=$metric==='unknown'?'unknown':($metric==='no_eligible_teachers'?'gap':$resilience);
        $href=self::url($filters,array('coverage_detail'=>(int)$row['id'].':'.$regionKey)).'#coverage-detail';
        $label=self::cellLabel((string)$row['name'],(string)$region['label'],$cell);
        $html='<a class="dzn-coverage__cell dzn-coverage__cell--'.esc_attr($stateClass).'" href="'.esc_url($href).'" aria-label="'.esc_attr($label).'">';
        if($metric==='unknown'){
            $html.='<span class="dzn-coverage__percent">?</span><span class="dzn-coverage__badge" aria-hidden="true">?</span><span class="dzn-coverage__state">Unknown</span>';
        }elseif($metric==='no_eligible_teachers'){
            $html.='<span class="dzn-coverage__percent">—</span><span class="dzn-coverage__badge" aria-hidden="true">○</span><span class="dzn-coverage__state">No eligible</span>';
        }else{
            $html.='<span class="dzn-coverage__percent">'.esc_html((string)(int)$cell['coverage_percent'].'%').'</span><span class="dzn-coverage__badge" aria-hidden="true">'.esc_html(self::GLYPHS[$resilience]??'?').'</span><span class="dzn-coverage__state">'.esc_html(self::SHORT_STATES[$resilience]??'Unknown').'</span>';
        }
        return $html.'</a>';
    }

    private static function cellLabel(string $instrument,string $area,array $cell):string{
        $metric=(string)$cell['metric_state'];
        if($metric==='unknown')return $instrument.', '.$area.': coverage unknown because availability data could not be read. Open slot detail.';
        if($metric==='no_eligible_teachers')return $instrument.', '.$area.': no eligible active teacher with declared availability. Open slot detail.';
        $gap=(int)$cell['total_slots']-(int)$cell['covered_slots'];
        return sprintf('%s, %s: %d of %d golden-hour slots covered (%d percent); %d slots rely on one teacher; %d gaps. %s. Open slot detail.',$instrument,$area,(int)$cell['covered_slots'],(int)$cell['total_slots'],(int)$cell['coverage_percent'],(int)$cell['single_teacher_slots'],$gap,self::STATE_TEXT[(string)$cell['resilience']]??'Unknown resilience');
    }

    private static function detail(array $rows,array $regions,array $detail,array $filters,bool $canViewTeacherNames):void{
        if(!$detail||empty($detail['instrument'])||empty($detail['area'])){echo '<p class="dzn-coverage__hint">Select an instrument–area cell to open its day-by-time detail grid. Grid times are local to the selected area.</p>';return;}
        $instrument=null;foreach($rows as$row)if((int)$row['id']===(int)$detail['instrument'])$instrument=$row;
        $regionKey=(string)$detail['area'];
        if($instrument===null||!isset($regions[$regionKey])){echo '<p class="dzn-coverage__hint">That instrument–area detail is not available in the current filter.</p>';return;}
        $region=$regions[$regionKey];$cell=$instrument['areas'][$regionKey];
        echo '<section id="coverage-detail" class="dzn-coverage__detail" tabindex="-1" aria-labelledby="dzn-coverage-detail-title"><div class="dzn-coverage__detail-head"><div><p class="dzn-coverage__eyebrow">Slot detail</p><h3 id="dzn-coverage-detail-title">'.esc_html((string)$instrument['name'].' · '.(string)$region['label']).'</h3><p class="dzn-coverage__detail-meta">Times are local to '.esc_html((string)$region['label']).' ('.esc_html((string)($region['timezone_label']??$region['timezone'])).') · configured 30-minute golden-hour slots</p></div><a class="button" href="'.esc_url(self::url($filters,array('coverage_detail'=>null)).'#teacher-coverage').'">Back to atlas</a></div>';
        echo '<p class="dzn-coverage__summary">'.esc_html(self::summary($cell)).'</p>';
        if((string)$cell['metric_state']==='unknown')echo '<div class="notice notice-warning inline"><p>This instrument–area result is unknown because at least one teacher availability record could not be read. The grid does not present a reassuring percentage.</p></div>';
        elseif((string)$cell['metric_state']==='no_eligible_teachers')echo '<div class="notice notice-info inline"><p>No eligible active teacher with declared availability was found for this instrument and area. Every slot is a gap, but this is not a measured 0% capacity result.</p></div>';
        if(!empty($cell['longest_gaps'])&&(string)$cell['metric_state']==='available')self::gaps($cell['longest_gaps'],count($cell['gaps']));
        self::grid($cell,$region);
        self::teacherNames($cell,$canViewTeacherNames);
        echo '<script>(function(){var d=document.getElementById("coverage-detail");if(d&&window.location.hash==="#coverage-detail")d.focus({preventScroll:true});})();</script>';
        echo '</section>';
    }

    private static function summary(array $cell):string{
        $metric=(string)$cell['metric_state'];
        if($metric==='unknown')return 'Coverage is unknown for this instrument and area. No percentage is shown because the data cannot support a trustworthy result.';
        if($metric==='no_eligible_teachers')return 'No eligible active teacher with declared availability was found. All '.(int)$cell['total_slots'].' effective golden-hour slots are gaps, but this is not a measured 0% capacity result.';
        $covered=(int)$cell['covered_slots'];$single=(int)$cell['single_teacher_slots'];$total=(int)$cell['total_slots'];
        $longest=!empty($cell['longest_gaps'])?(string)$cell['longest_gaps'][0]['label']:null;
        $summary=$covered.'/'.$total.' slots covered; '.$single.' '.($single===1?'slot relies':'slots rely').' on one teacher; ';
        $summary.=$longest!==null?'longest gap: '.$longest.'.':'no gaps in the measured golden-hour slots.';
        return $summary;
    }

    private static function gaps(array $gaps,int $totalGaps):void{
        echo '<div class="dzn-coverage__gap-block"><h4>Longest gaps</h4><ul class="dzn-coverage__gaps">';
        foreach($gaps as$gap)echo '<li><strong>'.esc_html((string)$gap['label']).'</strong><span>'.esc_html((string)(int)$gap['slots']).' half-hour '.((int)$gap['slots']===1?'slot':'slots').'</span></li>';
        if($totalGaps>count($gaps))echo '<li class="dzn-coverage__more">+'.esc_html((string)($totalGaps-count($gaps))).' more '.($totalGaps-count($gaps)===1?'gap':'gaps').'</li>';
        echo '</ul></div>';
    }

    private static function grid(array $cell,array $region):void{
        $times=array_values(array_map('intval',(array)($cell['times']??array())));
        if(!$times){echo '<p class="dzn-coverage__note">No golden-hour window is configured for this area in the selected week.</p>';return;}
        $byDay=array();foreach($cell['slots']as$slot)$byDay[(int)$slot['weekday']][(int)$slot['minute']]=$slot;
        $days=(array)($cell['days']??array());
        echo '<div class="dzn-coverage__scroll" role="region" aria-label="Half-hour coverage grid local to '.esc_attr((string)$region['label']).'" tabindex="0"><table class="dzn-coverage__grid"><caption class="screen-reader-text">Half-hour coverage for '.esc_html((string)$region['label']).', local time, using the windows effective for the selected week. Each cell states Gap, one teacher, two or more teachers, or unknown.</caption><thead><tr><th scope="col">Day</th>';
        foreach($times as$minute)echo '<th scope="col"><abbr title="'.esc_attr(AcademyDemandWindowRule::timeLabel($minute).'–'.AcademyDemandWindowRule::timeLabel($minute+AcademyDemandWindowRule::SLOT_MINUTES)).'">'.esc_html(AcademyDemandWindowRule::timeLabel($minute)).'</abbr></th>';
        echo '</tr></thead><tbody>';
        for($weekday=1;$weekday<=7;$weekday++){
            $day=$days[$weekday]??null;
            echo '<tr><th scope="row">'.esc_html(AcademyDemandWindowRule::weekdayLabel($weekday)).'</th>';
            if(!is_array($day)||empty($day['has_window'])){echo '<td class="dzn-coverage__slot dzn-coverage__slot--outside" colspan="'.count($times).'">No configured window</td></tr>';continue;}
            foreach($times as$minute){
                $slot=$byDay[$weekday][$minute]??null;
                if($slot===null){echo '<td class="dzn-coverage__slot dzn-coverage__slot--outside"><span aria-hidden="true">—</span><span class="screen-reader-text">'.esc_html(AcademyDemandWindowRule::weekdayLabel($weekday).' '.AcademyDemandWindowRule::timeLabel($minute).': outside the configured window').'</span></td>';continue;}
                $state=(string)$slot['state'];$count=(int)$slot['teacher_count'];
                $text=$state==='gap'?'Gap':($state==='fragile'?'1':($state==='covered'?'2+':'?'));
                $sr=AcademyDemandWindowRule::weekdayLabel($weekday).' '.$slot['time'].' to '.$slot['end_time'].': '.self::STATE_TEXT[$state];
                if($state==='fragile'||$state==='covered')$sr.=', '.$count.' distinct '.($count===1?'teacher':'teachers');
                echo '<td class="dzn-coverage__slot dzn-coverage__slot--'.esc_attr($state).'"><span aria-hidden="true">'.esc_html($text).'</span><span class="screen-reader-text">'.esc_html($sr).'</span></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function teacherNames(array $cell,bool $canViewTeacherNames):void{
        if(!$canViewTeacherNames){echo '<p class="dzn-coverage__note">Teacher names are hidden because your role does not include teacher maintenance or availability access.</p>';return;}
        if((string)($cell['metric_state']??'')==='unknown'){echo '<p class="dzn-coverage__note">Teacher names are withheld while coverage is unknown.</p>';return;}
        $names=array_values(array_filter(array_map('strval',(array)$cell['teacher_names']),static fn(string $name):bool=>$name!==''));
        echo '<details class="dzn-coverage__teachers"><summary>Teachers contributing coverage ('.esc_html((string)count($names)).')</summary>';
        if(!$names)echo '<p>No teacher has a covered slot in this area.</p>';
        else{echo '<ul>';foreach($names as$name)echo '<li>'.esc_html($name).'</li>';echo '</ul>';}
        echo '</details>';
    }

    private static function footnote():void{
        echo '<div class="dzn-coverage__footnote"><p><strong>How to read this:</strong> coverage is the share of effective golden-hour slots for the selected week with at least one eligible teacher with declared availability. It is not free bookable capacity or a promise that a booking will succeed.</p><p>Windows come from the shared golden-hour configuration effective for the selected week. A disabled pay bonus does not remove a window. Active blocked rules and dated exceptions are applied; existing bookings and student-specific constraints are not added or subtracted here, and the booking-time scheduling authority remains the decision maker. No historical trend is shown because no comparable persisted coverage snapshot exists.</p></div>';
    }

    private static function url(array $filters,array $changes):string{
        $args=array('page'=>(string)($filters['page']??'dzn-teachers-menu'));
        if((int)($filters['instrument']??0)>0)$args['coverage_instrument']=(int)$filters['instrument'];
        if(!empty($filters['area']))$args['coverage_area']=(string)$filters['area'];
        $args['coverage_sort']=self::normalizeSort($filters['sort']??'gap');
        $args['coverage_week']=(string)($filters['week_value']??'');
        if(!empty($filters['detail']['instrument'])&&!empty($filters['detail']['area']))$args['coverage_detail']=(int)$filters['detail']['instrument'].':'.(string)$filters['detail']['area'];
        foreach($changes as$key=>$value){if($value===null||$value==='')unset($args[$key]);else$args[$key]=$value;}
        return add_query_arg($args,admin_url('admin.php'));
    }

    private static function normalizeSort(mixed $sort):string{return (string)$sort==='alpha'?'alpha':'gap';}

    private static function teacherCountLabel(int $count):string{return $count===1?'1 eligible teacher':$count.' eligible teachers';}

    private static function sortRows(array &$rows,string $sort,array $areaKeys):void{
        $severity=static function(array $row)use($areaKeys):array{
            $gaps=0;$single=0;
            foreach($areaKeys as$key){$cell=$row['areas'][$key]??null;if(!is_array($cell))continue;if((string)$cell['metric_state']==='available'){$gaps+=(int)$cell['total_slots']-(int)$cell['covered_slots'];$single+=(int)$cell['single_teacher_slots'];}else{$gaps+=(int)$cell['total_slots'];}
            }
            return array($gaps,$single);
        };
        if($sort==='alpha'){usort($rows,static fn(array $a,array $b):int=>strnatcasecmp((string)$a['name'],(string)$b['name'])?:(int)$a['id']<=>(int)$b['id']);return;}
        usort($rows,static function(array $a,array $b)use($severity):int{$sa=$severity($a);$sb=$severity($b);return $sb[0]<=>$sa[0]?:$sb[1]<=>$sa[1]?:strnatcasecmp((string)$a['name'],(string)$b['name'])?:(int)$a['id']<=>(int)$b['id'];});
    }
}
