<?php
namespace Delnavazan\Platform\Core\Application;

/** Testable persistence seam for the teacher-initiated reschedule workflow. */
interface LessonRescheduleStore {
    public function begin():void;
    public function commit():void;
    public function rollback():void;
    public function proposalByUid(string $uid,bool $lock=false):?object;
    public function proposalByCommandDigest(string $digest):?object;
    public function proposalByDecisionDigest(string $digest):?object;
    public function proposal(int $id,bool $lock=false):?object;
    public function pendingForStudent(int $studentId):array;
    public function pendingForLesson(int $lessonId,bool $lock=false):?object;
    public function lessonContext(int $lessonId):?object;
    public function lessonContextByUid(string $lessonUid):?object;
    public function versionIdByUid(string $uid):int;
    public function bookingClassByUid(string $uid):?object;
    public function insertProposal(array $data):int;
    public function updateProposal(int $id,int $expectedVersion,array $data):int;
    public function insertEvent(array $data):int;
    public function eventsFor(int $proposalId):array;
    public function insertNotification(array $data):int;
    public function pendingNotifications(int $limit=20):array;
    public function notification(int $id,bool $lock=false):?object;
    public function updateNotification(int $id,array $data):int;
    public function insertCalendarJob(array $data):int;
    public function pendingCalendarJobs(int $limit=10):array;
    public function calendarJob(int $id,bool $lock=false):?object;
    public function updateCalendarJob(int $id,array $data):int;
    public function ensureAndLockStudentRoot(int $studentId,string $now,int $actor):void;
    public function studentConflict(int $studentId,string $startsAtUtc,string $endsAtUtc,int $excludeLessonId):array;
    public function duplicate(\Throwable $e):?string;
}
