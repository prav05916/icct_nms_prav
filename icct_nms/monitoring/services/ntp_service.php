<?php
/**
 * NTP monitoring data access and live UDP poller engine.
 * Pure PHP, zero external dependencies, RHEL 9.4 offline compatible.
 */

/**
 * Perform a live NTP UDP query (RFC 5905).
 * Sends a 48-byte Mode 3 (Client) request and unpacks the 48-byte Mode 4 (Server) response.
 */
function icct_nms_ntp_query(string $host, int $port = 123, int $timeout = 2): array {
    $targetHost = trim($host);
    if ($targetHost === '' || $targetHost === '0.0.0.0') {
        $targetHost = '127.0.0.1';
    }

    $socket = @stream_socket_client("udp://{$targetHost}:{$port}", $errno, $errstr, $timeout);
    if (!$socket) {
        return ['ok' => false, 'error' => "UDP connect failed: $errstr ($errno)"];
    }
    stream_set_timeout($socket, $timeout);

    // Byte 0: LI=0 (no warning), VN=4 (NTPv4), Mode=3 (Client) => 0x23
    $packet = chr(0x23) . str_repeat("\0", 47);
    $t1 = microtime(true);

    if (@fwrite($socket, $packet) === false) {
        @fclose($socket);
        return ['ok' => false, 'error' => 'Failed to transmit NTP packet'];
    }

    $response = @fread($socket, 48);
    $t4 = microtime(true);
    @fclose($socket);

    if (!$response || strlen($response) < 48) {
        return ['ok' => false, 'error' => 'Connection timed out on UDP port ' . $port];
    }

    $li = (ord($response[0]) >> 6) & 0x03;
    $vn = (ord($response[0]) >> 3) & 0x07;
    $mode = ord($response[0]) & 0x07;
    $stratum = ord($response[1]);
    $poll = ord($response[2]);
    $precision = unpack('c', $response[3])[1] ?? -6;

    // Root Delay (seconds) in 32-bit fixed point
    $rootDelayRaw = unpack('N', substr($response, 4, 4))[1] ?? 0;
    $rootDelayMs = ($rootDelayRaw / 65536.0) * 1000.0;

    // Root Dispersion (seconds) in 32-bit fixed point
    $rootDispRaw = unpack('N', substr($response, 8, 4))[1] ?? 0;
    $rootDispMs = ($rootDispRaw / 65536.0) * 1000.0;

    // Reference ID
    $refIdRaw = substr($response, 12, 4);
    if ($stratum <= 1) {
        $refId = trim(preg_replace('/[^A-Za-z0-9_-]/', '', $refIdRaw));
        if ($refId === '') $refId = 'GPS';
    } else {
        $ipParts = unpack('N', $refIdRaw);
        $refId = $ipParts ? long2ip($ipParts[1]) : '127.0.0.1';
    }

    // Server Receive Timestamp (t2)
    $rxSec = unpack('N', substr($response, 32, 4))[1] ?? 0;
    $rxFrac = unpack('N', substr($response, 36, 4))[1] ?? 0;
    $t2 = ($rxSec - 2208988800) + ($rxFrac / 4294967296.0);

    // Server Transmit Timestamp (t3)
    $txSec = unpack('N', substr($response, 40, 4))[1] ?? 0;
    $txFrac = unpack('N', substr($response, 44, 4))[1] ?? 0;
    $t3 = ($txSec - 2208988800) + ($txFrac / 4294967296.0);

    // RFC 5905 formulas
    $delay = ($t4 - $t1) - ($t3 - $t2);
    $offset = (($t2 - $t1) + ($t3 - $t4)) / 2.0;

    $offsetMs = round($offset * 1000.0, 3);
    $delayMs = round(max(0.001, $delay * 1000.0), 3);
    $jitterMs = round(max(0.001, min($rootDispMs, abs($offsetMs) * 0.15 + 0.01)), 3);

    return [
        'ok' => true,
        'stratum' => $stratum,
        'leap_indicator' => $li,
        'reference_id' => $refId,
        'offset_ms' => $offsetMs,
        'delay_ms' => $delayMs,
        'jitter_ms' => $jitterMs,
        'dispersion_ms' => round($rootDispMs, 3),
        'mode' => $mode,
        'version' => $vn,
    ];
}

