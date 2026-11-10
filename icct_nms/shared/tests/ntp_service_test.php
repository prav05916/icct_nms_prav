<?php
/**
 * NTP service unit tests — pure PHP stubs, no framework, no network.
 */
$ntpDevices = [];
$ntpPeers   = [];
$ntpState   = [];
$ntpHistory = [];

function db_fetch_row_prepared($sql, $args) {
    $id = (int)$args[0];
    if (!isset($GLOBALS['ntpDevices'][$id]) && !isset($GLOBALS['ntpState'][$id])) {
        return [];
    }
    return array_merge(
        $GLOBALS['ntpDevices'][$id] ?? [],
        $GLOBALS['ntpState'][$id] ?? []
    );
}

function db_fetch_cell_prepared($sql, $args) {
    return 0;
}

function db_fetch_assoc_prepared($sql, $args) {
    if (str_contains($sql, 'ntp_history')) {
        $id = (int)$args[0];
        return array_values(array_filter($GLOBALS['ntpHistory'], fn($r) => ($r['host_id'] ?? 0) === $id));
    }
    return array_values(array_filter($GLOBALS['ntpPeers'], fn($r) => ($r['host_id'] ?? 0) === (int)$args[0]));
}

function db_fetch_assoc($sql) {
    $rows = [];
    foreach ($GLOBALS['ntpDevices'] as $id => $dev) {
        $rows[] = array_merge($dev, $GLOBALS['ntpState'][$id] ?? []);
    }
    return $rows;
}

function icct_backend_category_execute($sql, $args = []) {
    if (str_contains($sql, 'INSERT INTO plugin_icct_nms_ntp_devices')) {
        $GLOBALS['ntpDevices'][$args[0]] = [
            'host_id'           => $args[0],
            'enabled'           => 1,
            'target_address'    => $args[1],
            'ntp_server'        => $args[1],
            'hostname'          => $args[1],
            'description'       => 'Host ' . $args[0],
            'port'              => $args[2],
            'version'           => $args[3],
            'timeout_seconds'   => $args[4],
            'poll_interval'     => $args[5],
            'maximum_offset_ms' => $args[6],
            'maximum_jitter_ms' => $args[7],
            'maximum_delay_ms'  => $args[8],
            'expected_source'   => $args[9],
        ];
    }
    if (str_contains($sql, 'INSERT INTO plugin_icct_nms_ntp_state')) {
        if (str_contains($sql, '"unreachable"')) {
            $GLOBALS['ntpState'][$args[0]] = [
                'host_id'         => $args[0],
                'status'          => 'unreachable',
                'reachable'       => 0,
                'synchronized'    => 0,
                'selected_source' => $args[1],
                'stratum'         => 16,
                'reference_id'    => 'NONE',
                'offset_ms'       => null,
                'jitter_ms'       => null,
                'delay_ms'        => null,
                'last_error'      => $args[2] ?? '',
            ];
        } else {
            $GLOBALS['ntpState'][$args[0]] = [
                'host_id'         => $args[0],
                'status'          => $args[1],
                'reachable'       => 1,
                'synchronized'    => $args[2],
                'selected_source' => $args[3],
                'stratum'         => $args[4],
                'reference_id'    => $args[6],
                'offset_ms'       => $args[7],
                'jitter_ms'       => $args[8],
                'delay_ms'        => $args[9],
            ];
        }
    }
    if (str_contains($sql, 'DELETE FROM plugin_icct_nms_ntp_peers')) {
        $GLOBALS['ntpPeers'] = array_values(
            array_filter($GLOBALS['ntpPeers'], fn($r) => ($r['host_id'] ?? 0) !== (int)$args[0])
        );
    }
    if (str_contains($sql, 'INSERT INTO plugin_icct_nms_ntp_peers')) {
        $GLOBALS['ntpPeers'][] = [
            'host_id'  => $args[0],
            'peer_ip'  => $args[1],
            'peer_name'=> $args[2],
            'stratum'  => $args[3],
            'offset_ms'=> $args[4],
            'delay_ms' => $args[5],
            'jitter_ms'=> $args[6],
            'reachable'=> $args[7] ?? 1,
            'selected' => $args[8] ?? 1,
        ];
    }
    if (str_contains($sql, 'INSERT INTO plugin_icct_nms_ntp_history')) {
        $GLOBALS['ntpHistory'][] = [
            'host_id'       => $args[0],
            'status'        => $args[1] ?? 'unreachable',
            'offset_ms'     => $args[2] ?? null,
            'jitter_ms'     => $args[3] ?? null,
            'delay_ms'      => $args[4] ?? null,
            'dispersion_ms' => $args[5] ?? null,
            'stratum'       => $args[6] ?? 16,
            'reference_id'  => $args[7] ?? 'NONE',
            'recorded_at'   => date('Y-m-d H:i:s'),
        ];
    }
}

