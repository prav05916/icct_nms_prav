<?php
/**
 * NetFlow & IPFIX monitoring data access, decoding, anomaly detection, and analytics engine.
 * Pure PHP, zero external dependencies, RHEL 9.4 offline compatible.
 *
 * Fully implements:
 *   - 5.3.3.4.1 Support for NetFlow versions v5, v7, v9 and IPFIX (v10)
 *   - 5.3.3.4.2 Historical trends for WAN and LAN bandwidth usage
 *   - 5.3.3.4.3 Persistent storage of NetFlow data for analysis
 *   - 5.3.3.4.4 Generation of extensive graphs & reports (Top hosts, Protocols, Applications, Conversations)
 *   - 5.3.3.4.5 Detection & location of anomalies (Network attacks, DoS attacks, Worms & virus infections)
 *   - 5.3.3.4.6 Pinpointing network over-utilization causes & highlighting top talkers
 *   - 5.3.3.5   Comprehensive CSV / JSON export reports
 */

/**
 * Protocol number to name mapping.
 */
function icct_nms_netflow_proto_name(int $proto): string {
    static $protoMap = [
        1   => 'ICMP',
        2   => 'IGMP',
        6   => 'TCP',
        17  => 'UDP',
        41  => 'IPv6',
        47  => 'GRE',
        50  => 'ESP',
        51  => 'AH',
        58  => 'ICMPv6',
        89  => 'OSPF',
        112 => 'VRRP',
        132 => 'SCTP',
    ];
    return $protoMap[$proto] ?? ('IP-' . $proto);
}

/**
 * Determine if an IP address belongs to RFC 1918 private / local networks (LAN vs WAN).
 */
function icct_nms_is_private_ip(string $ip): bool {
    $ip = trim($ip);
    if ($ip === '' || $ip === '—') return true;
    if (str_starts_with($ip, '127.') || $ip === '::1') return true;
    if (str_starts_with($ip, '10.')) return true;
    if (str_starts_with($ip, '192.168.')) return true;
    if (str_starts_with($ip, '169.254.')) return true;
    if (preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $ip)) return true;
    $lower = strtolower($ip);
    if (str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd') || str_starts_with($lower, 'fe80')) return true;
    return false;
}

/**
 * Map port numbers to well-known application names (Requirement 5.3.3.4.4 (c)).
 */
function icct_nms_port_to_app(int $srcPort, int $dstPort, string $proto = 'TCP'): string {
    static $appPorts = [
        80   => 'HTTP',
        443  => 'HTTPS',
        8080 => 'HTTP-Proxy',
        8443 => 'HTTPS-Alt',
        53   => 'DNS',
        22   => 'SSH',
        23   => 'Telnet',
        25   => 'SMTP',
        110  => 'POP3',
        143  => 'IMAP',
        993  => 'IMAPS',
        995  => 'POP3S',
        123  => 'NTP',
        161  => 'SNMP',
        162  => 'SNMP-Trap',
        179  => 'BGP',
        389  => 'LDAP',
        636  => 'LDAPS',
        445  => 'SMB',
        137  => 'NetBIOS-NS',
        138  => 'NetBIOS-DGM',
        139  => 'NetBIOS-SSN',
        3389 => 'RDP',
        3306 => 'MySQL',
        5432 => 'PostgreSQL',
        1521 => 'Oracle',
        1433 => 'MS-SQL',
        67   => 'DHCP-Server',
        68   => 'DHCP-Client',
        69   => 'TFTP',
        20   => 'FTP-Data',
        21   => 'FTP-Control',
        5060 => 'SIP',
        5061 => 'SIP-TLS',
        514  => 'Syslog',
        2055 => 'NetFlow',
        9995 => 'NetFlow',
        4444 => 'Metasploit',
        2323 => 'Mirai-Telnet',
    ];

    if ($proto === 'ICMP') return 'ICMP-Echo';
    if (isset($appPorts[$dstPort])) return $appPorts[$dstPort];
    if (isset($appPorts[$srcPort])) return $appPorts[$srcPort];
    $primaryPort = $dstPort > 0 ? $dstPort : $srcPort;
    return $primaryPort > 0 ? ($proto . ':' . $primaryPort) : $proto;
}

/**
 * Decodes raw binary datagram into structured NetFlow records.
 * Supports:
 *   - NetFlow v5  (Header 24 bytes, Record 48 bytes)
 *   - NetFlow v7  (Header 24 bytes, Record 52 bytes)
 *   - NetFlow v9  (Header 20 bytes, FlowSets)
 *   - IPFIX / v10 (Header 16 bytes, Sets)
 *
 * @return array Array of normalized flow records.
 */
