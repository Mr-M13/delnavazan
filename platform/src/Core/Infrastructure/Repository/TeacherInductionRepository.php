<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

use Delnavazan\Platform\Core\Application\Induction\TeacherInductionCurriculum;

/**
 * Minimal persistence for educational induction progress.
 *
 * This is deliberately separate from teacher_onboarding_states: induction completion is not an
 * activation, access, or readiness fact. One row per (teacher, module) makes every write
 * idempotent at the database boundary. No invitation, provider, or finance value is stored here.
 */
final class TeacherInductionRepository {
    private string $prefix;

    public function __construct() { global $wpdb; $this->prefix = $wpdb->prefix . 'dzn_'; }

    /** @return array<string, object> keyed by module id */
    public function rowsForTeacher( int $teacherId ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id,teacher_id,module_id,state,content_version,acknowledged_version,completed_at,last_visited_at,version,created_at,updated_at
             FROM {$this->prefix}teacher_induction_progress WHERE teacher_id=%d ORDER BY id",
            $teacherId
        ) );
        if ( $wpdb->last_error !== '' ) throw new \RuntimeException( 'Teacher induction progress is unavailable' );
        $result = array();
        foreach ( (array) $rows as $row ) {
            $moduleId = TeacherInductionCurriculum::moduleId( (string) $row->module_id );
            if ( '' !== $moduleId ) { $result[ $moduleId ] = $row; }
        }
        return $result;
    }

    /** A visit is a presentation fact, not a completion or access decision. */
    public function touch( int $teacherId, string $moduleId, string $now, int $actor ): void {
        global $wpdb;
        $moduleId = TeacherInductionCurriculum::moduleId( $moduleId );
        if ( '' === $moduleId ) throw new \InvalidArgumentException( 'Induction module is not available' );
        $sql = $wpdb->prepare(
            "INSERT INTO {$this->prefix}teacher_induction_progress
                (teacher_id,module_id,state,content_version,acknowledged_version,completed_at,last_visited_at,version,created_at,updated_at,created_by,updated_by)
             VALUES (%d,%s,'in_progress',1,0,NULL,%s,1,%s,%s,%d,%d)
             ON DUPLICATE KEY UPDATE
                last_visited_at=VALUES(last_visited_at),
                updated_at=VALUES(updated_at),
                updated_by=VALUES(updated_by),
                version=version+1",
            $teacherId, $moduleId, $now, $now, $now, $actor, $actor
        );
        if ( $wpdb->query( $sql ) === false ) throw new \RuntimeException( 'Teacher induction visit could not be recorded' );
    }

    /**
     * Complete or re-acknowledge one module.
     *
     * The unique (teacher_id,module_id) key makes duplicate requests a no-op for identity and
     * completion time. Re-completion after a material content change advances the recorded
     * versions without changing any canonical teacher lifecycle or access fact.
     */
    public function complete( int $teacherId, string $moduleId, int $contentVersion, int $acknowledgedVersion, string $now, int $actor ): object {
        global $wpdb;
        $moduleId = TeacherInductionCurriculum::moduleId( $moduleId );
        if ( '' === $moduleId ) throw new \InvalidArgumentException( 'Induction module is not available' );
        $contentVersion = max( 1, $contentVersion );
        $acknowledgedVersion = max( 0, $acknowledgedVersion );
        $sql = $wpdb->prepare(
            "INSERT INTO {$this->prefix}teacher_induction_progress
                (teacher_id,module_id,state,content_version,acknowledged_version,completed_at,last_visited_at,version,created_at,updated_at,created_by,updated_by)
             VALUES (%d,%s,'completed',%d,%d,%s,%s,1,%s,%s,%d,%d)
             ON DUPLICATE KEY UPDATE
                state='completed',
                content_version=GREATEST(content_version,VALUES(content_version)),
                acknowledged_version=GREATEST(acknowledged_version,VALUES(acknowledged_version)),
                completed_at=COALESCE(completed_at,VALUES(completed_at)),
                last_visited_at=VALUES(last_visited_at),
                updated_at=VALUES(updated_at),
                updated_by=VALUES(updated_by),
                version=version+1",
            $teacherId, $moduleId, $contentVersion, $acknowledgedVersion, $now, $now, $now, $now, $actor, $actor
        );
        if ( $wpdb->query( $sql ) === false ) throw new \RuntimeException( 'Teacher induction completion could not be recorded' );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id,teacher_id,module_id,state,content_version,acknowledged_version,completed_at,last_visited_at,version,created_at,updated_at
             FROM {$this->prefix}teacher_induction_progress WHERE teacher_id=%d AND module_id=%s LIMIT 1",
            $teacherId, $moduleId
        ) );
        if ( ! $row ) throw new \RuntimeException( 'Teacher induction completion was not persisted' );
        return $row;
    }
}