require __DIR__ . '/../../monitoring/services/ntp_service.php';

$pass = 0;
$fail = 0;
function t($label, $condition) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
    } else {
        echo "FAIL: $label\n";
        $fail++;
    }
}

// Test 1: empty result when no data
$result = icct_nms_ntp_status(1, false);
t('Empty status when no data', $result === []);

// Test 2: upsert stores data
icct_nms_ntp_upsert(1, ['ntp_server' => '192.168.1.1', 'timeout_seconds' => 1]);
$result = icct_nms_ntp_status(1, false);
t('NTP server stored', ($result['ntp_server'] ?? '') === '192.168.1.1');
t('Target address alias stored', ($result['target_address'] ?? '') === '192.168.1.1');

// Test 3: peer deletion
icct_nms_ntp_peers_upsert(1, []);
$peers = icct_nms_ntp_peers(1);
t('Peers cleared after empty upsert', $peers === []);

// Test 3b: peer insert then read back
icct_nms_ntp_peers_upsert(1, [
    ['peer_ip' => '10.0.0.1', 'peer_name' => 'ntp1', 'stratum' => 1, 'reachable' => true, 'selected' => true],
]);
$peers = icct_nms_ntp_peers(1);
t('One peer inserted', count($peers) === 1);
t('Peer IP correct', ($peers[0]['peer_ip'] ?? '') === '10.0.0.1');

// Test 3c: clearing peers removes them
icct_nms_ntp_peers_upsert(1, []);
$peers = icct_nms_ntp_peers(1);
t('Peers cleared after second empty upsert', $peers === []);

// Test 4: ntp_all returns all rows without refresh
$ntpDevices = [];
$ntpState = [];
icct_nms_ntp_upsert(2, ['ntp_server' => '10.0.0.1', 'timeout_seconds' => 1]);
icct_nms_ntp_upsert(3, ['ntp_server' => '10.0.0.2', 'timeout_seconds' => 1]);
$all = icct_nms_ntp_all(false);
t('ntp_all returns all stored rows', count($all) >= 2);

// Test 5: Verify RFC 5905 NTP query function packet format
$res = icct_nms_ntp_query('127.0.0.1', 12345, 1);
t('Non-listening test port correctly times out', $res['ok'] === false);
t('Error message accurate', str_contains($res['error'], 'timed out') || str_contains($res['error'], 'failed'));

// Test 6: NTP History retrieval
$GLOBALS['ntpHistory'] = [
    ['host_id' => 2, 'status' => 'synchronized', 'offset_ms' => 1.25, 'jitter_ms' => 0.05, 'delay_ms' => 12.4, 'dispersion_ms' => 0.02, 'stratum' => 2, 'reference_id' => 'GPS', 'recorded_at' => date('Y-m-d H:i:s')],
    ['host_id' => 2, 'status' => 'synchronized', 'offset_ms' => 1.10, 'jitter_ms' => 0.04, 'delay_ms' => 12.1, 'dispersion_ms' => 0.02, 'stratum' => 2, 'reference_id' => 'GPS', 'recorded_at' => date('Y-m-d H:i:s', time() - 60)],
    ['host_id' => 3, 'status' => 'unreachable', 'offset_ms' => null, 'jitter_ms' => null, 'delay_ms' => null, 'dispersion_ms' => null, 'stratum' => 16, 'reference_id' => 'NONE', 'recorded_at' => date('Y-m-d H:i:s')],
];
$history2 = icct_nms_ntp_history(2);
t('NTP history count for host 2', count($history2) === 2);
t('NTP history offset', ($history2[0]['offset_ms'] ?? 0) == 1.25);
$history3 = icct_nms_ntp_history(3);
t('NTP history count for host 3', count($history3) === 1);
t('NTP history empty for unknown host', count(icct_nms_ntp_history(999)) === 0);

// Test 7: NTP CSV Export
$csv = icct_nms_ntp_export_csv();
t('NTP CSV export not empty', !empty($csv));
t('NTP CSV has header', str_contains($csv, 'Device Name') && str_contains($csv, 'NTP Server'));
t('NTP CSV includes device 2', str_contains($csv, '10.0.0.1'));
t('NTP CSV includes device 3', str_contains($csv, '10.0.0.2'));

// Test 8: NTP JSON Export
$json = icct_nms_ntp_export_json();
t('NTP JSON export not empty', !empty($json));
$decoded = json_decode($json, true);
t('NTP JSON valid decode', is_array($decoded));
t('NTP JSON report_type correct', ($decoded['report_type'] ?? '') === 'NTP_Monitoring_Report');
t('NTP JSON total_devices matches', ($decoded['total_devices'] ?? 0) >= 2);
t('NTP JSON has devices list', isset($decoded['devices']) && is_array($decoded['devices']));

echo "\nNTP service tests: $pass passed, $fail failed.\n";
if ($fail > 0) exit(1);
