<?php
/**
 * Source contract for the Schema-033 Core-dataset readiness slice.
 *
 * WordPress-free: it reads the tree and proves the three reviewed corrections — the Schema-033
 * migration is locked, a reconciliation run is persisted, and an exact operator replay converges —
 * plus the two round-3 corrections — the operator payload binds the evidence-reference digest it
 * stores, and a reconciliation run and its required findings commit in one transaction — and the
 * round-5 correction, that a failed projection read fails closed without recording evidence — so a
 * later edit cannot silently reopen any of them. The failure-injection proof of §6
 * (`phase-opreadiness-core-dataset-projection-failure-unit.php`) is embedded below, so this guard
 * executes that behaviour as well as the static seams.
 */
$root=dirname(__DIR__);
$migrator=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$service=file_get_contents($root.'/src/Core/Application/CoreDatasetReadinessService.php');
$plugin=file_get_contents($root.'/delnavazan-platform.php');

function dzn_readiness_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

// §1 Schema 033 is a locked migration, verified before it is recorded and before the option advances.
dzn_readiness_assert(str_contains($migrator,"'033_core_dataset_technical_prerequisites'=>array(__CLASS__,'install_core_dataset_technical_prerequisites')"),'Schema 033 must be a member of the locked migration map');
dzn_readiness_assert(str_contains($migrator,'private static function install_core_dataset_technical_prerequisites'),'Schema 033 must own its install step');
dzn_readiness_assert(str_contains($migrator,'private static function verify_core_dataset_technical_prerequisites_schema'),'Schema 033 must own its verifier');
$verified=strpos($migrator,"if(\$id==='033_core_dataset_technical_prerequisites')self::verify_core_dataset_technical_prerequisites_schema();");
$recorded=strpos($migrator,'$done[]=$id;');
$advanced=strpos($migrator,'update_option( self::OPTION, DZN_PLATFORM_SCHEMA_VERSION, false );');
dzn_readiness_assert($verified!==false&&$recorded!==false&&$advanced!==false,'The migration loop must verify Schema 033, record it and advance the option');
dzn_readiness_assert($verified<$recorded&&$recorded<$advanced,'Schema 033 must be verified before it is recorded and before the schema option advances');
dzn_readiness_assert(str_contains($migrator,"'032_notification_communications_authority', '033_core_dataset_technical_prerequisites' );"),'The current-schema check must require the Schema-033 ledger entry');
dzn_readiness_assert(str_contains($migrator,'self::verify_portal_facing_services_schema(); self::verify_notification_communications_schema(); self::verify_core_dataset_technical_prerequisites_schema();'),'The current-schema check must run the Schema-033 verifier');
dzn_readiness_assert(!str_contains($migrator,'ensure_core_dataset_technical_prerequisites')&&!str_contains($plugin,'ensure_core_dataset_technical_prerequisites'),'The separate post-upgrade installation path must be gone');

// §2 A reconciliation run is persisted as append-only evidence; no Core row is written or repaired.
dzn_readiness_assert(str_contains($service,"SELECT id,uid,created_by,created_at FROM {\$table}{\$where} ORDER BY id ASC"),'The projection must stay a bounded read of the four declared columns');
dzn_readiness_assert(str_contains($service,'core_dataset_reconciliation_runs')&&str_contains($service,'core_dataset_reconciliation_findings'),'Both reconciliation evidence tables must be written');
dzn_readiness_assert(str_contains($service,"'scope_kind'=>\$scope")&&str_contains($service,"'match_state'=>")&&str_contains($service,"'recorded_by'=>\$actor"),'The run row must record its scope, match state and actor');
dzn_readiness_assert(str_contains($service,"'expected_count'=>\$expectedCount")&&str_contains($service,"'actual_count'=>\$actualCount")&&str_contains($service,"'expected_digest'=>\$expectedDigest")&&str_contains($service,"'actual_digest'=>\$actualDigest"),'The run row must record the expected and actual counts and digests');
dzn_readiness_assert(str_contains($service,"'scope_count_mismatch'")&&str_contains($service,"'scope_digest_mismatch'"),'A mismatch must record its deterministic findings');
dzn_readiness_assert(str_contains($service,"'run_id'=>\$runId"),'The recorded run id must be returned');