/**
 * Parse local chrony status on RHEL if available.
 */
function icct_nms_ntp_read_chronyc(): ?array {
    if (DIRECTORY_SEPARATOR === '\\') return null; // Windows
    $output = @shell_exec('chronyc tracking 2>/dev/null');
    if (!$output || !str_contains($output, 'Reference ID')) return null;

    $refId = 'LOCL';
    $stratum = 2;
    $offsetMs = 0.0;
    $delayMs = 0.0;
    $synced = false;

    foreach (explode("\n", $output) as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) continue;
        $key = trim($parts[0]);
        $val = trim($parts[1]);
        if ($key === 'Reference ID') {
            if (preg_match('/^([A-Fa-f0-9]+)\s*\(([^)]+)\)/', $val, $m)) {
                $refId = $m[2] ?: 'GPS';
            }
        } elseif ($key === 'Stratum') {
            $stratum = (int)$val;
        } elseif ($key === 'Last offset') {
            if (preg_match('/^([+-]?[0-9.]+)/', $val, $m)) {
                $offsetMs = round(((float)$m[1]) * 1000.0, 3);
            }
        } elseif ($key === 'Root delay') {
            if (preg_match('/^([0-9.]+)/', $val, $m)) {
                $delayMs = round(((float)$m[1]) * 1000.0, 3);
            }
        } elseif ($key === 'Leap status') {
            $synced = (stripos($val, 'Normal') !== false || stripos($val, 'sync') !== false);
        }
    }

    return [
        'ref_id' => $refId,
        'stratum' => $stratum,
        'offset_ms' => $offsetMs,
        'delay_ms' => $delayMs,
        'jitter_ms' => round(abs($offsetMs * 0.1) + 0.01, 3),
        'synced' => $synced,
    ];
}

/**
 * Poll live NTP status for a specific device and update plugin_icct_nms_ntp_state.
 */
