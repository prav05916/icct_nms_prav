<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */

function icct_inv_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function icct_inv_user_id() {
    if (defined('SESS_USER_ID') && isset($_SESSION[SESS_USER_ID])) return (int)$_SESSION[SESS_USER_ID];
    return isset($_SESSION['sess_user_id']) ? (int)$_SESSION['sess_user_id'] : 0;
}

function icct_inv_client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 64) : '';
}

function icct_inv_audit($action, $entity_type, $entity_id, $details = []) {
    $json = is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    db_execute_prepared(
        'INSERT INTO plugin_icct_inv_audit (user_id, action, entity_type, entity_id, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
        [icct_inv_user_id(), substr((string)$action,0,64), substr((string)$entity_type,0,32), (int)$entity_id, $json === false ? '' : $json, icct_inv_client_ip()]
    );
}

function icct_inv_can_manage() {
    return function_exists('api_user_realm_auth') && api_user_realm_auth('device_edit.php');
}

function icct_inv_req($key, $default = '') {
    if (isset($_POST[$key])) return trim((string)$_POST[$key]);
    if (isset($_GET[$key])) return trim((string)$_GET[$key]);
    return $default;
}

function icct_inv_req_int($key, $default = 0) {
    if (isset($_POST[$key])) return (int)$_POST[$key];
    if (isset($_GET[$key])) return (int)$_GET[$key];
    return (int)$default;
}

function icct_inv_rows() {
    return max(10, min(200, (int)(isset($_SESSION['sess_rows']) ? $_SESSION['sess_rows'] : 30)));
}

function icct_inv_page() {
    return max(1, icct_inv_req_int('page', 1));
}

function icct_inv_status_info($row) {
    if (!$row) return ['key'=>'missing','label'=>'Missing'];
    if (($row['disabled'] ?? '') === 'on') return ['key'=>'disabled','label'=>'Disabled'];
    $status = isset($row['status']) ? (int)$row['status'] : null;
    if (defined('HOST_UP') && $status === (int)HOST_UP) return ['key'=>'up','label'=>'Up'];
    if (defined('HOST_DOWN') && $status === (int)HOST_DOWN) return ['key'=>'down','label'=>'Down'];
    if (defined('HOST_RECOVERING') && $status === (int)HOST_RECOVERING) return ['key'=>'recovering','label'=>'Recovering'];
    if (defined('HOST_ERROR') && $status === (int)HOST_ERROR) return ['key'=>'error','label'=>'Error'];
    return ['key'=>'unknown','label'=>'Unknown'];
}

function icct_inv_get_counts() {
    return [
        'types' => (int)db_fetch_cell("SELECT COUNT(*) FROM plugin_icct_inv_device_types WHERE enabled='on'"),
        'hosts' => (int)db_fetch_cell('SELECT COUNT(*) FROM host'),
        'mapped' => (int)db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_inv_device_meta WHERE device_type_id > 0'),
        'segments' => (int)db_fetch_cell("SELECT COUNT(*) FROM plugin_icct_inv_segments WHERE enabled='on'"),
        'oids' => (int)db_fetch_cell("SELECT COUNT(*) FROM plugin_icct_inv_oids WHERE enabled='on'"),
    ];
}

function icct_inv_get_device_types($enabled_only = false) {
    $where = $enabled_only ? " WHERE enabled='on'" : '';
    return db_fetch_assoc('SELECT * FROM plugin_icct_inv_device_types' . $where . ' ORDER BY bom_ref, item_description');
}

function icct_inv_get_segments($enabled_only = false) {
    $where = $enabled_only ? " WHERE enabled='on'" : '';
    return db_fetch_assoc('SELECT * FROM plugin_icct_inv_segments' . $where . ' ORDER BY name');
}

function icct_inv_get_type($id) {
    return db_fetch_row_prepared('SELECT * FROM plugin_icct_inv_device_types WHERE id = ?', [(int)$id]);
}