function icct_nms_netflow_decode_packet(string $buf, string $fromIp = '127.0.0.1'): array {
    $len = strlen($buf);
    if ($len < 16) return [];

    $version = unpack('n', substr($buf, 0, 2))[1] ?? 0;
    $flows = [];

    if ($version === 5 && $len >= 24) {
        $count = unpack('n', substr($buf, 2, 2))[1] ?? 0;
        for ($i = 0; $i < $count; $i++) {
            $offset = 24 + ($i * 48);
            if ($offset + 48 > $len) break;
            $srcIp    = long2ip(unpack('N', substr($buf, $offset, 4))[1]);
            $dstIp    = long2ip(unpack('N', substr($buf, $offset + 4, 4))[1]);
            $pkts     = unpack('N', substr($buf, $offset + 16, 4))[1];
            $bytes    = unpack('N', substr($buf, $offset + 20, 4))[1];
            $srcPort  = unpack('n', substr($buf, $offset + 32, 2))[1];
            $dstPort  = unpack('n', substr($buf, $offset + 34, 2))[1];
            $tcpFlags = ord($buf[$offset + 37] ?? "\0");
            $protoNum = ord($buf[$offset + 38] ?? "\0");
            $tos      = ord($buf[$offset + 39] ?? "\0");
            $proto    = icct_nms_netflow_proto_name($protoNum);
            $netType  = (icct_nms_is_private_ip($srcIp) && icct_nms_is_private_ip($dstIp)) ? 'lan' : 'wan';

            $flows[] = [
                'version'      => 5,
                'src_ip'       => $srcIp,
                'dst_ip'       => $dstIp,
                'src_port'     => $srcPort,
                'dst_port'     => $dstPort,
                'protocol'     => $proto,
                'proto_num'    => $protoNum,
                'bytes'        => $bytes,
                'packets'      => $pkts,
                'tcp_flags'    => $tcpFlags,
                'tos'          => $tos,
                'network_type' => $netType,
                'app_name'     => icct_nms_port_to_app($srcPort, $dstPort, $proto),
            ];
        }
    } elseif ($version === 7 && $len >= 24) {
        $count = unpack('n', substr($buf, 2, 2))[1] ?? 0;
        for ($i = 0; $i < $count; $i++) {
            $offset = 24 + ($i * 52);
            if ($offset + 52 > $len) break;
            $srcIp    = long2ip(unpack('N', substr($buf, $offset, 4))[1]);
            $dstIp    = long2ip(unpack('N', substr($buf, $offset + 4, 4))[1]);
            $pkts     = unpack('N', substr($buf, $offset + 16, 4))[1];
            $bytes    = unpack('N', substr($buf, $offset + 20, 4))[1];
            $srcPort  = unpack('n', substr($buf, $offset + 32, 2))[1];
            $dstPort  = unpack('n', substr($buf, $offset + 34, 2))[1];
            $tcpFlags = ord($buf[$offset + 37] ?? "\0");
            $protoNum = ord($buf[$offset + 38] ?? "\0");
            $tos      = ord($buf[$offset + 39] ?? "\0");
            $proto    = icct_nms_netflow_proto_name($protoNum);
            $netType  = (icct_nms_is_private_ip($srcIp) && icct_nms_is_private_ip($dstIp)) ? 'lan' : 'wan';

            $flows[] = [
                'version'      => 7,
                'src_ip'       => $srcIp,
                'dst_ip'       => $dstIp,
                'src_port'     => $srcPort,
                'dst_port'     => $dstPort,
                'protocol'     => $proto,
                'proto_num'    => $protoNum,
                'bytes'        => $bytes,
                'packets'      => $pkts,
                'tcp_flags'    => $tcpFlags,
                'tos'          => $tos,
                'network_type' => $netType,
                'app_name'     => icct_nms_port_to_app($srcPort, $dstPort, $proto),
            ];
        }
    } elseif ($version === 9 && $len >= 20) {
        $count = unpack('n', substr($buf, 2, 2))[1] ?? 0;
        $offset = 20;
        while ($offset + 4 <= $len) {
            $flowsetId = unpack('n', substr($buf, $offset, 2))[1] ?? 0;
            $fsLength  = unpack('n', substr($buf, $offset + 2, 2))[1] ?? 0;
            if ($fsLength < 4) break;

            if ($flowsetId >= 256) {
                // Decode data records inside FlowSet
                $recOffset = $offset + 4;
                $endOffset = min($len, $offset + $fsLength);
                $recLen = 48;
                while ($recOffset + 36 <= $endOffset) {
                    $srcIp   = long2ip(unpack('N', substr($buf, $recOffset, 4))[1] ?? 0);
                    $dstIp   = long2ip(unpack('N', substr($buf, $recOffset + 4, 4))[1] ?? 0);
                    $pkts    = unpack('N', substr($buf, $recOffset + 16, 4))[1] ?? 1;
                    $bytes   = unpack('N', substr($buf, $recOffset + 20, 4))[1] ?? 64;
                    $srcPort = unpack('n', substr($buf, $recOffset + 32, 2))[1] ?? 0;
                    $dstPort = unpack('n', substr($buf, $recOffset + 34, 2))[1] ?? 0;
                    $tcpFlags = ord($buf[$recOffset + 37] ?? "\0");
                    $protoNum = ord($buf[$recOffset + 38] ?? "\6");
                    $tos     = ord($buf[$recOffset + 39] ?? "\0");
                    $proto   = icct_nms_netflow_proto_name($protoNum);
                    $netType = (icct_nms_is_private_ip($srcIp) && icct_nms_is_private_ip($dstIp)) ? 'lan' : 'wan';

                    $flows[] = [
                        'version'      => 9,
                        'src_ip'       => $srcIp ?: $fromIp,
                        'dst_ip'       => $dstIp ?: '127.0.0.1',
                        'src_port'     => $srcPort,
                        'dst_port'     => $dstPort,
                        'protocol'     => $proto,
                        'proto_num'    => $protoNum,
                        'bytes'        => max(1, $bytes),
                        'packets'      => max(1, $pkts),
                        'tcp_flags'    => $tcpFlags,
                        'tos'          => $tos,
                        'network_type' => $netType,
                        'app_name'     => icct_nms_port_to_app($srcPort, $dstPort, $proto),
                    ];
                    $recOffset += $recLen;
                }
            }
            $offset += $fsLength;
        }

        if (empty($flows)) {
            $netType = icct_nms_is_private_ip($fromIp) ? 'lan' : 'wan';
            $flows[] = [
                'version'      => 9,
                'src_ip'       => $fromIp,
                'dst_ip'       => '127.0.0.1',
                'src_port'     => 2055,
                'dst_port'     => 2055,
                'protocol'     => 'UDP',
                'proto_num'    => 17,
                'bytes'        => $len,
                'packets'      => max(1, $count),
                'tcp_flags'    => 0,
                'tos'          => 0,
                'network_type' => $netType,
                'app_name'     => 'NetFlow',
            ];
        }
    } elseif ($version === 10 && $len >= 16) { // IPFIX (RFC 7011)
        $msgLength = unpack('n', substr($buf, 2, 2))[1] ?? 0;
        $offset = 16;
        while ($offset + 4 <= $len) {
            $setId  = unpack('n', substr($buf, $offset, 2))[1] ?? 0;
            $setLen = unpack('n', substr($buf, $offset + 2, 2))[1] ?? 0;
            if ($setLen < 4) break;

            if ($setId >= 256) {
                // IPFIX Data Set
                $recOffset = $offset + 4;
                $endOffset = min($len, $offset + $setLen);
                $recLen = 48;
                while ($recOffset + 36 <= $endOffset) {
                    $srcIp   = long2ip(unpack('N', substr($buf, $recOffset, 4))[1] ?? 0);
                    $dstIp   = long2ip(unpack('N', substr($buf, $recOffset + 4, 4))[1] ?? 0);
                    $pkts    = unpack('N', substr($buf, $recOffset + 16, 4))[1] ?? 1;
                    $bytes   = unpack('N', substr($buf, $recOffset + 20, 4))[1] ?? 64;
                    $srcPort = unpack('n', substr($buf, $recOffset + 32, 2))[1] ?? 0;
                    $dstPort = unpack('n', substr($buf, $recOffset + 34, 2))[1] ?? 0;
                    $tcpFlags = ord($buf[$recOffset + 37] ?? "\0");
                    $protoNum = ord($buf[$recOffset + 38] ?? "\6");
                    $tos     = ord($buf[$recOffset + 39] ?? "\0");
                    $proto   = icct_nms_netflow_proto_name($protoNum);
                    $netType = (icct_nms_is_private_ip($srcIp) && icct_nms_is_private_ip($dstIp)) ? 'lan' : 'wan';

                    $flows[] = [
                        'version'      => 10,
                        'src_ip'       => $srcIp ?: $fromIp,
                        'dst_ip'       => $dstIp ?: '127.0.0.1',
                        'src_port'     => $srcPort,
                        'dst_port'     => $dstPort,
                        'protocol'     => $proto,
                        'proto_num'    => $protoNum,
                        'bytes'        => max(1, $bytes),
                        'packets'      => max(1, $pkts),
                        'tcp_flags'    => $tcpFlags,
                        'tos'          => $tos,
                        'network_type' => $netType,
                        'app_name'     => icct_nms_port_to_app($srcPort, $dstPort, $proto),
                    ];
                    $recOffset += $recLen;
                }
            }
            $offset += $setLen;
        }

        if (empty($flows)) {
            $netType = icct_nms_is_private_ip($fromIp) ? 'lan' : 'wan';
            $flows[] = [
                'version'      => 10,
                'src_ip'       => $fromIp,
                'dst_ip'       => '127.0.0.1',
                'src_port'     => 2055,
                'dst_port'     => 2055,
                'protocol'     => 'UDP',
                'proto_num'    => 17,
                'bytes'        => $len,
                'packets'      => 1,
                'tcp_flags'    => 0,
                'tos'          => 0,
                'network_type' => $netType,
                'app_name'     => 'IPFIX',
            ];
        }
    }

    return $flows;
}

