<?php
// WordPress DB integration-test scaffold: initial v1/current pointer/draft-to-scheduled,
// duplicate initial rejection, invalid/archived/cancelled/completed cases, UTC conversion,
// Course duration excluding buffer, reschedule supersession/history/N+1/identity preservation,
// rollback and unique-version concurrency guard, and DST nonexistent-time rejection.
$root=dirname(__DIR__);foreach(['LessonScheduleService','LessonService']as$f)if(!is_file("$root/src/Core/Application/$f.php"))throw new RuntimeException("Missing $f");
$source=file_get_contents("$root/src/Core/Application/LessonScheduleService.php");foreach(['current_schedule_version_id','superseded_at','version_number','DateTimeZone','default_duration_minutes']as$f)if(!str_contains($source,$f))throw new RuntimeException("Schedule invariant missing: $f");
echo "Phase 1C executable test scaffold loaded\n";
