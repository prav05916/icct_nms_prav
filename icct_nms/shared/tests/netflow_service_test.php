<?php
/**
 * NetFlow & IPFIX service unit tests — pure PHP stubs, no framework, no network.
 * Tests:
 *   - v5, v7, v9, IPFIX (v10) packet decoding (Requirement 5.3.3.4.1)
 *   - Application mapping from ports (Requirement 5.3.3.4.4 (c))
 *   - WAN vs LAN classification (Requirement 5.3.3.4.2)
 *   - Anomaly detection triggers (Requirement 5.3.3.4.5)
 *   - Bytes human formatting
 *   - Upsert and status
 */

$netflowDevices = [];
$netflowState   = [];
$talkers        = [];
$records        = [];
$anomalyRecords = [];

function db_fetch_row_prepared($sql, $args) {
    $id = (int)$args[0];
    if (!isset($GLOBALS['netflowDevices'][$id]) && !isset($GLOBALS['netflowState'][$id])) {
        return [];
    }
    return array_merge(
        $GLOBALS['netflowDevices'][$id] ?? [],
        $GLOBALS['netflowState'][$id] ?? []
    );
}

function db_fetch_cell_prepared($sql, $args) {
    if (str_contains($sql, 'top_json')) {
        $id = (int)$args[0];
        return $GLOBALS['netflowState'][$id]['top_json'] ?? '[]';
    }
    return 0;
}

function db_fetch_cell($sql) { return 0; }

function db_fetch_row($sql) { return []; }

function db_fetch_assoc_prepared($sql, $args) {
    if (str_contains($sql, 'netflow_records')) {
        $id = (int)$args[0];
        return array_values(array_filter($GLOBALS['records'], fn($r) => ($r['host_id'] ?? 0) === $id));
    }
    if (str_contains($sql, 'top_talkers')) {
        $id = (int)$args[0];
        return array_values(array_filter($GLOBALS['talkers'], fn($t) => ($t['host_id'] ?? 0) === $id));
    }
    if (str_contains($sql, 'netflow_anomalies')) {
        $id = (int)$args[0];
        return array_values(array_filter($GLOBALS['anomalyRecords'], fn($a) => ($a['host_id'] ?? 0) === $id));
    }
    return [];
}

function db_fetch_assoc($sql) {
    if (str_contains($sql, 'netflow_anomalies')) return [];
    $rows = [];
    foreach ($GLOBALS['netflowDevices'] as $id => $dev) {
        $rows[] = array_merge($dev, $GLOBALS['netflowState'][$id] ?? []);
    }
    return $rows;
}

