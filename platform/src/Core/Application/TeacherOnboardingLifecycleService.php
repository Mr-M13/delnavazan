<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherRepository;

/**
 * Controlled revoke / reset lifecycle for unsuccessful Teacher onboarding.
 *
 * The operation is deliberately conservative: an invitation-only record may be
 * removed, an account created by onboarding may be reset only when it has no
 * protected academy history, and anything with teaching or finance history must
 * use the existing Teacher archive authority. Historical audit evidence and the
 * anonymised invitation record are retained even when the active Teacher row is
 * removed.
 */
final class TeacherOnboardingLifecycleService {
    private const MANAGE_TEACHERS = 'dzn_manage_teachers';
    private const MANAGE_ONBOARDING = 'dzn_manage_onboarding';
    private const REVOKE_INVITATIONS = 'dzn_revoke_teacher_invitations';

    public function __construct(
        private ?PrincipalInvitationRepository $repo = null,
        private ?TeacherRepository $teachers = null
    ) {
        $this->repo ??= new PrincipalInvitationRepository();
        $this->teachers ??= new TeacherRepository();
    }

    /** @return array{teacher_id:int,removed:bool,reason:string} */
    public function revokeAndRemove(int $teacherId, string $reason = 'admin_revoked'): array {
        $this->authorizeBoth(self::MANAGE_TEACHERS, self::REVOKE_INVITATIONS);
        $teacherId = Normalizer::id($teacherId);
        $reason = $this->reason($reason);
        $actor = get_current_user_id() ?: null;
        $now = current_time('mysql', true);
        $this->repo->begin();
        try {
            $teacher = $this->repo->teacherForUpdate($teacherId);
            if (!$teacher) { $this->repo->commit(); return array('teacher_id' => $teacherId, 'removed' => true, 'reason' => $reason); }
            if ((string) $teacher->status === 'archived' || $teacher->archived_at !== null) throw new \InvalidArgumentException('Archived Teacher records use restore, not invitation cleanup');
            $link = $this->repo->activeTeacherLinkForTeacher($teacherId);
            if ($link || (int) $teacher->wordpress_user_id > 0) throw new \InvalidArgumentException('teacher_account_exists');
            $history = $this->protectedHistory($teacherId);
            if ($history !== array()) throw new \InvalidArgumentException('teacher_history_exists');
            $this->revokeInvitationWithinTransaction($teacherId, $now, $actor, $reason);
            $this->deleteOnboardingFacts($teacherId, false);
            $deleted = $this->deleteTeacherRow($teacherId);
            $this->repo->audit('teacher', $teacherId, 'teacher_invitation.removed', $actor, hash('sha256', 'teacher-remove:' . $teacherId . ':' . $now . ':' . wp_generate_uuid4()), 'reason=' . $reason . ';deleted=' . $deleted);
            $this->repo->commit();
            return array('teacher_id' => $teacherId, 'removed' => $deleted, 'reason' => $reason);
        } catch (\Throwable $error) {
            $this->repo->rollback();
            throw $error;
        }
    }

