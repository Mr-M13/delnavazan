<?php
namespace Delnavazan\Platform\Core\Application;

/** Narrow capabilities used by the coordination follow-through controls. */
final class CoordinationCapabilities {
    public const ASSIGNMENT = 'dzn_manage_teacher_assignments';
    public const CONVERSION = 'dzn_convert_service_arrangements_to_enrolments';
    public const CLAIM = 'dzn_issue_student_account_claims';
    public const NOTIFICATION_RETRY = 'dzn_operate_notification_dispatch';
    public const PAYMENT = 'dzn_manage_payment_execution';
}