function icct_backend_category_execute($sql, $args = []) {
    if (str_contains($sql, 'INSERT INTO plugin_icct_nms_netflow_devices')) {
        $GLOBALS['netflowDevices'][$args[0]] = [
            'host_id'          => $args[0],
            'enabled'          => 1,
            'exporter_address' => $args[1],
            'hostname'         => $args[1],
            'description'      => 'Test Device ' . $args[0],
            'version'          => $args[2],
            'sampling_rate'    => $args[3],
            'active_timeout'   => $args[4],
            'inactive_timeout' => $args[5],
        ];
    }
    if (str_contains($sql, 'INSERT INTO plugin_icct_nms_netflow_state')) {
        $GLOBALS['netflowState'][$args[0]] = [
            'host_id'        => $args[0],
            'status'         => 'listening',
            'collector_path' => $args[1],
            'total_bytes'    => 0,
            'total_packets'  => 0,
            'total_flows'    => 0,
            'top_json'       => '[]',
            'anomaly_status' => 'normal',
            'anomaly_reason' => '',
            'last_error'     => '',
            'last_polled'    => date('Y-m-d H:i:s'),
        ];
    }
    if (str_contains($sql, 'UPDATE plugin_icct_nms_netflow_state')) {
        $id = end($args);
        if (isset($GLOBALS['netflowState'][$id])) {
            if (str_contains($sql, 'total_bytes = total_bytes +')) {
                $GLOBALS['netflowState'][$id]['status'] = 'active';
                $GLOBALS['netflowState'][$id]['total_bytes'] += (int)$args[0];
                $GLOBALS['netflowState'][$id]['total_packets'] += (int)$args[1];
                $GLOBALS['netflowState'][$id]['total_flows'] += (int)$args[2];
                $GLOBALS['netflowState'][$id]['top_json'] = (string)$args[3];
            } elseif (count($args) >= 5) {
                $GLOBALS['netflowState'][$id]['status'] = 'active';
                $GLOBALS['netflowState'][$id]['total_bytes'] = (int)$args[0];
                $GLOBALS['netflowState'][$id]['total_packets'] = (int)$args[1];
                $GLOBALS['netflowState'][$id]['total_flows'] = (int)$args[2];
                $GLOBALS['netflowState'][$id]['top_json'] = (string)$args[3];
            }
        }
    }
    if (str_contains($sql, 'INSERT INTO plugin_icct_nms_netflow_top_talkers')) {
        $GLOBALS['talkers'][] = [
            'host_id'  => $args[0],
            'src_ip'   => $args[1],
            'dst_ip'   => $args[2] ?? '10.0.0.1',
            'protocol' => $args[3] ?? 'TCP',
            'src_port' => $args[4] ?? 80,
            'dst_port' => $args[5] ?? 443,
            'bytes'    => $args[6] ?? 0,
            'packets'  => $args[7] ?? 0,
        ];
    }
    if (str_contains($sql, 'INSERT INTO plugin_icct_nms_netflow_records')) {
        $GLOBALS['records'][] = [
            'host_id'      => $args[0],
            'flow_version' => $args[1],
            'src_ip'       => $args[2],
            'dst_ip'       => $args[3],
            'src_port'     => $args[4],
            'dst_port'     => $args[5],
            'protocol'     => $args[6],
            'app_name'     => $args[7],
            'network_type' => $args[8],
            'bytes'        => $args[9],
            'packets'      => $args[10],
            'tcp_flags'    => $args[11],
            'tos'          => $args[12],
            'recorded_at'  => date('Y-m-d H:i:s'),
        ];
    }
    if (str_contains($sql, 'INSERT INTO plugin_icct_nms_netflow_anomalies')) {
        $GLOBALS['anomalyRecords'][] = [
            'host_id'      => $args[0],
            'anomaly_type' => $args[1],
            'severity'     => $args[2],
            'source_ip'    => $args[3],
            'target_ip'    => $args[4],
            'target_port'  => $args[5],
            'description'  => $args[6],
            'metric_value' => $args[7],
            'detected_at'  => date('Y-m-d H:i:s'),
        ];
    }
}

function icct_backend_current_user_id() { return 1; }

require __DIR__ . '/../../monitoring/services/netflow_service.php';

$pass = 0;
$fail = 0;
function t($label, $condition) {
    global $pass, $fail;
    if ($condition) { $pass++; }
    else { echo "FAIL: $label\n"; $fail++; }
}

// ===== Test 1: Protocol name mapping =====
t('Proto TCP', icct_nms_netflow_proto_name(6) === 'TCP');
t('Proto UDP', icct_nms_netflow_proto_name(17) === 'UDP');
t('Proto ICMP', icct_nms_netflow_proto_name(1) === 'ICMP');
t('Proto GRE', icct_nms_netflow_proto_name(47) === 'GRE');
t('Proto ESP', icct_nms_netflow_proto_name(50) === 'ESP');
t('Proto unknown', str_starts_with(icct_nms_netflow_proto_name(255), 'IP-'));

// ===== Test 2: LAN vs WAN classification =====
t('Private 10.x', icct_nms_is_private_ip('10.0.0.1') === true);
t('Private 192.168.x', icct_nms_is_private_ip('192.168.1.1') === true);
t('Private 172.16.x', icct_nms_is_private_ip('172.16.0.1') === true);
t('Private 127.x', icct_nms_is_private_ip('127.0.0.1') === true);
t('Public 8.8.8.8', icct_nms_is_private_ip('8.8.8.8') === false);
t('Public 1.1.1.1', icct_nms_is_private_ip('1.1.1.1') === false);
t('Public 203.0.113.1', icct_nms_is_private_ip('203.0.113.1') === false);

