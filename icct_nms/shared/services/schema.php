<?php
/** Lifecycle-only schema creation. Ordinary requests never create or repair tables. */
function icct_nms_schema_install()
{
    $sql = file_get_contents(__DIR__ . '/../database/schema.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '' && !db_execute($statement)) {
            throw new RuntimeException(
                'ICCT NMS schema installation failed. Check Cacti database logs.'
            );
        }
    }
    foreach (['severity_codes','facility_codes','match_strings'] as $column) {
        if (!db_fetch_cell("SHOW COLUMNS FROM plugin_icct_nms_syslog_devices LIKE '".$column."'")) {
            if(!db_execute("ALTER TABLE plugin_icct_nms_syslog_devices ADD `".$column."` TEXT DEFAULT NULL"))throw new RuntimeException('Syslog filter upgrade failed.');
        }
    }
    icct_nms_schema_diagnostic_mtr_migration();
    icct_nms_schema_mtr_background_migration();
    icct_nms_schema_sites_migration();
    icct_nms_schema_rack_catalogue_migration();
    icct_nms_schema_missing_preset_racks();
    icct_nms_schema_syslog_availability_migration();
    icct_nms_schema_rack_unit_count_migration();
    icct_nms_schema_monitoring_migration();
    if (
        !db_execute_prepared(
            'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
            ['icct_nms_schema_version', '1.2.14']
        )
    ) {
        throw new RuntimeException('ICCT NMS schema version could not be saved.');
    }
}

/** Lifecycle migration from the retired grouping feature to native site ownership. */
function icct_nms_schema_sites_migration() {
    $legacy=(bool)db_fetch_cell("SHOW COLUMNS FROM plugin_icct_nms_racks LIKE 'node_id'");
    if(!$legacy)return;
    $groups=array_column(db_fetch_assoc('SELECT id,site_id FROM plugin_icct_nms_rack_nodes'), 'site_id','id');
    if(!db_fetch_cell("SHOW COLUMNS FROM plugin_icct_nms_racks LIKE 'site_id'"))icct_nms_schema_execute("ALTER TABLE plugin_icct_nms_racks ADD site_id INT UNSIGNED NOT NULL DEFAULT 0, ADD profile_id VARCHAR(16) NOT NULL DEFAULT ''");
    icct_nms_schema_execute("UPDATE plugin_icct_nms_racks r JOIN plugin_icct_nms_rack_nodes n ON n.id=r.node_id LEFT JOIN plugin_icct_nms_meta m ON m.meta_key=CONCAT('node_rack_profile_',n.id) SET r.site_id=n.site_id,r.profile_id=COALESCE(m.meta_value,'')");
    if(db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_racks WHERE site_id=0'))throw new RuntimeException('A legacy rack has no site. Restore its site before upgrading.');
    foreach(db_fetch_assoc("SELECT meta_key,meta_value FROM plugin_icct_nms_meta WHERE meta_key LIKE 'dashboard_user_%'") as $row){
        $value=json_decode($row['meta_value'],true);if(!is_array($value))continue;
        $convert=static function($dashboard)use($value,$groups){
            if(!isset($dashboard['site_id']))$dashboard['site_id']=($value['node_scope']??'')==='preset'?(int)($groups[(int)($dashboard['node_id']??0)]??0):(int)($dashboard['node_id']??0);
            unset($dashboard['node_id']);return $dashboard;
        };
        $value['dashboards']=array_map($convert,$value['dashboards']??[]);
        if(isset($value['deleted']['dashboard']))$value['deleted']['dashboard']=$convert($value['deleted']['dashboard']);
        unset($value['node_scope']);$value['site_scope']='native';
        if(!db_execute_prepared('UPDATE plugin_icct_nms_meta SET meta_value=? WHERE meta_key=?',[json_encode($value,JSON_THROW_ON_ERROR),$row['meta_key']]))throw new RuntimeException('Dashboard migration failed.');
    }
    icct_nms_schema_execute("DELETE FROM plugin_icct_nms_meta WHERE meta_key LIKE 'device_node_id_%' OR meta_key LIKE 'node_rack_profile_%'");
    icct_nms_schema_execute('ALTER TABLE plugin_icct_nms_racks DROP INDEX node_rack, DROP COLUMN node_id, ADD KEY site_profile(site_id,profile_id,rack_number)');
    icct_nms_schema_execute('DROP TABLE plugin_icct_nms_rack_nodes');
}
function icct_nms_schema_execute($sql){if(!db_execute($sql))throw new RuntimeException('Site/rack migration failed. Check the Cacti database log.');}

/** Repair presets saved by older releases even after the catalogue migration ran.
 * Only create missing physical racks; existing IDs, capacity and placements stay intact.
 * Called by installation/upgrade, never by ordinary page reads.
 */
function icct_nms_schema_missing_preset_racks() {
    $lock='icct_backend_racks_'.substr(hash('sha256',(string)db_fetch_cell('SELECT DATABASE()')),0,32);
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1)throw new RuntimeException('Rack upgrade is busy. Retry shortly.');
    try {
        icct_nms_schema_execute('START TRANSACTION');
        $raw=(string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_profiles']);
        $profiles=$raw!==''?json_decode($raw,true,512,JSON_THROW_ON_ERROR):[];
        foreach($profiles as $key=>$profile) {
            if(db_fetch_cell_prepared('SELECT id FROM plugin_icct_nms_racks WHERE profile_id=? LIMIT 1',[$key]))continue;
            if(!is_string($key)||!preg_match('/^[a-f0-9]{16}$/D',$key)||!is_array($profile)||!is_string($profile['name']??null)||trim($profile['name'])===''||strlen($profile['name'])>600||filter_var($profile['unit_count']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]])===false)throw new RuntimeException('A saved rack preset is invalid. Correct its name and capacity in Presets before upgrading.');
            if(!db_execute_prepared('INSERT INTO plugin_icct_nms_racks(site_id,profile_id,rack_number,name,unit_count,updated_by,updated_at) VALUES(0,?,1,?,?,0,NOW())',[$key,$profile['name'],(int)$profile['unit_count']]))throw new RuntimeException('Saved rack presets could not be synchronized. Check the Cacti database log.');
        }
        icct_nms_schema_execute('COMMIT');
    } catch(Throwable $failure) {db_execute('ROLLBACK');throw $failure;}
    finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}

