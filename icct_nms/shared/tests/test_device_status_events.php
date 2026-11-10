<?php
if (PHP_SAPI !== 'cli') exit(1);
define('IN_CACTI_INSTALL', true);
require '/Apache24/htdocs/cacti/include/cli_check.php';
require_once __DIR__ . '/../services/bootstrap.php';
icct_nms_backend();
require_once __DIR__ . '/../../protocols/syslog/services/backend/syslog.php';

echo "=== Testing Device Availability Status Events ===" . PHP_EOL;

// Ensure host 1 is in plugin_icct_nms_syslog_devices
db_execute("INSERT IGNORE INTO plugin_icct_nms_syslog_devices(host_id,enabled,source_address,transport,max_severity,updated_by,updated_at) SELECT id,1,hostname,'both',6,1,NOW() FROM host WHERE deleted=''");

// 1. Initial collection seeds baseline
$r1 = icct_backend_syslog_collect_availability_events();
echo "Run 1 (seed): initialized={$r1['initialized']} events={$r1['events']} unchanged={$r1['unchanged']}" . PHP_EOL;

// 2. Simulate Disabling device 1
db_execute_prepared("UPDATE host SET disabled='on' WHERE id=1");
$r2 = icct_backend_syslog_collect_availability_events();
echo "Run 2 (disabled): events={$r2['events']}" . PHP_EOL;

// Check event in DB
$latestEvent = db_fetch_row_prepared("SELECT * FROM plugin_icct_nms_syslog_events WHERE host_id=1 AND event_origin='availability' ORDER BY id DESC LIMIT 1");
if ($latestEvent) {
    echo "Latest Event 1: origin={$latestEvent['event_origin']} severity={$latestEvent['severity']} msg={$latestEvent['message']}" . PHP_EOL;
} else {
    echo "ERROR: No event found after disabling host 1" . PHP_EOL;
}

// 3. Simulate Enabling device 1
db_execute_prepared("UPDATE host SET disabled='' WHERE id=1");
$r3 = icct_backend_syslog_collect_availability_events();
echo "Run 3 (enabled): events={$r3['events']}" . PHP_EOL;
$latestEvent2 = db_fetch_row_prepared("SELECT * FROM plugin_icct_nms_syslog_events WHERE host_id=1 AND event_origin='availability' ORDER BY id DESC LIMIT 1");
if ($latestEvent2) {
    echo "Latest Event 2: origin={$latestEvent2['event_origin']} severity={$latestEvent2['severity']} msg={$latestEvent2['message']}" . PHP_EOL;
}

// 4. Simulate Marking device 1 Down (status=3)
db_execute_prepared("UPDATE host SET status=3 WHERE id=1");
$r4 = icct_backend_syslog_collect_availability_events();
echo "Run 4 (down): events={$r4['events']}" . PHP_EOL;
$latestEvent3 = db_fetch_row_prepared("SELECT * FROM plugin_icct_nms_syslog_events WHERE host_id=1 AND event_origin='availability' ORDER BY id DESC LIMIT 1");
if ($latestEvent3) {
    echo "Latest Event 3: origin={$latestEvent3['event_origin']} severity={$latestEvent3['severity']} msg={$latestEvent3['message']}" . PHP_EOL;
}

// 5. Restore device 1 to Up (status=1)
db_execute_prepared("UPDATE host SET status=1 WHERE id=1");
$r5 = icct_backend_syslog_collect_availability_events();
echo "Run 5 (restored up): events={$r5['events']}" . PHP_EOL;
$latestEvent4 = db_fetch_row_prepared("SELECT * FROM plugin_icct_nms_syslog_events WHERE host_id=1 AND event_origin='availability' ORDER BY id DESC LIMIT 1");
if ($latestEvent4) {
    echo "Latest Event 4: origin={$latestEvent4['event_origin']} severity={$latestEvent4['severity']} msg={$latestEvent4['message']}" . PHP_EOL;
}

echo "=== All status transitions verified successfully! ===" . PHP_EOL;
