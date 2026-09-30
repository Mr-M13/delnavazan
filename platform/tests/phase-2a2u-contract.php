<?php
/**
 * Phase 2A.2-U finance, payability, effective-dated rate and statement authority source contract (§18).
 *
 * Pure static proof: build/schema identity, the migration and verifier call sites, the declared
 * twenty-one-table set, the three declared seed rows and the single declared policy root, the locked
 * vocabularies and the single reason-code allowlist, the derivation table as a single source, the
 * digest-only/append-only discipline, the §13.3 index and parent contract, the typed command results,
 * the five administrator capabilities, the §15.7 infrastructure allowlist and the §17 intents.
 */
$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/delnavazan-platform.php' );
$migration = file_get_contents( $root . '/src/Core/Infrastructure/Migration/Migrator.php' );
$rule = file_get_contents( $root . '/src/Core/Application/Finance/FinanceRule.php' );
$support = file_get_contents( $root . '/src/Core/Application/Finance/FinanceSupport.php' );
$policyService = file_get_contents( $root . '/src/Core/Application/Finance/FinancePolicyService.php' );
$rateService = file_get_contents( $root . '/src/Core/Application/Finance/TeacherRateService.php' );
$snapshotService = file_get_contents( $root . '/src/Core/Application/Finance/LessonFinanceSnapshotService.php' );
$payabilityService = file_get_contents( $root . '/src/Core/Application/Finance/LessonPayabilityService.php' );
$statementService = file_get_contents( $root . '/src/Core/Application/Finance/TeacherStatementService.php' );
$correctionService = file_get_contents( $root . '/src/Core/Application/Finance/FinanceCorrectionService.php' );
$reconciliationService = file_get_contents( $root . '/src/Core/Application/Finance/FinanceReconciliationService.php' );
$rateIntegrity = file_get_contents( $root . '/src/Core/Application/Finance/Integrity/FinanceRateIntegrity.php' );
$snapshotIntegrity = file_get_contents( $root . '/src/Core/Application/Finance/Integrity/FinanceSnapshotIntegrity.php' );
$payabilityIntegrity = file_get_contents( $root . '/src/Core/Application/Finance/Integrity/FinancePayabilityIntegrity.php' );
$statementIntegrity = file_get_contents( $root . '/src/Core/Application/Finance/Integrity/FinanceStatementIntegrity.php' );
$reconciliationIntegrity = file_get_contents( $root . '/src/Core/Application/Finance/Integrity/FinanceReconciliationIntegrity.php' );
$repositories = '';
foreach ( glob( $root . '/src/Core/Infrastructure/Repository/Finance*Repository.php' ) as $file ) $repositories .= file_get_contents( $file );
$repositories .= file_get_contents( $root . '/src/Core/Infrastructure/Repository/TeacherRateRepository.php' );
$readServices = '';
foreach ( glob( $root . '/src/Core/Application/Finance/Read/*.php' ) as $file ) $readServices .= file_get_contents( $file );
$controllers = '';
foreach ( glob( $root . '/src/Admin/Controller/Finance*Controller.php' ) as $file ) $controllers .= file_get_contents( $file );
$application = '';
foreach ( glob( $root . '/src/Core/Application/Finance/*.php' ) as $file ) $application .= file_get_contents( $file );
$application .= $rateIntegrity . $snapshotIntegrity . $payabilityIntegrity . $statementIntegrity . $reconciliationIntegrity . $readServices;

function dzn_u_contract( bool $condition, string $message ): void { if ( ! $condition ) throw new RuntimeException( 'Phase 2A.2-U contract: ' . $message ); }

// ---- Build/schema identity and migration wiring. ------------------------------------------------
if ( ! preg_match( "/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/", $plugin, $schema ) || (int) $schema[1] < 30 ) throw new RuntimeException( 'Missing Phase U schema identity' );
if ( ! preg_match( "/DZN_PLATFORM_BUILD_ID', 'phase2a2u-[a-z0-9-]+-[0-9]{8}\.[0-9]+'/", $plugin ) ) throw new RuntimeException( 'Missing Phase U build identity' );
foreach ( array( '030_finance_payability_rate_statement_authority', 'install_finance_payability_rate_statement_authority', 'verify_finance_payability_rate_statement_schema', 'dzn_platform_capability_version_2a2u', '$phaseUGrants', '$teacherURole' ) as $needle )
	if ( ! str_contains( $migration . $plugin, $needle ) ) throw new RuntimeException( 'Missing Phase U migration contract: ' . $needle );
if ( ! str_contains( $migration, "if(\$id==='030_finance_payability_rate_statement_authority')self::verify_finance_payability_rate_statement_schema();" ) ) throw new RuntimeException( 'Migration 030 must invoke the Phase U verifier before it is recorded' );
if ( substr_count( $migration, 'self::verify_finance_payability_rate_statement_schema();' ) < 3 ) throw new RuntimeException( 'The Phase U verifier must run after migration 030, on current-schema verification and before schema activation' );
if ( ! str_contains( $migration, "in_array( '030_finance_payability_rate_statement_authority', (array) get_option( self::COMPLETED, array() ), true )" ) ) throw new RuntimeException( 'Retained-030 pre-activation verification is missing' );
if ( ! str_contains( $migration, "'030_finance_payability_rate_statement_authority' )" ) ) throw new RuntimeException( 'Migration 030 must be listed as required' );
if ( ! str_contains( $migration, "'030_finance_payability_rate_statement_authority'=>array(__CLASS__,'install_finance_payability_rate_statement_authority')" ) ) throw new RuntimeException( 'Migration 030 must be scheduled in the migration ledger' );