/** Convert legacy site/group copies to one catalogue row per physical rack.
 * Keep occupied rack IDs, reservations and placements; remove only empty copies.
 */
function icct_nms_schema_rack_catalogue_migration() {
    if(db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_catalogue_v1']))return;
    $lock='icct_backend_racks_'.substr(hash('sha256',(string)db_fetch_cell('SELECT DATABASE()')),0,32);
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1)throw new RuntimeException('Rack upgrade is busy. Retry shortly.');
    $execute=static function($sql,$args=[]) { if(!db_execute_prepared($sql,$args))throw new RuntimeException('Rack catalogue upgrade failed.'); };
    try {
        $execute('START TRANSACTION');
        $raw=(string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_profiles']);
        $profiles=$raw!==''?json_decode($raw,true,512,JSON_THROW_ON_ERROR):[];
        $racks=db_fetch_assoc('SELECT * FROM plugin_icct_nms_racks ORDER BY id');
        $execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['rack_catalogue_backup',json_encode(['profiles'=>$profiles,'racks'=>$racks],JSON_THROW_ON_ERROR)]);
        $groups=[];
        foreach($racks as $rack)$groups[$rack['profile_id']][]=$rack;
        $names=array_map(static fn($p)=>strtolower($p['name']),$profiles);
        foreach($groups as $key=>$copies) {
            $occupied=[];
            foreach($copies as $rack) {
                $used=(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_nms_rack_devices WHERE rack_id=?',[$rack['id']]) || (int)db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_icct_nms_meta WHERE (meta_key LIKE 'rack_peripheral_%' AND meta_value=?) OR (meta_key=? AND meta_value!='[]')",[(string)$rack['id'],'rack_reserved_'.$rack['id']]);
                if($used)$occupied[]=$rack;
            }
            $keep=$occupied ?: [$copies[0]];
            $kept=array_column($keep,'id');
            foreach($copies as $rack)if(!in_array($rack['id'],$kept,true)) {
                $execute('DELETE FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_reserved_'.$rack['id']]);
                $execute('DELETE FROM plugin_icct_nms_racks WHERE id=?',[$rack['id']]);
            }
            foreach($keep as $number=>$rack) {
                $profileKey=$number===0&&isset($profiles[$key])?$key:bin2hex(random_bytes(8));
                $base=$profiles[$key]['name'] ?? $rack['name']; $name=$base;
                if($profileKey!==$key) {
                    $suffix=2;
                    while(in_array(strtolower($name),$names,true))$name=mb_substr($base,0,135).' '.$suffix++;
                    $names[] = strtolower($name);
                }
                $profiles[$profileKey]=['name'=>$name,'rack_count'=>1,'unit_count'=>(int)($profiles[$key]['unit_count'] ?? $rack['unit_count'])];
                $execute('UPDATE plugin_icct_nms_racks SET site_id=0,profile_id=?,rack_number=1,name=?,unit_count=? WHERE id=?',[$profileKey,$name,$profiles[$profileKey]['unit_count'],$rack['id']]);
            }
        }
        foreach($profiles as $key=>&$profile) {
            $profile['rack_count']=1;
            if(!db_fetch_cell_prepared('SELECT id FROM plugin_icct_nms_racks WHERE profile_id=? LIMIT 1',[$key]))$execute('INSERT INTO plugin_icct_nms_racks(site_id,profile_id,rack_number,name,unit_count,updated_by,updated_at) VALUES(0,?,1,?,?,0,NOW())',[$key,$profile['name'],$profile['unit_count']]);
        } unset($profile);
        $execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['rack_profiles',json_encode($profiles,JSON_THROW_ON_ERROR)]);
        $execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['rack_catalogue_v1','1']);
        $execute('COMMIT');
    } catch(Throwable $failure) {$execute('ROLLBACK');throw $failure;}
    finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}