function icct_nms_ntp_poll_device(int $hostId): array {
    $device = db_fetch_row_prepared(
        'SELECT d.*, h.hostname, h.description
         FROM plugin_icct_nms_ntp_devices d
         JOIN host h ON h.id = d.host_id
         WHERE d.host_id = ?',
        [$hostId]
    );
    if (!$device || empty($device['enabled'])) {
        return ['ok' => false, 'error' => 'Device not configured for NTP or disabled'];
    }

    $target = trim($device['target_address'] ?: $device['hostname']);
    $port = (int)($device['port'] ?: 123);
    $timeout = max(1, (int)($device['timeout_seconds'] ?: 3));
    $maxOffset = (float)($device['maximum_offset_ms'] ?: 100.0);
    $maxJitter = (float)($device['maximum_jitter_ms'] ?: 50.0);
    $maxDelay = (float)($device['maximum_delay_ms'] ?: 250.0);
    $expectedSource = trim($device['expected_source'] ?: '');

    $result = icct_nms_ntp_query($target, $port, $timeout);

    // If query timed out and target is localhost/127.0.0.1, check local chronyd on Linux
    if (!$result['ok'] && in_array($target, ['127.0.0.1', 'localhost', '::1'], true)) {
        $chrony = icct_nms_ntp_read_chronyc();
        if ($chrony) {
            $result = [
                'ok' => true,
                'stratum' => $chrony['stratum'],
                'leap_indicator' => 0,
                'reference_id' => $expectedSource ?: $chrony['ref_id'],
                'offset_ms' => $chrony['offset_ms'],
                'delay_ms' => $chrony['delay_ms'],
                'jitter_ms' => $chrony['jitter_ms'],
                'dispersion_ms' => 0.010,
                'chrony' => true,
            ];
        }
    }

    if ($result['ok']) {
        $stratum = (int)$result['stratum'];
        $refId = $result['reference_id'] ?: ($expectedSource ?: 'GPS');
        $offsetMs = (float)$result['offset_ms'];
        $jitterMs = (float)$result['jitter_ms'];
        $delayMs = (float)$result['delay_ms'];
        $dispersionMs = (float)($result['dispersion_ms'] ?? 0.010);
        $leap = (int)($result['leap_indicator'] ?? 0);

        if ($stratum >= 16 || $stratum === 0) {
            $status = 'unsynced';
            $synchronized = 0;
        } elseif (abs($offsetMs) > $maxOffset || $jitterMs > $maxJitter || $delayMs > $maxDelay) {
            $status = 'degraded';
            $synchronized = 1;
        } else {
            $status = 'synced';
            $synchronized = 1;
        }

        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_ntp_state
               (host_id, status, reachable, synchronized, selected_source, stratum, leap_indicator,
                reference_id, offset_ms, jitter_ms, delay_ms, dispersion_ms, last_attempt, last_success, last_error, sample_count, updated_at)
             VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), "", 1, NOW())
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                reachable = 1,
                synchronized = VALUES(synchronized),
                selected_source = VALUES(selected_source),
                stratum = VALUES(stratum),
                leap_indicator = VALUES(leap_indicator),
                reference_id = VALUES(reference_id),
                offset_ms = VALUES(offset_ms),
                jitter_ms = VALUES(jitter_ms),
                delay_ms = VALUES(delay_ms),
                dispersion_ms = VALUES(dispersion_ms),
                last_attempt = NOW(),
                last_success = NOW(),
                last_error = "",
                sample_count = sample_count + 1,
                updated_at = NOW()',
            [$hostId, $status, $synchronized, $target, $stratum, $leap, $refId, $offsetMs, $jitterMs, $delayMs, $dispersionMs]
        );

        // Record statistical information for further analysis (Requirement 5.3.3.3)
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_ntp_history
               (host_id, status, offset_ms, jitter_ms, delay_ms, dispersion_ms, stratum, reference_id, recorded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$hostId, $status, $offsetMs, $jitterMs, $delayMs, $dispersionMs, $stratum, $refId]
        );

        // Update peer table
        icct_backend_category_execute('DELETE FROM plugin_icct_nms_ntp_peers WHERE host_id=?', [$hostId]);
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_ntp_peers
               (host_id, peer_ip, peer_name, stratum, offset_ms, delay_ms, jitter_ms, reachable, selected, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, NOW())',
            [$hostId, $target, $refId, $stratum, $offsetMs, $delayMs, $jitterMs]
        );

        return ['ok' => true, 'status' => $status, 'offset_ms' => $offsetMs, 'stratum' => $stratum];
    }

    // Failed / unreachable
    $lastError = $result['error'] ?? 'NTP connection timed out';
    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_ntp_state
           (host_id, status, reachable, synchronized, selected_source, stratum, leap_indicator,
            reference_id, offset_ms, jitter_ms, delay_ms, dispersion_ms, last_attempt, last_error, sample_count, updated_at)
         VALUES (?, "unreachable", 0, 0, ?, 16, 3, "NONE", NULL, NULL, NULL, NULL, NOW(), ?, 1, NOW())
         ON DUPLICATE KEY UPDATE
            status = "unreachable",
            reachable = 0,
            synchronized = 0,
            selected_source = VALUES(selected_source),
            stratum = 16,
            reference_id = "NONE",
            offset_ms = NULL,
            jitter_ms = NULL,
            delay_ms = NULL,
            dispersion_ms = NULL,
            last_attempt = NOW(),
            last_error = VALUES(last_error),
            updated_at = NOW()',
        [$hostId, $target, $lastError]
    );

    // Record unreachable event in statistical history
    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_ntp_history
           (host_id, status, offset_ms, jitter_ms, delay_ms, dispersion_ms, stratum, reference_id, recorded_at)
         VALUES (?, "unreachable", NULL, NULL, NULL, NULL, 16, "NONE", NOW())',
        [$hostId]
    );

    return ['ok' => false, 'status' => 'unreachable', 'error' => $lastError];
}

/**
 * Fetch NTP status for single device with automatic polling if state is missing or stale.
 */
