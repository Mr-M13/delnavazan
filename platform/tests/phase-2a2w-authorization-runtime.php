<?php
$root=dirname(__DIR__);$source=file_get_contents($root.'/src/Core/Application/PortalOwnerReadPorts.php').file_get_contents($root.'/src/Portals/PortalOwnerPorts.php');
foreach(array('CanonicalEnrolmentLifecycleValidator::valid','TeacherAssignmentAssessment::validHistory','TermApplicabilityAssessment::CANONICAL_APPLICABLE','CanonicalLessonAuthorityValidator::valid','CanonicalLessonScheduleValidator::validForLesson','CanonicalLessonDeliveryValidator::validForLesson','CanonicalAttendanceValidator::validForCase','portal_object_not_owned','portal_upstream_aggregate_invalid') as $needle)if(strpos($source,$needle)===false)throw new RuntimeException('Phase-W authorization preflight missing '.$needle);
echo "Phase-W authorization source preflight passed\n";
if(!defined('ABSPATH')){fwrite(STDERR,"Phase-W authorization runtime requires the disposable WordPress/MariaDB runtime; not executed locally.\n");exit(2);}
