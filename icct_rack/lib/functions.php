<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */

function icct_rack_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function icct_rack_current_user_id() {
    if (defined('SESS_USER_ID') && isset($_SESSION[SESS_USER_ID])) {
        return (int)$_SESSION[SESS_USER_ID];
    }
    return isset($_SESSION['sess_user_id']) ? (int)$_SESSION['sess_user_id'] : 0;
}

function icct_rack_client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 64) : '';
}

function icct_rack_audit($action, $entity_type, $entity_id, $details = []) {
    $json = is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    db_execute_prepared(
        'INSERT INTO plugin_icct_rack_audit (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)',
        [icct_rack_current_user_id(), substr((string)$action, 0, 64), substr((string)$entity_type, 0, 32), (int)$entity_id, $json === false ? '' : $json, icct_rack_client_ip()]
    );
}

function icct_rack_can_manage() {
    return function_exists('api_user_realm_auth') && api_user_realm_auth('icct_rack_admin.php');
}

function icct_rack_disable_full_page_refresh($page) {
    global $config;
    if (function_exists('set_page_refresh')) {
        set_page_refresh([
            'seconds' => 999999,
            'page'    => $config['url_path'] . 'plugins/icct_rack/' . $page,
            'logout'  => 'false',
        ]);
    }
}

function icct_rack_stop_browser_refresh_timer() {
    print '<script>try{if(typeof myRefresh!=="undefined"){clearTimeout(myRefresh);}}catch(e){}</script>';
}

function icct_rack_request_string($key, $default = '') {
    if (isset($_POST[$key])) return trim((string)$_POST[$key]);
    if (isset($_GET[$key])) return trim((string)$_GET[$key]);
    return $default;
}

function icct_rack_request_int($key, $default = 0) {
    if (isset($_POST[$key])) return (int)$_POST[$key];
    if (isset($_GET[$key])) return (int)$_GET[$key];
    return (int)$default;
}

function icct_rack_page_rows() {
    return max(10, min(200, (int)(isset($_SESSION['sess_rows']) ? $_SESSION['sess_rows'] : 30)));
}

function icct_rack_page_number() {
    return max(1, icct_rack_request_int('page', 1));
}

function icct_rack_raise_errors($key, $errors) {
    raise_message($key, is_array($errors) ? implode(' ', $errors) : (string)$errors, MESSAGE_LEVEL_ERROR);
}

