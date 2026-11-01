<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Pure input contract for the Students administration workspace.
 *
 * Kept free of WordPress and database access so the directory semantics
 * (bounded paging, canonical filter names, deterministic sort) can be tested
 * without a runtime.
 */
final class StudentWorkspaceFilters {
    public const STATUSES = array(
        'all',
        'active',
        'introductory',
        'awaiting_enrolment',
        'renewal_due',
        'payment_due',
        'inactive',
        'archived',
    );

    public const SORTS = array(
        'recent',
        'name',
        'enrolment_date',
        'last_lesson',
        'next_lesson',
        'renewal_date',
        'payment_due_date',
        'status',
    );

    public const DEFAULT_STATUS = 'active';
    public const DEFAULT_SORT = 'recent';
    public const DEFAULT_DIRECTION = 'desc';
    public const PER_PAGE = 20;

    /** @return array{query:string,status:string,page:int,sort:string,direction:string,per_page:int} */
    public static function normalize(array $input): array {
        $rawQuery = $input['q'] ?? ($input['s'] ?? '');
        $query = is_scalar($rawQuery) ? trim((string) $rawQuery) : '';
        if (function_exists('sanitize_text_field')) $query = sanitize_text_field($query);
        if (strlen($query) > 191) $query = substr($query, 0, 191);

        $status = isset($input['student_status']) && is_scalar($input['student_status'])
            ? strtolower(trim((string) $input['student_status']))
            : self::DEFAULT_STATUS;
        if (!in_array($status, self::STATUSES, true)) $status = self::DEFAULT_STATUS;

        $sort = isset($input['student_sort']) && is_scalar($input['student_sort'])
            ? strtolower(trim((string) $input['student_sort']))
            : self::DEFAULT_SORT;
        if (!in_array($sort, self::SORTS, true)) $sort = self::DEFAULT_SORT;

        $direction = isset($input['student_order']) && is_scalar($input['student_order'])
            ? strtolower(trim((string) $input['student_order']))
            : self::defaultDirection($sort);
        if (!in_array($direction, array('asc', 'desc'), true)) $direction = self::defaultDirection($sort);

        $rawPage = $input['student_page'] ?? 1;
        $page = function_exists('absint') ? absint($rawPage) : max(0, (int) $rawPage);
        if ($page < 1) $page = 1;

        return array(
            'query' => $query,
            'status' => $status,
            'page' => $page,
            'sort' => $sort,
            'direction' => $direction,
            'per_page' => self::PER_PAGE,
        );
    }

    public static function defaultDirection(string $sort): string {
        return $sort === 'name' || $sort === 'enrolment_date' || $sort === 'renewal_date' || $sort === 'payment_due_date'
            ? 'asc'
            : 'desc';
    }

    /** Digits-only comparison key used for practical phone/WhatsApp search. */
    public static function phoneDigits(string $value): string {
        return preg_replace('/\D+/', '', $value) ?: '';
    }

    /** A phone-shaped query is safe to try against normalized stored numbers. */
    public static function isPhoneLike(string $query): bool {
        return self::phoneDigits($query) !== '' && preg_match('/^[0-9+() .\-]+$/D', trim($query)) === 1;
    }

    /** @return array<string,string> */
    public static function statusLabels(): array {
        return array(
            'all' => 'All students',
            'active' => 'Active',
            'introductory' => 'Introductory',
            'awaiting_enrolment' => 'Awaiting enrolment',
            'renewal_due' => 'Renewal due',
            'payment_due' => 'Payment due',
            'inactive' => 'Inactive',
            'archived' => 'Archived',
        );
    }

    /** @return array<string,string> */
    public static function sortLabels(): array {
        return array(
            'recent' => 'Recent activity',
            'name' => 'Name',
            'enrolment_date' => 'Enrolment date',
            'last_lesson' => 'Last lesson',
            'next_lesson' => 'Next lesson',
            'renewal_date' => 'Renewal date',
            'payment_due_date' => 'Payment due date',
            'status' => 'Student status',
        );
    }

    /** Plain-English operational state, derived only from canonical fields. */
    public static function directoryStatus(string $status, bool $archived, bool $introActive, bool $activeEnrolment): string {
        if ($archived || $status === 'archived') return 'Archived';
        if ($status === 'inactive') return 'Inactive';
        if ($introActive && !$activeEnrolment) return 'Introductory';
        if ($status === 'active') return $activeEnrolment ? 'Active' : 'Awaiting enrolment';
        return $status === '' ? 'Not recorded' : ucfirst(str_replace('_', ' ', $status));
    }
}
