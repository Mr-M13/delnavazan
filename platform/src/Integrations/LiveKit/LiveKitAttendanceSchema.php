<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Additive durable storage for the LiveKit attendance adapter.
 *
 * This is deliberately a feature-local schema boundary: it installs only the three correlation
 * tables the adapter needs and never advances the platform-wide schema option, so an unrelated,
 * in-flight migration can never be activated by enabling or deploying this adapter. It performs no
 * backfill, writes no attendance evidence, creates no canonical Lesson/Delivery/Finance row and
 * stores no raw webhook payload, token, secret or contact value.
 */
final class LiveKitAttendanceSchema {
    public const VERSION='livekit_attendance_correlation_v1';
    public const OPTION='dzn_livekit_attendance_schema_version';
    private const LOCK='dzn_livekit_attendance_schema_lock';

    public static function maybeInstall():void{
        if((string)get_option(self::OPTION,'')===self::VERSION){
            try{
                self::verify();
                return;
            }catch(\Throwable $error){
                // A version marker without a complete schema must self-repair rather than trust it.
            }
        }
        $token=wp_generate_password(24,false,false);
        $current=get_option(self::LOCK,array());
        if(is_array($current)&&(int)($current['at']??0)>time()-300)return;
        update_option(self::LOCK,array('token'=>$token,'at'=>time()),false);
        try{
            self::install();
            self::verify();
            update_option(self::OPTION,self::VERSION,false);
            delete_option(self::LOCK);
        }catch(\Throwable $error){
            $current=get_option(self::LOCK,array());
            if(is_array($current)&&(string)($current['token']??'')===$token)delete_option(self::LOCK);
            do_action('dzn_livekit_attendance_schema_failed',get_class($error),(string)$error->getMessage());
            throw $error;
        }
    }

