<?php
namespace Delnavazan\Platform\Portals;

final class PortalPrincipalResolver {
    public function resolve(string $requiredKind):array {
        if($requiredKind!=='any'&&!in_array($requiredKind,PortalRule::PRINCIPAL_KINDS,true))throw new \InvalidArgumentException('portal_principal_kind_not_permitted');
        $userId=(int)get_current_user_id();
        if($userId<1) throw new \InvalidArgumentException('portal_principal_required');
        global $wpdb; $p=$wpdb->prefix.'dzn_'; $now=current_time('mysql',true); $candidates=array();
        if($requiredKind==='teacher'||$requiredKind==='any'){$r=$wpdb->get_results($wpdb->prepare("SELECT teacher_id id FROM {$p}teacher_principal_links WHERE wordpress_user_id=%d AND status='active' AND revoked_at IS NULL",$userId));foreach($r as$row)$candidates[]=array('kind'=>'teacher','id'=>(int)$row->id);}
        if($requiredKind==='student'||$requiredKind==='any'){$r=$wpdb->get_results($wpdb->prepare("SELECT student_id id FROM {$p}student_principal_links WHERE wordpress_user_id=%d AND status='active' AND revoked_at IS NULL AND active_slot=1",$userId));foreach($r as$row)$candidates[]=array('kind'=>'student','id'=>(int)$row->id);}
        if($requiredKind==='guardian'){$r=$wpdb->get_results($wpdb->prepare("SELECT id grant_id,student_id id,authority_scope grant_scope,effective_from grant_effective_from,effective_until grant_effective_until FROM {$p}student_acceptance_authority_grants WHERE acting_wordpress_user_id=%d AND authority_type='guardian_representative' AND authority_scope=%s AND state='active' AND active_slot=1 AND effective_from<=%s AND (effective_until IS NULL OR effective_until>%s)",$userId,PortalRule::GUARDIAN_PORTAL_READ_SCOPE,$now,$now));foreach($r as$row)$candidates[]=array('kind'=>'guardian','id'=>(int)$row->id,'grant_id'=>(int)$row->grant_id,'grant_scope'=>(string)$row->grant_scope,'grant_effective_from'=>(string)$row->grant_effective_from,'grant_effective_until'=>$row->grant_effective_until===null?null:(string)$row->grant_effective_until);}
        if(count($candidates)!==1) throw new \InvalidArgumentException(count($candidates)?'portal_principal_ambiguous':'portal_principal_unresolved');
        if($requiredKind!=='any'&&$candidates[0]['kind']!==$requiredKind) throw new \InvalidArgumentException('portal_principal_kind_not_permitted');
        $candidate=$candidates[0];$entity=$candidate['kind']==='teacher'?'teachers':'students';$row=$wpdb->get_row($wpdb->prepare("SELECT uid,reference_code FROM {$p}{$entity} WHERE id=%d AND archived_at IS NULL LIMIT 1",(int)$candidate['id']));if(!$row||trim((string)$row->uid)===''||trim((string)$row->reference_code)==='')throw new \InvalidArgumentException('portal_principal_unresolved');$candidate['uid']=(string)$row->uid;$candidate['display_reference']=(string)$row->reference_code;$candidate['linked_at']=$candidate['kind']==='guardian'?(string)$candidate['grant_effective_from']:(string)$wpdb->get_var($wpdb->prepare("SELECT linked_at FROM {$p}".($candidate['kind']==='teacher'?'teacher':'student')."_principal_links WHERE ".($candidate['kind']==='teacher'?'teacher':'student')."_id=%d AND wordpress_user_id=%d AND status='active' LIMIT 1",(int)$candidate['id'],$userId));if($candidate['linked_at']==='')throw new \InvalidArgumentException('portal_principal_unresolved');return $candidate;
    }
}
