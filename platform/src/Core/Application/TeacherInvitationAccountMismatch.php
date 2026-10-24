<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Safe presentation signal for an authenticated claim made by the wrong
 * WordPress account. It intentionally carries no invited email address.
 */
final class TeacherInvitationAccountMismatch extends \InvalidArgumentException {}