// ===== Test 3: Application port mapping =====
t('Port 80 -> HTTP', icct_nms_port_to_app(12345, 80) === 'HTTP');
t('Port 443 -> HTTPS', icct_nms_port_to_app(54321, 443) === 'HTTPS');
t('Port 53 -> DNS', icct_nms_port_to_app(54321, 53) === 'DNS');
t('Port 22 -> SSH', icct_nms_port_to_app(54321, 22) === 'SSH');
t('Port 23 -> Telnet', icct_nms_port_to_app(54321, 23) === 'Telnet');
t('Port 123 -> NTP', icct_nms_port_to_app(54321, 123) === 'NTP');
t('Port 161 -> SNMP', icct_nms_port_to_app(54321, 161) === 'SNMP');
t('Port 179 -> BGP', icct_nms_port_to_app(54321, 179) === 'BGP');
t('Port 445 -> SMB', icct_nms_port_to_app(54321, 445) === 'SMB');
t('Port 3389 -> RDP', icct_nms_port_to_app(54321, 3389) === 'RDP');
t('Port 21 -> FTP', icct_nms_port_to_app(54321, 21) === 'FTP-Control');
t('Port 69 -> TFTP', icct_nms_port_to_app(54321, 69) === 'TFTP');
t('Proto ICMP -> ICMP-Echo', icct_nms_port_to_app(0, 0, 'ICMP') === 'ICMP-Echo');

// ===== Test 4: Bytes human readable =====
t('Bytes 512 -> B', icct_nms_netflow_bytes_human(512) === '512 B');
t('Bytes 1536 -> KB', icct_nms_netflow_bytes_human(1536) === '1.5 KB');
t('Bytes 1073741824 -> GB', icct_nms_netflow_bytes_human(1073741824) === '1 GB');
t('Bytes MB', icct_nms_netflow_bytes_human(2097152) === '2 MB');

// ===== Test 5: NetFlow v5 packet decoding =====
// Build a valid NetFlow v5 packet: Header (24 bytes) + 1 record (48 bytes)
$v5Header = pack('n', 5) . pack('n', 1) . str_repeat("\0", 20); // version=5, count=1
$v5Record = pack('N', ip2long('192.168.1.10'))  // srcIP       (+0, 4 bytes)
          . pack('N', ip2long('10.0.0.1'))       // dstIP       (+4, 4 bytes)
          . pack('N', 0)                          // nexthop     (+8, 4 bytes)
          . pack('n', 0) . pack('n', 0)          // input/output (+12, 2+2 bytes)
          . pack('N', 100)                        // packets     (+16, 4 bytes)
          . pack('N', 50000)                      // bytes       (+20, 4 bytes)
          . pack('N', 0) . pack('N', 0)          // first/last  (+24, 4+4 bytes)
          . pack('n', 12345) . pack('n', 80)     // srcPort, dstPort (+32, 2+2 bytes)
          . "\0"                                  // pad1        (+36, 1 byte)
          . "\x18"                                // tcp_flags   (+37, 1 byte = PSH+ACK)
          . chr(6)                                // proto       (+38, 1 byte = TCP)
          . chr(0)                                // tos         (+39, 1 byte)
          . pack('n', 0) . pack('n', 0)          // src_as, dst_as (+40, 2+2 bytes)
          . chr(0) . chr(0)                       // src_mask, dst_mask (+44, 1+1 bytes)
          . pack('n', 0);                         // pad2        (+46, 2 bytes) = 48 total

$v5Packet = $v5Header . $v5Record;
$v5Flows = icct_nms_netflow_decode_packet($v5Packet, '192.168.1.10');
t('v5 decode count', count($v5Flows) === 1);
t('v5 decode version', $v5Flows[0]['version'] === 5);
t('v5 decode srcIP', $v5Flows[0]['src_ip'] === '192.168.1.10');
t('v5 decode dstIP', $v5Flows[0]['dst_ip'] === '10.0.0.1');
t('v5 decode srcPort', $v5Flows[0]['src_port'] === 12345);
t('v5 decode dstPort', $v5Flows[0]['dst_port'] === 80);
t('v5 decode proto', $v5Flows[0]['protocol'] === 'TCP');
t('v5 decode bytes', $v5Flows[0]['bytes'] === 50000);
t('v5 decode packets', $v5Flows[0]['packets'] === 100);
t('v5 decode tcp_flags', $v5Flows[0]['tcp_flags'] === 0x18);
t('v5 decode app', $v5Flows[0]['app_name'] === 'HTTP');
t('v5 decode net LAN (both private)', $v5Flows[0]['network_type'] === 'lan');

