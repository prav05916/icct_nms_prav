<?php
/**
 * ICCT NMS — RHEL 9.4 Offline Verification & Packet Generator
 *
 * Simulates real NetFlow v5 packets, worm signatures, and port scans
 * directly over local UDP sockets (UDP 2055) without requiring internet
 * access or external network equipment.
 *
 * Usage on RHEL 9.4:
 *   php rhel_offline_test.php
 */

echo "===============================================================\n";
echo " ICCT NMS — Offline RHEL 9.4 Traffic Simulation & Verification \n";
echo "===============================================================\n\n";

$collectorHost = '127.0.0.1';
$collectorPort = 2055;

$sock = @stream_socket_client("udp://{$collectorHost}:{$collectorPort}", $errno, $errstr, 2);
if (!$sock) {
    echo "[FAIL] Could not connect to UDP port $collectorPort: $errstr ($errno)\n";
    echo "       Please ensure icct-nms-netflow-collector.service is running:\n";
    echo "       sudo systemctl start icct-nms-netflow-collector.service\n";
    exit(1);
}

echo "[OK] Connected to NetFlow collector daemon at UDP {$collectorHost}:{$collectorPort}\n\n";

// Helper to build 48-byte NetFlow v5 record
function buildV5Record($srcIp, $dstIp, $srcPort, $dstPort, $pkts, $bytes, $proto = 6, $flags = 0x18) {
    return pack('N', ip2long($srcIp))
         . pack('N', ip2long($dstIp))
         . pack('N', 0)                  // nexthop
         . pack('n', 0) . pack('n', 0)  // input/output
         . pack('N', $pkts)
         . pack('N', $bytes)
         . pack('N', 0) . pack('N', 0)  // first/last
         . pack('n', $srcPort)
         . pack('n', $dstPort)
         . "\0"                          // pad1
         . chr($flags)                   // tcp_flags
         . chr($proto)                   // proto
         . chr(0)                        // tos
         . pack('n', 0) . pack('n', 0)  // as
         . chr(0) . chr(0)               // masks
         . pack('n', 0);                 // pad2
}

// 1. Send Normal HTTP / HTTPS / DNS Traffic
echo "1. Transmitting normal LAN/WAN traffic (HTTP, HTTPS, DNS)... ";
$v5Hdr = pack('n', 5) . pack('n', 3) . str_repeat("\0", 20); // count=3
$records = buildV5Record('192.168.1.100', '10.0.0.1', 49152, 80, 150, 75000, 6, 0x18)
         . buildV5Record('192.168.1.100', '8.8.8.8', 53210, 53, 4, 320, 17, 0)
         . buildV5Record('10.0.0.50', '203.0.113.10', 50123, 443, 850, 520000, 6, 0x18);
fwrite($sock, $v5Hdr . $records);
echo "SENT (3 flows)\n";

// 2. Send Worm Signature (SMB port 445 WannaCry simulation)
echo "2. Transmitting Worm / Exploit signature (SMB port 445 WannaCry)... ";
$v5HdrWorm = pack('n', 5) . pack('n', 1) . str_repeat("\0", 20);
$recWorm = buildV5Record('192.168.1.66', '10.0.0.1', 54321, 445, 12, 4096, 6, 0x18);
fwrite($sock, $v5HdrWorm . $recWorm);
echo "SENT (1 exploit flow)\n";

// 3. Send Port Scan (Vertical Scan across 12 ports)
echo "3. Transmitting Vertical Port Scan attack (probes 12 ports on target)... ";
$scanRecords = '';
for ($p = 1000; $p <= 1012; $p++) {
    $scanRecords .= buildV5Record('10.0.0.99', '10.0.0.1', 60000, $p, 1, 64, 6, 0x02); // SYN packet
}
$v5HdrScan = pack('n', 5) . pack('n', 13) . str_repeat("\0", 20);
fwrite($sock, $v5HdrScan . $scanRecords);
echo "SENT (13 scan flows)\n";

// 4. Send DoS SYN Flood Simulation
echo "4. Transmitting DoS SYN Flood attack simulation (55 SYN packets)... ";
$synRecords = '';
for ($i = 1; $i <= 55; $i++) {
    $synRecords .= buildV5Record('172.16.0.' . $i, '10.0.0.1', 50000 + $i, 80, 1, 64, 6, 0x02);
}
$v5HdrSyn = pack('n', 5) . pack('n', 55) . str_repeat("\0", 20);
fwrite($sock, $v5HdrSyn . $synRecords);
echo "SENT (55 SYN flood flows)\n\n";

fclose($sock);

echo "===============================================================\n";
echo " VERIFICATION COMPLETE!                                         \n";
echo "===============================================================\n";
echo "Now check the Cacti Web UI:\n";
echo "1. Open NetFlow Monitoring:\n";
echo "   http://<rhel-ip>/cacti/plugins/icct_nms/monitoring/controllers/netflow.php\n";
echo "2. Verify the following tabs:\n";
echo "   - Top Hosts         : Shows 192.168.1.100, 10.0.0.50, 10.0.0.99\n";
echo "   - Applications      : Shows HTTP, HTTPS, DNS, SMB\n";
echo "   - WAN vs LAN Trends : Shows LAN traffic and WAN traffic breakdown\n";
echo "   - Anomalies         : Shows CRITICAL (Worm/SMB 445), HIGH (Port Scan), CRITICAL (SYN Flood)\n";
echo "   - Reports           : Click 'Download CSV' or 'Export Full JSON Report'\n\n";

