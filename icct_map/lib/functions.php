<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */
function icct_map_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function icct_map_current_user_id() {
    if (defined('SESS_USER_ID') && isset($_SESSION[SESS_USER_ID])) return (int)$_SESSION[SESS_USER_ID];
    return isset($_SESSION['sess_user_id']) ? (int)$_SESSION['sess_user_id'] : 0;
}
function icct_map_client_ip() { return isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 64) : ''; }
function icct_map_can_manage() { return function_exists('api_user_realm_auth') && api_user_realm_auth('icct_map_admin.php'); }
function icct_map_get_string($key, $default = '') { if (isset($_POST[$key])) return trim((string)$_POST[$key]); if (isset($_GET[$key])) return trim((string)$_GET[$key]); return $default; }
function icct_map_get_int($key, $default = 0) { if (isset($_POST[$key])) return (int)$_POST[$key]; if (isset($_GET[$key])) return (int)$_GET[$key]; return (int)$default; }
function icct_map_page_rows() { return max(10, min(200, (int)(isset($_SESSION['sess_rows']) ? $_SESSION['sess_rows'] : 30))); }
function icct_map_page_number() { return max(1, icct_map_get_int('page', 1)); }
function icct_map_disable_full_page_refresh($page = 'icct_map.php') {
    global $config;
    if (function_exists('set_page_refresh')) set_page_refresh(['seconds' => 999999, 'page' => $config['url_path'] . 'plugins/icct_map/' . $page, 'logout' => 'false']);
}
function icct_map_stop_browser_refresh_timer() { print '<script>try{if(typeof myRefresh!=="undefined"){clearTimeout(myRefresh);}}catch(e){}</script>'; }
function icct_map_audit($action, $entity_type, $entity_id, $details = []) {
    $json = is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    db_execute_prepared('INSERT INTO plugin_icct_map_audit (user_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?)', [icct_map_current_user_id(), substr((string)$action,0,64), substr((string)$entity_type,0,32), (int)$entity_id, $json === false ? '' : $json, icct_map_client_ip()]);
}
function icct_map_host_status($host) {
    if ((string)($host['disabled'] ?? '') === 'on') return 'disabled';
    $status = (int)($host['status'] ?? 0);
    $up = defined('HOST_UP') ? (int)HOST_UP : 3;
    $down = defined('HOST_DOWN') ? (int)HOST_DOWN : 1;
    $recovering = defined('HOST_RECOVERING') ? (int)HOST_RECOVERING : 2;
    $error = defined('HOST_ERROR') ? (int)HOST_ERROR : 4;
    if ($status === $up) return 'up';
    if ($status === $down) return 'down';
    if ($status === $recovering) return 'recovering';
    if ($status === $error) return 'error';
    return 'unknown';
}
function icct_map_site_health($hosts, $site_id = 0) {
    $counts = ['total'=>0,'up'=>0,'down'=>0,'recovering'=>0,'error'=>0,'unknown'=>0,'disabled'=>0];
    foreach ($hosts as $host) { $key = icct_map_host_status($host); $counts['total']++; if (!isset($counts[$key])) $key='unknown'; $counts[$key]++; }
    if ($counts['down'] > 0 || $counts['error'] > 0) { $key='down'; $label='Critical'; }
    elseif ($counts['recovering'] > 0) { $key='recovering'; $label='Recovering'; }
    elseif ($counts['total'] > 0 && $counts['up'] > 0 && ($counts['up'] + $counts['disabled']) === $counts['total']) { $key='up'; $label='Up'; }
    elseif ($counts['total'] === 0) { $key='unknown'; $label='No devices'; }
    elseif ($counts['disabled'] === $counts['total']) { $key='disabled'; $label='Disabled'; }
    else { $key='unknown'; $label='Unknown'; }

    $link = ['status_key'=>'not_configured','status_label'=>'Not configured'];
    if (function_exists('icct_map_external_site_link_health')) {
        $external = icct_map_external_site_link_health((int)$site_id);
        if (is_array($external)) {
            $allowed=['up','down','recovering','unknown','not_configured'];
            $lk=(string)($external['status_key'] ?? 'unknown'); if(!in_array($lk,$allowed,true)) $lk='unknown';
            $link=['status_key'=>$lk,'status_label'=>(string)($external['status_label'] ?? ucfirst($lk))];
        }
    }
    if ($link['status_key']==='down') { $key='down'; $label='Critical'; }
    elseif ($link['status_key']==='recovering' && $key==='up') { $key='recovering'; $label='Recovering'; }
    return ['status_key'=>$key,'status_label'=>$label,'counts'=>$counts,'link'=>$link];
}
function icct_map_valid_india_coordinate($lat,$lng) {
    if(!is_numeric($lat)||!is_numeric($lng)) return false; $lat=(float)$lat; $lng=(float)$lng;
    if($lat==0.0&&$lng==0.0) return false;
    return $lat>=5.0 && $lat<=38.8 && $lng>=66.0 && $lng<=99.5;
}
function icct_map_get_cacti_sites($valid_only=false) {
    /*
     * Cacti 1.2.30 native schema source of truth.
     * IMPORTANT: the core `sites` table does NOT contain a `disabled` column.
     * Keep this SELECT aligned with release/1.2.30 cacti.sql / sites.php.
     */
    $sites = db_fetch_assoc(
        'SELECT id,name,address1,address2,city,state,postal_code,country,timezone,latitude,longitude,zoom,alternate_id,notes
         FROM sites
         ORDER BY name'
    );

    if (!is_array($sites)) {
        $sites = [];
    }

    $hosts = db_fetch_assoc(
        "SELECT id,site_id,description,hostname,status,disabled,status_last_error
         FROM host
         WHERE deleted = ''
         ORDER BY site_id,description"
    );

    if (!is_array($hosts)) {
        $hosts = [];
    }

    $by_site = [];
    foreach ($hosts as $host) {
        $by_site[(int)$host['site_id']][] = $host;
    }

    $out = [];
    foreach ($sites as $site) {
        $site_id = (int)$site['id'];
        $site_hosts = $by_site[$site_id] ?? [];
        $site['coordinate_valid'] = icct_map_valid_india_coordinate($site['latitude'], $site['longitude']);

        if ($valid_only && !$site['coordinate_valid']) {
            continue;
        }

        $site['health'] = icct_map_site_health($site_hosts, $site_id);
        $site['host_count'] = count($site_hosts);
        $site['devices_url'] = 'host.php?site_id=' . $site_id;
        $site['site_url'] = 'sites.php?action=edit&id=' . $site_id;
        $out[] = $site;
    }

    return $out;
}

