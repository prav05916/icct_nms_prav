<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */
chdir('../..');
include('./include/auth.php');
include_once($config['base_path'] . '/plugins/icct_map/lib/functions.php');
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
function icct_map_json($payload,$status=200){http_response_code((int)$status);print json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
$action=isset_request_var('action')?(string)get_nfilter_request_var('action'):'';
if($action==='snapshot'){ $data=icct_map_sites_snapshot(); $data['ok']=true; icct_map_json($data); }
icct_map_json(['ok'=>false,'error'=>'Unknown action.'],400);