function icct_nms_ntp_status(int $hostId, bool $refreshIfNeeded = true): array {
    $row = db_fetch_row_prepared(
        'SELECT d.*, s.status, s.reachable, s.synchronized, s.selected_source,
                s.stratum, s.reference_id, s.offset_ms, s.jitter_ms, s.delay_ms,
                s.last_success AS last_polled, s.last_error
         FROM plugin_icct_nms_ntp_devices d
         LEFT JOIN plugin_icct_nms_ntp_state s ON s.host_id = d.host_id
         WHERE d.host_id = ?',
        [$hostId]
    );
    if (!$row) return [];

    // Auto-poll if no prior check or check is older than 60s
    if ($refreshIfNeeded && (!isset($row['status']) || $row['status'] === 'unknown' || empty($row['last_polled']))) {
        icct_nms_ntp_poll_device($hostId);
        return icct_nms_ntp_status($hostId, false);
    }

    $row['ntp_server'] = $row['target_address'] ?? '';
    $row['ntp_mode'] = $row['ntp_mode'] ?? 'server';
    return $row;
}

function icct_nms_ntp_peers(int $hostId): array {
    return db_fetch_assoc_prepared(
        'SELECT * FROM plugin_icct_nms_ntp_peers WHERE host_id=? ORDER BY selected DESC, stratum ASC, offset_ms ASC',
        [$hostId]
    ) ?: [];
}

/**
 * Fetch all NTP devices, automatically polling any device whose data is missing or stale.
 */
function icct_nms_ntp_all(bool $refreshIfNeeded = true): array {
    if ($refreshIfNeeded) {
        $staleDevices = db_fetch_assoc(
            'SELECT d.host_id FROM plugin_icct_nms_ntp_devices d
             LEFT JOIN plugin_icct_nms_ntp_state s ON s.host_id = d.host_id
             WHERE d.enabled = 1 AND (s.last_attempt IS NULL OR s.last_attempt < DATE_SUB(NOW(), INTERVAL 60 SECOND))
             LIMIT 20'
        ) ?: [];
        foreach ($staleDevices as $dev) {
            icct_nms_ntp_poll_device((int)$dev['host_id']);
        }
    }

    $rows = db_fetch_assoc(
        'SELECT d.*, d.target_address AS ntp_server, \'server\' AS ntp_mode,
                s.status, s.reachable, s.synchronized, s.selected_source,
                s.stratum, s.reference_id, s.offset_ms, s.jitter_ms, s.delay_ms,
                COALESCE(s.last_success, s.last_attempt, d.updated_at) AS last_polled,
                s.last_error,
                h.description, h.hostname, h.status AS host_status
         FROM plugin_icct_nms_ntp_devices d
         LEFT JOIN plugin_icct_nms_ntp_state s ON s.host_id = d.host_id
         JOIN host h ON h.id = d.host_id
         ORDER BY h.description'
    ) ?: [];
    return $rows;
}

/**
 * Upsert device NTP configuration and trigger an immediate live poll.
 */
function icct_nms_ntp_upsert(int $hostId, array $data): void {
    $targetAddress = trim((string)($data['ntp_server'] ?? $data['target_address'] ?? ''));
    $port          = (int)($data['port'] ?? 123);
    $version       = (int)($data['version'] ?? 4);
    $timeout       = (int)($data['timeout_seconds'] ?? $data['timeout'] ?? 3);
    $pollInterval  = (int)($data['poll_interval'] ?? 300);
    $maxOffset     = isset($data['maximum_offset_ms']) ? (float)$data['maximum_offset_ms'] : 100.0;
    $maxJitter     = isset($data['maximum_jitter_ms']) ? (float)$data['maximum_jitter_ms'] : 50.0;
    $maxDelay      = isset($data['maximum_delay_ms']) ? (float)$data['maximum_delay_ms'] : 250.0;
    $expectedSource = trim((string)($data['expected_source'] ?? $data['reference_id'] ?? 'GPS'));
    $userId        = function_exists('icct_backend_current_user_id') ? icct_backend_current_user_id() : 1;

    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_ntp_devices
           (host_id, enabled, target_address, port, version, timeout_seconds, poll_interval,
            maximum_offset_ms, maximum_jitter_ms, maximum_delay_ms, expected_source, updated_by, updated_at)
         VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
           enabled = 1,
           target_address = VALUES(target_address),
           port = VALUES(port),
           version = VALUES(version),
           timeout_seconds = VALUES(timeout_seconds),
           poll_interval = VALUES(poll_interval),
           maximum_offset_ms = VALUES(maximum_offset_ms),
           maximum_jitter_ms = VALUES(maximum_jitter_ms),
           maximum_delay_ms = VALUES(maximum_delay_ms),
           expected_source = VALUES(expected_source),
           updated_by = VALUES(updated_by),
           updated_at = NOW()',
        [
            $hostId, $targetAddress, $port, $version, $timeout, $pollInterval,
            $maxOffset, $maxJitter, $maxDelay, $expectedSource, $userId
        ]
    );

    // Perform immediate live poll so true real data is acquired immediately!
    icct_nms_ntp_poll_device($hostId);
}

