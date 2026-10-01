<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherOnboardingRepository;

/** Authoritative invitation-only lifecycle from linked principal to approved Teacher readiness. */
final class TeacherOnboardingService {
    private const EDITABLE = array( 'linked_pending', 'in_progress', 'returned', 'rejected' );
    private const RESUBMITTABLE = array( 'linked_pending', 'in_progress', 'returned', 'rejected' );

    public function __construct(private ?TeacherOnboardingRepository $repo = null) { $this->repo ??= new TeacherOnboardingRepository(); }

    public function currentForUser(?int $userId = null): array {
        $userId ??= (int) get_current_user_id(); if ( $userId < 1 ) throw new \InvalidArgumentException( 'Authenticated teacher required' );
        $teacher = $this->repo->linkedTeacherForUser( $userId ); if ( ! $teacher ) throw new \InvalidArgumentException( 'Linked teacher principal required' );
        $state = (string) ( $teacher->onboarding_state ?: 'linked_pending' ); $progress = $this->progress( $teacher );
        return array(
            'teacher_id' => (int) $teacher->id,
            'state' => $state,
            'readiness_state' => (string) ( $teacher->readiness_state ?: 'not_ready' ),
            'profile_state' => $progress['profile_state'],
            'availability_state' => $progress['availability_state'],
            'agreement_state' => (string) ( $teacher->agreement_state ?: 'not_required' ),
            'submitted_at' => $teacher->submitted_at,
            'reviewed_at' => $teacher->reviewed_at,
            'review_reason_code' => $teacher->review_reason_code,
            'can_edit' => in_array( $state, self::EDITABLE, true ),
            'can_submit' => in_array( $state, self::RESUBMITTABLE, true ) && $progress['ready_for_review'],
            'ready_for_review' => $progress['ready_for_review'],
            'profile' => array(
                'display_name' => (string) $teacher->display_name, 'persian_name' => (string) ( $teacher->persian_name ?? '' ), 'english_name' => (string) ( $teacher->english_name ?? '' ),
                'email' => (string) ( $teacher->email ?? '' ), 'country_code' => (string) ( $teacher->country_code ?? '' ), 'city' => (string) ( $teacher->city ?? '' ),
                'timezone' => (string) ( $teacher->timezone ?? '' ), 'locale' => (string) ( $teacher->locale ?? '' ), 'calendar_preference' => (string) ( $teacher->calendar_preference ?? '' ),
            ),
            'availability_profile' => $this->profileArray( $this->repo->availabilityProfile( (int) $teacher->id ) ),
            'availability_rules' => array_map( static fn( object $row ): array => array(
                'id' => (int) $row->id, 'weekday' => (int) $row->weekday, 'local_start_time' => (string) $row->local_start_time,
                'local_end_time' => (string) $row->local_end_time, 'state' => (string) $row->state, 'timezone' => (string) $row->timezone,
            ), $this->repo->availabilityRules( (int) $teacher->id ) ),
        );
    }

    public function requiresOnboarding(?int $userId = null): bool {
        try { $current = $this->currentForUser( $userId ); } catch ( \Throwable ) { return true; }
        return $current['state'] !== 'active' || $current['readiness_state'] !== 'ready';
    }