// ===== Test 6: NetFlow v7 packet decoding =====
$v7Header = pack('n', 7) . pack('n', 1) . str_repeat("\0", 20);
$v7Record = pack('N', ip2long('10.0.0.5'))      // srcIP       (+0, 4 bytes)
          . pack('N', ip2long('8.8.8.8'))        // dstIP       (+4, 4 bytes)
          . pack('N', 0)                          // nexthop     (+8, 4 bytes)
          . pack('n', 0) . pack('n', 0)          // input/output (+12, 2+2 bytes)
          . pack('N', 50)                         // packets     (+16, 4 bytes)
          . pack('N', 25000)                      // bytes       (+20, 4 bytes)
          . pack('N', 0) . pack('N', 0)          // first/last  (+24, 4+4 bytes)
          . pack('n', 54321) . pack('n', 443)    // srcPort, dstPort (+32, 2+2 bytes)
          . "\0"                                  // pad1        (+36, 1 byte)
          . "\x02"                                // tcp_flags   (+37, 1 byte = SYN)
          . chr(6)                                // proto       (+38, 1 byte = TCP)
          . chr(0)                                // tos         (+39, 1 byte)
          . pack('n', 0) . pack('n', 0)          // src_as, dst_as (+40, 2+2 bytes)
          . chr(0) . chr(0)                       // masks       (+44, 1+1 bytes)
          . pack('n', 0)                          // pad2        (+46, 2 bytes) = 48 bytes
          . pack('N', 0);                         // router_sc   (+48, 4 bytes, v7 extra) = 52 total

$v7Packet = $v7Header . $v7Record;
$v7Flows = icct_nms_netflow_decode_packet($v7Packet, '10.0.0.5');
t('v7 decode count', count($v7Flows) === 1);
t('v7 decode version', $v7Flows[0]['version'] === 7);
t('v7 decode srcIP', $v7Flows[0]['src_ip'] === '10.0.0.5');
t('v7 decode dstIP', $v7Flows[0]['dst_ip'] === '8.8.8.8');
t('v7 decode app HTTPS', $v7Flows[0]['app_name'] === 'HTTPS');
t('v7 decode net WAN (one public)', $v7Flows[0]['network_type'] === 'wan');

// ===== Test 7: v9 fallback for small packets =====
$v9Header = pack('n', 9) . pack('n', 0) . str_repeat("\0", 16); // version=9, count=0
$v9Flows = icct_nms_netflow_decode_packet($v9Header, '192.168.1.1');
t('v9 fallback count', count($v9Flows) === 1);
t('v9 fallback version', $v9Flows[0]['version'] === 9);
t('v9 fallback app', $v9Flows[0]['app_name'] === 'NetFlow');

// ===== Test 8: IPFIX (v10) fallback for small packets =====
$ipfixHeader = pack('n', 10) . pack('n', 16) . str_repeat("\0", 12); // version=10, msg_length=16
$ipfixFlows = icct_nms_netflow_decode_packet($ipfixHeader, '172.16.0.1');
t('IPFIX fallback count', count($ipfixFlows) === 1);
t('IPFIX fallback version', $ipfixFlows[0]['version'] === 10);
t('IPFIX fallback app', $ipfixFlows[0]['app_name'] === 'IPFIX');
t('IPFIX fallback net LAN', $ipfixFlows[0]['network_type'] === 'lan');

// ===== Test 9: Anomaly detection - Worm (port 445) =====
$wormFlows = [
    ['src_ip' => '192.168.1.50', 'dst_ip' => '192.168.1.100', 'src_port' => 54321, 'dst_port' => 445, 'protocol' => 'TCP', 'packets' => 10, 'bytes' => 2048, 'tcp_flags' => 0x18],
];
$wormAnomalies = icct_nms_netflow_detect_anomalies(1, $wormFlows);
t('Worm detection triggered', count($wormAnomalies) >= 1);
t('Worm type correct', $wormAnomalies[0]['anomaly_type'] === 'worm_virus');
t('Worm severity critical', $wormAnomalies[0]['severity'] === 'critical');

// ===== Test 10: Anomaly detection - Port Scan =====
$scanFlows = [];
for ($i = 1; $i <= 15; $i++) {
    $scanFlows[] = ['src_ip' => '10.0.0.99', 'dst_ip' => '10.0.0.1', 'src_port' => 54321, 'dst_port' => 1000 + $i, 'protocol' => 'TCP', 'packets' => 1, 'bytes' => 64, 'tcp_flags' => 0x02];
}
$scanAnomalies = icct_nms_netflow_detect_anomalies(1, $scanFlows);
$portScanFound = false;
foreach ($scanAnomalies as $a) {
    if ($a['anomaly_type'] === 'port_scan') $portScanFound = true;
}
t('Port scan detected', $portScanFound);

