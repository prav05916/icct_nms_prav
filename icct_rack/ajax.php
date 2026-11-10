<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */
chdir('../..');
include('./include/auth.php');
include_once($config['base_path'] . '/plugins/icct_rack/lib/functions.php');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function icct_rack_json($payload, $status = 200) {
    http_response_code((int)$status);
    print json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset_request_var('action') ? (string)get_nfilter_request_var('action') : '';

if ($action === 'status_all') {
    $racks = icct_rack_get_racks_with_items(true);
    $items = [];
    foreach ($racks as $rack) {
        foreach ($rack['placements'] as $p) {
            $status = $p['host_description'] === null
                ? ['key'=>'missing','label'=>'Missing']
                : icct_rack_status_info(['status'=>$p['host_status'],'disabled'=>$p['host_disabled']]);
            $items[] = ['placement_id'=>(int)$p['id'],'status_key'=>$status['key'],'status_label'=>$status['label']];
        }
    }
    icct_rack_json(['ok'=>true,'placements'=>$items,'summary'=>icct_rack_totals_from_racks($racks),'refreshed_at'=>date('H:i:s')]);
}

if ($action === 'device') {
    $host_id = isset_request_var('host_id') ? (int)get_filter_request_var('host_id') : 0;
    $host = icct_rack_get_host($host_id);
    if (!$host) icct_rack_json(['ok'=>false,'error'=>'Cacti device not found.'], 404);
    $status = icct_rack_status_info($host);
    icct_rack_json([
        'ok'=>true,
        'device'=>[
            'description'=>(string)($host['description'] ?? ''),
            'hostname'=>(string)($host['hostname'] ?? ''),
            'status'=>$status['label'],
            'snmp_sysName'=>(string)($host['snmp_sysName'] ?? ''),
            'snmp_sysLocation'=>(string)($host['snmp_sysLocation'] ?? ''),
            'status_last_error'=>(string)($host['status_last_error'] ?? ''),
        ],
        'edit_url'=>$config['url_path'] . 'host.php?action=edit&id=' . (int)$host_id,
    ]);
}

if (!icct_rack_can_manage()) {
    icct_rack_json(['ok'=>false,'error'=>'You do not have permission to modify rack topology.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    icct_rack_json(['ok'=>false,'error'=>'POST required.'], 405);
}

if ($action === 'place_device') {
    $host_id = icct_rack_request_int('host_id');
    $host = icct_rack_get_host($host_id);
    if (!$host) icct_rack_json(['ok'=>false,'errors'=>['Cacti device not found.']], 404);
    $category = icct_rack_request_string('category', icct_rack_guess_category($host));
    $result = icct_rack_save_device_placement([
        'rack_id'=>icct_rack_request_int('rack_id'),
        'host_id'=>$host_id,
        'label'=>(string)($host['description'] ?? ''),
        'start_u'=>icct_rack_request_int('start_u'),
        'height_u'=>max(1,icct_rack_request_int('height_u',1)),
        'category'=>$category,
    ]);
    if (!$result['ok']) icct_rack_json(['ok'=>false,'errors'=>$result['errors']], 422);
    icct_rack_json(['ok'=>true,'id'=>$result['id']]);
}

if ($action === 'move_device') {
    $result = icct_rack_move_device(icct_rack_request_int('placement_id'), icct_rack_request_int('rack_id'), icct_rack_request_int('start_u'));
    if (!$result['ok']) icct_rack_json(['ok'=>false,'errors'=>$result['errors']], 422);
    icct_rack_json(['ok'=>true]);
}

if ($action === 'update_device') {
    $id = icct_rack_request_int('placement_id');
    $p = icct_rack_get_placement($id);
    if (!$p) icct_rack_json(['ok'=>false,'errors'=>['Device placement not found.']], 404);
    $result = icct_rack_save_device_placement([
        'rack_id'=>(int)$p['rack_id'],
        'host_id'=>(int)$p['host_id'],
        'label'=>$p['label'],
        'start_u'=>(int)$p['start_u'],
        'height_u'=>max(1,icct_rack_request_int('height_u',(int)$p['height_u'])),
        'category'=>icct_rack_request_string('category',$p['category']),
    ], $id);
    if (!$result['ok']) icct_rack_json(['ok'=>false,'errors'=>$result['errors']], 422);
    icct_rack_json(['ok'=>true]);
}

if ($action === 'delete_device') {
    $result = icct_rack_delete_device_placement(icct_rack_request_int('placement_id'));
    if (!$result['ok']) icct_rack_json(['ok'=>false,'errors'=>[$result['error']]], 422);
    icct_rack_json(['ok'=>true]);
}

if ($action === 'place_peripheral') {
    $key = icct_rack_request_string('peripheral_key');
    $catalog = icct_rack_peripheral_catalog();
    if (!isset($catalog[$key])) icct_rack_json(['ok'=>false,'errors'=>['Invalid peripheral type.']], 422);
    $result = icct_rack_save_peripheral_placement([
        'rack_id'=>icct_rack_request_int('rack_id'),
        'peripheral_key'=>$key,
        'label'=>$catalog[$key]['label'],
        'start_u'=>icct_rack_request_int('start_u'),
        'height_u'=>max(1,icct_rack_request_int('height_u',$catalog[$key]['default_u'])),
        'stencil'=>$catalog[$key]['stencil'],
    ]);
    if (!$result['ok']) icct_rack_json(['ok'=>false,'errors'=>$result['errors']], 422);
    icct_rack_json(['ok'=>true,'id'=>$result['id']]);
}

if ($action === 'move_peripheral') {
    $result = icct_rack_move_peripheral(icct_rack_request_int('placement_id'), icct_rack_request_int('rack_id'), icct_rack_request_int('start_u'));
    if (!$result['ok']) icct_rack_json(['ok'=>false,'errors'=>$result['errors']], 422);
    icct_rack_json(['ok'=>true]);
}

if ($action === 'update_peripheral') {
    $id = icct_rack_request_int('placement_id');
    $p = icct_rack_get_peripheral_placement($id);
    if (!$p) icct_rack_json(['ok'=>false,'errors'=>['Peripheral placement not found.']], 404);
    $result = icct_rack_save_peripheral_placement([
        'rack_id'=>(int)$p['rack_id'],
        'peripheral_key'=>$p['peripheral_key'],
        'label'=>$p['label'],
        'start_u'=>(int)$p['start_u'],
        'height_u'=>max(1,icct_rack_request_int('height_u',(int)$p['height_u'])),
        'stencil'=>$p['stencil'],
    ], $id);
    if (!$result['ok']) icct_rack_json(['ok'=>false,'errors'=>$result['errors']], 422);
    icct_rack_json(['ok'=>true]);
}

if ($action === 'delete_peripheral') {
    $result = icct_rack_delete_peripheral_placement(icct_rack_request_int('placement_id'));
    if (!$result['ok']) icct_rack_json(['ok'=>false,'errors'=>[$result['error']]], 422);
    icct_rack_json(['ok'=>true]);
}

icct_rack_json(['ok'=>false,'error'=>'Unknown action.'], 400);