function icct_inv_get_segment($id) {
    return db_fetch_row_prepared('SELECT * FROM plugin_icct_inv_segments WHERE id = ?', [(int)$id]);
}

function icct_inv_get_host($id) {
    return db_fetch_row_prepared('SELECT * FROM host WHERE id = ?', [(int)$id]);
}

function icct_inv_get_device_meta($host_id) {
    return db_fetch_row_prepared('SELECT * FROM plugin_icct_inv_device_meta WHERE host_id = ?', [(int)$host_id]);
}

function icct_inv_list_actual_devices($filter, $rows, $page, &$total) {
    $filter = trim((string)$filter);
    $where = ' WHERE 1=1';
    $params = [];
    if ($filter !== '') {
        $where .= ' AND (h.description LIKE ? OR h.hostname LIKE ? OR dt.item_description LIKE ? OR s.name LIKE ?)';
        $like = '%' . $filter . '%';
        $params = [$like,$like,$like,$like];
    }
    $base = ' FROM host h LEFT JOIN plugin_icct_inv_device_meta m ON m.host_id=h.id LEFT JOIN plugin_icct_inv_device_types dt ON dt.id=m.device_type_id LEFT JOIN plugin_icct_inv_segments s ON s.id=m.segment_id ';
    $total = (int)db_fetch_cell_prepared('SELECT COUNT(*)' . $base . $where, $params);
    $offset = max(0, ($page-1)*$rows);
    return db_fetch_assoc_prepared(
        'SELECT h.id,h.description,h.hostname,h.status,h.disabled,h.snmp_version,h.snmp_sysName,h.snmp_sysLocation,m.device_type_id,m.segment_id,m.serial_number,m.mac_address,m.maintenance_due_date,m.serviceability,m.cross_launch_url,dt.item_description AS device_type_name,dt.management_protocol,dt.fcaps_summary,s.name AS segment_name' . $base . $where . ' ORDER BY h.description,h.hostname LIMIT ' . (int)$offset . ',' . (int)$rows,
        $params
    );
}

