<?php
if (PHP_SAPI !== 'cli') exit(1);
define('IN_CACTI_INSTALL', true);
require '/Apache24/htdocs/cacti/include/cli_check.php';

echo "Sites: " . db_fetch_cell('SELECT COUNT(*) FROM sites') . PHP_EOL;
echo "Sites with coords: " . db_fetch_cell('SELECT COUNT(*) FROM sites WHERE latitude IS NOT NULL AND latitude!=0 AND longitude IS NOT NULL AND longitude!=0') . PHP_EOL;
$rows = db_fetch_assoc('SELECT id,name,latitude,longitude FROM sites LIMIT 5');
foreach ($rows as $r) echo "Site: {$r['id']} {$r['name']} lat={$r['latitude']} lon={$r['longitude']}" . PHP_EOL;
echo "Devices: " . db_fetch_cell("SELECT COUNT(*) FROM host WHERE deleted=''") . PHP_EOL;
$hosts = db_fetch_assoc("SELECT id,description,hostname,status,disabled,site_id FROM host WHERE deleted='' LIMIT 5");
foreach ($hosts as $h) echo "Device: {$h['id']} {$h['description']} site={$h['site_id']} status={$h['status']} disabled={$h['disabled']}" . PHP_EOL;
echo "Syslog availability state rows: " . db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_syslog_availability_state') . PHP_EOL;
echo "Syslog events total: " . db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_syslog_events') . PHP_EOL;
echo "Syslog availability events: " . db_fetch_cell("SELECT COUNT(*) FROM plugin_icct_nms_syslog_events WHERE event_origin='availability'") . PHP_EOL;
$evts = db_fetch_assoc('SELECT host_id,event_origin,message FROM plugin_icct_nms_syslog_events LIMIT 5');
foreach ($evts as $e) echo "Event: host={$e['host_id']} origin={$e['event_origin']} msg={$e['message']}" . PHP_EOL;
echo "Racks: " . db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_racks') . PHP_EOL;
echo "Rack devices placed: " . db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_rack_devices') . PHP_EOL;
$placed = db_fetch_assoc('SELECT host_id,rack_id,start_unit,unit_height FROM plugin_icct_nms_rack_devices LIMIT 5');
foreach ($placed as $p) echo "Placed: host={$p['host_id']} rack={$p['rack_id']} start={$p['start_unit']}" . PHP_EOL;
echo "Syslog devices: " . db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_syslog_devices') . PHP_EOL;
$sd = db_fetch_assoc('SELECT host_id,enabled,source_address FROM plugin_icct_nms_syslog_devices LIMIT 5');
foreach ($sd as $s) echo "SyslogDev: host={$s['host_id']} enabled={$s['enabled']} src={$s['source_address']}" . PHP_EOL;
echo "Protocol states: " . db_fetch_cell("SELECT COUNT(*) FROM plugin_icct_nms_meta WHERE meta_key LIKE 'protocol_state_%'") . PHP_EOL;
echo "MIB bundles: " . db_fetch_cell("SELECT COUNT(*) FROM plugin_icct_nms_meta WHERE meta_key LIKE 'mib_%'") . PHP_EOL;
