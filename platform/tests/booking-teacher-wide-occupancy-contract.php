<?php
/** Source contract for cross-instrument introductory booking capacity. */
$root=dirname(__DIR__);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$query=$read('src/Core/Infrastructure/Repository/TeacherBookingOccupancyQuery.php');
$occupancy=$read('src/Core/Application/CanonicalTeacherOccupancyReadService.php');
$allocation=$read('src/Core/Application/BookingAllocationService.php');
$response=$read('src/Core/Application/BookingTeacherResponseService.php');
$responseRepo=$read('src/Core/Infrastructure/Repository/BookingTeacherResponseRepository.php');
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
foreach(array("o.state='pending'","o.active_slot=1","r.lifecycle_status='submitted'","rt.starts_at_utc<%s","rt.occupied_ends_at_utc>%s")as$needle)$assert(str_contains($query,$needle),'Pending Teacher offers must reserve their exact buffered UTC interval: '.$needle);
foreach(array("c.state NOT IN ('failed','cancelled')","rt.occupied_ends_at_utc","rt.starts_at_utc<%s")as$needle)$assert(str_contains($query,$needle),'Teacher confirmations must reserve their persisted buffered UTC interval until explicit release: '.$needle);
$assert(str_contains($occupancy,'TeacherBookingOccupancyQuery::conflicts')&&str_contains($occupancy,'$excludeBookingRequestId'),'The shared Teacher occupancy authority must include booking offers and confirmations while allowing its own request to be rechecked.');
$assert(str_contains($allocation,'lockEligibleTeacherRoots')&&str_contains($allocation,'ensureAndLockTeacherRoot')&&str_contains($allocation,'sort($ids,SORT_NUMERIC)'),'Cross-instrument offer allocation must serialize eligible Teachers in a stable order.');
$assert(str_contains($response,'ensureAndLockTeacherRoot')&&str_contains($response,'teacherOccupancyConflicts')&&str_contains($response,"'teacher_slot_conflict'"),'Teacher acceptance must lock and recheck Teacher-wide capacity before confirmation.');
$assert(str_contains($responseRepo,'CanonicalTeacherOccupancyReadService')&&str_contains($responseRepo,'ensureAndLockTeacherRoot'),'The response repository must use the same Teacher capacity authority and root lock.');
echo "Booking teacher-wide occupancy contract passed\n";