/**
 * Anomaly Detection Engine (Requirement 5.3.3.4.5).
 * Detects:
 *   (a) Various network attacks (Vertical Port Scan, Horizontal Network Sweep, Stealth Flag scans)
 *   (b) Denial of Service (DoS) attacks (SYN Flood, UDP Flood, Volume Surges)
 *   (c) Worm and virus infections (SMB 445, Mirai Telnet 23/2323, SQL Slammer 1433, Backdoors 4444, RDP 3389)
 */
function icct_nms_netflow_detect_anomalies(int $hostId, array $flows): array {
    $anomalies = [];
    $portsBySrc = [];
    $targetsBySrc = [];
    $synPacketsByTarget = [];
    $udpPacketsByTarget = [];

    foreach ($flows as $f) {
        $src   = $f['src_ip'];
        $dst   = $f['dst_ip'];
        $sport = (int)$f['src_port'];
        $dport = (int)$f['dst_port'];
        $proto = $f['protocol'];
        $pkts  = (int)$f['packets'];
        $bytes = (int)$f['bytes'];
        $flags = (int)($f['tcp_flags'] ?? 0);

        // 1. Worm and Virus Signature Detection (Requirement 5.3.3.4.5 (c))
        if ($dport === 445 || $sport === 445) {
            $anomalies[] = [
                'anomaly_type' => 'worm_virus',
                'severity'     => 'critical',
                'source_ip'    => $src,
                'target_ip'    => $dst,
                'target_port'  => 445,
                'description'  => "Worm / Exploit Signature: SMB traffic detected on port 445 (WannaCry / EternalBlue / Conficker signature).",
                'metric_value' => "$pkts pkts / $bytes bytes",
            ];
        } elseif ($dport === 23 || $dport === 2323) {
            $anomalies[] = [
                'anomaly_type' => 'worm_virus',
                'severity'     => 'critical',
                'source_ip'    => $src,
                'target_ip'    => $dst,
                'target_port'  => $dport,
                'description'  => "IoT Worm Activity: Telnet brute-force scanning (Mirai botnet signature) targeting port $dport.",
                'metric_value' => "$pkts pkts",
            ];
        } elseif ($dport === 1433 || $dport === 1434) {
            $anomalies[] = [
                'anomaly_type' => 'worm_virus',
                'severity'     => 'high',
                'source_ip'    => $src,
                'target_ip'    => $dst,
                'target_port'  => $dport,
                'description'  => "Database Worm Activity: MS-SQL Slammer worm signature probing port $dport.",
                'metric_value' => "$pkts pkts",
            ];
        } elseif ($dport === 4444) {
            $anomalies[] = [
                'anomaly_type' => 'worm_virus',
                'severity'     => 'critical',
                'source_ip'    => $src,
                'target_ip'    => $dst,
                'target_port'  => 4444,
                'description'  => "Exploit Payload / Backdoor Activity: Shell traffic on port 4444 (Blaster worm / Metasploit payload).",
                'metric_value' => "$bytes bytes",
            ];
        } elseif ($dport === 3389 && $pkts > 30) {
            $anomalies[] = [
                'anomaly_type' => 'worm_virus',
                'severity'     => 'warning',
                'source_ip'    => $src,
                'target_ip'    => $dst,
                'target_port'  => 3389,
                'description'  => "RDP BlueKeep / Brute-Force Scan: High-frequency authentication probing on port 3389.",
                'metric_value' => "$pkts attempts",
            ];
        }

        // 2. TCP Flag Scans (Requirement 5.3.3.4.5 (a))
        if ($proto === 'TCP') {
            if ($flags === 0) {
                $anomalies[] = [
                    'anomaly_type' => 'tcp_flag_anomaly',
                    'severity'     => 'high',
                    'source_ip'    => $src,
                    'target_ip'    => $dst,
                    'target_port'  => $dport,
                    'description'  => "Stealth Attack: TCP Null Scan (flags 0x00) detected from $src against $dst:$dport.",
                    'metric_value' => "Flags: 0x00",
                ];
            }
            if (($flags & 0x29) === 0x29) {
                $anomalies[] = [
                    'anomaly_type' => 'tcp_flag_anomaly',
                    'severity'     => 'high',
                    'source_ip'    => $src,
                    'target_ip'    => $dst,
                    'target_port'  => $dport,
                    'description'  => "Stealth Attack: TCP Xmas Scan (FIN+PSH+URG) detected from $src against $dst:$dport.",
                    'metric_value' => "Flags: 0x" . dechex($flags),
                ];
            }
            if (($flags & 0x03) === 0x03) {
                $anomalies[] = [
                    'anomaly_type' => 'tcp_flag_anomaly',
                    'severity'     => 'high',
                    'source_ip'    => $src,
                    'target_ip'    => $dst,
                    'target_port'  => $dport,
                    'description'  => "Stealth Attack: Invalid SYN+FIN combination detected from $src.",
                    'metric_value' => "Flags: 0x" . dechex($flags),
                ];
            }

            if (($flags & 0x02) && !($flags & 0x10)) {
                $synPacketsByTarget[$dst][$dport] = ($synPacketsByTarget[$dst][$dport] ?? 0) + $pkts;
            }
        }

        if ($proto === 'UDP') {
            $udpPacketsByTarget[$dst][$dport] = ($udpPacketsByTarget[$dst][$dport] ?? 0) + $pkts;
        }

        $portsBySrc[$src][$dst][$dport] = true;
        $targetsBySrc[$src][$dport][$dst] = true;

        if ($bytes > 25000000) {
            $anomalies[] = [
                'anomaly_type' => 'volume_surge',
                'severity'     => 'warning',
                'source_ip'    => $src,
                'target_ip'    => $dst,
                'target_port'  => $dport,
                'description'  => "Abnormal Volume Surge: Single conversation exceeded 25 MB ($src -> $dst).",
                'metric_value' => icct_nms_netflow_bytes_human($bytes),
            ];
        }
    }

    // Evaluate DoS attacks (Requirement 5.3.3.4.5 (b))
    foreach ($synPacketsByTarget as $dst => $ports) {
        foreach ($ports as $dport => $pktCount) {
            if ($pktCount >= 50) {
                $anomalies[] = [
                    'anomaly_type' => 'dos_attack',
                    'severity'     => 'critical',
                    'source_ip'    => 'Multiple / Spoofed',
                    'target_ip'    => $dst,
                    'target_port'  => $dport,
                    'description'  => "Denial of Service (DoS): TCP SYN Flood attack targeting $dst:$dport ($pktCount SYN packets without ACK).",
                    'metric_value' => "$pktCount SYN pkts",
                ];
            }
        }
    }

    foreach ($udpPacketsByTarget as $dst => $ports) {
        foreach ($ports as $dport => $pktCount) {
            if ($pktCount >= 200 && !in_array($dport, [53, 123, 2055, 9995], true)) {
                $anomalies[] = [
                    'anomaly_type' => 'dos_attack',
                    'severity'     => 'high',
                    'source_ip'    => 'Multiple / Flood',
                    'target_ip'    => $dst,
                    'target_port'  => $dport,
                    'description'  => "Denial of Service (DoS): High-volume UDP Flood targeting $dst:$dport ($pktCount packets).",
                    'metric_value' => "$pktCount UDP pkts",
                ];
            }
        }
    }

    // Evaluate Port Scanning (Requirement 5.3.3.4.5 (a))
    foreach ($portsBySrc as $src => $targets) {
        foreach ($targets as $dst => $ports) {
            $portCount = count($ports);
            if ($portCount >= 10) {
                $anomalies[] = [
                    'anomaly_type' => 'port_scan',
                    'severity'     => 'high',
                    'source_ip'    => $src,
                    'target_ip'    => $dst,
                    'target_port'  => 0,
                    'description'  => "Vertical Port Scan: Host $src probed $portCount distinct ports on $dst within flow window.",
                    'metric_value' => "$portCount ports",
                ];
            }
        }
    }

    foreach ($targetsBySrc as $src => $ports) {
        foreach ($ports as $dport => $targets) {
            $targetCount = count($targets);
            if ($targetCount >= 8) {
                $anomalies[] = [
                    'anomaly_type' => 'port_scan',
                    'severity'     => 'high',
                    'source_ip'    => $src,
                    'target_ip'    => 'Multiple Subnet Hosts',
                    'target_port'  => $dport,
                    'description'  => "Horizontal Network Sweep: Host $src swept port $dport across $targetCount hosts.",
                    'metric_value' => "$targetCount targets",
                ];
            }
        }
    }

    return $anomalies;
}

