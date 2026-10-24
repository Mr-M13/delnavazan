<?php
/** MariaDB regression for byte-exact OAuth scope policy under utf8mb4_unicode_ci. */
mysqli_report(MYSQLI_REPORT_OFF);
$config=array();
foreach(array('HOST','PORT','NAME','USER','PASSWORD') as $key){
    $value=getenv('DZN_OAUTH_SCOPE_TEST_DB_'.$key);
    if($value===false||$value==='')throw new RuntimeException('Missing disposable database setting DZN_OAUTH_SCOPE_TEST_DB_'.$key);
    $config[strtolower($key)]=$value;
}
if(!str_starts_with($config['name'],'dzn_oauth_scope_test'))throw new RuntimeException('Refusing a database outside the dzn_oauth_scope_test namespace');
$database=new mysqli($config['host'],$config['user'],$config['password'],$config['name'],(int)$config['port']);
if($database->connect_errno)throw new RuntimeException('Disposable MariaDB connection failed: '.$database->connect_error);
$database->set_charset('utf8mb4');

final class DznExactScopeWpdb {
    public string $prefix='oauth_scope_';
    public string $last_error='';
    public function __construct(private mysqli $database){}
    public function prepare(string $query,mixed ...$arguments):string{
        $offset=0;
        return (string)preg_replace_callback('/%%|%[dfs]/',function(array $match)use($arguments,&$offset):string{
            if($match[0]==='%%')return '%';
            if(!array_key_exists($offset,$arguments))throw new RuntimeException('Missing SQL prepare argument');
            $value=$arguments[$offset++];
            return match($match[0]){
                '%d'=>(string)(int)$value,
                '%f'=>(string)(float)$value,
                '%s'=>"'".$this->database->real_escape_string((string)$value)."'",
            };
        },$query);
    }
    public function query(string $sql):int|false{
        $this->last_error='';$result=$this->database->query($sql);
        if($result===false){$this->last_error=$this->database->error;return false;}
        if($result instanceof mysqli_result){$result->free();return 0;}
        return $this->database->affected_rows;
    }
    public function get_var(string $sql):mixed{
        $this->last_error='';$result=$this->database->query($sql);
        if(!$result instanceof mysqli_result){$this->last_error=$this->database->error;return null;}
        $row=$result->fetch_row();$result->free();return $row[0]??null;
    }
    public function get_row(string $sql):?object{
        $this->last_error='';$result=$this->database->query($sql);
        if(!$result instanceof mysqli_result){$this->last_error=$this->database->error;return null;}
        $row=$result->fetch_object();$result->free();return $row?:null;
    }
    public function rows(string $sql):array{
        $result=$this->database->query($sql);
        if(!$result instanceof mysqli_result)throw new RuntimeException('Fixture query failed: '.$this->database->error);
        $rows=$result->fetch_all(MYSQLI_ASSOC);$result->free();return $rows;
    }
    public function raw(string $sql):void{
        if($this->database->query($sql)===false)throw new RuntimeException('Fixture SQL failed: '.$this->database->error);
    }
}

