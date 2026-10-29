<?php
/** Composite navigation capabilities delegate to existing narrow authorities only. */
final class DznCompositeCapTestUser {
    public function __construct(private array $caps) {}
    public function has_cap(string $cap): bool { return in_array($cap, $this->caps, true); }
}
function get_userdata(int $userId): object|false {
    return new DznCompositeCapTestUser($GLOBALS['dzn_composite_caps'] ?? array());
}

require_once dirname(__DIR__) . '/src/Admin/Controller/Menu.php';

use Delnavazan\Platform\Admin\Controller\Menu;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$map = static function (array $caps, string $capability): array {
    $GLOBALS['dzn_composite_caps'] = $caps;
    return Menu::mapMetaCap(array($capability), $capability, 7, array());
};

$assert($map(array('manage_options'), Menu::ENROLMENT_WORKSPACE_CAP) === array('manage_options'), 'Site administrators keep manage_options through the workspace capability.');
$assert($map(array('dzn_manage_terms'), Menu::ENROLMENT_WORKSPACE_CAP) === array('dzn_manage_terms'), 'Terms-only operators must reach the Enrolment workspace.');
$assert($map(array('dzn_manage_enrolments'), Menu::ENROLMENT_WORKSPACE_CAP) === array('dzn_manage_enrolments'), 'Enrolment operators must reach the Enrolment workspace.');
$assert($map(array('dzn_manage_exceptions'), Menu::CATALOGUE_WORKSPACE_CAP) === array('dzn_manage_exceptions'), 'Exception-only operators must reach the catalogue workspace.');
$assert($map(array('dzn_manage_commercial_catalogue'), Menu::CATALOGUE_WORKSPACE_CAP) === array('dzn_manage_commercial_catalogue'), 'Commercial catalogue operators must reach the catalogue workspace.');
$assert($map(array('dzn_manage_post_intro_automation'), Menu::ATTENDANCE_WORKSPACE_CAP) === array('dzn_manage_post_intro_automation'), 'Post-intro operators must reach the attendance workspace.');
$assert($map(array('dzn_manage_onboarding'), Menu::TEACHER_ONBOARDING_CAP) === array('dzn_manage_onboarding'), 'Onboarding operators must reach the onboarding tab.');
$assert($map(array('dzn_revoke_teacher_invitations'), Menu::TEACHER_ONBOARDING_CAP) === array('dzn_revoke_teacher_invitations'), 'Invitation operators must reach the onboarding tab.');
$assert($map(array(), Menu::ENROLMENT_WORKSPACE_CAP) === array('do_not_allow'), 'An actor with no delegated capability must be denied.');
$assert($map(array('unrelated_capability'), Menu::CATALOGUE_WORKSPACE_CAP) === array('do_not_allow'), 'Unrelated capabilities must not open a workspace.');
$assert($map(array('dzn_manage_teachers'), Menu::TEACHER_OPERATIONS_CAP) === array('dzn_manage_teachers'), 'The existing teacher operations mapping must remain intact.');
$assert(Menu::mapMetaCap(array('untouched'), 'unrelated_capability', 7, array()) === array('untouched'), 'Unrelated capabilities must pass through unchanged.');

echo "Admin navigation composite capability unit passed\n";