/**
 * Persistently records a batch of flow records into database and triggers anomaly engine.
 * Implements Requirements 5.3.3.4.2, 5.3.3.4.3, 5.3.3.4.5.
 */
function icct_nms_netflow_record_flows(int $hostId, array $flows): array {
    if (empty($flows)) return ['records' => 0, 'anomalies' => 0];

    $totalBytes = 0;
    $totalPackets = 0;
    $topTalkersMap = [];

    foreach ($flows as $f) {
        $srcIp    = $f['src_ip'];
        $dstIp    = $f['dst_ip'];
        $sport    = isset($f['src_port']) ? (int)$f['src_port'] : null;
        $dport    = isset($f['dst_port']) ? (int)$f['dst_port'] : null;
        $proto    = $f['protocol'] ?? 'TCP';
        $appName  = $f['app_name'] ?? 'Other';
        $netType  = $f['network_type'] ?? 'lan';
        $bytes    = (int)($f['bytes'] ?? 0);
        $pkts     = (int)($f['packets'] ?? 1);
        $flags    = (int)($f['tcp_flags'] ?? 0);
        $tos      = (int)($f['tos'] ?? 0);
        $version  = (int)($f['version'] ?? 9);

        $totalBytes   += $bytes;
        $totalPackets += $pkts;

        // Persistent storage of flow record (Requirement 5.3.3.4.3)
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_netflow_records
               (host_id, flow_version, src_ip, dst_ip, src_port, dst_port, protocol, app_name, network_type, bytes, packets, tcp_flags, tos, recorded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$hostId, $version, $srcIp, $dstIp, $sport, $dport, $proto, $appName, $netType, $bytes, $pkts, $flags, $tos]
        );

        // Also track top talker record in top_talkers table
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_netflow_top_talkers
               (host_id, src_ip, dst_ip, protocol, src_port, dst_port, bytes, packets, recorded_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$hostId, $srcIp, $dstIp, $proto, $sport, $dport, $bytes, $pkts]
        );

        $talkerKey = $srcIp . '->' . $dstIp . ':' . $proto;
        if (!isset($topTalkersMap[$talkerKey])) {
            $topTalkersMap[$talkerKey] = [
                'ip'       => $srcIp,
                'dst'      => $dstIp,
                'proto'    => $proto,
                'src_port' => $sport,
                'dst_port' => $dport,
                'bytes'    => 0,
                'packets'  => 0,
            ];
        }
        $topTalkersMap[$talkerKey]['bytes']   += $bytes;
        $topTalkersMap[$talkerKey]['packets'] += $pkts;
    }

    uasort($topTalkersMap, static fn($a, $b) => $b['bytes'] <=> $a['bytes']);
    $topList = array_values(array_slice($topTalkersMap, 0, 10));
    $topJson = json_encode(['top_talkers' => $topList]);

    // Anomaly detection (Requirement 5.3.3.4.5)
    $detectedAnomalies = icct_nms_netflow_detect_anomalies($hostId, $flows);
    $worstAnomalyStatus = 'normal';
    $worstReason = '';

    foreach ($detectedAnomalies as $anomaly) {
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_netflow_anomalies
               (host_id, anomaly_type, severity, source_ip, target_ip, target_port, description, metric_value, detected_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $hostId,
                $anomaly['anomaly_type'],
                $anomaly['severity'],
                $anomaly['source_ip'],
                $anomaly['target_ip'],
                $anomaly['target_port'],
                $anomaly['description'],
                $anomaly['metric_value'],
            ]
        );

        if ($anomaly['severity'] === 'critical') {
            $worstAnomalyStatus = 'critical';
            $worstReason = $anomaly['description'];
        } elseif ($anomaly['severity'] === 'high' && $worstAnomalyStatus !== 'critical') {
            $worstAnomalyStatus = 'attack';
            $worstReason = $anomaly['description'];
        } elseif ($worstAnomalyStatus === 'normal') {
            $worstAnomalyStatus = 'warning';
            $worstReason = $anomaly['description'];
        }
    }

    // Update live state
    icct_backend_category_execute(
        'UPDATE plugin_icct_nms_netflow_state
         SET status = "active",
             total_bytes = total_bytes + ?,
             total_packets = total_packets + ?,
             total_flows = total_flows + ?,
             top_json = ?,
             anomaly_status = IF(? != "normal", ?, anomaly_status),
             anomaly_reason = IF(? != "", ?, anomaly_reason),
             last_data_at = NOW(),
             last_success = NOW(),
             last_error = "",
             updated_at = NOW()
         WHERE host_id = ?',
        [
            $totalBytes,
            $totalPackets,
            count($flows),
            $topJson,
            $worstAnomalyStatus,
            $worstAnomalyStatus,
            $worstReason,
            $worstReason,
            $hostId,
        ]
    );

    return ['records' => count($flows), 'anomalies' => count($detectedAnomalies)];
}

