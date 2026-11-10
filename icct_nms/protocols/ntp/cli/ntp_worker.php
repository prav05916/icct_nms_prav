#!/usr/bin/env php
<?php
/**
 * ICCT NMS NTP Polling Worker CLI.
 * Polls configured NTP devices, records real UDP latency/offset/jitter/stratum,
 * and outputs structured JSON for systemd/cron logging.
 *
 * Usage:
 *   php protocols/ntp/cli/ntp_worker.php --limit=500
 *   php protocols/ntp/cli/ntp_worker.php --host=1
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// Find and load Cacti cli_check
$cactiPaths = [
    __DIR__ . '/../../../../../include/cli_check.php',
    dirname(__DIR__, 4) . '/include/cli_check.php',
    '/usr/share/cacti/include/cli_check.php',
    'C:/Apache24/htdocs/cacti/include/cli_check.php',
];
$loaded = false;
foreach ($cactiPaths as $p) {
    if (file_exists($p)) {
        define('IN_CACTI_INSTALL', true);
        require $p;
        $loaded = true;
        break;
    }
}
if (!$loaded) {
    fwrite(STDERR, "Unable to find Cacti include/cli_check.php\n");
    exit(1);
}

require_once dirname(__DIR__, 3) . '/shared/services/bootstrap.php';
require_once dirname(__DIR__, 3) . '/monitoring/services/ntp_service.php';

$options = getopt('', ['limit::', 'host::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php ntp_worker.php [--limit=500] [--host=<id>]\n";
    exit(0);
}

$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 500;
$targetHostId = isset($options['host']) ? (int)$options['host'] : 0;

$polled = 0;
$healthy = 0;
$failed = 0;

try {
    icct_nms_backend();

    if ($targetHostId > 0) {
        $devices = db_fetch_assoc_prepared(
            'SELECT host_id FROM plugin_icct_nms_ntp_devices WHERE host_id = ? AND enabled = 1',
            [$targetHostId]
        ) ?: [];
    } else {
        $devices = db_fetch_assoc(
            'SELECT host_id FROM plugin_icct_nms_ntp_devices WHERE enabled = 1 LIMIT ' . (int)$limit
        ) ?: [];
    }

    foreach ($devices as $dev) {
        $hid = (int)$dev['host_id'];
        $res = icct_nms_ntp_poll_device($hid);
        $polled++;
        if (!empty($res['ok']) && ($res['status'] ?? '') === 'synced') {
            $healthy++;
        } else {
            $failed++;
        }
    }

    // Exact output format expected by systemd journal:
    echo json_encode([
        'polled'  => $polled,
        'healthy' => $healthy,
        'failed'  => $failed,
    ]) . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "NTP worker error: " . $e->getMessage() . "\n");
    echo json_encode([
        'polled'  => $polled,
        'healthy' => $healthy,
        'failed'  => $failed,
        'error'   => $e->getMessage(),
    ]) . "\n";
    exit(1);
}
