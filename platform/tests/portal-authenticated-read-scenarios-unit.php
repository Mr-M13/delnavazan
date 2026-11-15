<?php
/** Isolated read-model regression with synthetic principals and no real DB. */
namespace Delnavazan\Platform\Portals {
    final class PortalPrincipalResolver {
        public static int $id = 7;
        public function resolve(string $kind): array {
            return array('id' => self::$id, 'kind' => $kind);
        }
    }
    final class PortalInternalReadSurface {
        public function principal(array $principal): array { return array('kind' => $principal['kind']); }
    }
    final class StudentUpcomingBookingReadService {
        public function forStudent(int $id): array { return array(); }
    }
    final class PostIntroStudentReadService {
        public static string $mode = 'data';
        public function forStudent(int $id): array {
            if ($id !== PortalPrincipalResolver::$id) throw new \RuntimeException('wrong_owner');
            return match (self::$mode) {
                'data' => array(array('reference' => 'OWNED-' . $id, 'actionable' => true)),
                'empty' => array(),
                'db_failure' => throw new \RuntimeException('SQL_PASSWORD_SECRET'),
                'timeout' => throw new \RuntimeException('network_timeout_token_SECRET'),
                default => throw new \RuntimeException('unexpected_mode'),
            };
        }
    }
}
namespace {
    if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
    $events = array();
    function do_action($hook, $payload) { global $events; $events[] = array('hook' => $hook, 'data' => $payload); }
    final class FakePortalDB {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public bool $rescheduleFailure = false;
        public bool $coreFailure = false;
        public int $writes = 0;
        public function prepare($sql, ...$args): string { return vsprintf($sql, $args); }
        public function get_col($sql): array {
            $this->last_error = $this->coreFailure && str_contains($sql, 'FROM wp_dzn_lessons') ? 'Synthetic database failure' : '';
            return array();
        }
        public function get_results($sql, $output = null): array {
            $this->last_error = '';
            if ($this->rescheduleFailure && str_contains($sql, 'lesson_reschedule_proposals')) {
                $this->last_error = 'Synthetic SQL SECRET';
                return array();
            }
            return array();
        }
        public function get_row($sql, $output = null) {
            $this->last_error = '';
            if (str_contains($sql, 'FROM wp_dzn_students')) {
                return array('display_name' => 'Synthetic Student', 'timezone' => 'Australia/Brisbane');
            }
            return null;
        }
        public function get_var($sql) { $this->last_error = ''; return null; }
        public function query($sql) { $this->writes++; throw new \RuntimeException('mutation_in_read'); }
        public function insert($table, $values) { $this->writes++; throw new \RuntimeException('mutation_in_read'); }
        public function update($table, $values, $where) { $this->writes++; throw new \RuntimeException('mutation_in_read'); }
        public function delete($table, $where) { $this->writes++; throw new \RuntimeException('mutation_in_read'); }
    }
    $wpdb = new FakePortalDB();
    require dirname(__DIR__) . '/src/Portals/PortalAuthenticatedReadService.php';
    $assert = static function($condition, string $message): void {
        if (!$condition) throw new \RuntimeException($message);
    };
    $service = new \Delnavazan\Platform\Portals\PortalAuthenticatedReadService();
    $intro = \Delnavazan\Platform\Portals\PostIntroStudentReadService::class;
    $principal = \Delnavazan\Platform\Portals\PortalPrincipalResolver::class;
    $read = static fn() => $service->student();

    $ok = $read();
    $assert(count($ok['post_intro']) === 1 && $ok['post_intro'][0]['reference'] === 'OWNED-7', 'Successful owned data absent');
    $assert($ok['read_health']['post_intro']['state'] === 'ok' && $ok['read_health']['reschedule_proposals']['state'] === 'ok', 'Good reads must be ok');

    $intro::$mode = 'empty';
    $empty = $read();
    $assert($empty['post_intro'] === array() && $empty['read_health']['post_intro']['state'] === 'ok', 'Genuine empty result is not a failure');

    $intro::$mode = 'db_failure';
    $failed = $read();
    $assert($failed['post_intro'] === array() && $failed['read_health']['post_intro']['state'] === 'unavailable', 'Read failure falsely treated as empty');
    $assert(($failed['principal']['kind'] ?? '') === 'student' && is_array($failed['profile']), 'Partial valid data must survive');
    $assert($failed['read_health']['reschedule_proposals']['state'] === 'ok', 'Independent read must remain available');

    $intro::$mode = 'timeout';
    $wpdb->rescheduleFailure = true;
    $both = $read();
    $assert($both['read_health']['post_intro']['state'] === 'unavailable', 'Timeout must be unavailable');
    $assert($both['read_health']['reschedule_proposals']['state'] === 'unavailable', 'Database reschedule error must be unavailable');
    $assert($both['post_intro'] === array() && $both['reschedule_proposals'] === array(), 'Failures must not publish invented data');

    $intro::$mode = 'data';
    $wpdb->rescheduleFailure = false;
    $recovered = $read();
    $assert(count($recovered['post_intro']) === 1 && $recovered['read_health']['post_intro']['state'] === 'ok', 'Retry did not recover');

    $principal::$id = 8;
    $other = $read();
    $assert($other['post_intro'][0]['reference'] === 'OWNED-8' && $other['post_intro'][0]['reference'] !== $ok['post_intro'][0]['reference'], 'Student cross-account information leaked');
    $wpdb->coreFailure = true;
    try { $read(); throw new \RuntimeException('Core DB read wrongly appeared empty'); }
    catch (\RuntimeException $error) {
        $assert($error->getMessage() === 'portal_upstream_aggregate_invalid', 'Core DB failure was not rejected');
    }
    $wpdb->coreFailure = false;
    $assert($wpdb->writes === 0, 'A portal read mutated state');

    foreach ($events as $event) {
        $serialized = json_encode($event);
        $assert(!str_contains($serialized, 'SECRET') && !str_contains($serialized, 'network_timeout_token'), 'Sensitive data in diagnostic');
        $assert(($event['data']['severity'] ?? '') === 'error' && !empty($event['data']['timestamp']), 'Missing structured diagnostics');
    }
    $assert(count($events) === 3, 'Expected one event per failed projection, not duplicate events');
    echo "Portal authenticated read scenarios PASS\n";
}
