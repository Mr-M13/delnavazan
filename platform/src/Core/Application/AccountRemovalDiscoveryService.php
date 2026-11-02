<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Read-only inventory for a canonical Student or Teacher ID.
 *
 * The inventory deliberately treats every discovered relationship as a blocker.
 * A future executor must classify each edge, including indirect and external
 * resources, before it may use this manifest as deletion authority.
 */
final class AccountRemovalDiscoveryService {
    private const KINDS = array('student' => 'students', 'teacher' => 'teachers');
    private const LINK_COLUMNS = array(
        'student' => array('student_id', 'beneficiary_student_id', 'subject_student_id', 'resolved_student_id'),
        'teacher' => array('teacher_id', 'resolved_teacher_id'),
    );
    private const WP_COLUMNS = array('wordpress_user_id', 'principal_user_id', 'teacher_actor_user_id', 'guardian_actor_user_id', 'acting_wordpress_user_id', 'accepting_wordpress_user_id', 'claimed_principal_id');

    /** Resolve only the canonical reference printed in the Student/Teacher workspace. */
    public function discoverByReference(string $reference): array {
        $reference = strtoupper(trim($reference));
        if (!preg_match('/^DZN-(STU|TCH)-[0-9]{6,}$/D', $reference, $match)) throw new \InvalidArgumentException('invalid_account_reference');
        $kind = $match[1] === 'STU' ? 'student' : 'teacher';
        global $wpdb;
        $table = $wpdb->prefix . 'dzn_' . self::KINDS[$kind];
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE reference_code=%s LIMIT 1", $reference));
        $this->assertDatabaseHealthy();
        if ($id < 1) throw new \InvalidArgumentException('account_not_found');
        $manifest = $this->discover($kind, $id);
        if ($manifest['reference_code'] !== $reference) throw new \RuntimeException('account_reference_changed');
        return $manifest;
    }

    public function discover(string $kind, int $id): array {
        if (!isset(self::KINDS[$kind]) || $id < 1) throw new \InvalidArgumentException('invalid_account_selector');
        global $wpdb;
        $prefix = $wpdb->prefix . 'dzn_';
        $root = $prefix . self::KINDS[$kind];
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, uid, reference_code, wordpress_user_id, status FROM {$root} WHERE id=%d", $id), ARRAY_A);
        $this->assertDatabaseHealthy();
        if (!is_array($row)) throw new \InvalidArgumentException('account_not_found');
        $userId = (int) ($row['wordpress_user_id'] ?? 0);
        $relations = array();
        $blockers = array();
        $tables = $this->tables($prefix);
        foreach ($tables as $table) {
            $name = substr($table, strlen($prefix));
            if ($table === $root) continue;
            $columns = $this->columns($table);
            $matching = array_intersect($columns, self::LINK_COLUMNS[$kind]);
            if ($userId > 0) $matching = array_merge($matching, array_intersect($columns, self::WP_COLUMNS));
            foreach (array_unique($matching) as $column) {
                $value = in_array($column, self::WP_COLUMNS, true) ? $userId : $id;
                // Identifiers come only from information_schema and are checked again here.
                if (!preg_match('/^[a-z0-9_]+$/D', $table) || !preg_match('/^[a-z0-9_]+$/D', $column)) throw new \RuntimeException('invalid_schema_identifier');
                $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}`=%d", $value));
                $this->assertDatabaseHealthy();
                if ($count > 0) {
                    $relations[] = array('table' => $name, 'column' => $column, 'count' => $count);
                }
            }
        }
        if ($userId > 0) {
            $user = get_userdata($userId);
            if (!$user) $blockers[] = 'wordpress_user_missing';
            else {
                if (array_intersect((array) $user->roles, array('administrator', 'editor', 'shop_manager'))) $blockers[] = 'privileged_wordpress_identity';
                foreach (array('students', 'teachers') as $other) {
                    $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$prefix}{$other}` WHERE wordpress_user_id=%d AND id<>%d", $userId, $other === self::KINDS[$kind] ? $id : 0));
                    $this->assertDatabaseHealthy();
                    if ($count > 0) $blockers[] = 'shared_wordpress_identity';
                }
                $posts = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author=%d", $userId));
                $this->assertDatabaseHealthy();
                $comments = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id=%d", $userId));
                $this->assertDatabaseHealthy();
                if ($posts > 0) { $relations[] = array('table' => 'wp_posts', 'column' => 'post_author', 'count' => $posts); $blockers[] = 'wordpress_content_ownership'; }
                if ($comments > 0) { $relations[] = array('table' => 'wp_comments', 'column' => 'user_id', 'count' => $comments); $blockers[] = 'wordpress_comment_ownership'; }
            }
        } else $blockers[] = 'wordpress_identity_unresolved';
        if (function_exists('is_multisite') && is_multisite()) $blockers[] = 'multisite_ownership_unreviewed';
        // Account origin is not an eligibility shortcut: real and test accounts
        // require the same complete dependency and retention review.
        $blockers[] = 'indirect_and_external_dependencies_unreviewed';
        usort($relations, static fn(array $a, array $b): int => strcmp($a['table'] . '.' . $a['column'], $b['table'] . '.' . $b['column']));
        $manifest = array(
            'kind' => $kind,
            'id' => $id,
            'canonical_uid' => (string) $row['uid'],
            'reference_code' => (string) $row['reference_code'],
            'status' => (string) $row['status'],
            'wordpress_user_id' => $userId,
            'relations' => $relations,
            'blockers' => array_values(array_unique($blockers)),
        );
        $manifest['digest'] = hash('sha256', wp_json_encode($manifest));
        return $manifest;
    }

    private function tables(string $prefix): array {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME', $wpdb->esc_like($prefix) . '%'));
        $this->assertDatabaseHealthy();
        return array_values(array_filter((array) $rows, static fn($name): bool => is_string($name) && str_starts_with($name, $prefix) && preg_match('/^[a-z0-9_]+$/D', $name) === 1));
    }

    private function columns(string $table): array {
        global $wpdb;
        $columns = $wpdb->get_col($wpdb->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table));
        $this->assertDatabaseHealthy();
        return (array) $columns;
    }

    private function assertDatabaseHealthy(): void {
        global $wpdb;
        if ((string) $wpdb->last_error !== '') throw new \RuntimeException('account_discovery_database_error');
    }
}