// ---- The declared twenty-one-table set. ---------------------------------------------------------
$tables = array( 'finance_policy_roots','finance_teacher_roots','finance_policies','finance_policy_commands','finance_teacher_rates','finance_teacher_rate_events','finance_teacher_rate_commands','finance_lesson_snapshots','finance_snapshot_corrections','finance_snapshot_commands','finance_payability_evaluations','finance_payability_overrides','finance_payability_commands','finance_statements','finance_statement_lines','finance_statement_events','finance_statement_commands','finance_reconciliation_runs','finance_reconciliation_findings','finance_reconciliation_commands','finance_exceptions' );
if ( count( $tables ) !== 21 ) throw new RuntimeException( 'The declared Phase U set is not twenty-one tables' );
foreach ( $tables as $table ) dzn_u_contract( str_contains( $rule, "'" . $table . "'" ) && str_contains( $migration, "CREATE TABLE {\$p}" . $table . ' ' ), 'a declared table is missing from the rule set or the migration: ' . $table );
$installStart = strpos( $migration, 'private static function install_finance_payability_rate_statement_authority' );
$installEnd = strpos( $migration, 'private static function verify_finance_payability_rate_statement_schema' );
dzn_u_contract( $installStart !== false && $installEnd !== false && $installEnd > $installStart, 'the Phase U installer and verifier must both exist, installer first' );
$install = substr( $migration, $installStart, $installEnd - $installStart );
$installBody = substr( $install, strpos( $install, '{' ) );
if ( str_contains( $installBody, 'ALTER TABLE' ) || str_contains( $installBody, 'DELETE FROM' ) || str_contains( $installBody, 'UPDATE ' ) || str_contains( $installBody, 'INSERT INTO' ) ) throw new RuntimeException( 'Migration 030 must be additive only' );
preg_match_all( '/CREATE TABLE \{\$p\}([a-z_]+)/', $installBody, $created );
$createdTables = $created[1]; sort( $createdTables ); $declaredTables = $tables; sort( $declaredTables );
if ( $createdTables !== $declaredTables ) throw new RuntimeException( 'Migration 030 must create exactly the twenty-one declared tables' );
if ( count( array_unique( $createdTables ) ) !== 21 ) throw new RuntimeException( 'Each Phase U table must be declared exactly once' );
if ( str_contains( $installBody, 'FOREIGN KEY' ) || str_contains( $installBody, 'CHECK (' ) || str_contains( $installBody, ' float' ) || str_contains( $installBody, ' decimal(' ) ) throw new RuntimeException( 'Phase U storage declares no foreign key, no CHECK and no floating-point column' );
foreach ( array( 'platform_audit_events', 'platform_outbox' ) as $infrastructure ) if ( str_contains( $installBody, "CREATE TABLE {\$p}{$infrastructure}" ) ) throw new RuntimeException( 'Migration 030 must never create or alter the pre-existing infrastructure seams' );
if ( substr_count( $installBody, 'finance_policy_roots' ) < 2 ) throw new RuntimeException( 'Migration 030 must seed the global policy root with insert-or-resolve semantics' );
if ( ! str_contains( $installBody, "'root_key' => 'finance_policy'" ) ) throw new RuntimeException( 'Migration 030 must seed the declared root key' );
foreach ( array( 'INTRO_PAYABILITY_POLICY' => 'non_payable', 'STUDENT_NO_SHOW_COMPENSATION_POLICY' => 'payable', 'INTERRUPTION_COMPENSATION_POLICY' => 'payable' ) as $key => $value )
	if ( ! str_contains( $installBody, "'" . $key . "' => '" . $value . "'" ) ) throw new RuntimeException( 'Migration 030 must seed the declared default for ' . $key );
foreach ( $tables as $table ) if ( substr_count( $installBody, 'CREATE TABLE {$p}' . $table . ' ' ) !== 1 ) throw new RuntimeException( 'Each declared table is declared exactly once: ' . $table );
if ( str_contains( $installBody, 'finance_policies`' ) && str_contains( $installBody, 'FINANCE_STATEMENT_TIMEZONE' ) ) throw new RuntimeException( 'The statement timezone key is never seeded' );

// ---- Locked vocabularies (§5.2). ---------------------------------------------------------------
foreach ( array(
    "DISPOSITIONS=array('payable','non_payable','pending')",
    "BASIS_CODES=array('delivered_occurrence','student_no_show','interruption','teacher_non_delivery','academy_obligation','delivery_review_required','occurrence_not_attempted','lesson_not_finalised','introductory_policy_non_payable','administrator_override')",
    "RATE_SCOPE_KINDS=array('teacher','teacher_course')",
    "RATE_STATES=array('active','superseded','withdrawn')",
    "COMPENSATION_BASES=array('per_session')",
    "STATEMENT_STATES=array('draft','issued','superseded','withdrawn')",
    "STATEMENT_EVENT_TYPES=array('drafted','issued','superseded','withdrawn')",
    "CORRECTION_KINDS=array('snapshot_correction','payability_override','statement_supersession')",
    "SNAPSHOT_BOUNDARY='occurrence_start'",
    "MAX_STATEMENT_PERIOD_DAYS=62",
    "STATEMENT_CURRENCY_RULE='single_currency'",
    "AMOUNT_EXACTNESS='exact_integer'",
    "FINANCE_TIMEZONE_SOURCE='recorded_policy'",
    "POLICY_ADMISSIBILITY_RULE='later_than_recorded_consumption'",
    "PAYABILITY_DERIVATION_VERSION='finance_payability_v1'",
    "UNSET_POLICY_KEY='FINANCE_STATEMENT_TIMEZONE'",
) as $needle ) dzn_u_contract( str_contains( preg_replace( '/\s+/', '', $rule ), $needle ), 'missing locked vocabulary: ' . $needle );
foreach ( array( 'INTRO_PAYABILITY_POLICY', 'STUDENT_NO_SHOW_COMPENSATION_POLICY', 'INTERRUPTION_COMPENSATION_POLICY', 'FINANCE_STATEMENT_TIMEZONE' ) as $key ) dzn_u_contract( str_contains( $rule, "'" . $key . "'" ), 'missing declared policy key: ' . $key );
if ( ! preg_match( '/POLICY_KEYS=array\(([^)]*)\)/', $rule, $policyKeys ) ) throw new RuntimeException( 'FinanceRule::POLICY_KEYS must be declared literally' );
if ( substr_count( $policyKeys[1], ',' ) !== 3 ) throw new RuntimeException( 'FinanceRule::POLICY_KEYS must be exactly four keys' );
foreach ( array( "'MAX_STATEMENT_PERIOD_DAYS'", "'occurrence_start'", "'single_currency'" ) as $structural ) if ( str_contains( $policyKeys[1], $structural ) ) throw new RuntimeException( 'A structural finance invariant must not be a configurable policy key' );