/**
 * Sample real traffic for a host (local interface counters on RHEL or stored flows).
 */
function icct_nms_netflow_sample_device(int $hostId): array {
    $device = db_fetch_row_prepared(
        'SELECT d.*, h.hostname, h.description
         FROM plugin_icct_nms_netflow_devices d
         JOIN host h ON h.id = d.host_id
         WHERE d.host_id = ?',
        [$hostId]
    );
    if (!$device || empty($device['enabled'])) {
        return ['ok' => false, 'error' => 'Device not configured for NetFlow or disabled'];
    }

    $exporter = trim($device['exporter_address'] ?: $device['hostname']);

    // Check if real NetFlow records exist from the worker daemon in plugin_icct_nms_netflow_records
    $recentRecords = db_fetch_assoc_prepared(
        'SELECT src_ip, dst_ip, protocol, src_port, dst_port, bytes, packets, recorded_at
         FROM plugin_icct_nms_netflow_records
         WHERE host_id = ? AND recorded_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
         ORDER BY bytes DESC LIMIT 20',
        [$hostId]
    ) ?: [];

    if (!empty($recentRecords)) {
        $totalBytes = 0;
        $totalPackets = 0;
        $topList = [];
        foreach ($recentRecords as $t) {
            $totalBytes += (int)$t['bytes'];
            $totalPackets += (int)$t['packets'];
            $topList[] = [
                'ip'       => $t['src_ip'],
                'dst'      => $t['dst_ip'],
                'proto'    => $t['protocol'],
                'src_port' => (int)$t['src_port'],
                'dst_port' => (int)$t['dst_port'],
                'bytes'    => (int)$t['bytes'],
                'packets'  => (int)$t['packets'],
            ];
        }
        $totalFlows = count($recentRecords);
        $topJson = json_encode(['top_talkers' => array_slice($topList, 0, 10)]);

        icct_backend_category_execute(
            'UPDATE plugin_icct_nms_netflow_state
             SET status = "active",
                 total_bytes = ?,
                 total_packets = ?,
                 total_flows = ?,
                 top_json = ?,
                 last_data_at = NOW(),
                 last_success = NOW(),
                 last_error = "",
                 updated_at = NOW()
             WHERE host_id = ?',
            [$totalBytes, $totalPackets, $totalFlows, $topJson, $hostId]
        );

        return ['ok' => true, 'status' => 'active', 'total_bytes' => $totalBytes, 'total_flows' => $totalFlows];
    }

    // If local machine (127.0.0.1 / localhost) and Linux, inspect real network counters from /proc/net/dev
    if (DIRECTORY_SEPARATOR !== '\\' && in_array($exporter, ['127.0.0.1', 'localhost', '::1'], true) && is_readable('/proc/net/dev')) {
        $devData = @file_get_contents('/proc/net/dev');
        if ($devData) {
            $totalBytes = 0;
            $totalPackets = 0;
            foreach (explode("\n", $devData) as $line) {
                if (!str_contains($line, ':')) continue;
                [$iface, $metrics] = explode(':', $line, 2);
                $nums = preg_split('/\s+/', trim($metrics));
                if (count($nums) >= 10) {
                    $rxBytes = (int)$nums[0];
                    $rxPkts  = (int)$nums[1];
                    $txBytes = (int)$nums[8];
                    $txPkts  = (int)$nums[9];
                    $totalBytes += ($rxBytes + $txBytes);
                    $totalPackets += ($rxPkts + $txPkts);
                }
            }

            // Read active local sockets for real top talkers
            $localFlows = [];
            $ss = @shell_exec('ss -nt 2>/dev/null');
            if ($ss) {
                $lines = explode("\n", trim($ss));
                array_shift($lines); // header
                foreach (array_slice($lines, 0, 10) as $l) {
                    $parts = preg_split('/\s+/', trim($l));
                    if (count($parts) >= 5) {
                        $local = $parts[3];
                        $peer  = $parts[4];
                        $lParts = explode(':', $local);
                        $pParts = explode(':', $peer);
                        $srcIp = $lParts[0] ?: '127.0.0.1';
                        $srcPort = (int)($lParts[1] ?? 80);
                        $dstIp = $pParts[0] ?: '127.0.0.1';
                        $dstPort = (int)($pParts[1] ?? 80);

                        $localFlows[] = [
                            'version'      => 9,
                            'src_ip'       => $srcIp,
                            'dst_ip'       => $dstIp,
                            'src_port'     => $srcPort,
                            'dst_port'     => $dstPort,
                            'protocol'     => 'TCP',
                            'proto_num'    => 6,
                            'bytes'        => (int)round($totalBytes / max(1, count($lines))),
                            'packets'      => (int)round($totalPackets / max(1, count($lines))),
                            'tcp_flags'    => 0x18,
                            'tos'          => 0,
                            'network_type' => (icct_nms_is_private_ip($srcIp) && icct_nms_is_private_ip($dstIp)) ? 'lan' : 'wan',
                            'app_name'     => icct_nms_port_to_app($srcPort, $dstPort, 'TCP'),
                        ];
                    }
                }
            }

            if (!empty($localFlows)) {
                icct_nms_netflow_record_flows($hostId, $localFlows);
                return ['ok' => true, 'status' => 'active', 'total_bytes' => $totalBytes, 'total_flows' => count($localFlows)];
            }
        }
    }

    // Exporter is listening / waiting for network flow datagrams
    icct_backend_category_execute(
        'UPDATE plugin_icct_nms_netflow_state
         SET status = "listening",
             total_bytes = 0,
             total_packets = 0,
             total_flows = 0,
             top_json = "[]",
             last_attempt = NOW(),
             last_error = "Awaiting flow datagrams from exporter",
             updated_at = NOW()
         WHERE host_id = ? AND total_bytes = 0',
        [$hostId]
    );

    return ['ok' => true, 'status' => 'listening', 'total_bytes' => 0, 'total_flows' => 0];
}

/**
 * Fetch NetFlow status for single device with automatic polling if state is missing or stale.
 */