    public function updateOwnProfile(array $input): void {
        $actor = (int) get_current_user_id(); if ( $actor < 1 ) throw new \RuntimeException( 'Unauthorized' );
        $now = current_time( 'mysql', true ); $this->repo->begin();
        try {
            $teacher = $this->repo->linkedTeacherForUser( $actor, true ); if ( ! $teacher ) throw new \InvalidArgumentException( 'Linked teacher principal required' );
            $state = (string) ( $teacher->onboarding_state ?: 'linked_pending' ); if ( ! in_array( $state, self::EDITABLE, true ) ) throw new \InvalidArgumentException( 'Teacher onboarding is not editable' );
            $data = array(
                'display_name' => Normalizer::text( $input['display_name'] ?? '', 191, true ),
                'persian_name' => Normalizer::text( $input['persian_name'] ?? null ),
                'english_name' => Normalizer::text( $input['english_name'] ?? null ),
                'email' => Normalizer::email( $input['email'] ?? null ),
                'country_code' => Normalizer::country( $input['country_code'] ?? null ),
                'city' => Normalizer::text( $input['city'] ?? null ),
                'timezone' => Normalizer::timezone( $input['timezone'] ?? null ),
                'locale' => Normalizer::locale( $input['locale'] ?? null ),
                'calendar_preference' => Normalizer::one( $input['calendar_preference'] ?? '', array( 'auto', 'gregorian', 'persian' ), 'calendar' ),
            );
            if ( ! $data['email'] || ! $data['country_code'] || ! $data['timezone'] || ! $data['locale'] ) throw new \InvalidArgumentException( 'Required teacher profile fields are missing' );
            $availability = $this->repo->availabilityProfile( (int) $teacher->id );
            if ( $availability && (string) $availability->timezone !== $data['timezone'] && $this->repo->availabilityHasFacts( (int) $availability->id ) ) throw new \InvalidArgumentException( 'Timezone cannot change while availability facts exist' );
            $this->repo->updateTeacherProfile( (int) $teacher->id, $data, $now, $actor );
            $fresh = $this->repo->teacherForUpdate( (int) $teacher->id ); $this->refreshProgress( $fresh, $state, $now, $actor );
            $this->repo->commit();
        } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    /** Recalculate stored progress after the canonical availability authority accepts a Teacher mutation. */
    public function refreshOwnProgress(): void {
        $actor = (int) get_current_user_id(); if ( $actor < 1 ) throw new \RuntimeException( 'Unauthorized' );
        $now = current_time( 'mysql', true ); $this->repo->begin();
        try {
            $teacher = $this->repo->linkedTeacherForUser( $actor, true ); if ( ! $teacher ) throw new \InvalidArgumentException( 'Linked teacher principal required' );
            $state = (string) ( $teacher->onboarding_state ?: 'linked_pending' ); if ( ! in_array( $state, self::EDITABLE, true ) ) throw new \InvalidArgumentException( 'Teacher onboarding is not editable' );
            $this->refreshProgress( $teacher, $state, $now, $actor ); $this->repo->commit();
        } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    public function submitOwn(): void {
        $actor = (int) get_current_user_id(); if ( $actor < 1 ) throw new \RuntimeException( 'Unauthorized' );
        $now = current_time( 'mysql', true ); $this->repo->begin();
        try {
            $teacher = $this->repo->linkedTeacherForUser( $actor, true ); if ( ! $teacher ) throw new \InvalidArgumentException( 'Linked teacher principal required' );
            $from = (string) ( $teacher->onboarding_state ?: 'linked_pending' ); if ( ! in_array( $from, self::RESUBMITTABLE, true ) ) throw new \InvalidArgumentException( 'Teacher onboarding cannot be submitted' );
            $progress = $this->progress( $teacher ); if ( ! $progress['ready_for_review'] ) throw new \InvalidArgumentException( 'Teacher onboarding is incomplete' );
            $this->repo->saveState( (int) $teacher->id, array(
                'state' => 'pending_review', 'readiness_state' => 'not_ready', 'profile_state' => $progress['profile_state'],
                'availability_state' => $progress['availability_state'], 'agreement_state' => 'not_required', 'submitted_at' => $now,
                'reviewed_at' => null, 'reviewed_by' => null, 'review_reason_code' => null, 'activated_at' => null,
            ), $now, $actor );
            $this->repo->appendEvent( (int) $teacher->id, $from, 'pending_review', null, $actor, $now ); $this->repo->commit();
        } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    public function review(int $teacherId, string $decision, ?string $reason = null): void {
        if ( ! current_user_can( 'dzn_manage_onboarding' ) ) throw new \RuntimeException( 'Unauthorized' );
        $decision = Normalizer::one( $decision, array( 'approve', 'return', 'reject' ), 'onboarding review decision' );
        $reason = Normalizer::text( $reason, 191, $decision !== 'approve' ); $actor = (int) get_current_user_id(); $now = current_time( 'mysql', true );
        $this->repo->begin();
        try {
            $teacher = $this->repo->teacherForUpdate( Normalizer::id( $teacherId ) ); if ( ! $teacher || $teacher->archived_at !== null ) throw new \InvalidArgumentException( 'Teacher not found' );
            $state = $this->repo->stateForUpdate( (int) $teacher->id ); if ( ! $state || $state->state !== 'pending_review' ) throw new \InvalidArgumentException( 'Teacher onboarding is not pending review' );
            $to = $decision === 'approve' ? 'active' : ( $decision === 'return' ? 'returned' : 'rejected' );
            $readiness = 'not_ready'; $activated = null; $progress = $this->progress( $teacher );
            if ( $decision === 'approve' ) {
                if ( $teacher->status !== 'active' || ! $progress['ready_for_review'] ) throw new \InvalidArgumentException( 'Teacher is not ready for activation' );
                $readiness = 'ready'; $activated = $now;
            }
            $this->repo->saveState( (int) $teacher->id, array(
                'state' => $to, 'readiness_state' => $readiness, 'profile_state' => $progress['profile_state'],
                'availability_state' => $progress['availability_state'], 'agreement_state' => 'not_required',
                'reviewed_at' => $now, 'reviewed_by' => $actor, 'review_reason_code' => $reason, 'activated_at' => $activated,
            ), $now, $actor );
            $this->repo->appendEvent( (int) $teacher->id, 'pending_review', $to, $reason, $actor, $now ); $this->repo->commit();
        } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    public function reviewRows(): array { if ( ! current_user_can( 'dzn_manage_onboarding' ) ) throw new \RuntimeException( 'Unauthorized' ); return $this->repo->reviewRows(); }

    private function refreshProgress(object $teacher, string $from, string $now, int $actor): void {
        $progress = $this->progress( $teacher ); $to = $from === 'linked_pending' ? 'in_progress' : $from;
        $this->repo->saveState( (int) $teacher->id, array(
            'state' => $to, 'readiness_state' => 'not_ready', 'profile_state' => $progress['profile_state'],
            'availability_state' => $progress['availability_state'], 'agreement_state' => 'not_required',
        ), $now, $actor );
        if ( $to !== $from ) $this->repo->appendEvent( (int) $teacher->id, $from, $to, null, $actor, $now );
    }

    private function progress(object $teacher): array {
        $profile = trim( (string) $teacher->display_name ) !== '' && is_email( (string) $teacher->email )
            && (bool) preg_match( '/^[A-Z]{2}$/', (string) $teacher->country_code )
            && in_array( (string) $teacher->timezone, timezone_identifiers_list(), true )
            && (bool) Normalizer::locale( $teacher->locale )
            && in_array( (string) $teacher->calendar_preference, array( 'auto', 'gregorian', 'persian' ), true );
        $availability = $profile && $this->repo->hasUsableAvailability( (int) $teacher->id, (string) $teacher->timezone );
        return array( 'profile_state' => $profile ? 'complete' : 'incomplete', 'availability_state' => $availability ? 'complete' : 'incomplete', 'ready_for_review' => $profile && $availability );
    }

    private function profileArray(?object $profile): ?array {
        return $profile ? array( 'timezone' => (string) $profile->timezone, 'status' => (string) $profile->status ) : null;
    }
}