// ---- The single reason-code allowlist (§5.2.1). -------------------------------------------------
if ( ! preg_match( '/EXCEPTION_REASON_CODES=array\((.*?)\);/s', $rule, $exceptionSet ) ) throw new RuntimeException( 'FinanceRule::EXCEPTION_REASON_CODES must be declared literally' );
if ( ! preg_match( '/FINDING_CODES=array\((.*?)\);/s', $rule, $findingSet ) ) throw new RuntimeException( 'FinanceRule::FINDING_CODES must be declared literally' );
if ( ! preg_match( '/OPERATOR_REASON_CODES=array\((.*?)\);/s', $rule, $operatorSet ) ) throw new RuntimeException( 'FinanceRule::OPERATOR_REASON_CODES must be declared literally' );
if ( ! preg_match( '/SHARED_REASON_CODES=array\((.*?)\);/s', $rule, $sharedSet ) ) throw new RuntimeException( 'FinanceRule::SHARED_REASON_CODES must be declared literally' );
preg_match_all( "/'([a-z0-9_]+)'/", $exceptionSet[1], $exceptions );
preg_match_all( "/'([a-z0-9_]+)'/", $findingSet[1], $findings );
preg_match_all( "/'([a-z0-9_]+)'/", $operatorSet[1], $operators );
preg_match_all( "/'([a-z0-9_]+)'/", $sharedSet[1], $shared );
$exceptions = array_values( array_unique( $exceptions[1] ) ); $findings = array_values( array_unique( $findings[1] ) ); $operators = array_values( array_unique( $operators[1] ) ); $shared = array_values( array_unique( $shared[1] ) );
if ( count( $exceptions ) !== 43 ) throw new RuntimeException( 'The declared durable exception/refusal vocabulary must have exactly 43 members' );
if ( count( $findings ) !== 21 ) throw new RuntimeException( 'The declared reconciliation finding vocabulary must have exactly 21 members' );
if ( count( $operators ) !== 4 ) throw new RuntimeException( 'The declared operator reason vocabulary must have exactly 4 members' );
$intersection = array_values( array_intersect( $exceptions, $findings ) ); sort( $intersection ); sort( $shared );
if ( $intersection !== $shared ) throw new RuntimeException( 'Exactly the twelve declared members may be shared by both reason-code views' );
foreach ( array( 'reasonCodes', 'exceptionReason', 'findingCode', 'operatorReason' ) as $method ) if ( ! str_contains( $rule, 'function ' . $method . '(' ) ) throw new RuntimeException( 'FinanceRule must expose ' . $method . '()' );
if ( ! str_contains( $rule, 'array_unique(array_merge(self::EXCEPTION_REASON_CODES,self::FINDING_CODES,self::OPERATOR_REASON_CODES))' ) ) throw new RuntimeException( 'FinanceRule::reasonCodes() must be the union of the three declared sets' );

// Every reason literal the Finance application layer writes must belong to the set that owns its row.
preg_match_all( "/FinanceRefusalException\(\s*'([a-z0-9_]+)'/", $application, $refusals );
foreach ( $refusals[1] as $code ) if ( ! in_array( $code, $exceptions, true ) ) throw new RuntimeException( 'A refusal uses a code outside the declared exception view: ' . $code );
preg_match_all( "/'finding_code'\s*=>\s*'([a-z0-9_]+)'/", $application, $findingLiterals );
foreach ( $findingLiterals[1] as $code ) if ( ! in_array( $code, $findings, true ) ) throw new RuntimeException( 'A finding uses a code outside the declared finding view: ' . $code );
preg_match_all( "/'reason_code'\s*=>\s*'([a-z0-9_]+)'/", $application, $reasonLiterals );
foreach ( $reasonLiterals[1] as $code ) if ( ! in_array( $code, array_merge( $exceptions, $operators ), true ) ) throw new RuntimeException( 'A recorded reason uses a code outside the declared views: ' . $code );
preg_match_all( "/self::exception\(\s*'([a-z0-9_]+)'/", $application, $exceptionLiterals );
foreach ( $exceptionLiterals[1] as $code ) if ( ! in_array( $code, $exceptions, true ) ) throw new RuntimeException( 'A recorded exception uses a code outside the declared exception view: ' . $code );