// §3 An exact replay converges on the recorded row; only a conflicting payload fails closed.
dzn_readiness_assert(str_contains($service,'command_payload_digest FROM {$table} WHERE command_key_digest=%s'),'The operator recorder must load the row its command key already produced');
dzn_readiness_assert(substr_count($service,'WHERE command_key_digest=%s')>=2,'A lost insert race must re-read the recorded row');
dzn_readiness_assert(str_contains($service,'Core operator evidence replay conflict'),'A same-key/different-payload replay must fail closed');
$replay=strpos($service,'private static function replayedCommandId');
dzn_readiness_assert($replay!==false&&strpos($service,'self::replayedCommandId($existing,$payloadDigest)')!==false,'Both replay paths must converge through the recorded row');

// §4 The operator idempotency payload binds the evidence-reference digest the row stores.
dzn_readiness_assert(str_contains($service,"\$evidenceReferenceDigest=hash('sha256',\$evidenceReference)"),'The evidence reference must be digested once, from the value the row stores');
dzn_readiness_assert(str_contains($service,"'evidence_reference_digest'=>\$evidenceReferenceDigest,'result'=>\$result"),'The digested evidence reference must be part of the idempotency payload');
dzn_readiness_assert(str_contains($service,"'evidence_reference_digest'=>\$evidenceReferenceDigest,'reason_code'=>\$reason"),'The stored evidence-reference digest must be the same value the payload bound');
$bound=strpos($service,"'evidence_reference_digest'=>\$evidenceReferenceDigest,'result'=>\$result");
$digested=strpos($service,'$payloadDigest=hash(');
dzn_readiness_assert($bound!==false&&$digested!==false&&$bound<$digested,'The evidence-reference digest must be bound before the payload digest is computed');

// §5 A reconciliation run and its required findings are one transaction.
dzn_readiness_assert(substr_count($service,'START TRANSACTION')===1&&substr_count($service,'ROLLBACK')===1,'The run row and its findings must share exactly one transaction');
$opened=strpos($service,'START TRANSACTION');
$insertRun=strpos($service,'$wpdb->insert($runs,');
$findings=strpos($service,'self::recordFindings($runId,');
$committed=strpos($service,"'COMMIT'");
dzn_readiness_assert($opened!==false&&$insertRun!==false&&$findings!==false&&$committed!==false,'The reconciliation writes must open a transaction and commit it');
dzn_readiness_assert($opened<$insertRun&&$insertRun<$findings&&$findings<$committed,'The run row and all of its required findings must be written before COMMIT');
dzn_readiness_assert(str_contains($service,'}catch(\Throwable $e){$wpdb->query(')&&str_contains($service,');throw $e;}'),'A failed run or finding insert must roll the transaction back and rethrow');

// §6 A failed projection read fails closed before the digest is computed and before the transaction opens.
dzn_readiness_assert(!str_contains($service,'?: array()'),'No projection read may be reconciled with a null-coalescing fallback');
dzn_readiness_assert(!str_contains($service,'ORDER BY id ASC",ARRAY_A) ?: array()'),'A failed projection read must not be masked as an empty dataset');
dzn_readiness_assert(str_contains($service,'(string)$wpdb->last_error')&&str_contains($service,'Core reconciliation projection read failed'),'The projection read must preserve and check the query failure state');
$readFailure=strpos($service,'Core reconciliation projection read failed');
$digest=strpos($service,"\$actualDigest=hash('sha256'");
$transaction=strpos($service,'START TRANSACTION');
dzn_readiness_assert($readFailure!==false&&$digest!==false&&$transaction!==false&&$readFailure<$digest&&$readFailure<$transaction,'A projection-read failure must be thrown before the digest is hashed and before the evidence transaction opens');

if(!defined('DZN_OPREADINESS_PROJECTION_UNIT_EMBEDDED'))define('DZN_OPREADINESS_PROJECTION_UNIT_EMBEDDED',true);
require __DIR__.'/phase-opreadiness-core-dataset-projection-failure-unit.php';
echo "Core-dataset readiness contract passed\n";