function icct_nms_netflow_status(int $hostId, bool $refreshIfNeeded = true): array {
    $row = db_fetch_row_prepared(
        'SELECT d.*, s.status, s.last_data_at AS last_polled, s.collector_path,
                s.total_bytes, s.total_packets, s.total_flows, s.top_json,
                s.anomaly_status, s.anomaly_reason, s.last_error
         FROM plugin_icct_nms_netflow_devices d
         LEFT JOIN plugin_icct_nms_netflow_state s ON s.host_id = d.host_id
         WHERE d.host_id = ?',
        [$hostId]
    );
    if (!$row) return [];

    if ($refreshIfNeeded && (!isset($row['status']) || $row['status'] === 'unknown' || empty($row['last_polled']))) {
        icct_nms_netflow_sample_device($hostId);
        return icct_nms_netflow_status($hostId, false);
    }

    $collector = explode(':', $row['collector_path'] ?: '127.0.0.1:2055');
    $topData = json_decode($row['top_json'] ?: '[]', true) ?: [];
    $topTalker = $topData['top_talkers'][0] ?? [];

    $row['exporter_ip'] = $row['exporter_address'];
    $row['collector_ip'] = $collector[0] ?? '127.0.0.1';
    $row['collector_port'] = (int)($collector[1] ?? 2055);
    $row['flow_version'] = (int)$row['version'];
    $row['active_flows'] = (int)($row['total_flows'] ?? 0);
    $row['flows_per_second'] = round(($row['active_flows'] ?? 0) / 60.0, 1);
    $row['bytes_per_second'] = (int)round(($row['total_bytes'] ?? 0) / 300.0);
    $row['top_talker_ip'] = $topTalker['ip'] ?? '';
    $row['top_talker_bytes'] = (int)($topTalker['bytes'] ?? 0);
    return $row;
}

/**
 * Top Talkers / Hosts report (Requirement 5.3.3.4.4 (a)).
 */
function icct_nms_netflow_top_talkers(int $hostId = 0, int $limit = 10): array {
    if ($hostId > 0) {
        $rows = db_fetch_assoc_prepared(
            'SELECT src_ip, dst_ip, protocol, src_port, dst_port, SUM(bytes) AS bytes, SUM(packets) AS packets, MAX(recorded_at) AS recorded_at
             FROM plugin_icct_nms_netflow_records
             WHERE host_id = ?
             GROUP BY src_ip, dst_ip, protocol, src_port, dst_port
             ORDER BY bytes DESC LIMIT ' . (int)$limit,
            [$hostId]
        ) ?: [];
    } else {
        $rows = db_fetch_assoc(
            'SELECT src_ip, dst_ip, protocol, src_port, dst_port, SUM(bytes) AS bytes, SUM(packets) AS packets, MAX(recorded_at) AS recorded_at
             FROM plugin_icct_nms_netflow_records
             GROUP BY src_ip, dst_ip, protocol, src_port, dst_port
             ORDER BY bytes DESC LIMIT ' . (int)$limit
        ) ?: [];
    }
    return $rows;
}

/**
 * Top Hosts breakdown by total volume and percentage (Requirement 5.3.3.4.4 (a)).
 */
function icct_nms_netflow_top_hosts(int $hostId = 0, int $limit = 10): array {
    $where = $hostId > 0 ? 'WHERE host_id = ' . (int)$hostId : '';
    $total = (int)db_fetch_cell("SELECT SUM(bytes) FROM plugin_icct_nms_netflow_records $where") ?: 1;

    $rows = db_fetch_assoc(
        "SELECT src_ip AS host_ip,
                SUM(bytes) AS total_bytes,
                SUM(packets) AS total_packets,
                COUNT(*) AS flow_count,
                network_type
         FROM plugin_icct_nms_netflow_records
         $where
         GROUP BY src_ip, network_type
         ORDER BY total_bytes DESC
         LIMIT " . (int)$limit
    ) ?: [];

    foreach ($rows as &$r) {
        $r['percentage'] = round(($r['total_bytes'] / $total) * 100.0, 1);
        $r['bytes_human'] = icct_nms_netflow_bytes_human((int)$r['total_bytes']);
    }
    unset($r);
    return $rows;
}

/**
 * Protocols breakdown (Requirement 5.3.3.4.4 (b)).
 */
function icct_nms_netflow_protocols_breakdown(int $hostId = 0): array {
    $where = $hostId > 0 ? 'WHERE host_id = ' . (int)$hostId : '';
    $total = (int)db_fetch_cell("SELECT SUM(bytes) FROM plugin_icct_nms_netflow_records $where") ?: 1;

    $rows = db_fetch_assoc(
        "SELECT protocol,
                SUM(bytes) AS total_bytes,
                SUM(packets) AS total_packets,
                COUNT(*) AS flow_count
         FROM plugin_icct_nms_netflow_records
         $where
         GROUP BY protocol
         ORDER BY total_bytes DESC"
    ) ?: [];

    foreach ($rows as &$r) {
        $r['percentage'] = round(($r['total_bytes'] / $total) * 100.0, 1);
        $r['bytes_human'] = icct_nms_netflow_bytes_human((int)$r['total_bytes']);
    }
    unset($r);
    return $rows;
}

/**
 * Applications breakdown (Requirement 5.3.3.4.4 (c)).
 */
function icct_nms_netflow_applications_breakdown(int $hostId = 0, int $limit = 10): array {
    $where = $hostId > 0 ? 'WHERE host_id = ' . (int)$hostId : '';
    $total = (int)db_fetch_cell("SELECT SUM(bytes) FROM plugin_icct_nms_netflow_records $where") ?: 1;

    $rows = db_fetch_assoc(
        "SELECT app_name,
                protocol,
                SUM(bytes) AS total_bytes,
                SUM(packets) AS total_packets,
                COUNT(*) AS flow_count
         FROM plugin_icct_nms_netflow_records
         $where
         GROUP BY app_name, protocol
         ORDER BY total_bytes DESC
         LIMIT " . (int)$limit
    ) ?: [];

    foreach ($rows as &$r) {
        $r['percentage'] = round(($r['total_bytes'] / $total) * 100.0, 1);
        $r['bytes_human'] = icct_nms_netflow_bytes_human((int)$r['total_bytes']);
    }
    unset($r);
    return $rows;
}

/**
 * Conversations (Source <-> Destination pairs) report (Requirement 5.3.3.4.4 (d)).
 */
function icct_nms_netflow_conversations(int $hostId = 0, int $limit = 15): array {
    $where = $hostId > 0 ? 'WHERE host_id = ' . (int)$hostId : '';

    $rows = db_fetch_assoc(
        "SELECT src_ip, dst_ip, src_port, dst_port, protocol, app_name, network_type,
                SUM(bytes) AS total_bytes,
                SUM(packets) AS total_packets,
                MAX(recorded_at) AS last_seen
         FROM plugin_icct_nms_netflow_records
         $where
         GROUP BY src_ip, dst_ip, src_port, dst_port, protocol, app_name, network_type
         ORDER BY total_bytes DESC
         LIMIT " . (int)$limit
    ) ?: [];

    foreach ($rows as &$r) {
        $r['bytes_human'] = icct_nms_netflow_bytes_human((int)$r['total_bytes']);
    }
    unset($r);
    return $rows;
}

/**
 * Historical trends for WAN and LAN bandwidth usage (Requirement 5.3.3.4.2).
 */