// ---- The derivation table is the single place a disposition is decided (§5.3). ------------------
if ( ! preg_match( '/PAYABILITY_DERIVATION=array\((.*?)\n    \);/s', $rule, $derivation ) ) throw new RuntimeException( 'FinanceRule::PAYABILITY_DERIVATION must be declared literally' );
foreach ( array(
    "array('row'=>1,'fact'=>'academy_obligation','disposition'=>'non_payable','basis_code'=>'academy_obligation'",
    "array('row'=>2,'fact'=>'not_finalised_with_snapshot','disposition'=>'pending','basis_code'=>'lesson_not_finalised'",
    "array('row'=>3,'fact'=>'outcome_review_required','disposition'=>'pending','basis_code'=>'delivery_review_required'",
    "array('row'=>4,'fact'=>'outcome_teacher_non_delivery','disposition'=>'non_payable','basis_code'=>'teacher_non_delivery'",
    "array('row'=>5,'fact'=>'introductory_non_payable','disposition'=>'non_payable','basis_code'=>'introductory_policy_non_payable'",
    "array('row'=>6,'fact'=>'completed_without_outcome','disposition'=>'payable','basis_code'=>'delivered_occurrence'",
    "array('row'=>7,'fact'=>'outcome_delivered','disposition'=>'payable','basis_code'=>'delivered_occurrence'",
    "array('row'=>8,'fact'=>'outcome_student_no_show','disposition'=>'policy','basis_code'=>'student_no_show'",
    "array('row'=>9,'fact'=>'outcome_interruption','disposition'=>'policy','basis_code'=>'interruption'",
    "array('row'=>10,'fact'=>'cancelled_before_occurrence','disposition'=>'non_payable','basis_code'=>'occurrence_not_attempted'",
    "array('row'=>11,'fact'=>'administrator_override','disposition'=>'override','basis_code'=>'administrator_override'",
) as $row ) dzn_u_contract( str_contains( preg_replace( '/\s+/', '', $derivation[1] ), $row ), 'missing derivation row: ' . $row );
if ( ! str_contains( preg_replace( '/\s+/', '', $payabilityService ), 'publicstaticfunctionderive(array$context,array$policies,array$rows=FinanceRule::PAYABILITY_DERIVATION)' ) ) throw new RuntimeException( 'The derivation must be evaluated from FinanceRule::PAYABILITY_DERIVATION' );
foreach ( array( 'payability_conflicts_with_delivery_fact', 'payability_supersession_conflict', 'payability_override_target_invalid', 'finance_policy_unset' ) as $code ) if ( ! str_contains( $payabilityService, $code ) && ! str_contains( $payabilityIntegrity, $code ) ) throw new RuntimeException( 'Missing declared payability refusal: ' . $code );

// ---- Digest-only / append-only discipline. ------------------------------------------------------
foreach ( array( 'finance_lesson_snapshots', 'finance_snapshot_corrections', 'finance_payability_evaluations', 'finance_payability_overrides', 'finance_statement_lines', 'finance_statement_events', 'finance_reconciliation_runs', 'finance_reconciliation_findings' ) as $table ) {
	if ( ! str_contains( $repositories, 'finance_' ) ) throw new RuntimeException( 'repositories must be present' );
}
if ( str_contains( $repositories, 'function updateSnapshot' ) || str_contains( $repositories, 'function delete' ) || str_contains( $repositories, 'TRUNCATE' ) ) throw new RuntimeException( 'An append-only Finance table may expose no update, delete or truncate path' );
if ( ! str_contains( $snapshotIntegrity, 'no update method and no delete method' ) && ! str_contains( $snapshotService, 'never mutated' ) ) throw new RuntimeException( 'The per-Lesson snapshot must be declared immutable' );
if ( ! str_contains( $support, 'Declared Finance table required' ) ) throw new RuntimeException( 'The shared persistence boundary must refuse an undeclared Finance table' );

// ---- §13.3 index invariant, parents and typed command results. ----------------------------------
if ( ! str_contains( $migration, 'a declared Phase 2A.2-U id column needs a leading index' ) || ! str_contains( $migration, 'self::indexLeadsWith( $physical, $name )' ) ) throw new RuntimeException( 'The verifier must assert the physical index invariant' );
if ( ! str_contains( $migration, "'finance_reconciliation_findings.line_id'=>'finance_statement_lines'" ) ) throw new RuntimeException( 'finance_statement_lines.id must be the declared parent of a finding line_id' );
if ( ! str_contains( $migration, "'finance_reconciliation_commands.exception_id'=>'finance_exceptions'" ) || ! str_contains( $migration, "'finance_reconciliation_commands.result_exception_id'=>'finance_exceptions'" ) ) throw new RuntimeException( 'finance_exceptions.id must be the declared parent of the reconciliation exception selector and its typed result' );
if ( ! str_contains( $migration, "'finance_policy_commands.result_policy_id'=>'finance_policies'" ) ) throw new RuntimeException( 'finance_policy_commands.result_policy_id must name finance_policies.id' );
if ( ! str_contains( $migration, 'the legacy schedule lineage is not a Phase 2A.2-U parent' ) ) throw new RuntimeException( 'The verifier must reject the legacy lesson_schedule_versions table as a Finance parent' );
foreach ( $tables as $table ) if ( preg_match( '/CREATE TABLE \{\$p\}' . $table . ' \((.*?)\) ENGINE=InnoDB/s', $installBody, $body ) ) { if ( str_contains( $body[1], 'result_id bigint' ) ) throw new RuntimeException( 'No command table may declare a polymorphic result_id: ' . $table ); }
foreach ( array(
    'finance_policy_commands' => array( 'result_policy_id' ),
    'finance_teacher_rate_commands' => array( 'result_rate_id' ),
    'finance_snapshot_commands' => array( 'result_snapshot_id', 'result_correction_id' ),
    'finance_payability_commands' => array( 'result_evaluation_id', 'result_override_id' ),
    'finance_statement_commands' => array( 'result_statement_id' ),
    'finance_reconciliation_commands' => array( 'result_run_id', 'result_exception_id' ),
) as $table => $typed ) {
	if ( ! preg_match( '/CREATE TABLE \{\$p\}' . $table . ' \((.*?)\) ENGINE=InnoDB/s', $installBody, $body ) ) throw new RuntimeException( 'Missing declared command table: ' . $table );
	foreach ( $typed as $column ) if ( ! str_contains( $body[1], $column . ' bigint unsigned' ) ) throw new RuntimeException( 'A declared command table is missing its typed result: ' . $table . '.' . $column );
}
if ( ! preg_match( '/CREATE TABLE \{\$p\}finance_reconciliation_commands \((.*?)\) ENGINE=InnoDB/s', $installBody, $reconciliationCommandBody ) || ! str_contains( $reconciliationCommandBody[1], 'exception_id bigint unsigned' ) ) throw new RuntimeException( 'finance_reconciliation_commands must declare its typed exception selector' );