    /** @return array{teacher_id:int,deleted_user:bool,linked_user_id:int,reason:string} */
    public function resetOnboarding(int $teacherId, string $reason = 'admin_reset'): array {
        $this->authorizeBoth(self::MANAGE_ONBOARDING, self::MANAGE_TEACHERS);
        $teacherId = Normalizer::id($teacherId);
        $reason = $this->reason($reason);
        $actor = get_current_user_id() ?: null;
        $now = current_time('mysql', true);
        $userId = 0;
        $this->repo->begin();
        try {
            $teacher = $this->repo->teacherForUpdate($teacherId);
            if (!$teacher) throw new \InvalidArgumentException('Teacher not found');
            if ((string) $teacher->status === 'archived' || $teacher->archived_at !== null) throw new \InvalidArgumentException('Archived Teacher records use restore, not onboarding reset');
            $history = $this->protectedHistory($teacherId);
            if ($history !== array()) throw new \InvalidArgumentException('teacher_history_exists');
            $this->revokeInvitationWithinTransaction($teacherId, $now, $actor, $reason);
            $link = $this->repo->activeTeacherLinkForTeacher($teacherId);
            $userId = $link ? (int) $link->wordpress_user_id : (int) ($teacher->wordpress_user_id ?? 0);
            if ($link) {
                $this->repo->update('teacher_principal_links', array(
                    'status' => 'revoked', 'revoked_at' => $now, 'revoked_by' => $actor, 'reason_code' => $reason,
                ), array('id' => (int) $link->id, 'status' => 'active'));
            }
            $this->repo->update('teachers', array('wordpress_user_id' => null, 'updated_at' => $now, 'updated_by' => $actor), array('id' => $teacherId));
            $this->deleteOnboardingFacts($teacherId, true);
            $this->clearTemporaryUnavailability($teacherId, $now, $actor);
            $this->repo->audit('teacher', $teacherId, 'teacher_onboarding.reset', $actor, hash('sha256', 'teacher-reset:' . $teacherId . ':' . $now . ':' . wp_generate_uuid4()), 'reason=' . $reason . ';linked_user=' . $userId);
            $this->repo->commit();
        } catch (\Throwable $error) {
            $this->repo->rollback();
            throw $error;
        }
        $deleted = false;
        if ($userId > 0) {
            $deleted = $this->removeTeacherSpecificAccess($userId);
            $this->repo->audit('teacher', $teacherId, $deleted ? 'teacher_onboarding.exclusive_user_deleted' : 'teacher_onboarding.access_unlinked', $actor, hash('sha256', 'teacher-reset-user:' . $teacherId . ':' . $userId . ':' . $now), $deleted ? 'exclusive_onboarding_account' : 'account_preserved_other_roles_or_content');
        }
        return array('teacher_id' => $teacherId, 'deleted_user' => $deleted, 'linked_user_id' => $userId, 'reason' => $reason);
    }