    public static function install():void{
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $p=$wpdb->prefix.'dzn_';
        $c=$wpdb->get_charset_collate();
        $tables=array(
            "CREATE TABLE {$p}livekit_attendance_rooms (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                uid char(26) NOT NULL,
                room_name varchar(128) NOT NULL,
                lesson_id bigint unsigned NOT NULL,
                lesson_uid char(26) NOT NULL,
                schedule_version_id bigint unsigned NOT NULL,
                schedule_uid char(26) NOT NULL,
                enrolment_id bigint unsigned NOT NULL,
                term_id bigint unsigned NOT NULL,
                teacher_id bigint unsigned NOT NULL,
                student_id bigint unsigned NOT NULL,
                teacher_identity_digest char(64) NOT NULL,
                student_identity_digest char(64) NOT NULL,
                window_start_utc datetime NOT NULL,
                window_end_utc datetime NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY(id),
                UNIQUE KEY uid(uid),
                UNIQUE KEY room_name(room_name),
                KEY lesson_schedule(lesson_id,schedule_version_id),
                KEY window_end(window_end_utc)
            ) ENGINE=InnoDB $c",
            "CREATE TABLE {$p}livekit_attendance_events (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                uid char(26) NOT NULL,
                room_id bigint unsigned NOT NULL,
                event_kind varchar(24) NOT NULL,
                event_digest char(64) NOT NULL,
                connection_digest char(64) NULL,
                identity_digest char(64) NULL,
                participant_role varchar(16) NULL,
                provider_at datetime NULL,
                received_at datetime NOT NULL,
                state varchar(24) NOT NULL,
                reason_code varchar(64) NULL,
                connection_id bigint unsigned NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY(id),
                UNIQUE KEY uid(uid),
                UNIQUE KEY event_digest(event_digest),
                KEY room_kind(room_id,event_kind),
                KEY connection(connection_id),
                KEY received(received_at)
            ) ENGINE=InnoDB $c",
            "CREATE TABLE {$p}livekit_attendance_connections (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                uid char(26) NOT NULL,
                room_id bigint unsigned NOT NULL,
                lesson_id bigint unsigned NOT NULL,
                schedule_version_id bigint unsigned NOT NULL,
                participant_role varchar(16) NOT NULL,
                participant_id bigint unsigned NOT NULL,
                identity_value varchar(128) NOT NULL,
                identity_digest char(64) NOT NULL,
                connection_digest char(64) NOT NULL,
                room_sid_digest char(64) NULL,
                join_event_id bigint unsigned NULL,
                join_event_digest char(64) NULL,
                join_at_utc datetime NULL,
                join_received_at datetime NULL,
                leave_event_id bigint unsigned NULL,
                leave_event_digest char(64) NULL,
                leave_at_utc datetime NULL,
                leave_received_at datetime NULL,
                state varchar(24) NOT NULL,
                closed_ingest_state varchar(24) NOT NULL,
                closed_ingest_reason varchar(64) NULL,
                closed_evidence_id bigint unsigned NULL,
                open_ingest_state varchar(24) NOT NULL,
                open_ingest_reason varchar(64) NULL,
                open_evidence_id bigint unsigned NULL,
                connection_version int unsigned NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY(id),
                UNIQUE KEY uid(uid),
                UNIQUE KEY connection_key(room_id,identity_digest,connection_digest),
                UNIQUE KEY join_event(join_event_digest),
                UNIQUE KEY leave_event(leave_event_digest),
                KEY room_state(room_id,state),
                KEY lesson_role(lesson_id,schedule_version_id,participant_role),
                KEY closed_ingest(closed_ingest_state),
                KEY open_ingest(open_ingest_state)
            ) ENGINE=InnoDB $c",
        );
        foreach($tables as$sql){dbDelta(self::dbdelta_sql($sql));if($wpdb->last_error!=='')throw new \RuntimeException('LiveKit attendance schema install failed: '.$wpdb->last_error);}
    }

    public static function verify():void{
        global $wpdb;
        $p=$wpdb->prefix.'dzn_';
        $spec=array(
            'livekit_attendance_rooms'=>array('uid','room_name','lesson_id','lesson_uid','schedule_version_id','schedule_uid','enrolment_id','term_id','teacher_id','student_id','teacher_identity_digest','student_identity_digest','window_start_utc','window_end_utc','created_at','updated_at'),
            'livekit_attendance_events'=>array('uid','room_id','event_kind','event_digest','connection_digest','identity_digest','participant_role','provider_at','received_at','state','reason_code','connection_id','created_at'),
            'livekit_attendance_connections'=>array('uid','room_id','lesson_id','schedule_version_id','participant_role','participant_id','identity_value','identity_digest','connection_digest','room_sid_digest','join_event_id','join_event_digest','join_at_utc','join_received_at','leave_event_id','leave_event_digest','leave_at_utc','leave_received_at','state','closed_ingest_state','closed_ingest_reason','closed_evidence_id','open_ingest_state','open_ingest_reason','open_evidence_id','connection_version','created_at','updated_at'),
        );
        foreach($spec as$table=>$columns){
            $name=$p.$table;
            if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$name))!==$name)throw new \RuntimeException('LiveKit attendance schema verification failed: missing '.$table);
            if(strcasecmp((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$name)),'InnoDB')!==0)throw new \RuntimeException('LiveKit attendance schema verification failed: '.$table.' must use InnoDB');
            foreach($columns as$column)if(!$wpdb->get_row("SHOW COLUMNS FROM {$name} LIKE '{$column}'"))throw new \RuntimeException('LiveKit attendance schema verification failed: missing '.$table.'.'.$column);
            foreach(array('payload','token','secret','email','phone','contact','raw_body')as$forbidden)if($wpdb->get_row("SHOW COLUMNS FROM {$name} LIKE '%{$forbidden}%'"))throw new \RuntimeException('LiveKit attendance schema verification failed: '.$table.' must not store '.$forbidden);
        }
        foreach(array(
            array('livekit_attendance_rooms','room_name',true,array('room_name')),
            array('livekit_attendance_events','event_digest',true,array('event_digest')),
            array('livekit_attendance_connections','connection_key',true,array('room_id','identity_digest','connection_digest')),
            array('livekit_attendance_connections','join_event',true,array('join_event_digest')),
            array('livekit_attendance_connections','leave_event',true,array('leave_event_digest')),
        )as$index)if(!self::hasIndexColumns($p.$index[0],$index[1],$index[2],$index[3]))throw new \RuntimeException('LiveKit attendance schema verification failed: index '.$index[0].'.'.$index[1]);
        foreach(array(array('livekit_attendance_events','event_digest'),array('livekit_attendance_connections','identity_digest'),array('livekit_attendance_connections','connection_digest'))as$shape){
            $row=$wpdb->get_row("SHOW COLUMNS FROM {$p}{$shape[0]} LIKE '{$shape[1]}'");
            if(!$row||strtolower((string)$row->Type)!=='char(64)'||$row->Null!=='NO')throw new \RuntimeException('LiveKit attendance schema verification failed: digest shape '.$shape[0].'.'.$shape[1]);
        }
    }

    private static function hasIndexColumns(string $table,string $index,bool $unique,array $columns):bool{
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name=%s",$index));
        if(!$rows)return false;
        usort($rows,static fn($a,$b)=>(int)$a->Seq_in_index<=>(int)$b->Seq_in_index);
        if($unique&&(int)$rows[0]->Non_unique!==0)return false;
        return array_map(static fn($row)=>$row->Column_name,$rows)===$columns;
    }

    private static function dbdelta_sql(string $sql):string{
        $open=strpos($sql,'(');$engine=strripos($sql,') ENGINE=');
        if($open===false||$engine===false||$engine<=$open)return $sql;
        $head=substr($sql,0,$open+1);$body=substr($sql,$open+1,$engine-$open-1);$tail=substr($sql,$engine);
        $parts=array();$buffer='';$depth=0;$length=strlen($body);
        for($i=0;$i<$length;$i++){
            $char=$body[$i];
            if($char==='(')$depth++;
            elseif($char===')')$depth--;
            if($char===','&&$depth===0){$parts[]=trim($buffer);$buffer='';continue;}
            $buffer.=$char;
        }
        if(trim($buffer)!=='')$parts[]=trim($buffer);
        foreach($parts as&$part){
            if(preg_match('/^PRIMARY\s+KEY\s*\((.+)\)$/i',$part,$match))$part='PRIMARY KEY  ('.$match[1].')';
            elseif(preg_match('/^UNIQUE\s+KEY\s+([^\s(]+)\s*\((.+)\)$/i',$part,$match))$part='UNIQUE KEY '.$match[1].' ('.$match[2].')';
            elseif(preg_match('/^KEY\s+([^\s(]+)\s*\((.+)\)$/i',$part,$match))$part='KEY '.$match[1].' ('.$match[2].')';
        }
        unset($part);
        return $head."\n\t\t\t\t".implode(",\n\t\t\t\t",$parts)."\n\t\t\t".$tail;
    }
}