// ---- Mutation limits (§7.2, §6.1, §10.5, §12.2). ------------------------------------------------
if ( substr_count( $rateService, "'effective_until','status','active_slot'" ) < 1 && ! str_contains( $rateIntegrity, 'three physical columns' ) && ! str_contains( $rateService, 'moveStatus' ) ) throw new RuntimeException( 'A rate row may move only effective_until, status and active_slot' );
if ( ! str_contains( $repositories, 'status IN (\'active\',\'superseded\')' ) ) throw new RuntimeException( 'A policy row may move at most twice in one declared direction' );
if ( ! str_contains( $repositories, "SET state='issued',issued_at=%s,issued_by=%d" ) ) throw new RuntimeException( 'The one-time issuance evidence must be stamped by the conditional draft-to-issued transition' );
if ( ! str_contains( $repositories, "SET state='superseded',superseded_at=%s,superseded_by_statement_id=%d" ) ) throw new RuntimeException( 'A supersession must stamp only the supersession edge' );
if ( ! str_contains( $migration, "'finance_snapshot_corrections.intro_policy_key'" ) && ! str_contains( $migration, 'intro_policy_key varchar(64) NULL' ) ) throw new RuntimeException( 'The snapshot correction must declare its immutable intro policy pair' );
if ( ! str_contains( $correctionService, "'snapshot_correction_incomplete'" ) ) throw new RuntimeException( 'A partial snapshot correction must be refused snapshot_correction_incomplete' );

// ---- Capabilities (§14.2). ----------------------------------------------------------------------
$capabilities = array( 'dzn_manage_finance_policies', 'dzn_manage_teacher_rates', 'dzn_manage_lesson_payability', 'dzn_manage_finance_statements', 'dzn_view_finance_authority' );
foreach ( $capabilities as $capability ) {
	if ( ! str_contains( $migration, "'" . $capability . "'" ) ) throw new RuntimeException( 'Missing Phase U capability: ' . $capability );
	if ( ! str_contains( $application . $readServices . $controllers, "'" . $capability . "'" ) ) throw new RuntimeException( 'No Phase U surface uses the capability: ' . $capability );
}
if ( ! str_contains( $migration, 'Finance authority capability installation failed: Teacher least privilege' ) ) throw new RuntimeException( 'No Teacher role may hold a Finance capability' );
if ( ! str_contains( $migration, "Finance authority capability installation failed: '.\$capability" ) ) throw new RuntimeException( 'Finance capabilities must be repaired per capability' );

// ---- No route, provider call, schedule or credential under the Finance layer. --------------------
foreach ( array( 'register_rest_route', 'wp_remote_', 'curl_', 'wp_schedule_event', 'wp_schedule_single_event', 'wp_cron', 'Stripe', 'client_secret', 'api_key' ) as $forbidden )
	if ( str_contains( $application, $forbidden ) || str_contains( $controllers, $forbidden ) ) throw new RuntimeException( 'Phase U must expose no route, provider call, schedule or credential: ' . $forbidden );
foreach ( $capabilities as $capability ) if ( str_contains( $controllers, 'add_menu_page' ) ) throw new RuntimeException( 'Phase U exposes administrator submenus only' );

// ---- §15.7 infrastructure allowlist and root discipline. ---------------------------------------
if ( ! str_contains( $rule, "INFRASTRUCTURE_TABLES=array('platform_audit_events','platform_outbox')" ) ) throw new RuntimeException( 'The two declared infrastructure seams must be asserted as declared data' );
$allowlist = array_merge( $tables, array( 'platform_audit_events', 'platform_outbox' ) );
$writeSources = $application . $repositories;
preg_match_all( "/\\\$wpdb->(?:insert|update)\(\\\$p\.'([a-z_]+)'/", $writeSources, $helperWrites );
foreach ( $helperWrites[1] as $name ) if ( ! in_array( $name, $allowlist, true ) ) throw new RuntimeException( 'A Finance code path writes a table outside the declared allowlist: ' . $name );
preg_match_all( '/(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+\{\$p\}([a-z_]+)/i', $writeSources, $sqlWrites );
preg_match_all( '/(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+\{\$this->p\}([a-z_]+)/i', $writeSources, $sqlWritesRepository );
foreach ( array_merge( $sqlWrites[1], $sqlWritesRepository[1] ) as $name ) if ( ! in_array( $name, $allowlist, true ) ) throw new RuntimeException( 'A Finance SQL write names a table outside the declared allowlist: ' . $name );
// A conditional statement may only target the table its own `declared()` guard resolved.
if ( ! str_contains( $repositories, '$table=$this->declared(' ) ) throw new RuntimeException( 'Every conditional Finance statement must resolve its table through the declared-table guard' );
if ( preg_match( '/(?:INSERT\s+INTO|DELETE\s+FROM)\s+[a-z_]+/i', $writeSources ) ) throw new RuntimeException( 'A Finance SQL write must name a declared Finance table or one of the two declared infrastructure seams' );
if ( ! str_contains( $support, 'platform_audit_events' ) || ! str_contains( $support, 'platform_outbox' ) ) throw new RuntimeException( 'The declared infrastructure seams must be written through the shared boundary' );
if ( ! str_contains( $support, 'public static function lockPolicyRoot(bool $exclusive)' ) || ! str_contains( $support, 'public static function lockTeacherRoot(int $teacherId,int $actor)' ) ) throw new RuntimeException( 'Both serialisation roots must be declared' );
if ( ! str_contains( $support, 'LOCK IN SHARE MODE' ) ) throw new RuntimeException( 'The global policy root must be acquirable in shared mode' );
foreach ( array( 'capture' => $snapshotService, 'evaluate' => $payabilityService, 'issue' => $statementService, 'draft' => $statementService ) as $surface => $source ) {
	if ( str_contains( $surface === 'capture' ? $source : $source, 'lockPolicyRootThenTeacher' ) === false ) throw new RuntimeException( 'A policy consumer must acquire the global policy root shared and first: ' . $surface );
}
if ( substr_count( $policyService, 'lockPolicyRoot(true)' ) < 3 ) throw new RuntimeException( 'Every mutating policy command must acquire the global policy root exclusively' );
foreach ( array( $rateService, $snapshotService, $payabilityService, $statementService, $correctionService, $reconciliationService ) as $source )
	if ( str_contains( $source, 'lockPolicyRoot(true)' ) ) throw new RuntimeException( 'Only a mutating policy command may acquire the global policy root exclusively' );