$wpdb=new DznExactScopeWpdb($database);
$scope='https://www.googleapis.com/auth/calendar.events openid';
$fixtures=array(1=>$scope,2=>'https://www.googleapis.com/auth/Calendar.events openid',3=>'https://www.googleapis.com/auth/calendar.events',4=>null);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$tables=array('integration_credentials','provider_identity_mappings','integration_oauth_authorizations','integration_connections','retained_records');
try{
    foreach($tables as $table)$wpdb->raw('DROP TABLE IF EXISTS oauth_scope_dzn_'.$table);
    $wpdb->raw("CREATE TABLE oauth_scope_dzn_integration_connections (id bigint unsigned NOT NULL,teacher_id bigint unsigned NOT NULL,provider_code varchar(32) NOT NULL,connection_state varchar(16) NOT NULL,connection_version int unsigned NOT NULL,active_slot tinyint unsigned NULL,scope_snapshot varchar(255) NULL,identity_state varchar(16) NOT NULL,failure_reason_code varchar(64) NULL,disconnected_at datetime NULL,updated_at datetime NOT NULL,PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $wpdb->raw("CREATE TABLE oauth_scope_dzn_integration_credentials (id bigint unsigned NOT NULL AUTO_INCREMENT,connection_id bigint unsigned NOT NULL,state varchar(16) NOT NULL,quarantined_at datetime NULL,quarantine_reason_code varchar(64) NULL,PRIMARY KEY(id),KEY connection_id(connection_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $wpdb->raw("CREATE TABLE oauth_scope_dzn_provider_identity_mappings (id bigint unsigned NOT NULL AUTO_INCREMENT,connection_id bigint unsigned NOT NULL,mapping_state varchar(16) NOT NULL,active_slot tinyint unsigned NULL,mapping_version int unsigned NOT NULL,revoked_at datetime NULL,updated_at datetime NOT NULL,PRIMARY KEY(id),KEY connection_id(connection_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $wpdb->raw("CREATE TABLE oauth_scope_dzn_integration_oauth_authorizations (id bigint unsigned NOT NULL,provider_code varchar(32) NOT NULL,authorization_state varchar(16) NOT NULL,scope_snapshot varchar(255) NULL,consumed_at datetime NULL,consumption_result varchar(64) NULL,failure_reason_code varchar(64) NULL,PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $wpdb->raw("CREATE TABLE oauth_scope_dzn_retained_records (id bigint unsigned NOT NULL,note varchar(64) NOT NULL,PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $wpdb->raw("INSERT INTO oauth_scope_dzn_retained_records VALUES (1,'must remain')");
    foreach($fixtures as $id=>$storedScope){
        $scopeSql=$storedScope===null?'NULL':"'".$database->real_escape_string($storedScope)."'";
        $wpdb->raw("INSERT INTO oauth_scope_dzn_integration_connections VALUES ({$id},{$id},'google_calendar','connected',1,1,{$scopeSql},'verified',NULL,NULL,'2026-10-16 00:00:00')");
        $wpdb->raw("INSERT INTO oauth_scope_dzn_integration_credentials (connection_id,state) VALUES ({$id},'active')");
        $wpdb->raw("INSERT INTO oauth_scope_dzn_provider_identity_mappings (connection_id,mapping_state,active_slot,mapping_version,updated_at) VALUES ({$id},'verified',1,1,'2026-10-16 00:00:00')");
        $wpdb->raw("INSERT INTO oauth_scope_dzn_integration_oauth_authorizations (id,provider_code,authorization_state,scope_snapshot) VALUES ({$id},'google_calendar','issued',{$scopeSql})");
    }
    $collationMatch=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM oauth_scope_dzn_integration_connections WHERE scope_snapshot=%s',$scope));
    $assert($collationMatch===2,'Fixture must prove utf8mb4_unicode_ci equates exact and case-variant scopes.');
    require_once dirname(__DIR__).'/src/Core/Application/ProviderIntegrationRule.php';
    require_once dirname(__DIR__).'/src/Core/Infrastructure/Repository/TeacherOnboardingRepository.php';
    require_once dirname(__DIR__).'/src/Core/Infrastructure/Repository/BookingTeacherResponseRepository.php';
    require_once dirname(__DIR__).'/src/Core/Infrastructure/Migration/Migrator.php';
    $readiness=new Delnavazan\Platform\Core\Infrastructure\Repository\TeacherOnboardingRepository();
    $acceptance=new Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    foreach(array(1=>true,2=>false,3=>false,4=>false) as $teacherId=>$allowed){
        $assert($readiness->hasUsableGoogleConnection($teacherId)===$allowed,'Readiness exact-scope decision failed for fixture '.$teacherId);
        $assert(($acceptance->activeConnection($teacherId)!==null)===$allowed,'Acceptance exact-scope decision failed for fixture '.$teacherId);
    }
    $migration=new ReflectionMethod(Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::class,'quarantine_legacy_google_oauth_grants');
    $migration->setAccessible(true);$migration->invoke(null);
    $snapshotSql="SELECT c.id,c.connection_state,c.connection_version,c.active_slot,c.failure_reason_code,k.state credential_state,k.quarantine_reason_code,m.mapping_state,m.active_slot mapping_slot,m.mapping_version,a.authorization_state,a.consumption_result FROM oauth_scope_dzn_integration_connections c INNER JOIN oauth_scope_dzn_integration_credentials k ON k.connection_id=c.id INNER JOIN oauth_scope_dzn_provider_identity_mappings m ON m.connection_id=c.id INNER JOIN oauth_scope_dzn_integration_oauth_authorizations a ON a.id=c.id ORDER BY c.id";
    $rows=$wpdb->rows($snapshotSql);
    $assert($rows[0]['connection_state']==='connected'&&$rows[0]['credential_state']==='active'&&$rows[0]['mapping_state']==='verified'&&$rows[0]['authorization_state']==='issued','Exact grant must remain usable.');
    foreach(array_slice($rows,1) as $row){
        $assert($row['connection_state']==='disconnected'&&$row['active_slot']===null&&$row['connection_version']==='2','Non-exact connection retained authority.');
        $assert($row['credential_state']==='quarantined'&&$row['quarantine_reason_code']==='oauth_scope_reconsent_required','Non-exact credential was not quarantined.');
        $assert($row['mapping_state']==='revoked'&&$row['mapping_slot']===null&&$row['mapping_version']==='2','Non-exact identity mapping was not revoked.');
        $assert($row['authorization_state']==='rejected'&&$row['consumption_result']==='scope_reconsent_required','Non-exact pending authorization was not rejected.');
    }
    $before=json_encode($rows,JSON_THROW_ON_ERROR);$migration->invoke(null);$after=json_encode($wpdb->rows($snapshotSql),JSON_THROW_ON_ERROR);
    $assert($before===$after,'Repeated quarantine must be idempotent.');
    $assert($wpdb->get_var('SELECT note FROM oauth_scope_dzn_retained_records WHERE id=1')==='must remain','Unrelated retained record changed.');
    echo "OAuth scope exact-matching MariaDB regression passed.\n";
}finally{
    foreach($tables as $table)$wpdb->raw('DROP TABLE IF EXISTS oauth_scope_dzn_'.$table);
    $database->close();
}