/** Preserve legacy MTR cycle counts when giving MTR its own device setting. */
function icct_nms_schema_diagnostic_mtr_migration(){
    if(!db_fetch_cell("SHOW COLUMNS FROM plugin_icct_nms_diagnostic_profiles LIKE 'mtr_cycles'")){
        if(!db_execute('ALTER TABLE plugin_icct_nms_diagnostic_profiles ADD mtr_cycles TINYINT UNSIGNED NOT NULL DEFAULT 4')||!db_execute('UPDATE plugin_icct_nms_diagnostic_profiles SET mtr_cycles=ping_count'))throw new RuntimeException('MTR settings upgrade failed.');
    }
}

/** Background MTR settings are private to each device diagnostic assignment. */
function icct_nms_schema_mtr_background_migration() {
    foreach ([
        ['diagnostic_profiles','arp_interface',"VARCHAR(15) NOT NULL DEFAULT ''"],
        ['diagnostic_profiles','pathchar_hops','TINYINT UNSIGNED NOT NULL DEFAULT 20'],
        ['diagnostic_profiles','pathchar_timeout','SMALLINT UNSIGNED NOT NULL DEFAULT 60'],
        ['diagnostic_profiles','mtr_background','TINYINT UNSIGNED NOT NULL DEFAULT 0'],
        ['diagnostic_profiles','mtr_interval','SMALLINT UNSIGNED NOT NULL DEFAULT 300'],
        ['diagnostic_jobs','is_background','TINYINT UNSIGNED NOT NULL DEFAULT 0'],
    ] as [$table,$column,$definition]) {
        if (!db_fetch_cell("SHOW COLUMNS FROM plugin_icct_nms_".$table." LIKE '".$column."'")) {
            icct_nms_schema_execute("ALTER TABLE plugin_icct_nms_".$table." ADD ".$column." ".$definition);
        }
    }
}