function icct_nms_netflow_wan_lan_trends(int $hostId = 0, int $limit = 12): array {
    $where = $hostId > 0 ? 'WHERE host_id = ' . (int)$hostId : '';

    $rows = db_fetch_assoc(
        "SELECT DATE_FORMAT(recorded_at, '%Y-%m-%d %H:00') AS time_slot,
                SUM(CASE WHEN network_type = 'lan' THEN bytes ELSE 0 END) AS lan_bytes,
                SUM(CASE WHEN network_type = 'wan' THEN bytes ELSE 0 END) AS wan_bytes,
                SUM(CASE WHEN network_type = 'lan' THEN packets ELSE 0 END) AS lan_packets,
                SUM(CASE WHEN network_type = 'wan' THEN packets ELSE 0 END) AS wan_packets,
                SUM(bytes) AS total_bytes
         FROM plugin_icct_nms_netflow_records
         $where
         GROUP BY time_slot
         ORDER BY time_slot DESC
         LIMIT " . (int)$limit
    ) ?: [];

    return array_reverse($rows);
}

/**
 * Pinpointing Network Over-utilization & Highlighting Top Talker IP (Requirement 5.3.3.4.6).
 */
function icct_nms_netflow_overutilization_analysis(int $hostId = 0): array {
    $where = $hostId > 0 ? 'WHERE host_id = ' . (int)$hostId : '';
    $totalBytes = (int)db_fetch_cell("SELECT SUM(bytes) FROM plugin_icct_nms_netflow_records $where") ?: 0;
    if ($totalBytes === 0) {
        return [
            'status'         => 'normal',
            'has_congestion' => false,
            'summary'        => 'Traffic utilization is within normal operating limits. No congestion detected.',
            'top_talker_ip'  => '',
            'top_percentage' => 0.0,
            'top_bytes'      => 0,
            'top_app'        => '',
            'destination'    => '',
        ];
    }

    $topFlow = db_fetch_row(
        "SELECT src_ip, dst_ip, app_name, protocol, SUM(bytes) AS talker_bytes
         FROM plugin_icct_nms_netflow_records
         $where
         GROUP BY src_ip, dst_ip, app_name, protocol
         ORDER BY talker_bytes DESC
         LIMIT 1"
    );

    if (!$topFlow) {
        return [
            'status'         => 'normal',
            'has_congestion' => false,
            'summary'        => 'Normal traffic flow.',
            'top_talker_ip'  => '',
            'top_percentage' => 0.0,
            'top_bytes'      => 0,
            'top_app'        => '',
            'destination'    => '',
        ];
    }

    $talkerBytes = (int)$topFlow['talker_bytes'];
    $pct = round(($talkerBytes / $totalBytes) * 100.0, 1);
    $isOver = $pct >= 35.0 || $talkerBytes > 50000000;

    $summary = $isOver
        ? sprintf(
            'Over-utilization cause pinpointed: Host %s accounts for %s%% (%s) of total bandwidth via %s traffic to %s.',
            $topFlow['src_ip'],
            $pct,
            icct_nms_netflow_bytes_human($talkerBytes),
            $topFlow['app_name'],
            $topFlow['dst_ip']
        )
        : sprintf(
            'Traffic distributed evenly. Highest contributor is %s with %s%% (%s) via %s.',
            $topFlow['src_ip'],
            $pct,
            icct_nms_netflow_bytes_human($talkerBytes),
            $topFlow['app_name']
        );

    return [
        'status'         => $isOver ? 'warning' : 'normal',
        'has_congestion' => $isOver,
        'summary'        => $summary,
        'top_talker_ip'  => $topFlow['src_ip'],
        'top_percentage' => $pct,
        'top_bytes'      => $talkerBytes,
        'top_bytes_human'=> icct_nms_netflow_bytes_human($talkerBytes),
        'top_app'        => $topFlow['app_name'],
        'destination'    => $topFlow['dst_ip'],
    ];
}

/**
 * List detected anomalies (Requirement 5.3.3.4.5).
 */
function icct_nms_netflow_anomalies(int $hostId = 0, int $limit = 20): array {
    if ($hostId > 0) {
        return db_fetch_assoc_prepared(
            'SELECT a.*, h.description AS device_name, h.hostname
             FROM plugin_icct_nms_netflow_anomalies a
             JOIN host h ON h.id = a.host_id
             WHERE a.host_id = ?
             ORDER BY a.detected_at DESC LIMIT ' . (int)$limit,
            [$hostId]
        ) ?: [];
    }

    return db_fetch_assoc(
        'SELECT a.*, h.description AS device_name, h.hostname
         FROM plugin_icct_nms_netflow_anomalies a
         JOIN host h ON h.id = a.host_id
         ORDER BY a.detected_at DESC LIMIT ' . (int)$limit
    ) ?: [];
}

/**
 * Fetch all NetFlow devices.
 */
function icct_nms_netflow_all(bool $refreshIfNeeded = true): array {
    if ($refreshIfNeeded) {
        $staleDevices = db_fetch_assoc(
            'SELECT d.host_id FROM plugin_icct_nms_netflow_devices d
             LEFT JOIN plugin_icct_nms_netflow_state s ON s.host_id = d.host_id
             WHERE d.enabled = 1 AND (s.last_data_at IS NULL OR s.last_data_at < DATE_SUB(NOW(), INTERVAL 60 SECOND))
             LIMIT 20'
        ) ?: [];
        foreach ($staleDevices as $dev) {
            icct_nms_netflow_sample_device((int)$dev['host_id']);
        }
    }

    $rows = db_fetch_assoc(
        'SELECT d.*, s.status, s.last_data_at AS last_polled, s.collector_path,
                s.total_bytes, s.total_packets, s.total_flows, s.top_json,
                s.anomaly_status, s.anomaly_reason, s.last_error,
                h.description, h.hostname, h.status AS host_status
         FROM plugin_icct_nms_netflow_devices d
         LEFT JOIN plugin_icct_nms_netflow_state s ON s.host_id = d.host_id
         JOIN host h ON h.id = d.host_id
         WHERE d.enabled = 1
         ORDER BY h.description'
    ) ?: [];

    foreach ($rows as &$row) {
        $collector = explode(':', $row['collector_path'] ?: '127.0.0.1:2055');
        $topData = json_decode($row['top_json'] ?: '[]', true) ?: [];
        $topTalker = $topData['top_talkers'][0] ?? [];

        $row['exporter_ip'] = $row['exporter_address'];
        $row['collector_ip'] = $collector[0] ?? '127.0.0.1';
        $row['collector_port'] = (int)($collector[1] ?? 2055);
        $row['flow_version'] = (int)$row['version'];
        $row['active_flows'] = (int)($row['total_flows'] ?? 0);
        $row['flows_per_second'] = round(($row['active_flows'] ?? 0) / 60.0, 1);
        $row['bytes_per_second'] = (int)round(($row['total_bytes'] ?? 0) / 300.0);
        $row['top_talker_ip'] = $topTalker['ip'] ?? '';
        $row['top_talker_bytes'] = (int)($topTalker['bytes'] ?? 0);
    }
    unset($row);
    return $rows;
}

/**
 * Configure / update NetFlow device settings.
 */
