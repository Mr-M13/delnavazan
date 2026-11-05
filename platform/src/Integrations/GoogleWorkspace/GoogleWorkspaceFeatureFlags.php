<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/**
 * Effective Google Meet feature flags and their configuration source.
 *
 * The runtime services and the production diagnostic share this resolver so a diagnostic can
 * never disagree with the worker about whether provisioning or automatic discovery is enabled.
 * It performs no I/O beyond reading the explicit constant, environment variable or application
 * option; it never prints or returns raw environment values.
 */
final class GoogleWorkspaceFeatureFlags {
    public const CLASSROOM_ENABLED='DZN_MEET_CLASSROOM_ENABLED';
    public const AUTO_RECONCILE='DZN_MEET_AUTO_RECONCILE';
    public const AUTO_RECONCILE_OPTION='dzn_meet_auto_reconcile';

    /** New provisioning is allowed unless explicitly paused. */
    public static function classroomEnabled():bool {
        return self::resolve(self::CLASSROOM_ENABLED, true, false, null)['effective'];
    }

    /** Missing-row reconciliation is opt-in and remains disabled by default. */
    public static function autoReconcile():bool {
        return self::resolve(self::AUTO_RECONCILE, false, true, self::AUTO_RECONCILE_OPTION)['effective'];
    }

    /**
     * Read-only report for diagnostics and tests.
     *
     * `source` is one of `constant`, `environment`, `application_option` or `default`.
     * Raw values are intentionally omitted.
     *
     * @return array{classroom_enabled:array{effective:bool,source:string},auto_reconcile:array{effective:bool,source:string}}
     */
    public static function report():array {
        return array(
            'classroom_enabled'=>self::resolve(self::CLASSROOM_ENABLED, true, false, null),
            'auto_reconcile'=>self::resolve(self::AUTO_RECONCILE, false, true, self::AUTO_RECONCILE_OPTION),
        );
    }

    /** @return array{effective:bool,source:string} */
    private static function resolve(string $name, bool $default, bool $emptyConstantIsFalse, ?string $option):array {
        if (defined($name)) {
            return array(
                'effective'=>self::normalise((string) constant($name), $emptyConstantIsFalse),
                'source'=>'constant',
            );
        }
        $environment=getenv($name);
        if (is_string($environment) && trim($environment)!=='') {
            return array(
                'effective'=>self::normalise($environment, $emptyConstantIsFalse),
                'source'=>'environment',
            );
        }
        if ($option!==null && function_exists('get_option')) {
            $value=get_option($option, null);
            if ($value!==null) {
                return array(
                    // The historical option contract is exact: only the literal string "1" enables.
                    'effective'=>(string) $value==='1',
                    'source'=>'application_option',
                );
            }
        }
        return array('effective'=>$default, 'source'=>'default');
    }

    private static function normalise(string $value, bool $emptyIsFalse):bool {
        $value=strtolower(trim($value));
        if ($emptyIsFalse && $value==='') return false;
        return !in_array($value, array('0','false','off','no'), true);
    }
}