function icct_rack_generate_code($name, $rack_id = 0) {
    $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', trim((string)$name)));
    $slug = trim($slug, '-');
    if ($slug === '') $slug = 'RACK';
    $slug = substr($slug, 0, 40);
    $candidate = $slug;
    $counter = 1;
    while ((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_rack_racks WHERE code = ? AND id <> ?', [$candidate, (int)$rack_id]) > 0) {
        $counter++;
        $candidate = substr($slug, 0, 34) . '-' . $counter;
    }
    return $candidate;
}

function icct_rack_get_racks($enabled_only = false) {
    $where = $enabled_only ? " WHERE enabled = 'on'" : '';
    return db_fetch_assoc("SELECT * FROM plugin_icct_rack_racks{$where} ORDER BY id");
}

function icct_rack_get_rack($rack_id) {
    return db_fetch_row_prepared('SELECT * FROM plugin_icct_rack_racks WHERE id = ?', [(int)$rack_id]);
}

function icct_rack_get_host($host_id) {
    return db_fetch_row_prepared('SELECT * FROM host WHERE id = ?', [(int)$host_id]);
}

function icct_rack_get_placement($placement_id) {
    return db_fetch_row_prepared(
        'SELECT p.*, h.description AS host_description, h.hostname AS host_hostname, h.status AS host_status, h.disabled AS host_disabled
         FROM plugin_icct_rack_placements p
         LEFT JOIN host h ON h.id = p.host_id
         WHERE p.id = ?',
        [(int)$placement_id]
    );
}

function icct_rack_get_placements($rack_id) {
    return db_fetch_assoc_prepared(
        'SELECT p.*, h.description AS host_description, h.hostname AS host_hostname, h.status AS host_status, h.disabled AS host_disabled
         FROM plugin_icct_rack_placements p
         LEFT JOIN host h ON h.id = p.host_id
         WHERE p.rack_id = ?
         ORDER BY p.start_u DESC, p.id',
        [(int)$rack_id]
    );
}

function icct_rack_get_peripheral_placement($id) {
    return db_fetch_row_prepared('SELECT * FROM plugin_icct_rack_peripheral_placements WHERE id = ?', [(int)$id]);
}

function icct_rack_get_peripheral_placements($rack_id) {
    return db_fetch_assoc_prepared(
        'SELECT * FROM plugin_icct_rack_peripheral_placements WHERE rack_id = ? ORDER BY start_u DESC, id',
        [(int)$rack_id]
    );
}

function icct_rack_get_racks_with_items($enabled_only = true) {
    $racks = icct_rack_get_racks($enabled_only);
    foreach ($racks as &$rack) {
        $rack['placements'] = icct_rack_get_placements((int)$rack['id']);
        $rack['peripherals'] = icct_rack_get_peripheral_placements((int)$rack['id']);
        $rack['status_counts'] = icct_rack_status_counts($rack['placements']);
        $used = 0;
        foreach ($rack['placements'] as $p) $used += (int)$p['height_u'];
        foreach ($rack['peripherals'] as $p) $used += (int)$p['height_u'];
        $rack['used_u'] = $used;
        $rack['free_u'] = max(0, (int)$rack['rack_units'] - $used);
    }
    unset($rack);
    return $racks;
}

function icct_rack_get_available_hosts() {
    return db_fetch_assoc(
        'SELECT h.id, h.description, h.hostname, h.status, h.disabled, h.snmp_sysDescr
         FROM host h
         LEFT JOIN plugin_icct_rack_placements p ON p.host_id = h.id
         WHERE p.id IS NULL
         ORDER BY h.description, h.hostname'
    );
}

function icct_rack_categories() {
    return [
        'router'    => 'Router',
        'switch'    => 'Switch',
        'server'    => 'Server',
        'firewall'  => 'Firewall',
        'radio'     => 'Radio / LOS',
        'vsat'      => 'VSAT / Modem',
        'ups'       => 'UPS / Power',
        'sensor'    => 'Sensor',
        'camera'    => 'Camera',
        'voice'     => 'Voice / VoIP',
        'encryptor' => 'Encryptor',
        'generic'   => 'Generic Device',
    ];
}

function icct_rack_peripheral_catalog() {
    return [
        'patch_panel' => ['label' => 'Patch Panel', 'stencil' => 'patch-panel', 'default_u' => 1],
        'pdu'         => ['label' => 'Rack PDU', 'stencil' => 'pdu', 'default_u' => 1],
        'kvm'         => ['label' => 'KVM Console', 'stencil' => 'kvm', 'default_u' => 1],
        'shelf'       => ['label' => 'Rack Shelf', 'stencil' => 'shelf', 'default_u' => 1],
        'blank'       => ['label' => 'Blanking Panel', 'stencil' => 'blank-panel', 'default_u' => 1],
        'chassis'     => ['label' => 'Generic Chassis', 'stencil' => 'chassis', 'default_u' => 2],
    ];
}

function icct_rack_guess_category($host) {
    $hay = strtolower((string)($host['description'] ?? '') . ' ' . (string)($host['hostname'] ?? '') . ' ' . (string)($host['snmp_sysDescr'] ?? ''));
    $map = [
        'router'    => ['router', 'edge'],
        'switch'    => ['switch'],
        'server'    => ['server', 'workstation', 'hyperv', 'esx', 'thin client'],
        'firewall'  => ['firewall', 'ips'],
        'radio'     => ['radio', 'los'],
        'vsat'      => ['vsat', 'satellite', 'modem', 'buc'],
        'ups'       => ['ups'],
        'sensor'    => ['sensor', 'temperature', 'hygro', 'gnss'],
        'camera'    => ['camera'],
        'voice'     => ['voip', 'phone'],
        'encryptor' => ['encryptor', 'diode'],
    ];
    foreach ($map as $category => $needles) {
        foreach ($needles as $needle) {
            if (strpos($hay, $needle) !== false) return $category;
        }
    }
    return 'generic';
}

function icct_rack_stencil_file($key) {
    $allowed = array_merge(array_keys(icct_rack_categories()), [
        'patch-panel', 'pdu', 'kvm', 'shelf', 'blank-panel', 'chassis'
    ]);
    return in_array($key, $allowed, true) ? $key . '.svg' : 'generic.svg';
}

function icct_rack_status_info($host) {
    if (!$host) return ['key' => 'missing', 'label' => 'Missing', 'class' => 'status-missing'];
    if (isset($host['disabled']) && (string)$host['disabled'] === 'on') {
        return ['key' => 'disabled', 'label' => 'Disabled', 'class' => 'status-disabled'];
    }
    $status = isset($host['status']) ? (int)$host['status'] : (isset($host['host_status']) ? (int)$host['host_status'] : null);
    if (defined('HOST_UP') && $status === (int)HOST_UP) return ['key' => 'up', 'label' => 'Up', 'class' => 'status-up'];
    if (defined('HOST_DOWN') && $status === (int)HOST_DOWN) return ['key' => 'down', 'label' => 'Down', 'class' => 'status-down'];
    if (defined('HOST_RECOVERING') && $status === (int)HOST_RECOVERING) return ['key' => 'recovering', 'label' => 'Recovering', 'class' => 'status-recovering'];
    if (defined('HOST_ERROR') && $status === (int)HOST_ERROR) return ['key' => 'error', 'label' => 'Error', 'class' => 'status-error'];
    return ['key' => 'unknown', 'label' => 'Unknown', 'class' => 'status-unknown'];
}

function icct_rack_status_counts($placements) {
    $counts = ['up'=>0,'down'=>0,'recovering'=>0,'error'=>0,'unknown'=>0,'disabled'=>0,'missing'=>0];
    foreach ($placements as $p) {
        $info = $p['host_description'] === null
            ? ['key' => 'missing']
            : icct_rack_status_info(['status' => $p['host_status'], 'disabled' => $p['host_disabled']]);
        $counts[$info['key']] = ($counts[$info['key']] ?? 0) + 1;
    }
    return $counts;
}

function icct_rack_totals_from_racks($racks) {
    $out = ['racks'=>0,'devices'=>0,'peripherals'=>0,'up'=>0,'down'=>0,'recovering'=>0,'error'=>0,'unknown'=>0,'disabled'=>0,'missing'=>0];
    foreach ($racks as $rack) {
        $out['racks']++;
        $out['devices'] += count($rack['placements']);
        $out['peripherals'] += count($rack['peripherals']);
        foreach ($rack['status_counts'] as $key => $value) $out[$key] += (int)$value;
    }
    return $out;
}

function icct_rack_range_overlap($rack_id, $start_u, $height_u, $ignore_type = '', $ignore_id = 0) {
    $end_u = (int)$start_u + (int)$height_u - 1;
    $device_sql = 'SELECT COUNT(*) FROM plugin_icct_rack_placements WHERE rack_id = ? AND start_u <= ? AND (start_u + height_u - 1) >= ?';
    $device_params = [(int)$rack_id, $end_u, (int)$start_u];
    if ($ignore_type === 'device' && (int)$ignore_id > 0) {
        $device_sql .= ' AND id <> ?';
        $device_params[] = (int)$ignore_id;
    }
    if ((int)db_fetch_cell_prepared($device_sql, $device_params) > 0) return true;

    $peripheral_sql = 'SELECT COUNT(*) FROM plugin_icct_rack_peripheral_placements WHERE rack_id = ? AND start_u <= ? AND (start_u + height_u - 1) >= ?';
    $peripheral_params = [(int)$rack_id, $end_u, (int)$start_u];
    if ($ignore_type === 'peripheral' && (int)$ignore_id > 0) {
        $peripheral_sql .= ' AND id <> ?';
        $peripheral_params[] = (int)$ignore_id;
    }
    return (int)db_fetch_cell_prepared($peripheral_sql, $peripheral_params) > 0;
}

function icct_rack_highest_used_u($rack_id) {
    $device = (int)db_fetch_cell_prepared('SELECT COALESCE(MAX(start_u + height_u - 1), 0) FROM plugin_icct_rack_placements WHERE rack_id = ?', [(int)$rack_id]);
    $peripheral = (int)db_fetch_cell_prepared('SELECT COALESCE(MAX(start_u + height_u - 1), 0) FROM plugin_icct_rack_peripheral_placements WHERE rack_id = ?', [(int)$rack_id]);
    return max($device, $peripheral);
}

function icct_rack_validate_rack_payload($data, $rack_id = 0) {
    $errors = [];
    $name = trim((string)($data['name'] ?? ''));
    $rack_units = (int)($data['rack_units'] ?? 42);
    if ($name === '') $errors[] = 'Rack name is required.';
    if ($rack_units < 1 || $rack_units > 100) $errors[] = 'Rack units must be between 1U and 100U.';
    if ((int)$rack_id > 0 && $rack_units < icct_rack_highest_used_u($rack_id)) {
        $errors[] = 'Rack size cannot be smaller than the highest occupied unit.';
    }
    return $errors;
}

function icct_rack_save_rack($data, $rack_id = 0) {
    $rack_id = (int)$rack_id;
    $errors = icct_rack_validate_rack_payload($data, $rack_id);
    if ($errors) return ['ok' => false, 'errors' => $errors, 'id' => $rack_id];

    $name = preg_replace('/\s+/', ' ', trim((string)$data['name']));
    $code = icct_rack_generate_code($name, $rack_id);
    $enabled = !empty($data['enabled']) ? 'on' : '';

    if ($rack_id > 0) {
        $before = icct_rack_get_rack($rack_id);
        db_execute_prepared(
            'UPDATE plugin_icct_rack_racks SET code = ?, name = ?, icct_name = ?, location = ?, rack_units = ?, enabled = ? WHERE id = ?',
            [$code, $name, '', '', (int)$data['rack_units'], $enabled, $rack_id]
        );
        icct_rack_audit('rack.update', 'rack', $rack_id, ['before' => $before, 'after' => ['name'=>$name,'rack_units'=>(int)$data['rack_units'],'enabled'=>$enabled]]);
        return ['ok' => true, 'id' => $rack_id, 'errors' => []];
    }

    db_execute_prepared(
        'INSERT INTO plugin_icct_rack_racks (code, name, icct_name, location, rack_units, enabled, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$code, $name, '', '', (int)$data['rack_units'], $enabled, icct_rack_current_user_id()]
    );
    $rack_id = (int)db_fetch_insert_id();
    icct_rack_audit('rack.create', 'rack', $rack_id, ['name'=>$name,'rack_units'=>(int)$data['rack_units']]);
    return ['ok' => true, 'id' => $rack_id, 'errors' => []];
}

function icct_rack_delete_rack($rack_id) {
    $rack = icct_rack_get_rack($rack_id);
    if (!$rack) return ['ok'=>false,'error'=>'Rack not found.'];
    $devices = (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_rack_placements WHERE rack_id = ?', [(int)$rack_id]);
    $peripherals = (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_rack_peripheral_placements WHERE rack_id = ?', [(int)$rack_id]);
    if ($devices + $peripherals > 0) return ['ok'=>false,'error'=>'Remove all devices and peripherals before deleting this rack.'];
    db_execute_prepared('DELETE FROM plugin_icct_rack_racks WHERE id = ?', [(int)$rack_id]);
    icct_rack_audit('rack.delete','rack',(int)$rack_id,$rack);
    return ['ok'=>true];
}

function icct_rack_validate_device_placement($data, $placement_id = 0) {
    $errors = [];
    $rack_id = (int)($data['rack_id'] ?? 0);
    $host_id = (int)($data['host_id'] ?? 0);
    $start_u = (int)($data['start_u'] ?? 0);
    $height_u = (int)($data['height_u'] ?? 1);
    $category = (string)($data['category'] ?? 'generic');
    $rack = icct_rack_get_rack($rack_id);
    if (!$rack) $errors[] = 'Rack does not exist.';
    if (!$host_id || !icct_rack_get_host($host_id)) $errors[] = 'Select a valid Cacti device.';
    if ($start_u < 1) $errors[] = 'Start U must be 1 or greater.';
    if ($height_u < 1 || $height_u > 20) $errors[] = 'Device height must be between 1U and 20U.';
    if ($rack && ($start_u + $height_u - 1) > (int)$rack['rack_units']) $errors[] = 'Device extends beyond the top of the rack.';
    if (!array_key_exists($category, icct_rack_categories())) $errors[] = 'Invalid device stencil.';
    if ($host_id > 0) {
        $exists = (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_rack_placements WHERE host_id = ? AND id <> ?', [$host_id, (int)$placement_id]);
        if ($exists > 0) $errors[] = 'This Cacti device is already placed in a rack.';
    }
    if ($rack && $start_u > 0 && $height_u > 0 && icct_rack_range_overlap($rack_id, $start_u, $height_u, 'device', $placement_id)) {
        $errors[] = 'The selected U range overlaps another rack item.';
    }
    return $errors;
}

function icct_rack_save_device_placement($data, $placement_id = 0) {
    $placement_id = (int)$placement_id;
    $errors = icct_rack_validate_device_placement($data, $placement_id);
    if ($errors) return ['ok'=>false,'errors'=>$errors,'id'=>$placement_id];
    $host = icct_rack_get_host((int)$data['host_id']);
    $label = trim((string)($data['label'] ?? ''));
    if ($label === '') $label = (string)($host['description'] ?? '');
    $params = [(int)$data['rack_id'],(int)$data['host_id'],$label,(int)$data['start_u'],(int)$data['height_u'],(string)$data['category'],''];

    if ($placement_id > 0) {
        $before = icct_rack_get_placement($placement_id);
        db_execute_prepared(
            'UPDATE plugin_icct_rack_placements SET rack_id = ?, host_id = ?, label = ?, start_u = ?, height_u = ?, category = ?, notes = ?, updated_by = ? WHERE id = ?',
            array_merge($params, [icct_rack_current_user_id(), $placement_id])
        );
        icct_rack_audit('device.update','device',$placement_id,['before'=>$before,'after'=>$data]);
        return ['ok'=>true,'id'=>$placement_id,'errors'=>[]];
    }

    db_execute_prepared(
        'INSERT INTO plugin_icct_rack_placements (rack_id, host_id, label, start_u, height_u, category, notes, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        array_merge($params, [icct_rack_current_user_id(), icct_rack_current_user_id()])
    );
    $placement_id = (int)db_fetch_insert_id();
    icct_rack_audit('device.place','device',$placement_id,$data);
    return ['ok'=>true,'id'=>$placement_id,'errors'=>[]];
}

function icct_rack_move_device($id, $rack_id, $start_u) {
    $p = icct_rack_get_placement($id);
    if (!$p) return ['ok'=>false,'errors'=>['Device placement not found.']];
    return icct_rack_save_device_placement([
        'rack_id'=>(int)$rack_id,'host_id'=>(int)$p['host_id'],'label'=>$p['label'],'start_u'=>(int)$start_u,
        'height_u'=>(int)$p['height_u'],'category'=>$p['category']
    ], (int)$id);
}

function icct_rack_delete_device_placement($id) {
    $p = icct_rack_get_placement($id);
    if (!$p) return ['ok'=>false,'error'=>'Device placement not found.'];
    db_execute_prepared('DELETE FROM plugin_icct_rack_placements WHERE id = ?', [(int)$id]);
    icct_rack_audit('device.remove','device',(int)$id,$p);
    return ['ok'=>true];
}

function icct_rack_validate_peripheral_placement($data, $id = 0) {
    $errors = [];
    $rack_id = (int)($data['rack_id'] ?? 0);
    $start_u = (int)($data['start_u'] ?? 0);
    $height_u = (int)($data['height_u'] ?? 1);
    $key = (string)($data['peripheral_key'] ?? '');
    $catalog = icct_rack_peripheral_catalog();
    $rack = icct_rack_get_rack($rack_id);
    if (!$rack) $errors[] = 'Rack does not exist.';
    if (!isset($catalog[$key])) $errors[] = 'Invalid peripheral type.';
    if ($start_u < 1) $errors[] = 'Start U must be 1 or greater.';
    if ($height_u < 1 || $height_u > 20) $errors[] = 'Peripheral height must be between 1U and 20U.';
    if ($rack && ($start_u + $height_u - 1) > (int)$rack['rack_units']) $errors[] = 'Peripheral extends beyond the top of the rack.';
    if ($rack && $start_u > 0 && $height_u > 0 && icct_rack_range_overlap($rack_id, $start_u, $height_u, 'peripheral', $id)) {
        $errors[] = 'The selected U range overlaps another rack item.';
    }
    return $errors;
}

function icct_rack_save_peripheral_placement($data, $id = 0) {
    $id = (int)$id;
    $errors = icct_rack_validate_peripheral_placement($data, $id);
    if ($errors) return ['ok'=>false,'errors'=>$errors,'id'=>$id];
    $catalog = icct_rack_peripheral_catalog();
    $key = (string)$data['peripheral_key'];
    $label = trim((string)($data['label'] ?? '')) ?: $catalog[$key]['label'];
    $stencil = (string)($data['stencil'] ?? $catalog[$key]['stencil']);

    if ($id > 0) {
        $before = icct_rack_get_peripheral_placement($id);
        db_execute_prepared(
            'UPDATE plugin_icct_rack_peripheral_placements SET rack_id = ?, peripheral_key = ?, label = ?, start_u = ?, height_u = ?, stencil = ?, updated_by = ? WHERE id = ?',
            [(int)$data['rack_id'],$key,$label,(int)$data['start_u'],(int)$data['height_u'],$stencil,icct_rack_current_user_id(),$id]
        );
        icct_rack_audit('peripheral.update','peripheral',$id,['before'=>$before,'after'=>$data]);
        return ['ok'=>true,'id'=>$id,'errors'=>[]];
    }

    db_execute_prepared(
        'INSERT INTO plugin_icct_rack_peripheral_placements (rack_id, peripheral_key, label, start_u, height_u, stencil, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [(int)$data['rack_id'],$key,$label,(int)$data['start_u'],(int)$data['height_u'],$stencil,icct_rack_current_user_id(),icct_rack_current_user_id()]
    );
    $id = (int)db_fetch_insert_id();
    icct_rack_audit('peripheral.place','peripheral',$id,$data);
    return ['ok'=>true,'id'=>$id,'errors'=>[]];
}

function icct_rack_move_peripheral($id, $rack_id, $start_u) {
    $p = icct_rack_get_peripheral_placement($id);
    if (!$p) return ['ok'=>false,'errors'=>['Peripheral placement not found.']];
    return icct_rack_save_peripheral_placement([
        'rack_id'=>(int)$rack_id,'peripheral_key'=>$p['peripheral_key'],'label'=>$p['label'],'start_u'=>(int)$start_u,
        'height_u'=>(int)$p['height_u'],'stencil'=>$p['stencil']
    ], (int)$id);
}

function icct_rack_delete_peripheral_placement($id) {
    $p = icct_rack_get_peripheral_placement($id);
    if (!$p) return ['ok'=>false,'error'=>'Peripheral placement not found.'];
    db_execute_prepared('DELETE FROM plugin_icct_rack_peripheral_placements WHERE id = ?', [(int)$id]);
    icct_rack_audit('peripheral.remove','peripheral',(int)$id,$p);
    return ['ok'=>true];
}

function icct_rack_get_audit($id) {
    return db_fetch_row_prepared('SELECT a.*, u.username FROM plugin_icct_rack_audit a LEFT JOIN user_auth u ON u.id = a.user_id WHERE a.id = ?', [(int)$id]);
}

function icct_rack_list_audit($filter, $rows, $page, &$total_rows) {
    $where = ' WHERE 1=1';
    $params = [];
    $filter = trim((string)$filter);
    if ($filter !== '') {
        $where .= ' AND (a.action LIKE ? OR a.entity_type LIKE ? OR a.details LIKE ? OR u.username LIKE ?)';
        $like = '%' . $filter . '%';
        $params = [$like,$like,$like,$like];
    }
    $total_rows = (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_rack_audit a LEFT JOIN user_auth u ON u.id = a.user_id' . $where, $params);
    $offset = max(0, ($page - 1) * $rows);
    return db_fetch_assoc_prepared(
        'SELECT a.*, u.username FROM plugin_icct_rack_audit a LEFT JOIN user_auth u ON u.id = a.user_id' . $where . ' ORDER BY a.id DESC LIMIT ' . (int)$offset . ',' . (int)$rows,
        $params
    );
}