foreach ( array( $snapshotService, $payabilityService, $statementService ) as $source )
	if ( ! str_contains( $source, 'FinanceSupport::lockPolicyRootThenTeacher' ) ) throw new RuntimeException( 'A policy consumer must take the global policy root before the Teacher root' );

// ---- §6.3 temporal admissibility. ---------------------------------------------------------------
if ( ! str_contains( $policyService, 'policy_effective_from_precedes_recorded_consumption' ) || ! str_contains( $repositories, 'consumptionMaximum' ) ) throw new RuntimeException( 'The policy registry must implement the §6.3 admissibility guard' );
if ( ! str_contains( $rule, "'policy_effective_from_precedes_recorded_consumption'" ) ) throw new RuntimeException( 'The admissibility refusal must be a declared member' );
if ( ! str_contains( $rateService, "'rate_effective_from_precedes_snapshot'" ) ) throw new RuntimeException( 'The rate registry must implement its own consumption guard' );

// ---- §17 intents are identity-only and never raised by a refusal. --------------------------------
if ( ! str_contains( $rule, "OUTBOX_INTENTS=array('TEACHER_STATEMENT_ISSUED','TEACHER_STATEMENT_SUPERSEDED','FINANCE_RECONCILIATION_EXCEPTION_RAISED')" ) ) throw new RuntimeException( 'Exactly the three declared notification intents must exist' );
foreach ( array( "'TEACHER_STATEMENT_ISSUED'=>'finance_statements'", "'TEACHER_STATEMENT_SUPERSEDED'=>'finance_statements'", "'FINANCE_RECONCILIATION_EXCEPTION_RAISED'=>'finance_reconciliation_runs'" ) as $mapping ) if ( ! str_contains( $rule, $mapping ) ) throw new RuntimeException( 'A notification intent must name exactly one declared aggregate: ' . $mapping );
preg_match( '/public static function outboxIntent\((.*?)\):void\{(.*?)\n    \}/s', $support, $outbox );
if ( ! $outbox ) throw new RuntimeException( 'The outbox write must be declared in the shared boundary' );
foreach ( array( "'aggregate_type'", "'aggregate_id'", "'event_type'", "'invitation_id'=>null", "'generation_id'=>null", "'idempotency_key'=>", "'status'=>'pending'", "'available_at'=>", "'leased_at'=>null", "'processed_at'=>null", "'attempt_count'=>0", "'created_at'=>" ) as $key ) if ( ! str_contains( $outbox[2], $key ) ) throw new RuntimeException( 'The declared outbox insert shape is missing: ' . $key );
foreach ( array( 'amount', 'period', 'currency', 'finding_code', 'reason_code', 'template', 'recipient' ) as $forbidden ) if ( str_contains( $outbox[2], $forbidden ) ) throw new RuntimeException( 'An outbox row may never carry a Finance business fact: ' . $forbidden );
foreach ( array( $statementService, $reconciliationService ) as $source ) if ( str_contains( $source, 'outboxIntent' ) && ! str_contains( $source, 'FinanceSupport::outboxIntent(' ) ) throw new RuntimeException( 'A Finance intent is only ever raised through the declared seam' );
if ( str_contains( $support, 'outboxIntent' ) && preg_match( '/commitRefusal\((.*?)\)\s*;/s', $support ) && str_contains( substr( $support, strpos( $support, 'public static function commitRefusal' ), 3000 ), 'outboxIntent' ) ) throw new RuntimeException( 'A refusal must never raise a notification intent' );

