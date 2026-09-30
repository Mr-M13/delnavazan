<?php
/** In-memory mutation proof for every Phase 2A.2-G authority semantic guard. */
require __DIR__.'/phase-2a2g-contract.php';
$root=dirname(__DIR__);
$baselineRepo=file_get_contents($root.'/src/Core/Infrastructure/Repository/StudentIdentityAuthorityRepository.php');
$baselineService=file_get_contents($root.'/src/Core/Application/FinalAcceptanceService.php');
$mutations=array(
    'principal Student'=>array('service','(int) $row->student_id === $studentId'),
    'principal WordPress user'=>array('service','(int) $row->wordpress_user_id === $userId'),
    'principal status'=>array('service','$row->status === \'active\''),
    'principal active slot'=>array('service','(int) $row->active_slot === 1'),
    'guardian Student'=>array('service','(int) $row->student_id === $studentId',2),
    'guardian WordPress user'=>array('service','(int) $row->acting_wordpress_user_id === $userId'),
    'guardian authority type'=>array('service','$row->authority_type === \'guardian_representative\''),
    'guardian scope'=>array('service','$row->authority_scope === \'service_acceptance\''),
    'guardian status'=>array('service','$row->state === \'active\''),
    'guardian active slot'=>array('service','(int) $row->active_slot === 1',2),
    'guardian effective_from'=>array('service','$row->effective_from <= $now'),
    'guardian effective_until'=>array('service','($row->effective_until === null || $row->effective_until > $now)'),
    'discovery membership Student'=>array('repo','(int)$row->student_id!==$studentId'),
    'discovery membership WordPress user'=>array('repo','!in_array((int)$row->{$userColumn},$userIds,true)'),
    'adult missing-authority rejection'=>array('service','if (!$principal) throw new \\InvalidArgumentException'),
    'minor missing-authority rejection'=>array('service','if (!$grant) throw new \\InvalidArgumentException'),
);
foreach($mutations as$name=>$mutation){
    [$target,$needle]=$mutation;$occurrence=$mutation[2]??1;$repo=$baselineRepo;$service=$baselineService;$source=$target==='repo'?$repo:$service;$offset=0;$position=false;
    for($seen=0;$seen<$occurrence;$seen++){$position=strpos($source,$needle,$offset);if($position===false)throw new RuntimeException('Mutation target unavailable: '.$name);$offset=$position+strlen($needle);}
    $source=substr_replace($source,'true',$position,strlen($needle));if($target==='repo')$repo=$source;else$service=$source;
    $failed=false;try{phase_2a2g_assert_authority_semantics($repo,$service);}catch(RuntimeException){$failed=true;}
    if(!$failed)throw new RuntimeException('Contract survived weakened predicate: '.$name);
    echo 'mutation_rejected='.$name."\n";
}
echo "Phase 2A.2-G authority semantic mutation contract passed\n";