    /** @return list<string> Dependency labels that require Archive instead of reset/removal. */
    public function protectedHistory(int $teacherId): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $checks = array(
            'enrolments' => "SELECT COUNT(*) FROM {$p}enrolments WHERE teacher_id=%d",
            'teacher assignments' => "SELECT COUNT(*) FROM {$p}teacher_assignments WHERE teacher_id=%d",
            'lessons' => "SELECT COUNT(*) FROM {$p}lessons WHERE teacher_id=%d",
            'attendance cases' => "SELECT COUNT(*) FROM {$p}canonical_attendance_cases WHERE teacher_id=%d",
            'intro outcomes' => "SELECT COUNT(*) FROM {$p}booking_request_intro_outcomes WHERE teacher_id=%d",
            'confirmed bookings' => "SELECT COUNT(*) FROM {$p}booking_request_confirmations WHERE teacher_id=%d",
            'finance statements' => "SELECT COUNT(*) FROM {$p}finance_statements WHERE teacher_id=%d",
            'finance lesson snapshots' => "SELECT COUNT(*) FROM {$p}finance_lesson_snapshots WHERE teacher_id=%d",
            'finance rates' => "SELECT COUNT(*) FROM {$p}finance_teacher_rates WHERE teacher_id=%d",
            'finance exceptions' => "SELECT COUNT(*) FROM {$p}finance_exceptions WHERE teacher_id=%d",
        );
        $found = array();
        foreach ($checks as $label => $sql) {
            $count = (int) $wpdb->get_var($wpdb->prepare($sql, $teacherId));
            if ($count > 0) $found[] = $label;
        }
        return $found;
    }

    private function revokeInvitationWithinTransaction(int $teacherId, string $now, ?int $actor, string $reason): void {
        $invitation = $this->repo->invitationForTeacher($teacherId);
        if (!$invitation) return;
        $this->repo->revokeUnclaimedGenerations((int) $invitation->id, $now);
        $this->repo->cancelPendingDeliveryOutboxes((int) $invitation->id, $now);
        $this->repo->update('teacher_invitations', array(
            'status' => 'revoked', 'version' => (int) $invitation->version + 1, 'updated_at' => $now, 'updated_by' => $actor,
        ), array('id' => (int) $invitation->id));
        if (!$this->repo->hasNonterminalGeneration((int) $invitation->id)) {
            $this->repo->anonymizeTerminalRecipientSnapshots((int) $invitation->id);
            $this->repo->anonymizeInvitationRecipientDigest((int) $invitation->id);
        }
        $this->repo->audit('teacher_invitation', (int) $invitation->id, 'teacher_invitation.revoked_for_cleanup', $actor, hash('sha256', 'invitation-cleanup:' . $teacherId . ':' . $now), $reason);
    }

    /** Remove onboarding/availability facts that belong to a rejected attempt, never historical leadgers. */
    private function deleteOnboardingFacts(int $teacherId, bool $keepAvailabilityHistory): void {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $wpdb->delete($p . 'teacher_onboarding_states', array('teacher_id' => $teacherId));
        if (!$keepAvailabilityHistory) {
            $wpdb->delete($p . 'teacher_availability_exceptions', array('teacher_id' => $teacherId));
            $wpdb->delete($p . 'teacher_availability_rules', array('teacher_id' => $teacherId));
            $wpdb->delete($p . 'teacher_availability_profiles', array('teacher_id' => $teacherId));
            $wpdb->delete($p . 'teacher_accepting_states', array('teacher_id' => $teacherId));
            $wpdb->delete($p . 'teacher_course_eligibilities', array('teacher_id' => $teacherId));
        }
    }

    private function clearTemporaryUnavailability(int $teacherId, string $now, ?int $actor): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dzn_teacher_availability_exceptions';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status='inactive',reason_code='onboarding_reset',version=version+1,updated_at=%s,updated_by=%d WHERE teacher_id=%d AND status='active' AND reason_code='temporary_unavailability'",
            $now, (int) $actor, $teacherId
        ));
    }

    private function deleteTeacherRow(int $teacherId): bool {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $wpdb->delete($p . 'teacher_profile_links', array('teacher_id' => $teacherId));
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$p}teachers WHERE id=%d AND archived_at IS NULL", $teacherId));
        if ($deleted === false) throw new \RuntimeException('Teacher removal failed.');
        return $deleted === 1;
    }

    /** Remove teacher access only when the account is demonstrably exclusive to onboarding. */
    private function removeTeacherSpecificAccess(int $userId): bool {
        $user = get_userdata($userId);
        if (!$user) return false;
        if ($this->isExclusiveOnboardingAccount($userId, $user)) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            return (bool) wp_delete_user($userId);
        }
        $user->remove_role('dzn_teacher');
        return false;
    }

    private function isExclusiveOnboardingAccount(int $userId, object $user): bool {
        $roles = array_values(array_diff((array) $user->roles, array('dzn_teacher', 'subscriber')));
        if ($roles !== array()) return false;
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author=%d AND post_status<>'auto-draft'", $userId)) > 0) return false;
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_principal_links WHERE wordpress_user_id=%d AND status='active'", $userId)) > 0) return false;
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_principal_links WHERE wordpress_user_id=%d AND status='active' AND teacher_id<>0", $userId)) > 0) return false;
        if (user_can($userId, 'manage_options')) return false;
        return true;
    }

    private function authorizeBoth(string $first, string $second): void {
        if (!current_user_can($first) || !current_user_can($second)) throw new \RuntimeException('Unauthorized');
    }

    private function reason(string $reason): string {
        $reason = sanitize_key($reason);
        return $reason !== '' ? substr($reason, 0, 64) : 'operator_decision';
    }
}
