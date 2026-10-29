<?php
namespace Delnavazan\Platform\Core\Application\Finance\Read;

use Delnavazan\Platform\Core\Application\Finance\{FinanceRule,FinanceSupport,TeacherRateService};

/**
 * Capability-protected, PII-minimised read seam for effective-dated teacher rates (§11.1, §14.1).
 *
 * The read layer hydrates and validates its aggregate through the owning validator and fails closed on a
 * malformed shape. It exposes identifiers, scope, amounts, currencies, intervals, states and the resolved
 * coverage outcome — never a raw command digest, an evidence payload, a caller key or a provider value.
 */
final class TeacherRateReadService {
    private const CAPABILITY='dzn_view_finance_authority';

    public function __construct(private ?TeacherRateService $rates=null){$this->rates??=new TeacherRateService();}

    /** §11.1 `teacherRateCoverage`: whether a rate is effective at an instant, and the recorded timeline. */
    public function coverage(int $teacherId,string $atUtc):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        if(!FinanceRule::utc($atUtc))throw new \InvalidArgumentException('Valid UTC instant required');
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $courseId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}courses ORDER BY id LIMIT 1"));
        $coverage=array('teacher_id'=>$teacherId,'at'=>$atUtc,'covered'=>false,'reason'=>null,'gap'=>false,'rate_id'=>null,'rate_version'=>null,'scope_kind'=>null,'amount_minor'=>null,'currency'=>null);
        if($courseId>0&&$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}teachers WHERE id=%d",$teacherId))!==null){
            $outcome=$this->rates->resolveFor($teacherId,$courseId,$atUtc);
            $coverage['covered']=$outcome['resolved'];
            $coverage['reason']=$outcome['reason'];
            $coverage['gap']=$outcome['gap'];
            if($outcome['rate']){
                $coverage['rate_id']=(int)$outcome['rate']->id;$coverage['rate_version']=(int)$outcome['rate']->rate_version;
                $coverage['scope_kind']=(string)$outcome['rate']->scope_kind;$coverage['amount_minor']=(int)$outcome['rate']->amount_minor;
                $coverage['currency']=(string)$outcome['rate']->currency;
            }
        }
        $timeline=$this->rates->timeline($teacherId);
        $coverage['timeline']=$timeline['rates'];
        return $coverage;
    }
    /** The recorded timeline of one Teacher, proved against the scope/overlap invariants. */
    public function timeline(int $teacherId):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $timeline=$this->rates->timeline($teacherId);
        $courseIds=array();
        foreach((array)($timeline['rates']??array()) as $rate){
            if((string)($rate['scope_kind']??'')==='teacher_course'&&(int)($rate['course_scope_id']??0)>0)$courseIds[]=(int)$rate['course_scope_id'];
        }
        $names=$this->courseNames($courseIds);
        if(isset($timeline['rates'])&&is_array($timeline['rates'])){
            foreach($timeline['rates'] as &$rate){
                $courseId=(int)($rate['course_scope_id']??0);
                $rate['course_name']=($rate['scope_kind']??'')==='teacher_course'?($names[$courseId]??'Course unavailable'):null;
            }
            unset($rate);
        }
        return $timeline;
    }

    /** @param list<int> $courseIds @return array<int,string> */
    private function courseNames(array $courseIds):array{
        $courseIds=array_values(array_unique(array_filter(array_map('intval',$courseIds),static fn(int $id):bool=>$id>0)));
        if($courseIds===array())return array();
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $marks=implode(',',$courseIds);
        $rows=$wpdb->get_results("SELECT id,COALESCE(NULLIF(name_en,''),NULLIF(name_fa,''),'Course unavailable') AS course_name FROM {$p}courses WHERE id IN ({$marks})")?:array();
        $names=array();
        foreach($rows as $row)$names[(int)$row->id]=(string)$row->course_name;
        return $names;
    }
}
