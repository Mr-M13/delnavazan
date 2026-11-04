<?php
declare(strict_types=1);

namespace Delnavazan\Recorder;

use PDO;
use PDOException;

final class Database {
    private PDO $pdo;
    private bool $sqlite;

    public function __construct(private Config $config) {
        $dsn = (string) $config->get('database_dsn');
        $this->sqlite = str_starts_with($dsn, 'sqlite:');
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if ($this->sqlite) {
            $path = substr($dsn, 7);
            if ($path !== ':memory:') {
                $dir = dirname($path);
                if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                    throw new \RuntimeException('recorder_database_directory_unavailable');
                }
            }
            $this->pdo = new PDO($dsn, null, null, $options);
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA synchronous=FULL');
            $this->pdo->exec('PRAGMA foreign_keys=ON');
            $this->pdo->exec('PRAGMA busy_timeout=10000');
        } else {
            $this->pdo = new PDO($dsn, (string) $config->get('database_user'), (string) $config->get('database_password'), $options);
            $this->pdo->exec("SET TIME ZONE 'UTC'");
        }
    }

    public function pdo(): PDO { return $this->pdo; }
    public function isSqlite(): bool { return $this->sqlite; }

    public function migrate(): void {
        foreach ($this->schema() as $statement) {
            $this->pdo->exec($statement);
        }
    }

    public function now(): string {
        $sql = $this->sqlite
            ? "SELECT strftime('%Y-%m-%d %H:%M:%S','now') AS now"
            : "SELECT to_char(now() AT TIME ZONE 'UTC','YYYY-MM-DD HH24:MI:SS') AS now";
        return (string) $this->pdo->query($sql)->fetchColumn();
    }

    public function plusSeconds(int $seconds): string {
        return gmdate('Y-m-d H:i:s', strtotime($this->now() . ' UTC') + $seconds);
    }

    public function transaction(callable $work): mixed {
        $this->pdo->exec($this->sqlite ? 'BEGIN IMMEDIATE' : 'BEGIN');
        try {
            $result = $work($this);
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $error) {
            try { $this->pdo->exec('ROLLBACK'); } catch (\Throwable) {}
            throw $error;
        }
    }

    public function fetch(string $sql, array $params = []): ?array {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function fetchAll(string $sql, array $params = []): array {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function scalar(string $sql, array $params = []): mixed {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }

    public function execute(string $sql, array $params = []): int {
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with((string) $key, ':') ? (string) $key : ':' . $key);
            if (is_bool($value)) $statement->bindValue($name, $value, PDO::PARAM_BOOL);
            elseif ($value === null) $statement->bindValue($name, null, PDO::PARAM_NULL);
            elseif (is_int($value)) $statement->bindValue($name, $value, PDO::PARAM_INT);
            else $statement->bindValue($name, $value);
        }
        $statement->execute();
        return $statement->rowCount();
    }

    public function insert(string $table, array $values): int {
        $columns = array_keys($values);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_map(static fn($c) => ':' . $c, $columns)) . ')';
        $this->execute($sql, $values);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $values, array $where): int {
        $sets = [];
        $params = [];
        foreach ($values as $column => $value) { $sets[] = $column . '=:' . $column; $params[$column] = $value; }
        $conditions = [];
        foreach ($where as $column => $value) { $conditions[] = $column . '=:w_' . $column; $params['w_' . $column] = $value; }
        return $this->execute('UPDATE ' . $table . ' SET ' . implode(',', $sets) . ' WHERE ' . implode(' AND ', $conditions), $params);
    }

    private function schema(): array {
        if ($this->sqlite) {
            return [
                "CREATE TABLE IF NOT EXISTS service_inbox (id INTEGER PRIMARY KEY AUTOINCREMENT, uid TEXT NOT NULL, command_id INTEGER NOT NULL, idempotency_key TEXT NOT NULL UNIQUE, command_type TEXT NOT NULL, payload_digest TEXT NOT NULL, payload_json TEXT NOT NULL, signature_json TEXT NOT NULL, state TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, max_attempts INTEGER NOT NULL DEFAULT 8, available_at TEXT NOT NULL, lease_token_digest TEXT NULL, lease_expires_at TEXT NULL, last_error TEXT NULL, result_code TEXT NULL, received_at TEXT NOT NULL, processed_at TEXT NULL, version INTEGER NOT NULL DEFAULT 1)",
                "CREATE INDEX IF NOT EXISTS service_inbox_claimable ON service_inbox(state, available_at)",
                "CREATE TABLE IF NOT EXISTS service_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, uid TEXT NOT NULL, event_type TEXT NOT NULL, recording_uid TEXT NOT NULL, content_version INTEGER NOT NULL, idempotency_key TEXT NOT NULL UNIQUE, payload_digest TEXT NOT NULL, payload_json TEXT NOT NULL, state TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, available_at TEXT NOT NULL, lease_token_digest TEXT NULL, lease_expires_at TEXT NULL, sent_at TEXT NULL, last_error TEXT NULL, created_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1)",
                "CREATE INDEX IF NOT EXISTS service_outbox_claimable ON service_outbox(state, available_at)",
                "CREATE TABLE IF NOT EXISTS recording_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, uid TEXT NOT NULL, recording_uid TEXT NOT NULL UNIQUE, content_version INTEGER NOT NULL, lesson_id INTEGER NOT NULL, lesson_uid TEXT NOT NULL, schedule_version_id INTEGER NOT NULL, schedule_version_uid TEXT NOT NULL, room_name TEXT NOT NULL DEFAULT '', teacher_id INTEGER NOT NULL, teacher_uid TEXT NOT NULL, student_id INTEGER NOT NULL, student_uid TEXT NOT NULL, provider TEXT NOT NULL, provider_egress_id TEXT NULL, state TEXT NOT NULL, authorisation_evidence_id INTEGER NOT NULL, authorisation_digest TEXT NOT NULL, scheduled_duration_seconds INTEGER NOT NULL, capture_mode TEXT NOT NULL, layout TEXT NOT NULL, watermark_required INTEGER NOT NULL, legal_hold_state TEXT NOT NULL DEFAULT 'none', last_error TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1)",
                "CREATE INDEX IF NOT EXISTS recording_jobs_state ON recording_jobs(state)",
                "CREATE TABLE IF NOT EXISTS recording_assets (id INTEGER PRIMARY KEY AUTOINCREMENT, recording_uid TEXT NOT NULL, content_version INTEGER NOT NULL, path_relative TEXT NOT NULL, state TEXT NOT NULL, bytes INTEGER NULL, sha256 TEXT NULL, duration_ms INTEGER NULL, width INTEGER NULL, height INTEGER NULL, video_codec TEXT NULL, audio_codec TEXT NULL, video_streams INTEGER NULL, audio_streams INTEGER NULL, sync_offset_ms INTEGER NULL, verified_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(recording_uid, content_version))",
                "CREATE TABLE IF NOT EXISTS provider_events (id INTEGER PRIMARY KEY AUTOINCREMENT, recording_uid TEXT NOT NULL, provider_egress_id TEXT NULL, event_type TEXT NOT NULL, idempotency_key TEXT NOT NULL UNIQUE, payload_digest TEXT NOT NULL, payload_json TEXT NOT NULL, occurred_at TEXT NOT NULL, received_at TEXT NOT NULL, applied_at TEXT NULL)",
                "CREATE TABLE IF NOT EXISTS transfer_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, recording_uid TEXT NOT NULL, content_version INTEGER NOT NULL, state TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, max_attempts INTEGER NOT NULL DEFAULT 8, available_at TEXT NOT NULL, lease_token_digest TEXT NULL, lease_expires_at TEXT NULL, last_error TEXT NULL, archive_object_key TEXT NULL, destination_temp_key TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1, UNIQUE(recording_uid, content_version))",
                "CREATE INDEX IF NOT EXISTS transfer_jobs_claimable ON transfer_jobs(state, available_at)",
                "CREATE TABLE IF NOT EXISTS transfer_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, transfer_job_id INTEGER NOT NULL, attempt INTEGER NOT NULL, started_at TEXT NOT NULL, finished_at TEXT NULL, outcome TEXT NULL, bytes_transferred INTEGER NULL, error_class TEXT NULL)",
                "CREATE TABLE IF NOT EXISTS archive_confirmations (id INTEGER PRIMARY KEY AUTOINCREMENT, recording_uid TEXT NOT NULL, content_version INTEGER NOT NULL, source_bytes INTEGER NOT NULL, destination_bytes INTEGER NOT NULL, source_sha256 TEXT NOT NULL, destination_sha256 TEXT NOT NULL, archive_node_identity TEXT NOT NULL, destination_object_key TEXT NOT NULL, durable_commit_receipt TEXT NOT NULL, confirmation_signature TEXT NOT NULL, confirmation_time TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE(recording_uid, content_version))",
                "CREATE TABLE IF NOT EXISTS deletion_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, recording_uid TEXT NOT NULL, content_version INTEGER NOT NULL, delete_archive INTEGER NOT NULL DEFAULT 0, state TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, max_attempts INTEGER NOT NULL DEFAULT 8, available_at TEXT NOT NULL, lease_token_digest TEXT NULL, lease_expires_at TEXT NULL, last_error TEXT NULL, source_device TEXT NULL, source_inode TEXT NULL, deletion_receipt TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1, UNIQUE(recording_uid, content_version))",
                "CREATE INDEX IF NOT EXISTS deletion_jobs_claimable ON deletion_jobs(state, available_at)",
            ];
        }
        return [
            "CREATE TABLE IF NOT EXISTS service_inbox (id BIGSERIAL PRIMARY KEY, uid TEXT NOT NULL, command_id BIGINT NOT NULL, idempotency_key TEXT NOT NULL UNIQUE, command_type TEXT NOT NULL, payload_digest TEXT NOT NULL, payload_json TEXT NOT NULL, signature_json TEXT NOT NULL, state TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, max_attempts INTEGER NOT NULL DEFAULT 8, available_at TIMESTAMP NOT NULL, lease_token_digest TEXT NULL, lease_expires_at TIMESTAMP NULL, last_error TEXT NULL, result_code TEXT NULL, received_at TIMESTAMP NOT NULL, processed_at TIMESTAMP NULL, version BIGINT NOT NULL DEFAULT 1)",
            "CREATE INDEX IF NOT EXISTS service_inbox_claimable ON service_inbox(state, available_at)",
            "CREATE TABLE IF NOT EXISTS service_outbox (id BIGSERIAL PRIMARY KEY, uid TEXT NOT NULL, event_type TEXT NOT NULL, recording_uid TEXT NOT NULL, content_version INTEGER NOT NULL, idempotency_key TEXT NOT NULL UNIQUE, payload_digest TEXT NOT NULL, payload_json TEXT NOT NULL, state TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, available_at TIMESTAMP NOT NULL, lease_token_digest TEXT NULL, lease_expires_at TIMESTAMP NULL, sent_at TIMESTAMP NULL, last_error TEXT NULL, created_at TIMESTAMP NOT NULL, version BIGINT NOT NULL DEFAULT 1)",
            "CREATE INDEX IF NOT EXISTS service_outbox_claimable ON service_outbox(state, available_at)",
            "CREATE TABLE IF NOT EXISTS recording_jobs (id BIGSERIAL PRIMARY KEY, uid TEXT NOT NULL, recording_uid TEXT NOT NULL UNIQUE, content_version INTEGER NOT NULL, lesson_id BIGINT NOT NULL, lesson_uid TEXT NOT NULL, schedule_version_id BIGINT NOT NULL, schedule_version_uid TEXT NOT NULL, room_name TEXT NOT NULL DEFAULT '', teacher_id BIGINT NOT NULL, teacher_uid TEXT NOT NULL, student_id BIGINT NOT NULL, student_uid TEXT NOT NULL, provider TEXT NOT NULL, provider_egress_id TEXT NULL, state TEXT NOT NULL, authorisation_evidence_id BIGINT NOT NULL, authorisation_digest TEXT NOT NULL, scheduled_duration_seconds INTEGER NOT NULL, capture_mode TEXT NOT NULL, layout TEXT NOT NULL, watermark_required BOOLEAN NOT NULL, legal_hold_state TEXT NOT NULL DEFAULT 'none', last_error TEXT NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL, version BIGINT NOT NULL DEFAULT 1)",
            "CREATE INDEX IF NOT EXISTS recording_jobs_state ON recording_jobs(state)",
            "CREATE TABLE IF NOT EXISTS recording_assets (id BIGSERIAL PRIMARY KEY, recording_uid TEXT NOT NULL, content_version INTEGER NOT NULL, path_relative TEXT NOT NULL, state TEXT NOT NULL, bytes BIGINT NULL, sha256 TEXT NULL, duration_ms BIGINT NULL, width INTEGER NULL, height INTEGER NULL, video_codec TEXT NULL, audio_codec TEXT NULL, video_streams INTEGER NULL, audio_streams INTEGER NULL, sync_offset_ms INTEGER NULL, verified_at TIMESTAMP NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL, UNIQUE(recording_uid, content_version))",
            "CREATE TABLE IF NOT EXISTS provider_events (id BIGSERIAL PRIMARY KEY, recording_uid TEXT NOT NULL, provider_egress_id TEXT NULL, event_type TEXT NOT NULL, idempotency_key TEXT NOT NULL UNIQUE, payload_digest TEXT NOT NULL, payload_json TEXT NOT NULL, occurred_at TIMESTAMP NOT NULL, received_at TIMESTAMP NOT NULL, applied_at TIMESTAMP NULL)",
            "CREATE TABLE IF NOT EXISTS transfer_jobs (id BIGSERIAL PRIMARY KEY, recording_uid TEXT NOT NULL, content_version INTEGER NOT NULL, state TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, max_attempts INTEGER NOT NULL DEFAULT 8, available_at TIMESTAMP NOT NULL, lease_token_digest TEXT NULL, lease_expires_at TIMESTAMP NULL, last_error TEXT NULL, archive_object_key TEXT NULL, destination_temp_key TEXT NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL, version BIGINT NOT NULL DEFAULT 1, UNIQUE(recording_uid, content_version))",
            "CREATE INDEX IF NOT EXISTS transfer_jobs_claimable ON transfer_jobs(state, available_at)",
            "CREATE TABLE IF NOT EXISTS transfer_attempts (id BIGSERIAL PRIMARY KEY, transfer_job_id BIGINT NOT NULL, attempt INTEGER NOT NULL, started_at TIMESTAMP NOT NULL, finished_at TIMESTAMP NULL, outcome TEXT NULL, bytes_transferred BIGINT NULL, error_class TEXT NULL)",
            "CREATE TABLE IF NOT EXISTS archive_confirmations (id BIGSERIAL PRIMARY KEY, recording_uid TEXT NOT NULL, content_version INTEGER NOT NULL, source_bytes BIGINT NOT NULL, destination_bytes BIGINT NOT NULL, source_sha256 TEXT NOT NULL, destination_sha256 TEXT NOT NULL, archive_node_identity TEXT NOT NULL, destination_object_key TEXT NOT NULL, durable_commit_receipt TEXT NOT NULL, confirmation_signature TEXT NOT NULL, confirmation_time TIMESTAMP NOT NULL, created_at TIMESTAMP NOT NULL, UNIQUE(recording_uid, content_version))",
            "CREATE TABLE IF NOT EXISTS deletion_jobs (id BIGSERIAL PRIMARY KEY, recording_uid TEXT NOT NULL, content_version INTEGER NOT NULL, delete_archive BOOLEAN NOT NULL DEFAULT FALSE, state TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, max_attempts INTEGER NOT NULL DEFAULT 8, available_at TIMESTAMP NOT NULL, lease_token_digest TEXT NULL, lease_expires_at TIMESTAMP NULL, last_error TEXT NULL, source_device TEXT NULL, source_inode TEXT NULL, deletion_receipt TEXT NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL, version BIGINT NOT NULL DEFAULT 1, UNIQUE(recording_uid, content_version))",
            "CREATE INDEX IF NOT EXISTS deletion_jobs_claimable ON deletion_jobs(state, available_at)",
        ];
    }
}
