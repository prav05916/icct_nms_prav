#!/usr/bin/env php
<?php
/**
 * ICCT NMS NetFlow Collector & Processing Worker.
 * Pure PHP, zero external dependencies, RHEL 9.4 offline compatible.
 *
 * Supports two execution modes:
 *   1. Daemon mode (listens on UDP 2055, decodes NetFlow v5/v7/v9/IPFIX v10, stores live flows):
 *      php protocols/netflow/cli/netflow_worker.php --daemon --port=2055
 *   2. One-shot sync mode (syncs rates & samples traffic for cron/timer):
 *      php protocols/netflow/cli/netflow_worker.php --sync --limit=500
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$cactiPaths = [
    __DIR__ . '/../../../../../include/cli_check.php',
    dirname(__DIR__, 5) . '/include/cli_check.php',
    dirname(__DIR__, 4) . '/include/cli_check.php',
    '/var/www/html/cacti/include/cli_check.php',
    '/var/www/cacti/include/cli_check.php',
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
require_once dirname(__DIR__, 3) . '/monitoring/services/netflow_service.php';

$options = getopt('', ['daemon', 'sync', 'port::', 'limit::', 'help']);
if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  Daemon: php netflow_worker.php --daemon [--port=2055]\n";
    echo "  Sync:   php netflow_worker.php --sync [--limit=500]\n";
    exit(0);
}

$isDaemon = isset($options['daemon']);
$port = isset($options['port']) ? max(1, (int)$options['port']) : 2055;
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 500;

try {
    icct_nms_backend();

    if ($isDaemon) {
        $socket = null;
        $stream = null;
        if (function_exists('socket_create')) {
            $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($socket) {
                socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
                if (!@socket_bind($socket, '0.0.0.0', $port)) {
                    @socket_close($socket);
                    $socket = null;
                }
            }
        }
        if (!$socket) {
            $errno = 0;
            $errstr = '';
            $stream = @stream_socket_server("udp://0.0.0.0:$port", $errno, $errstr, STREAM_SERVER_BIND);
            if (!$stream) {
                fwrite(STDERR, "Failed to bind UDP port $port: $errstr ($errno)\n");
                exit(1);
            }
        }

        echo "ICCT NMS NetFlow collector listening on UDP 0.0.0.0:$port...\n";
        echo "Supported versions: NetFlow v5, v7, v9, IPFIX (v10)\n";
        echo "Socket backend: " . ($socket ? 'ext-sockets' : 'php-streams') . "\n";

        while (true) {
            $from = '';
            $fromPort = 0;
            $buf = '';
            if ($socket) {
                $len = @socket_recvfrom($socket, $buf, 65535, 0, $from, $fromPort);
            } else {
                $peer = '';
                $buf = (string)@stream_socket_recvfrom($stream, 65535, 0, $peer);
                $len = strlen($buf);
                if ($peer) {
                    $parts = explode(':', $peer);
                    $from = $parts[0] ?? '127.0.0.1';
                }
            }
            if ($len === false || $len < 16) continue;

            // Find matching host for exporter address
            $hostId = (int)db_fetch_cell_prepared(
                'SELECT d.host_id FROM plugin_icct_nms_netflow_devices d
                 JOIN host h ON h.id = d.host_id
                 WHERE (d.exporter_address = ? OR h.hostname = ?) AND d.enabled = 1
                 LIMIT 1',
                [$from, $from]
            );

            // If not directly configured, match any host with this IP
            if (!$hostId) {
                $hostId = (int)db_fetch_cell_prepared('SELECT id FROM host WHERE hostname = ? LIMIT 1', [$from]);
            }
            // If not found, match first available active NetFlow device
            if (!$hostId) {
                $hostId = (int)db_fetch_cell('SELECT host_id FROM plugin_icct_nms_netflow_devices WHERE enabled = 1 ORDER BY host_id LIMIT 1');
            }
            // If still no host, match any active host in Cacti
            if (!$hostId) {
                $hostId = (int)db_fetch_cell('SELECT id FROM host WHERE disabled = "" ORDER BY id LIMIT 1');
            }
            if (!$hostId) {
                $hostId = (int)db_fetch_cell('SELECT id FROM host ORDER BY id LIMIT 1');
            }
            if (!$hostId) {
                $hostId = 1;
            }

            // Ensure this host has an entry in plugin_icct_nms_netflow_devices so UI immediately shows it
            $devExists = (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_nms_netflow_devices WHERE host_id = ?', [$hostId]);
            if (!$devExists) {
                icct_nms_netflow_upsert($hostId, [
                    'exporter_ip'    => $from,
                    'collector_ip'   => '127.0.0.1',
                    'collector_port' => $port,
                    'flow_version'   => 9,
                ]);
            }

            // Use the unified decoder supporting v5, v7, v9, IPFIX (v10)
            $flows = icct_nms_netflow_decode_packet($buf, $from);
            if (empty($flows)) continue;

            // Record flows persistently with anomaly detection
            $result = icct_nms_netflow_record_flows($hostId, $flows);

            $version = unpack('n', substr($buf, 0, 2))[1] ?? 0;
            $vLabel = $version === 10 ? 'IPFIX' : "v$version";
            echo date('Y-m-d H:i:s') . " [$from] $vLabel: {$result['records']} flows, {$result['anomalies']} anomalies\n";
        }
        exit(0);
    }

    // Sync mode (one-shot for timer or verification)
    $devices = db_fetch_assoc(
        'SELECT host_id FROM plugin_icct_nms_netflow_devices WHERE enabled = 1 LIMIT ' . (int)$limit
    ) ?: [];

    $synced = 0;
    $active = 0;
    $idle = 0;

    foreach ($devices as $dev) {
        $hid = (int)$dev['host_id'];
        $res = icct_nms_netflow_sample_device($hid);
        $synced++;
        if (!empty($res['ok']) && ($res['status'] ?? '') === 'active') {
            $active++;
        } else {
            $idle++;
        }
    }

    echo json_encode([
        'synced' => $synced,
        'active' => $active,
        'idle'   => $idle,
    ]) . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "NetFlow worker error: " . $e->getMessage() . "\n");
    echo json_encode([
        'synced' => 0,
        'active' => 0,
        'idle'   => 0,
        'error'  => $e->getMessage(),
    ]) . "\n";
    exit(1);
}