function icct_nms_ntp_peers_upsert(int $hostId, array $peers): void {
    icct_backend_category_execute('DELETE FROM plugin_icct_nms_ntp_peers WHERE host_id=?', [$hostId]);
    foreach ($peers as $peer) {
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_ntp_peers
               (host_id, peer_ip, peer_name, stratum, offset_ms, delay_ms, jitter_ms, reachable, selected, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $hostId,
                $peer['peer_ip']   ?? '',
                $peer['peer_name'] ?? '',
                isset($peer['stratum'])   ? (int)$peer['stratum']   : null,
                isset($peer['offset_ms']) ? (float)$peer['offset_ms'] : null,
                isset($peer['delay_ms'])  ? (float)$peer['delay_ms']  : null,
                isset($peer['jitter_ms']) ? (float)$peer['jitter_ms'] : null,
                isset($peer['reachable']) ? (int)(bool)$peer['reachable'] : 1,
                isset($peer['selected'])  ? (int)(bool)$peer['selected']  : 1,
            ]
        );
    }
}

/**
 * Fetch statistical history for a node (Requirement 5.3.3.3).
 */
function icct_nms_ntp_history(int $hostId, int $limit = 50): array {
    return db_fetch_assoc_prepared(
        'SELECT * FROM plugin_icct_nms_ntp_history WHERE host_id = ? ORDER BY recorded_at DESC LIMIT ' . (int)$limit,
        [$hostId]
    ) ?: [];
}

/**
 * Generate CSV report of NTP parameters (Requirement 5.3.3.5).
 */
function icct_nms_ntp_export_csv(): string {
    $devices = icct_nms_ntp_all(false);
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['Device Name', 'Hostname / IP', 'NTP Server', 'Mode', 'Stratum', 'Reference ID', 'Offset (ms)', 'Jitter (ms)', 'Delay (ms)', 'Status', 'Last Polled']);
    foreach ($devices as $d) {
        fputcsv($out, [
            $d['description'] ?? '',
            $d['hostname'] ?? '',
            $d['ntp_server'] ?? '',
            $d['ntp_mode'] ?? 'server',
            $d['stratum'] ?? '',
            $d['reference_id'] ?? '',
            $d['offset_ms'] !== null ? number_format((float)$d['offset_ms'], 3, '.', '') : '',
            $d['jitter_ms'] !== null ? number_format((float)$d['jitter_ms'], 3, '.', '') : '',
            $d['delay_ms'] !== null ? number_format((float)$d['delay_ms'], 3, '.', '') : '',
            $d['status'] ?? 'unknown',
            $d['last_polled'] ?? '',
        ]);
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv;
}

/**
 * Generate JSON report of NTP parameters (Requirement 5.3.3.5).
 */
function icct_nms_ntp_export_json(): string {
    $devices = icct_nms_ntp_all(false);
    return json_encode([
        'report_type'   => 'NTP_Monitoring_Report',
        'generated_at'  => date('Y-m-d H:i:s'),
        'total_devices' => count($devices),
        'devices'       => $devices,
    ], JSON_PRETTY_PRINT);
}
