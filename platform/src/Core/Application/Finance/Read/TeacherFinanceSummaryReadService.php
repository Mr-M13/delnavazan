<?php
namespace Delnavazan\Platform\Core\Application\Finance\Read;

use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/**
 * Operational snapshot for the authenticated Teacher.
 *
 * This is a read-only projection of canonical scheduling, attendance and Finance facts for the
 * Teacher's own Finance screen. It never derives a payout, never substitutes another Teacher's
 * data and never treats a cancellation as teaching time.
 *
 * Monthly boundaries are the Teacher-profile timezone's local month, converted to UTC. Scheduled
 * duration is reported separately from completed/delivered duration: a future authorised occurrence
 * contributes to scheduled minutes, never to taught minutes.
 */
final class TeacherFinanceSummaryReadService {
    private const ACTIVE_ENROLMENT_STATES=array('current','authorised');
    private const CANONICAL_MODEL='canonical_term_lesson_v1';

    /** Current Teacher's own snapshot, with month boundaries in the Teacher's profile timezone. */
    public function own(?string $timezone=null):array{
        $principal=(new PortalPrincipalResolver())->resolve('teacher');
        $teacherId=(int)$principal['id'];
        if(!(new PrincipalInvitationRepository())->hasActiveTeacherAuthority((int)get_current_user_id(),$teacherId))throw new \InvalidArgumentException('teacher_onboarding_required');
        $timezone=self::resolveTimezone($timezone);
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $zone=new \DateTimeZone($timezone);
        $monthStart=(new \DateTimeImmutable('first day of this month 00:00:00',$zone))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $monthEnd=(new \DateTimeImmutable('first day of next month 00:00:00',$zone))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $nowUtc=gmdate('Y-m-d H:i:s');

        $activeStudents=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT e.student_id) FROM {$p}teacher_assignments a
             INNER JOIN {$p}enrolments e ON e.id=a.enrolment_id AND e.archived_at IS NULL
             WHERE a.teacher_id=%d AND a.applicable_slot=1 AND a.state='assigned'
             AND e.lifecycle_state IN ('current','authorised')",
            $teacherId
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('teacher_finance_summary_unavailable');

        // One row per canonical Lesson occurrence. The effective delivery outcome is joined by its
        // applicable slot; the occurrence anchor follows Finance's own precedence (outcome first,
        // then the canonical schedule version). Completed lifecycle and an effective delivered
        // outcome are both canonical facts, so a delivered-but-not-yet-lifecycle-completed Lesson
        // is not silently reported as zero teaching.
        $monthRows=$wpdb->get_results($wpdb->prepare(
            "SELECT l.id lesson_id,l.student_id,l.lifecycle_state,
                    v.starts_at_utc,v.ends_at_utc,v.duration_minutes,
                    o.id outcome_id,o.delivery_state,o.attendance_state,o.outcome_code,
                    o.occurrence_starts_at_utc outcome_starts_at_utc,
                    o.occurrence_ends_at_utc outcome_ends_at_utc
             FROM {$p}lessons l
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             LEFT JOIN {$p}canonical_lesson_delivery_outcomes o ON o.lesson_id=l.id AND o.applicable_slot=1
             WHERE l.teacher_id=%d AND l.record_model=%s AND l.archived_at IS NULL
             AND l.lifecycle_state IN ('authorised','completed')
             AND COALESCE(o.occurrence_starts_at_utc,v.starts_at_utc)>=%s
             AND COALESCE(o.occurrence_starts_at_utc,v.starts_at_utc)<%s",
            $teacherId,self::CANONICAL_MODEL,$monthStart,$monthEnd
        ),ARRAY_A);
        if($wpdb->last_error!=='')throw new \RuntimeException('teacher_finance_summary_unavailable');
        $month=self::reconcileMonthRows(is_array($monthRows)?$monthRows:array());