/** Syslog-only additive upgrade; safe to rerun on an existing installation. */
function icct_nms_schema_syslog_availability_migration() {
    if (!db_execute('CREATE TABLE IF NOT EXISTS `plugin_icct_nms_syslog_availability_state` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `last_status` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `last_disabled` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `initialized_at` datetime NOT NULL,
  `last_changed_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`host_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;')) throw new RuntimeException('Syslog availability state migration failed.');
    if (!db_fetch_cell("SHOW COLUMNS FROM plugin_icct_nms_syslog_events LIKE 'event_origin'")) {
        if (!db_execute("ALTER TABLE plugin_icct_nms_syslog_events ADD event_origin VARCHAR(16) NOT NULL DEFAULT 'syslog' AFTER host_id")) {
            throw new RuntimeException('Syslog event-origin migration failed.');
        }
    }
    if (!db_fetch_cell("SHOW INDEX FROM plugin_icct_nms_syslog_events WHERE Key_name='origin_time'")) {
        if (!db_execute("ALTER TABLE plugin_icct_nms_syslog_events ADD KEY origin_time(event_origin,event_time)")) {
            throw new RuntimeException('Syslog event-origin index migration failed.');
        }
    }
}

/** Add rack_unit_count to device metadata; idempotent via SHOW COLUMNS guard. */
function icct_nms_schema_rack_unit_count_migration() {
    if (!db_fetch_cell("SHOW COLUMNS FROM plugin_icct_nms_device_metadata LIKE 'rack_unit_count'")) {
        if (!db_execute("ALTER TABLE plugin_icct_nms_device_metadata ADD rack_unit_count TINYINT UNSIGNED NOT NULL DEFAULT 1")) {
            throw new RuntimeException('Rack unit count migration failed.');
        }
    }
}

/** Create NTP and NetFlow monitoring tables; safe to rerun (CREATE TABLE IF NOT EXISTS). */
function icct_nms_schema_monitoring_migration() {
    $tables = [
        "CREATE TABLE IF NOT EXISTS `plugin_icct_nms_ntp_devices` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `enabled` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `target_address` varchar(255) NOT NULL,
  `port` smallint(5) unsigned NOT NULL DEFAULT 123,
  `version` tinyint(3) unsigned NOT NULL DEFAULT 4,
  `timeout_seconds` tinyint(3) unsigned NOT NULL DEFAULT 3,
  `poll_interval` int(10) unsigned NOT NULL DEFAULT 300,
  `maximum_offset_ms` decimal(12,3) NOT NULL DEFAULT 100.000,
  `maximum_jitter_ms` decimal(12,3) NOT NULL DEFAULT 50.000,
  `maximum_delay_ms` decimal(12,3) NOT NULL DEFAULT 250.000,
  `expected_source` varchar(255) NOT NULL DEFAULT '',
  `updated_by` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`host_id`),
  KEY `enabled_poll` (`enabled`,`poll_interval`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
        "CREATE TABLE IF NOT EXISTS `plugin_icct_nms_ntp_state` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'unknown',
  `reachable` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `synchronized` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `selected_source` varchar(255) NOT NULL DEFAULT '',
  `stratum` tinyint(3) unsigned DEFAULT NULL,
  `leap_indicator` tinyint(3) unsigned DEFAULT NULL,
  `reference_id` varchar(64) NOT NULL DEFAULT '',
  `offset_ms` decimal(14,3) DEFAULT NULL,
  `jitter_ms` decimal(14,3) DEFAULT NULL,
  `delay_ms` decimal(14,3) DEFAULT NULL,
  `dispersion_ms` decimal(14,3) DEFAULT NULL,
  `last_attempt` datetime DEFAULT NULL,
  `last_success` datetime DEFAULT NULL,
  `last_error` varchar(512) NOT NULL DEFAULT '',
  `sample_count` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`host_id`),
  KEY `status_updated` (`status`,`updated_at`),
  KEY `last_success` (`last_success`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
        "CREATE TABLE IF NOT EXISTS `plugin_icct_nms_ntp_peers` (
  `id`         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `host_id`    mediumint(8) unsigned NOT NULL,
  `peer_ip`    varchar(64)  NOT NULL DEFAULT '',
  `peer_name`  varchar(255) NOT NULL DEFAULT '',
  `stratum`    tinyint(3) unsigned DEFAULT NULL,
  `offset_ms`  float        DEFAULT NULL,
  `delay_ms`   float        DEFAULT NULL,
  `jitter_ms`  float        DEFAULT NULL,
  `reachable`  tinyint(1) unsigned NOT NULL DEFAULT 0,
  `selected`   tinyint(1) unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `host_id` (`host_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
        "CREATE TABLE IF NOT EXISTS `plugin_icct_nms_netflow_devices` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `enabled` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `exporter_address` varchar(255) NOT NULL,
  `version` tinyint(3) unsigned NOT NULL DEFAULT 9,
  `sampling_rate` int(10) unsigned NOT NULL DEFAULT 1,
  `active_timeout` int(10) unsigned NOT NULL DEFAULT 300,
  `inactive_timeout` int(10) unsigned NOT NULL DEFAULT 15,
  `updated_by` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`host_id`),
  UNIQUE KEY `exporter_address` (`exporter_address`),
  KEY `enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
        "CREATE TABLE IF NOT EXISTS `plugin_icct_nms_netflow_state` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'unknown',
  `last_attempt` datetime DEFAULT NULL,
  `last_success` datetime DEFAULT NULL,
  `last_data_at` datetime DEFAULT NULL,
  `last_error` varchar(512) NOT NULL DEFAULT '',
  `collector_path` varchar(512) NOT NULL DEFAULT '',
  `live_start` datetime DEFAULT NULL,
  `live_end` datetime DEFAULT NULL,
  `total_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `total_packets` bigint(20) unsigned NOT NULL DEFAULT 0,
  `total_flows` bigint(20) unsigned NOT NULL DEFAULT 0,
  `top_json` mediumtext NOT NULL,
  `anomaly_status` varchar(16) NOT NULL DEFAULT 'normal',
  `anomaly_reason` varchar(512) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`host_id`),
  KEY `status_updated` (`status`,`updated_at`),
  KEY `last_data_at` (`last_data_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
        "CREATE TABLE IF NOT EXISTS `plugin_icct_nms_netflow_top_talkers` (
  `id`         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `host_id`    mediumint(8) unsigned NOT NULL,
  `src_ip`     varchar(64)  NOT NULL DEFAULT '',
  `dst_ip`     varchar(64)  NOT NULL DEFAULT '',
  `protocol`   varchar(8)   NOT NULL DEFAULT '',
  `src_port`   smallint(5) unsigned DEFAULT NULL,
  `dst_port`   smallint(5) unsigned DEFAULT NULL,
  `bytes`      bigint(20) unsigned NOT NULL DEFAULT 0,
  `packets`    int(10) unsigned NOT NULL DEFAULT 0,
  `recorded_at` datetime    NOT NULL,
  `updated_at` datetime     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `host_id_time` (`host_id`,`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
        "CREATE TABLE IF NOT EXISTS `plugin_icct_nms_netflow_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `host_id` mediumint(8) unsigned NOT NULL,
  `flow_version` tinyint(3) unsigned NOT NULL DEFAULT 9,
  `src_ip` varchar(64) NOT NULL DEFAULT '',
  `dst_ip` varchar(64) NOT NULL DEFAULT '',
  `src_port` smallint(5) unsigned DEFAULT NULL,
  `dst_port` smallint(5) unsigned DEFAULT NULL,
  `protocol` varchar(16) NOT NULL DEFAULT 'TCP',
  `app_name` varchar(32) NOT NULL DEFAULT 'Other',
  `network_type` varchar(8) NOT NULL DEFAULT 'lan',
  `bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `packets` int(10) unsigned NOT NULL DEFAULT 0,
  `tcp_flags` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `tos` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `recorded_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `host_recorded` (`host_id`,`recorded_at`),
  KEY `host_net_time` (`host_id`,`network_type`,`recorded_at`),
  KEY `host_app_time` (`host_id`,`app_name`,`recorded_at`),
  KEY `host_proto_time` (`host_id`,`protocol`,`recorded_at`),
  KEY `host_src_time` (`host_id`,`src_ip`,`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
        "CREATE TABLE IF NOT EXISTS `plugin_icct_nms_netflow_anomalies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `host_id` mediumint(8) unsigned NOT NULL,
  `anomaly_type` varchar(32) NOT NULL DEFAULT '',
  `severity` varchar(16) NOT NULL DEFAULT 'warning',
  `source_ip` varchar(64) NOT NULL DEFAULT '',
  `target_ip` varchar(64) NOT NULL DEFAULT '',
  `target_port` smallint(5) unsigned DEFAULT NULL,
  `description` varchar(512) NOT NULL DEFAULT '',
  `metric_value` varchar(64) NOT NULL DEFAULT '',
  `detected_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `host_detected` (`host_id`,`detected_at`),
  KEY `type_detected` (`anomaly_type`,`detected_at`),
  KEY `severity_detected` (`severity`,`detected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
        "CREATE TABLE IF NOT EXISTS `plugin_icct_nms_ntp_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `host_id` mediumint(8) unsigned NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'unknown',
  `offset_ms` decimal(14,3) DEFAULT NULL,
  `jitter_ms` decimal(14,3) DEFAULT NULL,
  `delay_ms` decimal(14,3) DEFAULT NULL,
  `dispersion_ms` decimal(14,3) DEFAULT NULL,
  `stratum` tinyint(3) unsigned DEFAULT NULL,
  `reference_id` varchar(64) NOT NULL DEFAULT '',
  `recorded_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `host_recorded` (`host_id`,`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
    ];
    foreach ($tables as $ddl) {
        if (!db_execute($ddl)) {
            throw new RuntimeException('NTP/NetFlow monitoring schema migration failed.');
        }
    }
    $currentFiles = (string)db_fetch_cell_prepared("SELECT file FROM plugin_realms WHERE plugin='icct_nms' AND display='View ICCT NMS Inventory'");
    if ($currentFiles !== '' && !str_contains($currentFiles, 'ntp.php')) {
        $newFiles = $currentFiles . ',ntp.php,netflow.php';
        db_execute_prepared("UPDATE plugin_realms SET file=? WHERE plugin='icct_nms' AND display='View ICCT NMS Inventory'", [$newFiles]);
    }
}