function icct_inv_list_device_types($filter, $rows, $page, &$total) {
    $where = ' WHERE 1=1';
    $params = [];
    $filter = trim((string)$filter);
    if ($filter !== '') {
        $where .= ' AND (bom_ref LIKE ? OR item_description LIKE ? OR make_name LIKE ? OR model LIKE ? OR management_protocol LIKE ?)';
        $like = '%' . $filter . '%';
        $params = [$like,$like,$like,$like,$like];
    }
    $total = (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_inv_device_types' . $where, $params);
    $offset = max(0, ($page-1)*$rows);
    return db_fetch_assoc_prepared('SELECT * FROM plugin_icct_inv_device_types' . $where . ' ORDER BY bom_ref,item_description LIMIT ' . (int)$offset . ',' . (int)$rows, $params);
}

function icct_inv_list_oids($filter, $rows, $page, &$total) {
    $where = ' WHERE 1=1';
    $params = [];
    $filter = trim((string)$filter);
    if ($filter !== '') {
        $where .= ' AND (o.parameter_name LIKE ? OR o.oid LIKE ? OR o.area LIKE ? OR dt.item_description LIKE ?)';
        $like = '%' . $filter . '%';
        $params = [$like,$like,$like,$like];
    }
    $base = ' FROM plugin_icct_inv_oids o LEFT JOIN plugin_icct_inv_device_types dt ON dt.id=o.device_type_id ';
    $total = (int)db_fetch_cell_prepared('SELECT COUNT(*)' . $base . $where, $params);
    $offset = max(0, ($page-1)*$rows);
    return db_fetch_assoc_prepared('SELECT o.*,dt.item_description AS device_type_name' . $base . $where . ' ORDER BY o.area,o.parameter_name LIMIT ' . (int)$offset . ',' . (int)$rows, $params);
}

function icct_inv_save_meta($host_id, $data) {
    $host = icct_inv_get_host($host_id);
    if (!$host) return ['ok'=>false,'error'=>'Cacti device not found.'];

    $device_type_id = (int)($data['device_type_id'] ?? 0);
    $segment_id = (int)($data['segment_id'] ?? 0);
    if ($device_type_id && !icct_inv_get_type($device_type_id)) return ['ok'=>false,'error'=>'Invalid device type.'];
    if ($segment_id && !icct_inv_get_segment($segment_id)) return ['ok'=>false,'error'=>'Invalid segment.'];

    $serviceability = (string)($data['serviceability'] ?? 'Unknown');
    $allowed = ['Unknown','Serviceable','Degraded','Maintenance','Unserviceable'];
    if (!in_array($serviceability,$allowed,true)) $serviceability='Unknown';
    $date = trim((string)($data['maintenance_due_date'] ?? ''));
    if ($date === '') $date = null;

    db_execute_prepared(
        "INSERT INTO plugin_icct_inv_device_meta
        (host_id,device_type_id,segment_id,serial_number,mac_address,maintenance_due_date,serviceability,cross_launch_url,notes,updated_by,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE device_type_id=VALUES(device_type_id),segment_id=VALUES(segment_id),serial_number=VALUES(serial_number),mac_address=VALUES(mac_address),maintenance_due_date=VALUES(maintenance_due_date),serviceability=VALUES(serviceability),cross_launch_url=VALUES(cross_launch_url),notes=VALUES(notes),updated_by=VALUES(updated_by),updated_at=NOW()",
        [(int)$host_id,$device_type_id,$segment_id,(string)($data['serial_number']??''),(string)($data['mac_address']??''),$date,$serviceability,(string)($data['cross_launch_url']??''),(string)($data['notes']??''),icct_inv_user_id()]
    );
    icct_inv_audit('device.metadata.update','host',(int)$host_id,$data);
    return ['ok'=>true];
}

function icct_inv_save_segment($id, $data) {
    $name = trim((string)($data['name']??''));
    if ($name==='') return ['ok'=>false,'error'=>'Segment name is required.'];
    $cidr = trim((string)($data['cidr']??''));
    $desc = trim((string)($data['description']??''));
    $enabled = !empty($data['enabled']) ? 'on' : '';
    $id=(int)$id;
    if ($id>0) {
        db_execute_prepared('UPDATE plugin_icct_inv_segments SET name=?,cidr=?,description=?,enabled=?,updated_at=NOW() WHERE id=?',[$name,$cidr,$desc,$enabled,$id]);
        icct_inv_audit('segment.update','segment',$id,$data);
        return ['ok'=>true,'id'=>$id];
    }
    db_execute_prepared('INSERT INTO plugin_icct_inv_segments (name,cidr,description,enabled,created_by,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())',[$name,$cidr,$desc,$enabled,icct_inv_user_id()]);
    $id=(int)db_fetch_insert_id();
    icct_inv_audit('segment.create','segment',$id,$data);
    return ['ok'=>true,'id'=>$id];
}

function icct_inv_delete_segment($id) {
    $id=(int)$id;
    $inuse=(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_inv_device_meta WHERE segment_id=?',[$id]);
    if ($inuse>0) return ['ok'=>false,'error'=>'Segment is assigned to actual devices. Remove those assignments first.'];
    db_execute_prepared('DELETE FROM plugin_icct_inv_segments WHERE id=?',[$id]);
    icct_inv_audit('segment.delete','segment',$id,[]);
    return ['ok'=>true];
}

function icct_inv_get_oid($id) {
    return db_fetch_row_prepared('SELECT * FROM plugin_icct_inv_oids WHERE id=?',[(int)$id]);
}

function icct_inv_save_oid($id,$data) {
    $parameter = trim((string)($data['parameter_name']??''));
    $oid = trim((string)($data['oid']??''));
    if ($parameter==='' || $oid==='') return ['ok'=>false,'error'=>'Parameter name and OID are required.'];
    if (!preg_match('/^\.?[0-9]+(?:\.[0-9]+)+$/',$oid)) return ['ok'=>false,'error'=>'Use a numeric OID, for example .1.3.6.1.2.1.1.3.0.'];
    $row=[
        (int)($data['device_type_id']??0),
        substr((string)($data['area']??'F'),0,16),
        $parameter,$oid,
        substr((string)($data['collection_method']??'poll'),0,32),
        substr((string)($data['data_type']??'string'),0,32),
        substr((string)($data['unit']??''),0,32),
        substr((string)($data['severity']??'info'),0,16),
        substr((string)($data['comparison']??''),0,24),
        substr((string)($data['threshold_value']??''),0,64),
        substr((string)($data['notes']??''),0,512),
        !empty($data['enabled'])?'on':'',
    ];
    $id=(int)$id;
    if ($id>0) {
        db_execute_prepared('UPDATE plugin_icct_inv_oids SET device_type_id=?,area=?,parameter_name=?,oid=?,collection_method=?,data_type=?,unit=?,severity=?,comparison=?,threshold_value=?,notes=?,enabled=?,updated_at=NOW() WHERE id=?',array_merge($row,[$id]));
        icct_inv_audit('oid.update','oid',$id,$data);
        return ['ok'=>true,'id'=>$id];
    }
    db_execute_prepared('INSERT INTO plugin_icct_inv_oids (device_type_id,area,parameter_name,oid,collection_method,data_type,unit,severity,comparison,threshold_value,notes,enabled,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())',array_merge($row,[icct_inv_user_id()]));
    $id=(int)db_fetch_insert_id();
    icct_inv_audit('oid.create','oid',$id,$data);
    return ['ok'=>true,'id'=>$id];
}

function icct_inv_delete_oid($id) {
    $id=(int)$id;
    db_execute_prepared('DELETE FROM plugin_icct_inv_oids WHERE id=?',[$id]);
    icct_inv_audit('oid.delete','oid',$id,[]);
    return ['ok'=>true];
}

function icct_inv_protocol_tokens($text) {
    $text = strtoupper((string)$text);
    $tokens=[];
    $checks=[
        'SNMP'=>'SNMP','SYSLOG'=>'Syslog','SSH'=>'SSH','TELNET'=>'Telnet','NTP'=>'NTP','TACACS+'=>'TACACS+',
        'CDP'=>'CDP','LLDP'=>'LLDP','WEBUI'=>'WebUI','HTTP'=>'HTTP/HTTPS','MODBUS'=>'MODBUS','RS-232'=>'RS-232','RS-485'=>'RS-485','RS-422'=>'RS-422','MIL-1553'=>'MIL-1553'
    ];
    foreach($checks as $needle=>$label){ if(strpos($text,$needle)!==false) $tokens[$label]=true; }
    return array_keys($tokens);
}

function icct_inv_audit_list($filter,$rows,$page,&$total) {
    $where=' WHERE 1=1'; $params=[]; $filter=trim((string)$filter);
    if($filter!==''){
        $where.=' AND (a.action LIKE ? OR a.entity_type LIKE ? OR a.details LIKE ? OR u.username LIKE ?)';
        $like='%'.$filter.'%'; $params=[$like,$like,$like,$like];
    }
    $base=' FROM plugin_icct_inv_audit a LEFT JOIN user_auth u ON u.id=a.user_id ';
    $total=(int)db_fetch_cell_prepared('SELECT COUNT(*)'.$base.$where,$params);
    $offset=max(0,($page-1)*$rows);
    return db_fetch_assoc_prepared('SELECT a.*,u.username'.$base.$where.' ORDER BY a.id DESC LIMIT '.(int)$offset.','.(int)$rows,$params);
}