        $upcoming=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}lessons l
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             WHERE l.teacher_id=%d AND l.record_model=%s AND l.archived_at IS NULL
             AND l.lifecycle_state='authorised' AND v.starts_at_utc>=%s",
            $teacherId,self::CANONICAL_MODEL,$nowUtc
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('teacher_finance_summary_unavailable');

        $next=$wpdb->get_row($wpdb->prepare(
            "SELECT v.starts_at_utc,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.reference_code,''),'هنرجو') student_name,
                    COALESCE(NULLIF(i.name_fa,''),NULLIF(c.name_fa,''),NULLIF(c.name_en,''),'') course_name
             FROM {$p}lessons l
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             LEFT JOIN {$p}students s ON s.id=l.student_id
             LEFT JOIN {$p}courses c ON c.id=l.course_id
             LEFT JOIN {$p}instruments i ON i.id=c.instrument_id
             WHERE l.teacher_id=%d AND l.record_model=%s AND l.archived_at IS NULL
             AND l.lifecycle_state='authorised' AND v.starts_at_utc>=%s
             ORDER BY v.starts_at_utc LIMIT 1",
            $teacherId,self::CANONICAL_MODEL,$nowUtc
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('teacher_finance_summary_unavailable');

        $ending=$wpdb->get_results($wpdb->prepare(
            "SELECT t.id term_id,t.reference_code term_reference,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.reference_code,''),'هنرجو') student_name,
                    COALESCE(NULLIF(i.name_fa,''),NULLIF(c.name_fa,''),NULLIF(c.name_en,''),'') course_name,
                    COUNT(l.id) total,
                    SUM(CASE WHEN l.lifecycle_state='completed' THEN 1 ELSE 0 END) completed,
                    SUM(CASE WHEN l.lifecycle_state='cancelled' THEN 1 ELSE 0 END) cancelled,
                    MAX(CASE WHEN l.lifecycle_state='authorised' THEN v.starts_at_utc END) last_scheduled_utc
             FROM {$p}terms t
             INNER JOIN {$p}enrolments e ON e.id=t.enrolment_id AND e.archived_at IS NULL
             INNER JOIN {$p}teacher_assignments a ON a.enrolment_id=e.id AND a.teacher_id=%d AND a.applicable_slot=1
             INNER JOIN {$p}lessons l ON l.term_id=t.id AND l.record_model=%s AND l.archived_at IS NULL
             LEFT JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             LEFT JOIN {$p}students s ON s.id=e.student_id
             LEFT JOIN {$p}courses c ON c.id=e.course_id
             LEFT JOIN {$p}instruments i ON i.id=c.instrument_id
             WHERE t.lifecycle_state='current' AND t.archived_at IS NULL
             GROUP BY t.id,t.reference_code,s.display_name,s.reference_code,i.name_fa,c.name_fa,c.name_en
             HAVING (COUNT(l.id)-SUM(CASE WHEN l.lifecycle_state='completed' THEN 1 ELSE 0 END)-SUM(CASE WHEN l.lifecycle_state='cancelled' THEN 1 ELSE 0 END)) BETWEEN 0 AND 3
             ORDER BY MAX(CASE WHEN l.lifecycle_state='authorised' THEN v.starts_at_utc END) IS NULL,MAX(CASE WHEN l.lifecycle_state='authorised' THEN v.starts_at_utc END) LIMIT 10",
            $teacherId,self::CANONICAL_MODEL
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('teacher_finance_summary_unavailable');

        $pendingReschedules=0;$rescheduleRequestsMonth=0;
        if(self::tableExists($p.'lesson_reschedule_proposals')){
            $pendingReschedules=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lesson_reschedule_proposals WHERE teacher_id=%d AND state='pending' AND (expires_at IS NULL OR expires_at>%s)",$teacherId,$nowUtc));
            if($wpdb->last_error!=='')throw new \RuntimeException('teacher_finance_summary_unavailable');
            $rescheduleRequestsMonth=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lesson_reschedule_proposals WHERE teacher_id=%d AND created_at>=%s AND created_at<%s",$teacherId,$monthStart,$monthEnd));
            if($wpdb->last_error!=='')throw new \RuntimeException('teacher_finance_summary_unavailable');
        }

        $rows=array();
        foreach((array)$ending as $row){
            $total=(int)$row->total;
            $completed=(int)$row->completed;
            $cancelled=(int)$row->cancelled;
            $rows[]=array(
                'term_reference'=>(string)$row->term_reference,
                'student'=>(string)$row->student_name,
                'course'=>(string)$row->course_name,
                'total'=>$total,
                'completed'=>$completed,
                'remaining'=>max(0,$total-$completed-$cancelled),
                'last_scheduled_utc'=>(string)($row->last_scheduled_utc??''),
            );
        }

        return array(
            'timezone'=>$timezone,
            'month_start_utc'=>$monthStart,
            'month_end_utc'=>$monthEnd,
            'students_month'=>$month['students'],
            'active_students'=>$activeStudents,
            'lessons_month'=>$month['scheduled_lessons'],
            'scheduled_lessons_month'=>$month['scheduled_lessons'],
            'completed_month'=>$month['completed_lessons'],
            'completed_lessons_month'=>$month['completed_lessons'],
            'minutes_month'=>$month['completed_minutes'],
            'completed_minutes_month'=>$month['completed_minutes'],
            'scheduled_minutes_month'=>$month['scheduled_minutes'],
            'attended_lessons_month'=>$month['attended_lessons'],
            'attended_minutes_month'=>$month['attended_minutes'],
            'delivered_lessons_month'=>$month['delivered_lessons'],
            'upcoming_lessons'=>$upcoming,
            'reschedule_requests_month'=>$rescheduleRequestsMonth,
            'next_lesson'=>$next?array(
                'starts_at_utc'=>(string)$next->starts_at_utc,
                'student'=>(string)$next->student_name,
                'course'=>(string)$next->course_name,
            ):null,
            'ending_terms'=>$rows,
            'pending_reschedules'=>$pendingReschedules,
            'remuneration'=>$this->remuneration($teacherId,$nowUtc),
        );
    }

    /**
     * Pure reconciliation of already month-filtered canonical Lesson rows.
     *
     * Public so the reported regression can be proven without WordPress: three scheduled
     * occurrences whose effective outcome is `delivered` must count as three completed Lessons and
     * their full duration even when the denormalised lifecycle row still says `authorised`.
     *
     * @param list<array<string,mixed>|object> $rows
     * @return array{students:int,scheduled_lessons:int,scheduled_minutes:int,completed_lessons:int,completed_minutes:int,attended_lessons:int,attended_minutes:int,delivered_lessons:int}
     */
    public static function reconcileMonthRows(array $rows):array{
        $students=array();$scheduled=array();$completed=array();$attended=array();$delivered=array();
        $scheduledMinutes=0;$completedMinutes=0;$attendedMinutes=0;
        foreach($rows as $row){
            $row=(array)$row;
            $state=strtolower(trim((string)($row['lifecycle_state']??'')));
            if(!in_array($state,array('authorised','completed'),true))continue;
            $lessonId=(int)($row['lesson_id']??0);
            if($lessonId<1)continue;
            $studentId=(int)($row['student_id']??0);
            if($studentId>0)$students[$studentId]=true;

            $hasOutcome=(int)($row['outcome_id']??0)>0;
            $delivery=$hasOutcome?strtolower(trim((string)($row['delivery_state']??''))):'';
            $attendance=$hasOutcome?strtolower(trim((string)($row['attendance_state']??''))):'';
            $outcomeCode=strtolower(trim((string)($row['outcome_code']??'')));

            $scheduleMinutes=max(0,(int)($row['duration_minutes']??0));
            if($scheduleMinutes<1)$scheduleMinutes=self::minutesBetween($row['starts_at_utc']??'', $row['ends_at_utc']??'');
            $outcomeMinutes=$hasOutcome?self::minutesBetween($row['outcome_starts_at_utc']??'', $row['outcome_ends_at_utc']??''):0;
            if($outcomeMinutes<1)$outcomeMinutes=$scheduleMinutes;

            $scheduled[$lessonId]=true;
            $scheduledMinutes+=$scheduleMinutes;

            $isDelivered=$hasOutcome&&$delivery==='delivered';
            // An explicit non-delivery or unresolved review is never turned into teaching merely
            // because the lifecycle row reached `completed`; the delivery outcome reconciles it.
            $explicitlyNotDelivered=$hasOutcome&&(in_array($delivery,array('not_delivered','unknown'),true)||in_array($outcomeCode,array('teacher_non_delivery','review_required'),true));
            if(($state==='completed'||$isDelivered)&&!$explicitlyNotDelivered){
                $completed[$lessonId]=true;
                $completedMinutes+=$outcomeMinutes;
            }
            if($isDelivered)$delivered[$lessonId]=true;
            if($hasOutcome&&$attendance==='attended'){
                $attended[$lessonId]=true;
                $attendedMinutes+=$outcomeMinutes;
            }
        }
        return array(
            'students'=>count($students),
            'scheduled_lessons'=>count($scheduled),
            'scheduled_minutes'=>$scheduledMinutes,
            'completed_lessons'=>count($completed),
            'completed_minutes'=>$completedMinutes,
            'attended_lessons'=>count($attended),
            'attended_minutes'=>$attendedMinutes,
            'delivered_lessons'=>count($delivered),
        );
    }

    /**
     * Pure remuneration projection over already-scoped Finance facts.
     *
     * Expected earnings are never presented as settled payments. This build has no teacher payout
     * ledger, so the payout status is explicitly unavailable instead of inventing a paid/unpaid fact.
     *
     * @param list<array<string,mixed>|object> $activeRates
     * @param list<array<string,mixed>|object> $issuedStatements
     * @param list<array<string,mixed>|object> $goldenRows
     * @return array<string,mixed>
     */
    public static function remunerationModel(array $activeRates,array $statements,array $goldenRows,?string $nowUtc=null):array{
        $rate=self::rateModel($activeRates);
        $nowUtc=$nowUtc!==null&&self::isUtc($nowUtc)?$nowUtc:gmdate('Y-m-d H:i:s');
        $issued=array();$paid=array();
        foreach($statements as $statement){
            $statement=(array)$statement;
            $state=(string)($statement['state']??'');
            if($state==='issued')$issued[]=$statement;
            elseif($state==='paid')$paid[]=$statement;
        }
        $all=array_merge($issued,$paid);
        $currency='';$currencies=array();$earned=0;$paidTotal=0;$unpaid=0;$oldestUnpaid=null;
        foreach($all as $statement){
            $statementCurrency=strtoupper(trim((string)($statement['currency']??'')));
            if(preg_match('/^[A-Z]{3}$/D',$statementCurrency)!==1)continue;
            $currencies[$statementCurrency]=true;
            $amount=(int)($statement['payable_amount_minor']??0);
            $earned+=$amount;
            if((string)($statement['state']??'')==='paid'){$paidTotal+=$amount;}
            else{$unpaid+=$amount;$end=trim((string)($statement['period_end_utc']??''));if($end!==''&&($oldestUnpaid===null||$end<$oldestUnpaid))$oldestUnpaid=$end;}
        }
        if(count($currencies)===1)$currency=(string)array_key_first($currencies);
        $paymentStatus=self::paymentStatus($unpaid,$oldestUnpaid,$nowUtc);
        $earnedModel=count($currencies)===1
            ?array('state'=>'available','amount_minor'=>$earned,'currency'=>$currency,'statement_count'=>count($all),'paid_amount_minor'=>$paidTotal,'unpaid_amount_minor'=>$unpaid,'paid_statement_count'=>count($paid),'unpaid_statement_count'=>count($issued),'payment_status'=>$paymentStatus,'oldest_unpaid_period_end'=>$oldestUnpaid,'basis'=>'issued_and_paid_statements')
            :array('state'=>'unavailable','reason'=>$currencies===array()?'no_issued_statement':'multiple_currencies','amount_minor'=>null,'currency'=>null,'statement_count'=>count($all),'paid_amount_minor'=>null,'unpaid_amount_minor'=>null,'payment_status'=>$currencies===array()?'not_due':'unavailable','basis'=>'issued_and_paid_statements');

        $goldenMinutes=0;$goldenBonus=0;$goldenCurrencies=array();$goldenLessons=array();$basisPoints=array();$goldenDetails=0;
        foreach($goldenRows as $row){
            $row=(array)$row;
            $minutes=max(0,(int)($row['eligible_minutes']??0));
            $bonus=max(0,(int)($row['total_bonus_minor']??0));
            $details=(string)($row['window_definitions']??'');
            $goldenDetails++;
            $goldenMinutes+=$minutes;
            $goldenBonus+=$bonus;
            if((int)($row['lesson_id']??0)>0)$goldenLessons[(int)$row['lesson_id']]=true;
            $rowCurrency=strtoupper(trim((string)($row['currency']??'')));
            if(preg_match('/^[A-Z]{3}$/D',$rowCurrency)===1)$goldenCurrencies[$rowCurrency]=true;
            $definitions=json_decode($details,true);
            if(is_array($definitions))foreach($definitions as $definition){
                $points=(int)($definition['bonus_basis_points']??0);
                if($points>0)$basisPoints[$points]=true;
            }
        }
        $basisPoints=array_keys($basisPoints);sort($basisPoints,SORT_NUMERIC);
        $goldenCurrency=count($goldenCurrencies)===1?(string)array_key_first($goldenCurrencies):($currency!==''?$currency:'');
        $golden=array(
            'state'=>$goldenDetails>0?'available':'unavailable',
            'reason'=>$goldenDetails>0?null:'no_golden_hour_record',
            'eligible_minutes'=>$goldenDetails>0?$goldenMinutes:null,
            'lesson_count'=>count($goldenLessons),
            'bonus_minor'=>$goldenDetails>0?$goldenBonus:null,
            'bonus_basis_points'=>count($basisPoints)===1?$basisPoints[0]:null,
            'bonus_basis_points_min'=>$basisPoints!==array()?$basisPoints[0]:null,
            'bonus_basis_points_max'=>$basisPoints!==array()?$basisPoints[count($basisPoints)-1]:null,
            'bonus_basis_points_mixed'=>count($basisPoints)>1,
            'currency'=>$goldenCurrency!==''?$goldenCurrency:null,
        );

        $expected=$earnedModel['state']==='available'
            ?array('state'=>'available','amount_minor'=>(int)$earnedModel['amount_minor'],'currency'=>$earnedModel['currency'],'includes_golden_hour_bonus'=>true)
            :array('state'=>'unavailable','reason'=>$earnedModel['reason'],'amount_minor'=>null,'currency'=>null,'includes_golden_hour_bonus'=>null);
        $payout=$earnedModel['state']==='available'
            ?array(
                'state'=>'available','status'=>$paymentStatus,'reason'=>null,
                'paid_minor'=>$paidTotal,'unpaid_minor'=>$unpaid,'actual_payments'=>$paidTotal,
                'currency'=>$currency,'oldest_unpaid_period_end'=>$oldestUnpaid,
                'paid_statement_count'=>count($paid),'unpaid_statement_count'=>count($issued),
            )
            :array(
                'state'=>'unavailable','status'=>'unavailable','reason'=>$earnedModel['reason'],
                'paid_minor'=>null,'unpaid_minor'=>null,'actual_payments'=>null,
                'currency'=>$currency!==''?$currency:($rate['currency']??null),'oldest_unpaid_period_end'=>null,
                'paid_statement_count'=>null,'unpaid_statement_count'=>null,
            );

        return array(
            'state'=>($rate['state']==='available'||$earnedModel['state']==='available')?'partial':'unavailable',
            'estimate_only'=>true,
            'rate'=>$rate,
            'earned'=>$earnedModel,
            'golden_hour'=>$golden,
            'expected_total'=>$expected,
            'payout'=>$payout,
        );
    }

    /**
     * Pure payment status: settled, pending or overdue.
     *
     * A statement is paid only when its append-only `payment_recorded` event exists; an
     * unpaid issued statement older than the 30-day settlement window is overdue.
     */
    public static function paymentStatus(int $unpaidMinor,?string $oldestUnpaidPeriodEndUtc,string $nowUtc):string{
        if($unpaidMinor<=0)return 'up_to_date';
        if($oldestUnpaidPeriodEndUtc===null||!self::isUtc($oldestUnpaidPeriodEndUtc))return 'pending';
        $oldest=strtotime($oldestUnpaidPeriodEndUtc.' UTC');
        $now=strtotime($nowUtc.' UTC');
        if($oldest===false||$now===false)return 'pending';
        return ($now-$oldest)>(30*86400)?'overdue':'pending';
    }

    private static function isUtc(string $value):bool{
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$value)===1&&strtotime($value.' UTC')!==false;
    }

    /** @param list<array<string,mixed>|object> $rates @return array<string,mixed> */
    private static function rateModel(array $rates):array{
        if(count($rates)!==1)return array('state'=>'unavailable','reason'=>$rates===array()?'rate_not_recorded':'rate_ambiguous','amount_minor'=>null,'currency'=>null,'compensation_basis'=>null,'unit_label'=>null,'effective_from'=>null);
        $rate=(array)$rates[0];
        $amount=(int)($rate['amount_minor']??-1);
        $currency=strtoupper(trim((string)($rate['currency']??'')));
        if($amount<0||preg_match('/^[A-Z]{3}$/D',$currency)!==1)return array('state'=>'unavailable','reason'=>'rate_invalid','amount_minor'=>null,'currency'=>null,'compensation_basis'=>null,'unit_label'=>null,'effective_from'=>null);
        return array(
            'state'=>'available',
            'reason'=>null,
            'amount_minor'=>$amount,
            'currency'=>$currency,
            'compensation_basis'=>(string)($rate['compensation_basis']??''),
            'unit_label'=>self::rateUnitLabel((string)($rate['compensation_basis']??'')),
            'effective_from'=>(string)($rate['effective_from']??''),
            'scope_kind'=>(string)($rate['scope_kind']??''),
        );
    }

    private static function rateUnitLabel(string $basis):string{
        return match($basis){
            'per_session'=>'هر جلسه',
            default=>'واحد نامشخص',
        };
    }

    /** Read active effective-dated rates and issued completed-period statements, if the tables exist. */
    private function remuneration(int $teacherId,string $nowUtc):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rates=array();
        if(self::tableExists($p.'finance_teacher_rates')){
            $rates=$wpdb->get_results($wpdb->prepare(
                "SELECT id,scope_kind,course_scope_id,compensation_basis,amount_minor,currency,effective_from,effective_until
                 FROM {$p}finance_teacher_rates
                 WHERE teacher_id=%d AND status='active' AND effective_from<=%s AND (effective_until IS NULL OR effective_until>%s)
                 ORDER BY id",
                $teacherId,$nowUtc,$nowUtc
            ),ARRAY_A);
            if($wpdb->last_error!=='')throw new \RuntimeException('teacher_finance_summary_unavailable');
            $rates=is_array($rates)?$rates:array();
        }
        $statements=array();
        if(self::tableExists($p.'finance_statements')){
            $statements=$wpdb->get_results($wpdb->prepare(
                "SELECT id,currency,period_end_utc,payable_amount_minor,payable_line_count,state
                 FROM {$p}finance_statements
                 WHERE teacher_id=%d AND state IN ('issued','paid') AND period_end_utc<=%s
                 ORDER BY period_end_utc DESC,id DESC",
                $teacherId,$nowUtc
            ),ARRAY_A);
            if($wpdb->last_error!=='')throw new \RuntimeException('teacher_finance_summary_unavailable');
            $statements=is_array($statements)?$statements:array();
        }
        $goldenRows=array();
        if($statements!==array()&&self::tableExists($p.'finance_statement_lines')&&self::tableExists($p.'finance_golden_hour_details')){
            $ids=array_values(array_filter(array_map(static fn(array $row):int=>(int)($row['id']??0),$statements),static fn(int $id):bool=>$id>0));
            if($ids!==array()){
                $marks=implode(',',array_fill(0,count($ids),'%d'));
                $sql="SELECT line.statement_id,line.lesson_id,line.currency,line.disposition,line.line_amount_minor,
                             detail.eligible_minutes,detail.total_bonus_minor,detail.window_definitions
                      FROM {$p}finance_statement_lines line
                      LEFT JOIN {$p}finance_golden_hour_details detail
                        ON detail.snapshot_version_reference=CONCAT('S',line.snapshot_id,':C',COALESCE(line.snapshot_correction_id,0))
                      WHERE line.statement_id IN ({$marks}) AND line.disposition='payable'";
                $goldenRows=$wpdb->get_results($wpdb->prepare($sql,...$ids),ARRAY_A);
                if($wpdb->last_error!=='')throw new \RuntimeException('teacher_finance_summary_unavailable');
                $goldenRows=is_array($goldenRows)?$goldenRows:array();
            }
        }
        return self::remunerationModel($rates,$statements,$goldenRows,$nowUtc);
    }

    private static function resolveTimezone(?string $timezone):string{
        $timezone=trim((string)$timezone);
        if($timezone!==''&&(in_array($timezone,timezone_identifiers_list(),true)||preg_match('/^[+-]\d{2}:\d{2}$/D',$timezone)===1))return $timezone;
        $fallback=trim((string)wp_timezone_string());
        if($fallback!==''&&(in_array($fallback,timezone_identifiers_list(),true)||preg_match('/^[+-]\d{2}:\d{2}$/D',$fallback)===1))return $fallback;
        return 'UTC';
    }

    private static function tableExists(string $table):bool{
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table;
    }

    private static function minutesBetween(mixed $start,mixed $end):int{
        $start=trim((string)$start);$end=trim((string)$end);
        if($start===''||$end==='')return 0;
        $from=strtotime($start.' UTC');$to=strtotime($end.' UTC');
        if($from===false||$to===false||$to<=$from)return 0;
        return (int)round(($to-$from)/60);
    }
}