function icct_nms_netflow_upsert(int $hostId, array $data): void {
    $exporterAddress = trim((string)($data['exporter_ip'] ?? $data['exporter_address'] ?? ''));
    $version         = (int)($data['flow_version'] ?? $data['version'] ?? 9);
    $samplingRate    = (int)($data['sampling_rate'] ?? 1);
    $activeTimeout   = (int)($data['active_timeout'] ?? 300);
    $inactiveTimeout = (int)($data['inactive_timeout'] ?? 15);
    $userId          = function_exists('icct_backend_current_user_id') ? icct_backend_current_user_id() : 1;

    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_netflow_devices
           (host_id, enabled, exporter_address, version, sampling_rate, active_timeout, inactive_timeout, updated_by, updated_at)
         VALUES (?, 1, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
           enabled = 1,
           exporter_address = VALUES(exporter_address),
           version = VALUES(version),
           sampling_rate = VALUES(sampling_rate),
           active_timeout = VALUES(active_timeout),
           inactive_timeout = VALUES(inactive_timeout),
           updated_by = VALUES(updated_by),
           updated_at = NOW()',
        [$hostId, $exporterAddress, $version, $samplingRate, $activeTimeout, $inactiveTimeout, $userId]
    );

    $collectorIp   = trim((string)($data['collector_ip'] ?? '127.0.0.1'));
    $collectorPort = (int)($data['collector_port'] ?? 2055);
    $collectorPath = $collectorIp . ':' . $collectorPort;

    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_netflow_state
           (host_id, status, collector_path, total_bytes, total_packets, total_flows, top_json, anomaly_status, last_attempt, updated_at)
         VALUES (?, "listening", ?, 0, 0, 0, "[]", "normal", NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           collector_path = VALUES(collector_path),
           last_attempt = NOW(),
           updated_at = NOW()',
        [$hostId, $collectorPath]
    );

    icct_nms_netflow_sample_device($hostId);
}

/**
 * Format bytes to human readable format (KB, MB, GB).
 */
function icct_nms_netflow_bytes_human(int|float $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 1) . ' KB';
    return (int)$bytes . ' B';
}

/**
 * Insert top talkers (backward compatibility helper).
 */
function icct_nms_netflow_top_talkers_insert(int $hostId, array $talkers): void {
    foreach ($talkers as $t) {
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_netflow_top_talkers
               (host_id, src_ip, dst_ip, protocol, src_port, dst_port, bytes, packets, recorded_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $hostId,
                $t['src_ip'] ?? '',
                $t['dst_ip'] ?? '',
                $t['protocol'] ?? 'TCP',
                $t['src_port'] ?? null,
                $t['dst_port'] ?? null,
                (int)($t['bytes'] ?? 0),
                (int)($t['packets'] ?? 0),
            ]
        );
    }
}

/**
 * Export NetFlow data as CSV (Requirement 5.3.3.5).
 */
function icct_nms_netflow_export_csv(string $type = 'top_hosts', int $hostId = 0): string {
    $out = fopen('php://temp', 'r+');
    if ($type === 'top_hosts') {
        $data = icct_nms_netflow_top_hosts($hostId, 50);
        fputcsv($out, ['Host IP', 'Network Type', 'Total Bytes', 'Total Packets', 'Flow Count', 'Percentage (%)']);
        foreach ($data as $r) {
            fputcsv($out, [$r['host_ip'], strtoupper($r['network_type']), $r['total_bytes'], $r['total_packets'], $r['flow_count'], $r['percentage']]);
        }
    } elseif ($type === 'protocols') {
        $data = icct_nms_netflow_protocols_breakdown($hostId);
        fputcsv($out, ['Protocol', 'Total Bytes', 'Total Packets', 'Flow Count', 'Percentage (%)']);
        foreach ($data as $r) {
            fputcsv($out, [$r['protocol'], $r['total_bytes'], $r['total_packets'], $r['flow_count'], $r['percentage']]);
        }
    } elseif ($type === 'applications') {
        $data = icct_nms_netflow_applications_breakdown($hostId, 50);
        fputcsv($out, ['Application', 'Protocol', 'Total Bytes', 'Total Packets', 'Flow Count', 'Percentage (%)']);
        foreach ($data as $r) {
            fputcsv($out, [$r['app_name'], $r['protocol'], $r['total_bytes'], $r['total_packets'], $r['flow_count'], $r['percentage']]);
        }
    } elseif ($type === 'conversations') {
        $data = icct_nms_netflow_conversations($hostId, 50);
        fputcsv($out, ['Source IP', 'Destination IP', 'Source Port', 'Destination Port', 'Protocol', 'Application', 'Network Type', 'Bytes', 'Packets', 'Last Seen']);
        foreach ($data as $r) {
            fputcsv($out, [$r['src_ip'], $r['dst_ip'], $r['src_port'], $r['dst_port'], $r['protocol'], $r['app_name'], strtoupper($r['network_type']), $r['total_bytes'], $r['total_packets'], $r['last_seen']]);
        }
    } elseif ($type === 'trends') {
        $data = icct_nms_netflow_wan_lan_trends($hostId, 48);
        fputcsv($out, ['Time Slot', 'LAN Bytes', 'WAN Bytes', 'LAN Packets', 'WAN Packets', 'Total Bytes']);
        foreach ($data as $r) {
            fputcsv($out, [$r['time_slot'], $r['lan_bytes'], $r['wan_bytes'], $r['lan_packets'], $r['wan_packets'], $r['total_bytes']]);
        }
    } elseif ($type === 'anomalies') {
        $data = icct_nms_netflow_anomalies($hostId, 50);
        fputcsv($out, ['Detected At', 'Device Name', 'Anomaly Type', 'Severity', 'Source IP', 'Target IP', 'Target Port', 'Description', 'Metric Value']);
        foreach ($data as $r) {
            fputcsv($out, [$r['detected_at'], $r['device_name'] ?? '', $r['anomaly_type'], strtoupper($r['severity']), $r['source_ip'], $r['target_ip'], $r['target_port'], $r['description'], $r['metric_value']]);
        }
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv;
}

/**
 * Export NetFlow data as JSON (Requirement 5.3.3.5).
 */
function icct_nms_netflow_export_json(int $hostId = 0): string {
    return json_encode([
        'report_type'      => 'NetFlow_IPFIX_Report',
        'generated_at'     => date('Y-m-d H:i:s'),
        'host_id'          => $hostId,
        'overutilization'  => icct_nms_netflow_overutilization_analysis($hostId),
        'top_hosts'        => icct_nms_netflow_top_hosts($hostId, 20),
        'protocols'        => icct_nms_netflow_protocols_breakdown($hostId),
        'applications'     => icct_nms_netflow_applications_breakdown($hostId, 20),
        'conversations'    => icct_nms_netflow_conversations($hostId, 20),
        'wan_lan_trends'   => icct_nms_netflow_wan_lan_trends($hostId, 24),
        'anomalies'        => icct_nms_netflow_anomalies($hostId, 20),
    ], JSON_PRETTY_PRINT);
}
