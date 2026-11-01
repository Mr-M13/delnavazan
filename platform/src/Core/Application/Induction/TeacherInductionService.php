<?php
namespace Delnavazan\Platform\Core\Application\Induction;

use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherInductionRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherOnboardingRepository;

/**
 * Educational induction progress for an approved, ready Teacher.
 *
 * Induction is intentionally not part of TeacherOnboardingService. It never activates, blocks,
 * demotes, or offboards a Teacher. The authenticated principal is resolved from the active
 * WordPress link and the same readiness authority used by the Teacher Portal; request data can
 * only name a curriculum module, never a Teacher or another user's progress.
 */
final class TeacherInductionService {
    public const STATE_NOT_STARTED = 'not_started';
    public const STATE_IN_PROGRESS = 'in_progress';
    public const STATE_COMPLETED = 'completed';

    public function __construct(
        private ?TeacherInductionRepository $repo = null,
        private ?TeacherOnboardingRepository $onboarding = null,
        private ?PrincipalInvitationRepository $invitations = null
    ) {
        $this->repo ??= new TeacherInductionRepository();
        $this->onboarding ??= new TeacherOnboardingRepository();
        $this->invitations ??= new PrincipalInvitationRepository();
    }

    /** @return array<string, mixed> */
    public function hubForUser( ?int $userId = null ): array {
        $teacher = $this->teacherForUser( $userId );
        return $this->hub( (int) $teacher->id );
    }

    /** @return array<string, mixed> */
    public function moduleForUser( string $moduleId, ?int $userId = null, bool $recordVisit = true ): array {
        $teacher = $this->teacherForUser( $userId );
        $module = TeacherInductionCurriculum::module( $moduleId );
        if ( ! $module ) throw new \InvalidArgumentException( 'Induction module is not available' );
        if ( $recordVisit ) {
            $this->repo->touch( (int) $teacher->id, (string) $module['id'], current_time( 'mysql', true ), (int) get_current_user_id() );
        }
        $hub = $this->hub( (int) $teacher->id );
        $selected = null;
        foreach ( $hub['modules'] as $candidate ) {
            if ( $candidate['id'] === $module['id'] ) { $selected = $candidate; break; }
        }
        if ( ! $selected ) throw new \RuntimeException( 'Induction module projection is unavailable' );
        return array( 'hub' => $hub, 'module' => $selected );
    }

    /**
     * Idempotently complete one module.
     *
     * The current server-side curriculum version is authoritative; a tampered client version is
     * ignored. Repeating the same request cannot create a second row or change completion time.
     *
     * @return array<string, mixed>
     */
    public function completeForUser( string $moduleId, ?int $userId = null ): array {
        $teacher = $this->teacherForUser( $userId );
        $module = TeacherInductionCurriculum::module( $moduleId );
        if ( ! $module ) throw new \InvalidArgumentException( 'Induction module is not available' );
        $before = $this->repo->rowsForTeacher( (int) $teacher->id );
        $alreadyCompleted = isset( $before[ $module['id'] ] ) && self::STATE_COMPLETED === (string) $before[ $module['id'] ]->state;
        $row = $this->repo->complete(
            (int) $teacher->id,
            (string) $module['id'],
            (int) $module['version'],
            (int) ( $module['acknowledgement_version'] ?? 0 ),
            current_time( 'mysql', true ),
            (int) get_current_user_id()
        );
        return array(
            'module_id' => (string) $row->module_id,
            'state' => (string) $row->state,
            'completed_at' => (string) $row->completed_at,
            'already_completed' => $alreadyCompleted,
            'hub' => $this->hub( (int) $teacher->id ),
        );
    }

    /** @return array<string, mixed> */
    private function hub( int $teacherId ): array {
        $modules = TeacherInductionCurriculum::all();
        $rows = $this->repo->rowsForTeacher( $teacherId );
        $projected = array();
        $completed = 0;
        $started = 0;
        $lastVisited = null;
        $next = null;
        foreach ( $modules as $module ) {
            $row = $rows[ $module['id'] ] ?? null;
            $state = self::STATE_NOT_STARTED;
            if ( $row ) {
                $state = self::STATE_COMPLETED === (string) $row->state ? self::STATE_COMPLETED : self::STATE_IN_PROGRESS;
            }
            if ( self::STATE_COMPLETED === $state ) { ++$completed; }
            if ( self::STATE_IN_PROGRESS === $state ) { ++$started; }
            if ( ! $next && self::STATE_COMPLETED !== $state ) { $next = (string) $module['id']; }
            $acknowledgedVersion = $row ? (int) $row->acknowledged_version : 0;
            $needsReacknowledgement = self::STATE_COMPLETED === $state
                && (int) ( $module['acknowledgement_version'] ?? 0 ) > $acknowledgedVersion;
            if ( $row && ! empty( $row->last_visited_at ) && ( null === $lastVisited || (string) $row->last_visited_at > $lastVisited ) ) {
                $lastVisited = (string) $row->last_visited_at;
            }
            $projected[] = $module + array(
                'state' => $state,
                'content_version' => $row ? (int) $row->content_version : 0,
                'acknowledged_version' => $acknowledgedVersion,
                'completed_at' => $row && ! empty( $row->completed_at ) ? (string) $row->completed_at : null,
                'last_visited_at' => $row && ! empty( $row->last_visited_at ) ? (string) $row->last_visited_at : null,
                'needs_reacknowledgement' => $needsReacknowledgement,
                'is_started' => self::STATE_NOT_STARTED !== $state,
            );
        }
        if ( null === $next ) {
            // A teacher who has visited every module resumes at the most recently visited one.
            foreach ( $projected as $module ) {
                if ( null !== $module['last_visited_at'] && ( null === $lastVisited || $module['last_visited_at'] === $lastVisited ) ) {
                    $next = (string) $module['id'];
                }
            }
        }
        $total = count( $projected );
        $state = 0 === $completed ? ( $started > 0 ? self::STATE_IN_PROGRESS : self::STATE_NOT_STARTED )
            : ( $completed === $total ? self::STATE_COMPLETED : self::STATE_IN_PROGRESS );
        return array(
            'teacher_id' => $teacherId,
            'modules' => $projected,
            'completed_count' => $completed,
            'started_count' => $started,
            'total_count' => $total,
            'percent' => $total > 0 ? (int) round( $completed * 100 / $total ) : 0,
            'state' => $state,
            'next_module_id' => $next,
            'last_visited_at' => $lastVisited,
            'content_version' => max( array_map( static fn( array $module ): int => (int) $module['version'], $projected ) ),
        );
    }

    /** Resolve the authenticated ready Teacher or fail closed. */
    private function teacherForUser( ?int $userId ): object {
        $userId ??= (int) get_current_user_id();
        if ( $userId < 1 ) throw new \InvalidArgumentException( 'Induction requires an authenticated teacher' );
        $teacher = $this->onboarding->linkedTeacherForUser( $userId );
        if ( ! $teacher || (string) $teacher->principal_status !== 'active' ) {
            throw new \InvalidArgumentException( 'Induction requires a linked teacher principal' );
        }
        // The same live readiness proof used by PortalAuthenticatedReadService. A rejected,
        // offboarded, incomplete, or no-longer-available Teacher cannot reach induction.
        if ( ! $this->invitations->hasActiveTeacherAuthority( $userId, (int) $teacher->id ) ) {
            throw new \InvalidArgumentException( 'Induction is available only to an active ready teacher' );
        }
        return $teacher;
    }
}
