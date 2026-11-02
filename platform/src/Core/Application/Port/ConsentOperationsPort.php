<?php
namespace Delnavazan\Platform\Core\Application\Port;

/** Read-only port for authoritative consent operations projections. */
interface ConsentOperationsPort {
    public function counts():array;
    public function pendingTeachers(int $limit=50):array;
    public function pendingStudents(int $limit=50):array;
    public function guardianAttention(int $limit=50):array;
    public function withdrawn(int $limit=50):array;
    public function exceptions(int $limit=50):array;
    public function jobs(int $limit=50):array;
    public function evidence(string $subjectType,int $subjectId,int $limit=100):array;
    public function policyLibrary():array;
}