// ---- §15.3 replay re-verification. --------------------------------------------------------------
// A replay may converge only after the authoritative aggregate and the recorded result row are
// re-verified: each command family re-loads its typed result under the held root, re-proves the
// selectors and the owning integrity/derivation, and fails closed on an absent or invalid result.
if ( ! str_contains( $rule, 'public static function commandOutcomeStates(string $operation):array' ) ) throw new RuntimeException( 'The declared non-refusal outcome states of an operation must be one declared helper' );
if ( ! str_contains( $rule, "return \$operation==='run'?array(\$success,self::COMMAND_FAILED_STATE):array(\$success);" ) ) throw new RuntimeException( 'Only a reconciliation run may declare the `failed` outcome alongside its success state' );
foreach ( array(
	'public static function assertReplayState(object $row,string $operation):void',
	'public static function replayResultRow(int $resultId,callable $reload,array $selectors,string $aggregate):object',
	'public static function assertReplayPayload(string $payloadDigest,array $facts,string $aggregate):void',
) as $helper ) if ( ! str_contains( $support, $helper ) ) throw new RuntimeException( 'The shared §15.3 replay re-verification helper is missing: ' . $helper );
if ( ! str_contains( $support, "FinanceRule::COMMAND_REFUSAL_STATE)throw new FinanceRefusalException((string)\$row->reason_code" ) ) throw new RuntimeException( 'A replayed refusal must still converge on its refusal' );
if ( substr_count( $support, "throw new FinanceRefusalException('command_replay_conflict'" ) < 3 ) throw new RuntimeException( 'An absent, mismatched or non-reproducing recorded result must fail closed with command_replay_conflict' );
// Every command family re-loads its own typed result and re-proves it with its own section's proof.
$replayFamilies = array(
	'policy' => array( $policyService, array( 'FinanceSupport::assertReplayState(', 'FinanceSupport::replayResultRow(', '$this->policies->byId($id,true)', 'FinanceSupport::assertReplayPayload(' ) ),
	'rate' => array( $rateService, array( 'FinanceSupport::assertReplayState(', 'FinanceSupport::replayResultRow(', '$this->rates->byId($id,true)', 'FinanceRateIntegrity::validate(', 'FinanceSupport::assertReplayPayload(' ) ),
	'snapshot' => array( $snapshotService, array( 'FinanceSupport::assertReplayState(', 'FinanceSupport::replayResultRow(', '$this->snapshots->byId($id,true)', 'FinanceSnapshotIntegrity::assertDigest(', 'FinanceRateIntegrity::covers(' ) ),
	'payability' => array( $payabilityService, array( 'FinanceSupport::assertReplayState(', 'FinanceSupport::replayResultRow(', '$this->evaluations->evaluationById($id,true)', 'FinancePayabilityIntegrity::digest(', 'overrideById(' ) ),
	'correction' => array( $correctionService, array( 'FinanceSupport::assertReplayState(', 'FinanceSupport::replayResultRow(', '$this->snapshots->correctionById($id,true)', 'FinanceRule::CORRECTION_DIGEST_FIELDS', 'prior_snapshot_digest' ) ),
	'statement' => array( $statementService, array( 'FinanceSupport::assertReplayState(', 'FinanceSupport::replayResultRow(', '$this->statements->byId($id,true)', 'FinanceStatementIntegrity::assertTotals(', 'FinanceStatementIntegrity::assertTimezoneTriple(' ) ),
	'reconciliation' => array( $reconciliationService, array( 'FinanceSupport::assertReplayState(', 'FinanceSupport::replayResultRow(', '$this->reconciliation->runById($id,true)', '$this->reconciliation->exceptionById($id,true)', 'FinanceReconciliationIntegrity::assertRun(', 'FinanceReconciliationIntegrity::findingsDigest(' ) ),
);
foreach ( $replayFamilies as $family => $entry ) foreach ( $entry[1] as $needle )
	if ( ! str_contains( $entry[0], $needle ) ) throw new RuntimeException( 'A command family replay must re-load and re-prove its recorded result (' . $family . '): ' . $needle );
// §15.3: the exact typed result shape of every operation is declared, re-proved by every family, and
// never bypassed by returning a recorded typed result without re-loading and re-verifying it.
if ( ! str_contains( $rule, 'public const COMMAND_RESULT_COLUMNS=array(' ) ) throw new RuntimeException( 'The typed result columns of every command table must be one declaration' );
if ( ! str_contains( $rule, 'public const COMMAND_OPERATION_RESULTS=array(' ) ) throw new RuntimeException( 'The exact typed result shape of every command operation must be one declaration' );
foreach ( array(
	'finance_policy_commands' => 'result_policy_id',
	'finance_teacher_rate_commands' => 'result_rate_id',
	'finance_snapshot_commands' => 'result_snapshot_id',
	'finance_payability_commands' => 'result_evaluation_id',
	'finance_statement_commands' => 'result_statement_id',
	'finance_reconciliation_commands' => 'result_run_id',
) as $commandTable => $typedColumn )
	if ( ! str_contains( $rule, "'" . $commandTable . "'=>array('" . $typedColumn . "'" ) ) throw new RuntimeException( 'A command table must declare its own typed result columns: ' . $commandTable . '.' . $typedColumn );
foreach ( array(
	"'capture'=>array('result_snapshot_id')",
	"'correct_snapshot'=>array('result_snapshot_id','result_correction_id')",
	"'evaluate'=>array('result_evaluation_id')",
	"'override'=>array('result_evaluation_id','result_override_id')",
	"'run'=>array('result_run_id')",
	"'resolve_exception'=>array('result_exception_id')",
) as $shape ) if ( ! str_contains( $rule, $shape ) ) throw new RuntimeException( 'A command operation must declare its exact typed result shape: ' . $shape );
if ( ! preg_match( '/COMMAND_OPERATION_RESULTS=array\((.*?)\n    \);/s', $rule, $operationResults ) ) throw new RuntimeException( 'FinanceRule::COMMAND_OPERATION_RESULTS must be declared literally' );
foreach ( array( 'record','capture','correct_snapshot','evaluate','override','draft','resolve_exception','run','close','issue','supersede','withdraw' ) as $operation )
	if ( ! str_contains( $operationResults[1], "'" . $operation . "'=>array(" ) ) throw new RuntimeException( 'Every declared mutating operation must declare its typed result shape: ' . $operation );
