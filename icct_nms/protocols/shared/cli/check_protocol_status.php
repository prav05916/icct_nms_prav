<?php
/**
 * ICCT NMS Protocol & Diagnostic Verification CLI (Offline RHEL & Windows)
 *
 * Usage:
 *   php plugins/icct_nms/protocols/shared/cli/check_protocol_status.php
 */
if (PHP_SAPI !== 'cli') exit(1);
define('IN_CACTI_INSTALL', true);

$basePaths = ['/var/www/html/cacti', '/Apache24/htdocs/cacti', dirname(__DIR__, 5)];
$cliCheck = null;
foreach ($basePaths as $path) {
    if (file_exists($path . '/include/cli_check.php')) {
        $cliCheck = $path . '/include/cli_check.php';
        break;
    }
}
if (!$cliCheck) {
    echo "ERROR: Unable to locate Cacti cli_check.php" . PHP_EOL;
    exit(1);
}
require $cliCheck;
require_once dirname(__DIR__, 2) . '/shared/services/bootstrap.php';
icct_nms_backend();
require_once dirname(__DIR__, 2) . '/shared/services/protocol_preset_service.php';
require_once dirname(__DIR__, 2) . '/protocols/syslog/services/backend/syslog.php';

echo "=================================================================" . PHP_EOL;
echo "          ICCT NMS PROTOCOL & SYSTEM DIAGNOSTIC TOOL             " . PHP_EOL;
echo "=================================================================" . PHP_EOL;
echo "OS Platform: " . PHP_OS . " (" . php_uname('s') . ")" . PHP_EOL;
echo "PHP Version: " . PHP_VERSION . PHP_EOL;
echo "Time:        " . date('Y-m-d H:i:s T') . PHP_EOL;
echo PHP_EOL;

// -------------------------------------------------------------
// 1. NTP PROTOCOL STATUS
// -------------------------------------------------------------
echo "[1] NTP (Network Time Protocol) Status" . PHP_EOL;
echo "-----------------------------------------------------------------" . PHP_EOL;
$presets = icct_nms_protocol_presets();
$ntpPreset = $presets['ntp'] ?? null;
if ($ntpPreset) {
    echo "  Configured Preset: YES" . PHP_EOL;
    echo "    Server:         " . ($ntpPreset['server'] ?: 'Local server / Poller') . PHP_EOL;
    echo "    Port:           " . $ntpPreset['port'] . PHP_EOL;
    echo "    Version:        NTP v" . $ntpPreset['version'] . PHP_EOL;
    echo "    Poll Interval:  " . $ntpPreset['poll_interval'] . " s" . PHP_EOL;
    echo "    Max Offset:     " . $ntpPreset['maximum_offset'] . " ms" . PHP_EOL;
} else {
    echo "  Configured Preset: Default (Not yet customized in Presets -> Protocol Defaults)" . PHP_EOL;
}

if (stripos(PHP_OS, 'WIN') === false) {
    // Linux / RHEL checks
    $chronyActive = trim((string)@shell_exec('systemctl is-active chronyd 2>/dev/null'));
    $ntpdActive = trim((string)@shell_exec('systemctl is-active ntpd 2>/dev/null'));
    echo "  RHEL chronyd service: " . ($chronyActive === 'active' ? 'ACTIVE (running)' : ($chronyActive ?: 'Not active / not installed')) . PHP_EOL;
    echo "  RHEL ntpd service:    " . ($ntpdActive === 'active' ? 'ACTIVE (running)' : ($ntpdActive ?: 'Not active / not installed')) . PHP_EOL;

    $port123 = @shell_exec('ss -ulnp 2>/dev/null | grep :123');
    if ($port123) {
        echo "  UDP Port 123 Listening: YES" . PHP_EOL;
        echo "    " . trim($port123) . PHP_EOL;
    } else {
        echo "  UDP Port 123 Listening: NO (chronyd/ntpd not listening on UDP 123)" . PHP_EOL;
    }

    if ($chronyActive === 'active') {
        $tracking = @shell_exec('chronyc tracking 2>/dev/null');
        if ($tracking) {
            echo "  Chrony Tracking:" . PHP_EOL;
            foreach (explode("\n", trim($tracking)) as $line) {
                if (preg_match('/(Reference ID|Stratum|System time|Last offset|RMS offset)/i', $line)) {
                    echo "    " . trim($line) . PHP_EOL;
                }
            }
        }
    }
} else {
    echo "  Windows Time Service check:" . PHP_EOL;
    $w32tm = @shell_exec('w32tm /query /status 2>&1');
    if ($w32tm && stripos($w32tm, 'error') === false) {
        echo "    " . substr(trim($w32tm), 0, 160) . "..." . PHP_EOL;
    } else {
        echo "    w32tm status available via Administrator prompt." . PHP_EOL;
    }
}
echo PHP_EOL;