// ===== Test 11: Anomaly detection - SYN Flood (DoS) =====
$synFlows = [];
for ($i = 0; $i < 60; $i++) {
    $synFlows[] = ['src_ip' => '10.0.0.' . ($i + 1), 'dst_ip' => '10.0.0.100', 'src_port' => 54321, 'dst_port' => 80, 'protocol' => 'TCP', 'packets' => 1, 'bytes' => 64, 'tcp_flags' => 0x02]; // SYN only
}
$synAnomalies = icct_nms_netflow_detect_anomalies(1, $synFlows);
$dosFound = false;
foreach ($synAnomalies as $a) {
    if ($a['anomaly_type'] === 'dos_attack') $dosFound = true;
}
t('SYN Flood DoS detected', $dosFound);

// ===== Test 12: Anomaly detection - Volume Surge =====
$surgeFlows = [
    ['src_ip' => '10.0.0.1', 'dst_ip' => '10.0.0.2', 'src_port' => 80, 'dst_port' => 12345, 'protocol' => 'TCP', 'packets' => 50000, 'bytes' => 30000000, 'tcp_flags' => 0x18],
];
$surgeAnomalies = icct_nms_netflow_detect_anomalies(1, $surgeFlows);
$surgeFound = false;
foreach ($surgeAnomalies as $a) {
    if ($a['anomaly_type'] === 'volume_surge') $surgeFound = true;
}
t('Volume surge detected', $surgeFound);

// ===== Test 13: Anomaly detection - TCP Null Scan =====
$nullFlows = [
    ['src_ip' => '10.0.0.50', 'dst_ip' => '10.0.0.100', 'src_port' => 54321, 'dst_port' => 80, 'protocol' => 'TCP', 'packets' => 1, 'bytes' => 40, 'tcp_flags' => 0],
];
$nullAnomalies = icct_nms_netflow_detect_anomalies(1, $nullFlows);
$nullScanFound = false;
foreach ($nullAnomalies as $a) {
    if ($a['anomaly_type'] === 'tcp_flag_anomaly') $nullScanFound = true;
}
t('TCP Null Scan detected', $nullScanFound);

// ===== Test 14: Anomaly detection - Telnet (Mirai) port 2323 =====
$miraiFlows = [
    ['src_ip' => '10.0.0.50', 'dst_ip' => '10.0.0.100', 'src_port' => 54321, 'dst_port' => 2323, 'protocol' => 'TCP', 'packets' => 5, 'bytes' => 200, 'tcp_flags' => 0x02],
];
$miraiAnomalies = icct_nms_netflow_detect_anomalies(1, $miraiFlows);
$miraiFound = false;
foreach ($miraiAnomalies as $a) {
    if ($a['anomaly_type'] === 'worm_virus' && $a['target_port'] === 2323) $miraiFound = true;
}
t('Mirai botnet detection', $miraiFound);

// ===== Test 15: Record flows (persistent storage) =====
$GLOBALS['records'] = [];
$GLOBALS['anomalyRecords'] = [];
$GLOBALS['talkers'] = [];
icct_nms_netflow_upsert(100, ['exporter_ip' => '10.1.1.1', 'collector_ip' => '10.1.1.2', 'collector_port' => 2055]);
$testFlows = [
    ['version' => 5, 'src_ip' => '192.168.1.10', 'dst_ip' => '10.0.0.1', 'src_port' => 12345, 'dst_port' => 80, 'protocol' => 'TCP', 'app_name' => 'HTTP', 'network_type' => 'lan', 'bytes' => 5000, 'packets' => 10, 'tcp_flags' => 0x18, 'tos' => 0],
    ['version' => 5, 'src_ip' => '192.168.1.10', 'dst_ip' => '8.8.8.8', 'src_port' => 54321, 'dst_port' => 53, 'protocol' => 'UDP', 'app_name' => 'DNS', 'network_type' => 'wan', 'bytes' => 200, 'packets' => 2, 'tcp_flags' => 0, 'tos' => 0],
];
$result = icct_nms_netflow_record_flows(100, $testFlows);
t('Record flows count', $result['records'] === 2);
t('Records stored', count($GLOBALS['records']) === 2);
t('Top talkers stored', count($GLOBALS['talkers']) >= 2);

// ===== Test 16: Status after upsert =====
$r = icct_nms_netflow_status(100, false);
t('Status after upsert', !empty($r));
t('Status exporter_address', ($r['exporter_address'] ?? '') !== '');

// ===== Test 17: Empty status for non-existent device =====
assert(icct_nms_netflow_status(9999, false) === [], 'Empty for missing device');

echo "\nNetFlow service tests: $pass passed, $fail failed.\n";
if ($fail > 0) exit(1);