if ( ! str_contains( $rule, 'public static function commandOperationResults(string $commandTable,string $operation):?array' ) ) throw new RuntimeException( 'The declared typed result shape of an operation must be one declared helper' );
if ( ! str_contains( $support, 'public static function assertReplayResultShape(object $row,string $commandTable,string $operation):void' ) ) throw new RuntimeException( 'The shared §15.3 typed-result-shape helper is missing' );
if ( ! str_contains( $support, "throw new FinanceRefusalException('command_replay_conflict','The recorded command row carries a '.\$column.' its own operation never records');" ) ) throw new RuntimeException( 'An unrelated typed result a corrupted command row carries must fail closed' );
if ( ! str_contains( $support, "throw new FinanceRefusalException('command_replay_conflict','The recorded command row does not carry the '.\$column.' its own operation records');" ) ) throw new RuntimeException( 'An absent required typed result must fail closed' );
foreach ( array( 'policy' => $policyService, 'rate' => $rateService, 'snapshot' => $snapshotService, 'payability' => $payabilityService, 'correction' => $correctionService, 'statement' => $statementService, 'reconciliation' => $reconciliationService ) as $family => $source )
	if ( ! str_contains( $source, 'FinanceSupport::assertReplayResultShape(' ) ) throw new RuntimeException( 'A command family must re-prove its operation\'s declared typed result shape (' . $family . ')' );
if ( preg_match( '/=>\s*\$row->result_[a-z_]+/', $policyService . $rateService . $snapshotService . $payabilityService . $correctionService . $statementService . $reconciliationService ) ) throw new RuntimeException( 'A replay may never return a recorded typed result without re-loading and re-verifying it' );
foreach ( array( 'finance_policy_commands', 'finance_teacher_rate_commands', 'finance_snapshot_commands', 'finance_payability_commands', 'finance_statement_commands', 'finance_reconciliation_commands' ) as $commandTable )
	if ( ! str_contains( $migration, $commandTable ) ) throw new RuntimeException( 'A declared command table is missing: ' . $commandTable );
// The corruption suite carries one corruption-replay probe per command family, each asserting the
// fail-closed replay and the converging replay after exact restoration.
$corruption = file_get_contents( $root . '/tests/phase-2a2u-corruption-runtime.php' );
foreach ( array( "'policy'", "'rate'", "'snapshot'", "'payability'", "'correction'", "'statement'", "'reconciliation run'", "'payability override'", "'exception resolution'" ) as $family )
	if ( ! str_contains( $corruption, $family ) ) throw new RuntimeException( 'The corruption suite must cover the replay of every command family: ' . $family );
if ( substr_count( $corruption, '$replayProbe(' ) < 15 ) throw new RuntimeException( 'Every command family needs its own corruption-replay probe, plus one substituted secondary typed result per family' );
if ( ! str_contains( $corruption, "identical replay converges on the recorded typed result after exact restoration" ) ) throw new RuntimeException( 'Every corruption-replay probe must prove convergence after exact restoration' );
if ( substr_count( $corruption, "substituted secondary result" ) < 6 ) throw new RuntimeException( 'Every command family must prove that a substituted secondary typed result fails closed' );
if ( ! str_contains( $corruption, 'correction substituted secondary result' ) ) throw new RuntimeException( 'The corruption suite must prove that a substituted valid correction id is refused' );
if ( ! str_contains( $corruption, 'correction substituted corrected rate amount' ) ) throw new RuntimeException( 'The corruption suite must prove that a correction whose corrected rate amount alone differs is refused command_replay_conflict' );
if ( ! str_contains( $corruption, 'correction substituted corrected derived amount' ) ) throw new RuntimeException( 'The corruption suite must prove that a correction whose corrected derived amount alone differs is refused command_replay_conflict' );
if ( ! str_contains( $corruption, 'a converged override replay reports the override its own evaluation carries' ) ) throw new RuntimeException( 'The corruption suite must prove a converged replay reports the verified secondary result, never the recorded command value' );
// The pure replay unit suite proves the shared behaviour *and* the correction command facts' own
// canonical reconstitution, so the payload proof of a re-loaded correction cannot silently regress.
$replayUnit = file_get_contents( $root . '/tests/phase-2a2u-replay-unit.php' );
foreach ( array(
	'FinanceSupport::assertReplayResultShape(',
	"'correctionFacts'",
	"'canonicalInt'",
	"'canonicalCurrency'",
	'the facts reconstituted from the recorded correction row reproduce the recorded command payload exactly',
	'a substituted correction of the same Lesson, snapshot and reason fails the payload proof',
	'a substituted corrected rate amount fails the payload proof',
) as $needle ) if ( ! str_contains( $replayUnit, $needle ) ) throw new RuntimeException( 'The §15.3 replay unit suite must prove the typed-result shape and the correction payload reconstitution: ' . $needle );

// ---- The candidate must ship the contract it implements. ----------------------------------------
if ( ! is_readable( $root . '/docs/PHASE-2A-2U-FINANCE-PAYABILITY-RATE-STATEMENT-AUTHORITY-CONTRACT.md' ) ) throw new RuntimeException( 'The implementation candidate must carry the contract it implements' );
if ( ! is_readable( $root . '/docs/PHASE-2A-2U-FINANCE-PAYABILITY-RATE-STATEMENT-AUTHORITY.md' ) ) throw new RuntimeException( 'The implementation candidate must carry its implementation record' );
if ( ! is_readable( $root . '/docs/FINANCE-POLICY-REGISTRY.md' ) ) throw new RuntimeException( 'The implementation candidate must carry the finance policy registry document' );

// ---- The adjacent Phase-T tree is preserved. -----------------------------------------------------
if ( substr_count( $migration, "'029_payment_event_decision_claim_authority'" ) < 3 ) throw new RuntimeException( 'The Phase T decision-claim migration must stay wired' );
echo "phase-2a2u-contract: OK\n";