// -------------------------------------------------------------
// 2. NETFLOW PROTOCOL STATUS
// -------------------------------------------------------------
echo "[2] NetFlow / IPFIX Protocol Status" . PHP_EOL;
echo "-----------------------------------------------------------------" . PHP_EOL;
$netflowPreset = $presets['netflow'] ?? null;
if ($netflowPreset) {
    echo "  Configured Preset: YES" . PHP_EOL;
    echo "    Version:        " . ($netflowPreset['version'] === '10' ? 'IPFIX (v10)' : 'NetFlow v' . $netflowPreset['version']) . PHP_EOL;
    echo "    Collector IP:   " . ($netflowPreset['collector'] ?: '0.0.0.0 (all interfaces)') . PHP_EOL;
    echo "    Collector Port: " . $netflowPreset['port'] . " UDP" . PHP_EOL;
    echo "    Sampling Rate:  1:" . $netflowPreset['sampling_rate'] . PHP_EOL;
    echo "    Active Timeout: " . $netflowPreset['active_timeout'] . " s" . PHP_EOL;
} else {
    echo "  Configured Preset: Default (Version 9, Port 2055 UDP, Sampling 1:1)" . PHP_EOL;
}

if (stripos(PHP_OS, 'WIN') === false) {
    // Linux / RHEL checks for nfcapd / flow-capture
    $nfcapd = @shell_exec('pgrep -a nfcapd 2>/dev/null');
    $flowCapture = @shell_exec('pgrep -a flow-capture 2>/dev/null');
    if ($nfcapd) {
        echo "  RHEL nfcapd daemon: RUNNING (" . trim($nfcapd) . ")" . PHP_EOL;
    } elseif ($flowCapture) {
        echo "  RHEL flow-capture daemon: RUNNING (" . trim($flowCapture) . ")" . PHP_EOL;
    } else {
        echo "  RHEL flow collector daemon: NOT RUNNING (install 'nfdump' and run nfcapd -p 2055)" . PHP_EOL;
    }

    $port2055 = @shell_exec('ss -ulnp 2>/dev/null | grep -E ":(2055|9995)"');
    if ($port2055) {
        echo "  NetFlow Port Listening: YES" . PHP_EOL;
        echo "    " . trim($port2055) . PHP_EOL;
    } else {
        echo "  NetFlow Port Listening: NO (No process listening on UDP 2055/9995)" . PHP_EOL;
    }
} else {
    echo "  Windows environment: NetFlow exporter packages (nfdump/nfcapd) run natively on Linux/RHEL." . PHP_EOL;
}
echo PHP_EOL;

// -------------------------------------------------------------
// 3. DEVICE STATUS & AVAILABILITY EVENTS
// -------------------------------------------------------------
echo "[3] Device Availability & Syslog Events Status" . PHP_EOL;
echo "-----------------------------------------------------------------" . PHP_EOL;
$totalDevices = db_fetch_cell("SELECT COUNT(*) FROM host WHERE deleted=''");
$stateRows = db_fetch_cell("SELECT COUNT(*) FROM plugin_icct_nms_syslog_availability_state");
$totalEvents = db_fetch_cell("SELECT COUNT(*) FROM plugin_icct_nms_syslog_events");
$availEvents = db_fetch_cell("SELECT COUNT(*) FROM plugin_icct_nms_syslog_events WHERE event_origin='availability'");
echo "  Total Inventory Devices:        $totalDevices" . PHP_EOL;
echo "  Availability State Tracked:     $stateRows" . PHP_EOL;
echo "  Total Syslog Events:            $totalEvents" . PHP_EOL;
echo "  Device Availability Events:     $availEvents" . PHP_EOL;
$latest = db_fetch_assoc("SELECT host_id, event_origin, severity, message, event_time FROM plugin_icct_nms_syslog_events ORDER BY id DESC LIMIT 3");
if ($latest) {
    echo "  Recent Events:" . PHP_EOL;
    foreach ($latest as $ev) {
        echo "    [{$ev['event_time']}] [Host {$ev['host_id']}] [{$ev['event_origin']}/{$ev['severity']}] {$ev['message']}" . PHP_EOL;
    }
}
echo PHP_EOL;

// -------------------------------------------------------------
// 4. MIB REPOSITORY & OFFLINE PARSER
// -------------------------------------------------------------
echo "[4] SNMP MIB Repository Status" . PHP_EOL;
echo "-----------------------------------------------------------------" . PHP_EOL;
require_once dirname(__DIR__, 2) . '/snmp/mibs/services/mib_repository_service.php';
$bundles = icct_mib_list();
echo "  Installed MIB Bundles: " . count($bundles) . PHP_EOL;
$mibsDir = dirname(__DIR__, 2) . '/shared/assets/mibs';
$bundledMibs = glob($mibsDir . '/*.txt');
echo "  Bundled RFC MIB Definitions: " . count($bundledMibs) . " files" . PHP_EOL;
echo PHP_EOL;

echo "=================================================================" . PHP_EOL;
echo "                     DIAGNOSTIC COMPLETE                         " . PHP_EOL;
echo "=================================================================" . PHP_EOL;