function icct_map_sites_snapshot() {
    $sites=icct_map_get_cacti_sites(false);$valid=[];$missing=[];$summary=['total_sites'=>count($sites),'mapped_sites'=>0,'missing_coordinates'=>0,'up'=>0,'down'=>0,'recovering'=>0,'unknown'=>0,'disabled'=>0];
    foreach($sites as $site){if(!$site['coordinate_valid']){$summary['missing_coordinates']++;$missing[]=['id'=>(int)$site['id'],'name'=>(string)$site['name']];continue;}$summary['mapped_sites']++;$key=(string)$site['health']['status_key'];if(!isset($summary[$key]))$key='unknown';$summary[$key]++;$valid[]=$site;}
    return ['sites'=>$valid,'missing'=>$missing,'summary'=>$summary,'refreshed_at'=>date('Y-m-d H:i:s')];
}
function icct_map_get_site($site_id){return db_fetch_row_prepared('SELECT * FROM sites WHERE id = ?',[(int)$site_id]);}
function icct_map_update_site_position($site_id,$lat,$lng,$source='gnss',$when=null,$audit=true){
    $site=icct_map_get_site($site_id);if(!$site)return['ok'=>false,'error'=>'Cacti site not found.'];if(!is_numeric($lat)||!is_numeric($lng))return['ok'=>false,'error'=>'Latitude/longitude must be numeric.'];$lat=(float)$lat;$lng=(float)$lng;if(!icct_map_valid_india_coordinate($lat,$lng))return['ok'=>false,'error'=>'Coordinates are outside the configured India operational extent.'];$when=$when?:date('Y-m-d H:i:s');
    db_execute_prepared('UPDATE sites SET latitude = ?, longitude = ? WHERE id = ?',[$lat,$lng,(int)$site_id]);
    if(read_config_option('icct_map_track_enabled')==='on')db_execute_prepared('INSERT INTO plugin_icct_map_site_positions (site_id,latitude,longitude,source,recorded_at) VALUES (?,?,?,?,?)',[(int)$site_id,$lat,$lng,substr((string)$source,0,32),$when]);
    set_config_option('time_last_change_site',time());set_config_option('time_last_change_site_device',time());if($audit)icct_map_audit('site.position','site',(int)$site_id,['lat'=>$lat,'lng'=>$lng,'source'=>$source,'at'=>$when]);return['ok'=>true];
}
function icct_map_runtime_status(){
    global $config; $base=$config['base_path'].'/plugins/icct_map';
    $files=[
      'Leaflet JavaScript'=>'vendor/leaflet/leaflet.js',
      'Leaflet CSS'=>'vendor/leaflet/leaflet.css',
      'India states GeoJSON'=>'data/india_states.geojson',
    ];
    $rows=[];$ready=true;
    foreach($files as $label=>$rel){$path=$base.'/'.$rel;$ok=is_file($path)&&is_readable($path)&&filesize($path)>100;$ready=$ready&&$ok;$rows[]=['label'=>$label,'relative'=>$rel,'ok'=>$ok,'bytes'=>$ok?filesize($path):0];}
    $feature_count=0;
    if($ready){
        $json=@json_decode(@file_get_contents($base.'/data/india_states.geojson'),true);
        $feature_count=(is_array($json)&&isset($json['features'])&&is_array($json['features']))?count($json['features']):0;
        if(!is_array($json)||($json['type']??'')!=='FeatureCollection'||$feature_count<30)$ready=false;
    }
    return ['ready'=>$ready,'files'=>$rows,'feature_count'=>$feature_count];
}
function icct_map_get_audit($id){return db_fetch_row_prepared('SELECT a.*,u.username FROM plugin_icct_map_audit a LEFT JOIN user_auth u ON u.id=a.user_id WHERE a.id=?',[(int)$id]);}
function icct_map_list_audit($filter,$entity,$rows,$page,&$total_rows){
    $where=' WHERE 1=1';$params=[];$filter=trim((string)$filter);$entity=trim((string)$entity);
    if($filter!==''){$where.=' AND (a.action LIKE ? OR a.entity_type LIKE ? OR a.details LIKE ? OR u.username LIKE ?)';$like='%'.$filter.'%';$params=[$like,$like,$like,$like];}
    if($entity!==''){$where.=' AND a.entity_type = ?';$params[]=$entity;}
    $total_rows=(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_map_audit a LEFT JOIN user_auth u ON u.id=a.user_id'.$where,$params);$offset=max(0,($page-1)*$rows);
    return db_fetch_assoc_prepared('SELECT a.*,u.username FROM plugin_icct_map_audit a LEFT JOIN user_auth u ON u.id=a.user_id'.$where.' ORDER BY a.id DESC LIMIT '.(int)$offset.','.(int)$rows,$params);
}
