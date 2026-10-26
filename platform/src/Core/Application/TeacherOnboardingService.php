<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherOnboardingRepository;

/** Invitation-led lifecycle with explicit Teacher submission and staff review. */
final class TeacherOnboardingService {
    private const EDITABLE = array( 'linked_pending', 'in_progress', 'returned', 'active' );
    private const COMPLETABLE = array( 'linked_pending', 'in_progress', 'returned' );

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
            'google_state' => $progress['google_state'],
            'agreement_state' => (string) ( $teacher->agreement_state ?: 'not_required' ),
            'submitted_at' => $teacher->submitted_at,
            'reviewed_at' => $teacher->reviewed_at,
            'review_reason_code' => $teacher->review_reason_code,
            'can_edit' => in_array( $state, self::EDITABLE, true ),
            'can_submit' => in_array( $state, self::COMPLETABLE, true ) && $progress['ready_for_activation'],
            'ready_for_activation' => $progress['ready_for_activation'],
            'profile' => array(
                'display_name' => (string) $teacher->display_name, 'persian_name' => (string) ( $teacher->persian_name ?? '' ), 'english_name' => (string) ( $teacher->english_name ?? '' ),
                'email' => (string) ( $teacher->email ?? '' ), 'phone' => (string) ( $teacher->phone ?? '' ), 'whatsapp_phone' => (string) ( $teacher->whatsapp_phone ?? '' ),
                'country_code' => (string) ( $teacher->country_code ?? '' ), 'city' => (string) ( $teacher->city ?? '' ),
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
            $data = $this->validatedProfile( $input );
            $principal = get_userdata( $actor );
            if ( ! $principal || ! hash_equals( strtolower( (string) $principal->user_email ), strtolower( (string) $data['email'] ) ) ) throw new \InvalidArgumentException( 'Teacher email must match the linked account' );
            $availability = $this->repo->availabilityProfile( (int) $teacher->id );
            if ( $availability && (string) $availability->timezone !== $data['timezone'] && $this->repo->availabilityHasFacts( (int) $availability->id ) ) throw new \InvalidArgumentException( 'Timezone cannot change while availability facts exist' );
            $this->repo->updateTeacherProfile( (int) $teacher->id, $data, $now, $actor );
            $fresh = $this->repo->teacherForUpdate( (int) $teacher->id ); $this->refreshProgress( $fresh, $state, $now, $actor );
            $this->repo->commit();
        } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    /** Capability-gated administrator maintenance of mutable Teacher profile facts. */
    public function updateByAdministrator(int $teacherId, array $input): void {
        if ( ! current_user_can( 'dzn_manage_teachers' ) ) throw new \RuntimeException( 'Unauthorized' );
        $now = current_time( 'mysql', true ); $actor = (int) get_current_user_id(); $this->repo->begin();
        try {
            $teacher = $this->repo->teacherForUpdate( Normalizer::id( $teacherId ) );
            if ( ! $teacher || $teacher->archived_at !== null || $teacher->status === 'archived' ) throw new \InvalidArgumentException( 'Teacher not found' );
            $data = $this->validatedProfile( $input );
            $data['phone'] = Normalizer::phone( $input['phone'] ?? null );
            $data['whatsapp_phone'] = Normalizer::phone( $input['whatsapp_phone'] ?? null );
            $availability = $this->repo->availabilityProfile( $teacherId );
            if ( $availability && (string) $availability->timezone !== $data['timezone'] && $this->repo->availabilityHasFacts( (int) $availability->id ) ) throw new \InvalidArgumentException( 'Timezone cannot change while availability facts exist' );
            $this->repo->updateTeacherProfile( $teacherId, $data, $now, $actor );
            $this->repo->commit();
        } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    private function validatedProfile(array $input): array {
        $data = array(
            'display_name' => Normalizer::text( $input['display_name'] ?? '', 191, true ),
            'persian_name' => Normalizer::text( $input['persian_name'] ?? null ),
            'english_name' => Normalizer::text( $input['english_name'] ?? null ),
            'email' => Normalizer::email( $input['email'] ?? null ),
            'phone' => Normalizer::phone( $input['phone'] ?? null ),
            'whatsapp_phone' => Normalizer::phone( $input['whatsapp_phone'] ?? null ),
            'country_code' => Normalizer::country( $input['country_code'] ?? null ),
            'city' => Normalizer::text( $input['city'] ?? null ),
            'timezone' => Normalizer::timezone( $input['timezone'] ?? null ),
            'locale' => Normalizer::locale( $input['locale'] ?? null ),
            'calendar_preference' => Normalizer::one( $input['calendar_preference'] ?? '', array( 'auto', 'gregorian', 'persian' ), 'calendar' ),
        );
        if ( ! $data['email'] || ! $data['country_code'] || ! $data['timezone'] || ! $data['locale'] ) throw new \InvalidArgumentException( 'Required teacher profile fields are missing' );
        return $data;
    }

    /** Recalculate stored progress after the canonical availability authority accepts a Teacher mutation. */
    public function refreshOwnProgress(): void {
        $actor = (int) get_current_user_id(); if ( $actor < 1 ) throw new \RuntimeException( 'Unauthorized' );
        $now = current_time( 'mysql', true ); $this->repo->begin();
        try {
            $teacher = $this->repo->linkedTeacherForUser( $actor, true ); if ( ! $teacher ) throw new \InvalidArgumentException( 'Linked teacher principal required' );
            // Availability changes can demote an active Teacher back to setup.
            // Never let an internal progress refresh revive an offboarded principal.
            $state = (string) ( $teacher->onboarding_state ?: 'linked_pending' ); if ( $state === 'offboarded' ) throw new \InvalidArgumentException( 'Teacher onboarding is not editable' );
            $to = $this->refreshProgress( $teacher, $state, $now, $actor ); $this->repo->commit();
        } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
        if ( $to === 'active' && $state !== 'active' ) do_action( 'dzn_teacher_activated', (int) $teacher->id, $actor );
    }

    public function submitOwn(): void {
        $actor = (int) get_current_user_id(); if ( $actor < 1 ) throw new \RuntimeException( 'Unauthorized' );
        $now = current_time( 'mysql', true ); $this->repo->begin();
        try {
            $teacher = $this->repo->linkedTeacherForUser( $actor, true ); if ( ! $teacher ) throw new \InvalidArgumentException( 'Linked teacher principal required' );
            $from = (string) ( $teacher->onboarding_state ?: 'linked_pending' ); if ( ! in_array( $from, self::COMPLETABLE, true ) ) throw new \InvalidArgumentException( 'Teacher onboarding cannot be completed' );
            $progress = $this->progress( $teacher ); if ( ! $progress['ready_for_activation'] ) throw new \InvalidArgumentException( 'Teacher onboarding is incomplete' );
            $this->repo->saveState( (int) $teacher->id, array(
                'state' => 'pending_review', 'readiness_state' => 'not_ready', 'profile_state' => $progress['profile_state'],
                'availability_state' => $progress['availability_state'], 'agreement_state' => 'not_required', 'submitted_at' => $now,
                'reviewed_at' => null, 'reviewed_by' => null, 'review_reason_code' => null, 'activated_at' => null,
            ), $now, $actor );
            $this->repo->appendEvent( (int) $teacher->id, $from, 'pending_review', 'teacher_submitted_for_review', $actor, $now ); $this->repo->commit();
        } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    /** Read-only progress for an already-loaded Teacher row in the onboarding workspace. */
    public function progressForAdministrator(object $teacher): array {
        if (!current_user_can('dzn_manage_onboarding')) throw new \RuntimeException('Unauthorized');
        return $this->progress($teacher);
    }

    public function reviewRows(): array { if ( ! current_user_can( 'dzn_manage_onboarding' ) ) throw new \RuntimeException( 'Unauthorized' ); return $this->repo->reviewRows(); }

    public function review(int $teacherId, string $decision, ?string $reason = null): void {
        if ( ! current_user_can( 'dzn_manage_onboarding' ) ) throw new \RuntimeException( 'Unauthorized' );
        if ( ! in_array( $decision, array( 'approve', 'return', 'reject' ), true ) ) throw new \InvalidArgumentException( 'Unsupported onboarding decision' );
        $reason = Normalizer::text( $reason, 64 );
        if ( $decision !== 'approve' && ! $reason ) throw new \InvalidArgumentException( 'A reason is required' );
        $now = current_time( 'mysql', true ); $actor = (int) get_current_user_id(); $this->repo->begin();
        try {
            $teacher = $this->repo->teacherForUpdate( Normalizer::id( $teacherId ) );
            $state = $teacher ? $this->repo->stateForUpdate( (int) $teacher->id ) : null;
            if ( ! $teacher || $teacher->archived_at !== null || ! $state || (string) $state->state !== 'pending_review' ) throw new \InvalidArgumentException( 'Teacher onboarding is not awaiting review' );
            $progress = $this->progress( $teacher );
            if ( $decision === 'approve' && ! $progress['ready_for_activation'] ) throw new \InvalidArgumentException( 'Teacher onboarding is incomplete' );
            $to = $decision === 'approve' ? 'active' : ( $decision === 'return' ? 'returned' : 'rejected' );
            $this->repo->saveState( (int) $teacher->id, array(
                'state' => $to, 'readiness_state' => $decision === 'approve' ? 'ready' : 'not_ready',
                'profile_state' => $progress['profile_state'], 'availability_state' => $progress['availability_state'], 'agreement_state' => 'not_required',
                'reviewed_at' => $now, 'reviewed_by' => $actor, 'review_reason_code' => $reason,
                'activated_at' => $decision === 'approve' ? $now : null,
            ), $now, $actor );
            $this->repo->appendEvent( (int) $teacher->id, 'pending_review', $to, $decision === 'approve' ? 'administrator_approved' : $reason, $actor, $now );
            $this->repo->commit();
        } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
        if ( $decision === 'approve' ) do_action( 'dzn_teacher_activated', (int) $teacher->id, $actor );
    }

    private function refreshProgress(object $teacher, string $from, string $now, int $actor): string {
        $progress = $this->progress( $teacher );
        $staysActive = $from === 'active' && $progress['ready_for_activation'];
        $to = $staysActive ? 'active' : ( in_array($from,array('linked_pending','returned','active'),true) ? 'in_progress' : $from );
        $this->repo->saveState( (int) $teacher->id, array(
            'state' => $to, 'readiness_state' => $staysActive ? 'ready' : 'not_ready', 'profile_state' => $progress['profile_state'],
            'availability_state' => $progress['availability_state'], 'agreement_state' => 'not_required',
            'reviewed_at' => null, 'reviewed_by' => null, 'review_reason_code' => null,
            'activated_at' => $staysActive ? ( $teacher->activated_at ?? $now ) : null,
        ), $now, $actor );
        if ( $to !== $from ) $this->repo->appendEvent( (int) $teacher->id, $from, $to, $progress['ready_for_activation'] ? 'onboarding_requirements_complete' : 'onboarding_requirements_incomplete', $actor, $now );
        return $to;
    }

    private function progress(object $teacher): array {
        $profile = trim( (string) $teacher->display_name ) !== '' && is_email( (string) $teacher->email )
            && (bool) preg_match( '/^[A-Z]{2}$/', (string) $teacher->country_code )
            && in_array( (string) $teacher->timezone, timezone_identifiers_list(), true )
            && (bool) Normalizer::locale( $teacher->locale )
            && in_array( (string) $teacher->calendar_preference, array( 'auto', 'gregorian', 'persian' ), true );
        $availability = $profile && $this->repo->hasUsableAvailability( (int) $teacher->id, (string) $teacher->timezone );
        $google = $this->repo->hasUsableGoogleConnection( (int) $teacher->id );
        return array( 'profile_state' => $profile ? 'complete' : 'incomplete', 'availability_state' => $availability ? 'complete' : 'incomplete', 'google_state' => $google ? 'connected' : 'not_connected', 'ready_for_activation' => $profile && $availability && $google );
    }

    private function profileArray(?object $profile): ?array {
        return $profile ? array( 'timezone' => (string) $profile->timezone, 'status' => (string) $profile->status ) : null;
    }
}
