<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherOnboardingRepository;

/**
 * Read-only onboarding workspace projection.
 *
 * Reports progress, blockers and evidence availability without exposing OAuth
 * credentials, provider references, calendar/meeting identifiers or invitation
 * secrets. A Google account connection is deliberately not treated as proof that
 * Google Meet works.
 */
final class TeacherOnboardingWorkspaceReadService {
    private TeacherOnboardingRepository $repository;

    public function __construct(?TeacherOnboardingRepository $repository = null) {
        $this->repository = $repository ?? new TeacherOnboardingRepository();
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(int $limit = 100): array {
        if (!current_user_can('dzn_manage_onboarding')) throw new \RuntimeException('Unauthorized');
        $service = new TeacherOnboardingService($this->repository);
        $rows = array();
        foreach ($this->repository->workspaceRows($limit) as $row) {
            $row->id = (int) $row->teacher_id;
            $row->onboarding_state = $row->state ?? null;
            $progress = $service->progressForAdministrator($row);
            $google = $this->repository->googleConnection((int) $row->teacher_id);
            $projections = $this->repository->projectionReadiness((int) $row->teacher_id);
            $profileIssues = $this->profileIssues($row);
            $blockers = $this->blockers($row, $progress, $profileIssues);
            $rows[] = array(
                'teacher' => $row,
                'progress' => $progress,
                'profile_issues' => $profileIssues,
                'blockers' => $blockers,
                'next_action' => $this->nextAction($row, $progress, $profileIssues, $blockers),
                'google' => $this->googleState($google, $progress),
                'calendar' => $this->projectionLabel($projections['calendar'], 'calendar event'),
                'meeting' => $this->projectionLabel($projections['meeting'], 'meeting'),
            );
        }
        return $rows;
    }

    /** @return array<int, string> */
    private function profileIssues(object $row): array {
        $issues = array();
        if (trim((string) ($row->display_name ?? '')) === '') $issues[] = 'Display name is missing.';
        if (!is_email((string) ($row->email ?? ''))) $issues[] = 'A valid email address is missing.';
        if (!preg_match('/^[A-Z]{2}$/D', (string) ($row->country_code ?? ''))) $issues[] = 'Country code is missing or invalid.';
        if (!in_array((string) ($row->timezone ?? ''), timezone_identifiers_list(), true)) $issues[] = 'A valid IANA timezone is missing.';
        if (!Normalizer::locale($row->locale ?? null)) $issues[] = 'Language/locale is missing.';
        if (!in_array((string) ($row->calendar_preference ?? ''), array('auto', 'gregorian', 'persian'), true)) $issues[] = 'Calendar preference is missing.';
        return $issues;
    }

    /** @return array<int, string> */
    private function blockers(object $row, array $progress, array $profileIssues): array {
        if ((string) ($row->principal_status ?? '') !== 'active') {
            return array('Teacher account is not linked.');
        }
        $state = (string) ($row->state ?? '');
        if ($state === 'rejected') return array('Onboarding was rejected; a new submission or invitation is required.');
        if ($state === 'offboarded') return array('Teacher access is offboarded; restore the profile and issue a new invitation.');
        $blockers = array();
        foreach ($profileIssues as $issue) $blockers[] = $issue;
        if ((string) $progress['availability_state'] !== 'complete') $blockers[] = 'Weekly availability is incomplete.';
        // No Teacher Google connection is required: Delnavazan owns the Workspace organizer.
        return $blockers;
    }

    private function nextAction(object $row, array $progress, array $profileIssues, array $blockers): string {
        $state = (string) ($row->state ?? '');
        if ((string) ($row->principal_status ?? '') !== 'active') {
            $generation = (string) ($row->generation_status ?? '');
            $expiresAt = (string) ($row->generation_expires_at ?? '');
            if (in_array($generation, array('queued_for_delivery', 'active'), true) && $expiresAt > gmdate('Y-m-d H:i:s')) {
                return 'Invitation is active; await delivery and account claim.';
            }
            if (in_array($generation, array('failed', 'expired', 'revoked', 'superseded'), true)) {
                return 'Issue a replacement invitation or link a verified existing account.';
            }
            return 'Send an invitation or link a verified existing account.';
        }
        if ($state === 'pending_review') {
            return $blockers ? 'Resolve the blockers before approval; the Teacher may need to resubmit.' : 'Review and decide on the submitted onboarding.';
        }
        if ($state === 'rejected') return 'Offboard or relink the account, then issue a new invitation or correct the submission.';
        if ($state === 'offboarded') return 'Restore the Teacher profile, then issue a new invitation.';
        if ($state === 'active' && ((string) ($row->readiness_state ?? '') === 'ready' || !empty($progress['ready_for_activation']))) return 'No onboarding action required.';
        if ($profileIssues) return 'Teacher to complete the missing profile fields.';
        if ((string) $progress['availability_state'] !== 'complete') return 'Teacher to complete weekly availability.';
        // Classroom access is provisioned centrally by Delnavazan.
        return 'Await the Teacher submission.';
    }

    /** @return array<string, mixed> */
    private function googleState(?object $google, array $progress): array {
        // A legacy Teacher grant may still exist for historical events. It is reported for the
        // administrator but is no longer requested, required or presented as a readiness blocker.
        return array(
            'connection_state' => (string) ($google->connection_state ?? 'managed_centrally'),
            'identity_state' => (string) ($google->identity_state ?? 'managed_centrally'),
            'label' => 'Classroom managed centrally by Delnavazan',
            'scope_verified' => true,
            'legacy_teacher_grant' => $google !== null,
            'meet_caveat' => 'Teachers join as external co-hosts; they are not asked to connect Google.',
            'ready_for_activation' => true,
        );
    }

    /** @param array{total:int,verified:int,pending:int,last_at:?string} $projection */
    private function projectionLabel(array $projection, string $kind): array {
        if ((int) $projection['verified'] > 0) {
            $label = (int) $projection['verified'] . ' verified ' . $kind . ' projection' . ((int) $projection['verified'] === 1 ? '' : 's') . ' recorded';
        } elseif ((int) $projection['pending'] > 0) {
            $label = (int) $projection['pending'] . ' ' . $kind . ' projection' . ((int) $projection['pending'] === 1 ? '' : 's') . ' awaiting acknowledgement';
        } else {
            $label = 'No verified ' . $kind . ' evidence yet';
        }
        return array('total' => (int) $projection['total'], 'verified' => (int) $projection['verified'], 'pending' => (int) $projection['pending'], 'last_at' => $projection['last_at'], 'label' => $label);
    }
}
