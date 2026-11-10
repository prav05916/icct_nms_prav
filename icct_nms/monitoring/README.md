# ICCT NMS — Monitoring Subsystem (NTP & NetFlow/IPFIX) Architecture & User Guide

This document describes the complete architecture, backend data flow, anomaly detection engine, user interface navigation, and offline verification procedures for the **NTP** (Requirement 5.3.3.3) and **NetFlow / IPFIX** (Requirements 5.3.3.4.1–5.3.3.4.6, 5.3.3.5) subsystems in the ICCT NMS Cacti plugin.

---

## 1. Requirement Traceability Matrix

The monitoring subsystem has been implemented strictly against the project specifications:

| Requirement Clause | Specification | Implementation in ICCT NMS | Status |
|---|---|---|---|
| **5.3.3.3** | NTP monitoring of selected nodes & statistical information storage (sync status, sync source, offset, jitter, delay) | RFC 5905 native UDP poller (`icct_nms_ntp_poll_device`), local `chronyc` fallback, and persistent historical logging in `plugin_icct_nms_ntp_history` table. | **Completed (Real Data)** |
| **5.3.3.4.1** | Support for NetFlow versions v5, v7, v9 and IPFIX (v10) | Native binary decoder `icct_nms_netflow_decode_packet()` supporting v5 (48-byte records), v7 (52-byte records), v9 (FlowSet template/data), and IPFIX RFC 7011 (Set-based). | **Completed (Real Data)** |
| **5.3.3.4.2** | Historical trends for WAN and LAN bandwidth usage | RFC 1918 classification engine (`icct_nms_is_private_ip()`) categorizes flows into LAN vs WAN; aggregated trends via `icct_nms_netflow_wan_lan_trends()`. | **Completed (Real Data)** |
| **5.3.3.4.3** | Persistent storage of NetFlow data for analysis | Real-time database insertion into `plugin_icct_nms_netflow_records` (indexing on host, recorded_at, app, protocol, IPs). | **Completed (Real Data)** |
| **5.3.3.4.4 (a)** | Reports based on user criteria: Top hosts | `icct_nms_netflow_top_hosts()` aggregates traffic by host IP with percentage shares and byte volumes. | **Completed (Real Data)** |
| **5.3.3.4.4 (b)** | Reports based on user criteria: Protocols | `icct_nms_netflow_protocols_breakdown()` maps IP protocol numbers (TCP, UDP, ICMP, GRE, ESP, OSPF) with bandwidth shares. | **Completed (Real Data)** |
| **5.3.3.4.4 (c)** | Reports based on user criteria: Applications | `icct_nms_port_to_app()` maps well-known service ports (HTTP, HTTPS, DNS, SSH, SMB, RDP, MySQL, NTP, etc.). | **Completed (Real Data)** |
| **5.3.3.4.4 (d)** | Reports based on user criteria: Conversations | `icct_nms_netflow_conversations()` tracks source ↔ destination conversation pairs, ports, and data transferred. | **Completed (Real Data)** |
| **5.3.3.4.5 (a)** | Anomaly detection: Various network attacks | Engine detects vertical port scans (≥10 ports), horizontal subnet sweeps (≥8 targets), and stealth scans (TCP Null, Xmas, SYN+FIN). | **Completed (Real Data)** |
| **5.3.3.4.5 (b)** | Anomaly detection: Denial of Service (DoS) attacks | Detects TCP SYN floods (≥50 unacknowledged SYN packets), UDP floods (≥200 packets to non-standard ports), and large volume surges (>25 MB). | **Completed (Real Data)** |
| **5.3.3.4.5 (c)** | Anomaly detection: Worms and virus infections | Detects signatures for SMB port 445 (WannaCry, EternalBlue), Telnet 23/2323 (Mirai botnet), MS-SQL 1433/1434 (Slammer), Shell backdoor 4444 (Metasploit), RDP 3389. | **Completed (Real Data)** |
| **5.3.3.4.6** | Pinpointing network over-utilization causes & highlighting top talkers | `icct_nms_netflow_overutilization_analysis()` calculates link saturation, flags congestion, and pinpoints top talker culprits. | **Completed (Real Data)** |
| **5.3.3.5** | Reports: Comprehensive CSV and JSON export | Controllers provide one-click CSV download for all report types and complete structured JSON export. | **Completed (Real Data)** |

---

## 2. Complete Backend Working Flow

The backend operates with **zero external dependencies** and is designed specifically for air-gapped, offline environments like **RHEL 9.4**.

### A. NTP Subsystem Backend Flow

```
[User / Browser]
       │
       ▼ Navigates to monitoring/controllers/ntp.php
[icct_nms_ntp_all()]
       │
       ├─► Checks freshness of last poll (cache TTL: 60s)
       │
       ▼ If stale or missing:
[icct_nms_ntp_poll_device($hostId)]
       │
       ├─► Opens native UDP socket (udp://<target_address>:123)
       ├─► Constructs 48-byte NTPv4 Mode 3 Client packet
       ├─► Records transmit timestamp t1, receives response timestamp t4
       │
       ├──► [Response Received (RFC 5905)]
       │         │
       │         ├─► Unpacks Stratum, Leap Indicator, Poll, Precision, RefID
       │         ├─► Extracts timestamps: Reference, Origin (t1), Receive (t2), Transmit (t3)
       │         ├─► Computes: Delay = (t4 - t1) - (t3 - t2)
       │         ├─► Computes: Offset = ((t2 - t1) + (t3 - t4)) / 2
       │         ├─► Evaluates thresholds: offset <= max_offset, jitter <= max_jitter
       │         ├─► UPSERT into `plugin_icct_nms_ntp_state`
       │         └─► INSERT into `plugin_icct_nms_ntp_history` (Statistical History)
       │
       └──► [Timeout / Unreachable]
                 │
                 ├─► If target is 127.0.0.1 on Linux: queries `chronyc tracking`
                 ├─► Sets status = 'unreachable', offset = NULL, jitter = NULL, refId = 'NONE'
                 ├─► UPDATE `plugin_icct_nms_ntp_state`
                 └─► INSERT sample into `plugin_icct_nms_ntp_history`
```

### B. NetFlow / IPFIX Subsystem Backend Flow

```
[Network Exporters: Routers / Switches / Linux hosts]
       │
       ▼ Sends UDP datagrams to port 2055 / 9995
[icct-nms-netflow-collector daemon (protocols/netflow/cli/netflow_worker.php)]
       │
       ├─► Reads datagram buffer ($buf) & remote IP address ($fromIp)
       │
       ▼ [icct_nms_netflow_decode_packet($buf, $fromIp)]
       │
       ├─► Version Check (Bytes 0-1):
       │     ├─ Version 5 : Parses 24-byte header + N * 48-byte records
       │     ├─ Version 7 : Parses 24-byte header + N * 52-byte records
       │     ├─ Version 9 : Parses 20-byte header + FlowSets (Templates & Data)
       │     └─ IPFIX (10): Parses 16-byte header + Sets (RFC 7011)
       │
       ├─► Normalizes each flow into structured array:
       │     [src_ip, dst_ip, src_port, dst_port, protocol, bytes, packets, tcp_flags, tos]
       ├─► Enriches with Application Name: `icct_nms_port_to_app(srcPort, dstPort, proto)`
       ├─► Enriches with Network Type: `icct_nms_is_private_ip()` -> 'lan' vs 'wan'
       │
       ▼ [icct_nms_netflow_record_flows($hostId, $flows)]
       │
       ├─► Persistent Storage:
       │     INSERT each record into `plugin_icct_nms_netflow_records`
       │     INSERT into `plugin_icct_nms_netflow_top_talkers`
       │
       ├─► Anomaly Detection Engine: `icct_nms_netflow_detect_anomalies($hostId, $flows)`
       │     ├─ Worm & Virus Signatures: Ports 445, 23, 2323, 1433, 4444, 3389
       │     ├─ TCP Flag Scans: Null scan (0x00), Xmas scan (FIN+PSH+URG), SYN+FIN
       │     ├─ DoS Attacks: SYN flood (>=50 pkts), UDP flood (>=200 pkts), Volume surge (>25 MB)
       │     ├─ Port Scans: Vertical (>=10 ports on single target), Horizontal sweep (>=8 targets)
       │     └─ INSERT detected anomalies into `plugin_icct_nms_netflow_anomalies`
       │
       └─► State Update:
             UPDATE `plugin_icct_nms_netflow_state`:
               total_bytes += bytes, total_packets += pkts, total_flows += count,
               anomaly_status = worst_severity, last_data_at = NOW()
```

---

## 3. How the User Can See Everything in the UI

### 1. Enabling Protocols on a Device
1. Open the Device Configuration Wizard:
   `http://localhost/cacti/plugins/icct_nms/inventory/controllers/device.php?id=1`
2. Click **Protocol Config** tab (or `#protocol`).
3. **Configure NTP**:
   - Expand the **NTP (Network Time Protocol)** card.
   - Enter NTP Server Address (e.g. `127.0.0.1` or upstream NTP server).
   - Port defaults to `123`, Version to `NTPv4`, Timeout to `3`s.
   - Click **Save Protocol Configuration**. The poller immediately polls the node.
4. **Configure NetFlow**:
   - Expand the **NetFlow** card.
   - Enter Exporter Address (e.g. `127.0.0.1` or router IP).
   - Select Version (`NetFlow v5`, `NetFlow v7`, `NetFlow v9`, or `IPFIX`).
   - Set Collector Port (default `2055`).
   - Click **Save Protocol Configuration**.

---

### 2. Viewing NTP Monitoring & Reports
Navigate to **NTP Monitoring**:
`http://localhost/cacti/plugins/icct_nms/monitoring/controllers/ntp.php`

**What you will see:**
- **Status Cards**: Total monitored NTP devices, Synchronized count, Degraded count, Unreachable count.
- **Device Table**:
  - **Device Name & Hostname**
  - **NTP Server**: Target being polled
  - **Stratum**: Color-coded badge (Stratum 1–2: Green, 3–5: Yellow, 16: Red)
  - **Reference ID**: GPS, PPS, upstream server IP, or NONE
  - **Offset (ms)**: High-precision clock offset
  - **Jitter (ms)**: Clock frequency stability
  - **Delay (ms)**: Round-trip network transmission latency
  - **Status Badge**: `synchronized`, `degraded`, or `unreachable`
  - **Last Polled**: Timestamp of latest poll
- **Reports & Export Section**:
  - **Download CSV Report**: One-click download of all NTP parameters formatted for spreadsheets.
  - **Download JSON Report**: Complete JSON report containing all device metrics and metadata.

---

### 3. Viewing Device-Level NTP Statistical History
1. In the NTP Overview or Inventory list, click on a device name.
2. Under the device view tabs, click **NTP**.
3. **Sections displayed:**
   - **NTP Summary**: Detailed metrics grid.
   - **NTP Peers**: Peer association table with reachable/selected flags.
   - **Statistical History (Requirement 5.3.3.3)**:
     - Table showing the last 25–50 polling samples: Recorded At, Status badge, Stratum, Reference ID, Offset (ms), Jitter (ms), Delay (ms), and Dispersion (ms).

---

### 4. Viewing NetFlow / IPFIX Monitoring, Analytics & Security
Navigate to **NetFlow Monitoring**:
`http://localhost/cacti/plugins/icct_nms/monitoring/controllers/netflow.php`

The page provides a complete multi-tab analytics console:

| Tab | Content & Capabilities | Requirement Reference |
|---|---|---|
| **Exporters & Devices** | Shows all configured NetFlow exporters, Active/Listening status, Anomaly status badge (`Normal`, `Warning`, `Attack`, `Critical`), Total Bytes, Packets, Flows, and Top Talker. | 5.3.3.4.1 |
| **Top Hosts** | Top talker host IP addresses, network classification (LAN vs WAN), total bytes transferred, packet counts, and percentage utilization bars. | 5.3.3.4.4 (a) |
| **Protocols** | Protocol distribution breakdown (TCP, UDP, ICMP, GRE, ESP, OSPF) with byte volume and traffic share percentages. | 5.3.3.4.4 (b) |
| **Applications** | Application-layer breakdown (HTTP, HTTPS, DNS, SSH, SMB, RDP, MySQL, NTP, Syslog, etc.) derived from flow port analysis. | 5.3.3.4.4 (c) |
| **Conversations** | Active end-to-end conversations (Source IP:Port ↔ Destination IP:Port), protocol used, bytes transferred, and packet volume. | 5.3.3.4.4 (d) |
| **WAN vs LAN Trends** | Comparative bandwidth usage analysis between internal LAN traffic and external WAN traffic, with volume breakdowns and percentage shares. | 5.3.3.4.2 |
| **Anomalies & Security** | Live intrusion and attack detection log: Severity badge (`Critical`, `High`, `Warning`), Attack Type (`Worm / Virus`, `DoS Attack`, `Port Scan`, `TCP Flag Anomaly`, `Volume Surge`), Target IP:Port, Evidence description, and Detection timestamp. | 5.3.3.4.5 |
| **Over-utilization** | Network bottleneck and saturation diagnostics: Bandwidth consumption rate, Congestion warning threshold analysis, and Top Talker IP addresses identified as primary contributors. | 5.3.3.4.6 |
| **Reports** | On-demand report generator: Individual **Download CSV** buttons for Top Hosts, Protocols, Applications, Conversations, WAN/LAN Trends, and Security Anomalies, plus **Export Full JSON Report**. | 5.3.3.5 |

---

## 4. Offline RHEL 9.4 Verification & Testing Guide

In an air-gapped RHEL 9.4 environment with no internet access, follow these instructions to verify real packet capture and reporting.

### Step 1: Ensure Collector Daemons & Services Are Active
```bash
# Verify NetFlow UDP collector daemon is running on port 2055:
sudo systemctl status icct-nms-netflow-collector.service
sudo ss -lunp | grep ':2055'

# Verify NTP timer is active:
sudo systemctl list-timers | grep 'icct-nms-ntp'
```

### Step 2: Configure Local Master Clock (chrony)
Because RHEL 9.4 cannot reach public internet pools, configure the server as its own stratum 10 master clock:
```bash
sudo nano /etc/chrony.conf
```
Add or ensure these lines exist:
```ini
local stratum 10
allow 127.0.0.1
allow ::1
```
Restart chrony:
```bash
sudo systemctl restart chronyd
chronyc tracking
```

### Step 3: Run the Verification Script (Pure PHP UDP Generator)
To simulate network traffic and verify the entire pipeline (decoding, persistent storage, anomaly detection, reports) without external network gear, run this one-line command on your RHEL 9.4 server:

```bash
php -r '
// 1. Send NetFlow v5 HTTP traffic
$hdr = pack("n", 5) . pack("n", 1) . str_repeat("\0", 20);
$rec = pack("N", ip2long("192.168.1.10")) . pack("N", ip2long("10.0.0.1")) . pack("N", 0) . pack("n", 0) . pack("n", 0)
     . pack("N", 250) . pack("N", 125000) . pack("N", 0) . pack("N", 0)
     . pack("n", 49152) . pack("n", 80) . "\0" . "\x18" . chr(6) . chr(0)
     . pack("n", 0) . pack("n", 0) . chr(0) . chr(0) . pack("n", 0);
$sock = stream_socket_client("udp://127.0.0.1:2055", $e, $s);
fwrite($sock, $hdr . $rec);

// 2. Send Worm signature (SMB port 445 WannaCry simulation)
$recWorm = pack("N", ip2long("10.0.0.99")) . pack("N", ip2long("10.0.0.1")) . pack("N", 0) . pack("n", 0) . pack("n", 0)
         . pack("N", 5) . pack("N", 2048) . pack("N", 0) . pack("N", 0)
         . pack("n", 54321) . pack("n", 445) . "\0" . "\x18" . chr(6) . chr(0)
         . pack("n", 0) . pack("n", 0) . chr(0) . chr(0) . pack("n", 0);
fwrite($sock, $hdr . $recWorm);
fclose($sock);
echo "Injected test NetFlow packets successfully.\n";
'
```

### Step 4: Verify in the Cacti Web Interface
1. Refresh `http://localhost/cacti/plugins/icct_nms/monitoring/controllers/netflow.php`.
2. Notice:
   - Device status changes to **Active**.
   - **Top Hosts** tab shows `192.168.1.10` and `10.0.0.99`.
   - **Applications** tab shows `HTTP` and `SMB`.
   - **Anomalies & Security** tab shows:
     - `CRITICAL`: Worm / Exploit Signature: SMB traffic detected on port 445 (WannaCry / EternalBlue / Conficker signature).
   - **Reports** tab allows immediate CSV and JSON export of this real, decoded traffic.

---

## 5. Running the Automated Unit Test Suite

Execute the test suites from the plugin directory. All tests use lightweight PHP stubs and require no active database, network connection, or third-party test runners:

```bash
# 1. NetFlow & IPFIX (v5, v7, v9, IPFIX, Anomaly Engine, Top Talkers, LAN/WAN)
php shared/tests/netflow_service_test.php
# Expected output: NetFlow service tests: 68 passed, 0 failed.

# 2. NTP Monitoring (RFC 5905, Jitter, Offset, Delay, History, CSV/JSON Exports)
php shared/tests/ntp_service_test.php
# Expected output: NTP service tests: 23 passed, 0 failed.

# 3. Rack Unit Count & Multi-Unit Placement
php shared/tests/rack_unit_count_test.php
php shared/tests/device_view_placement_test.php
php shared/tests/rack_placement_test.php

# 4. Feature Paths & Permission Registration
php shared/tests/feature_paths_test.php
```

All unit tests must pass with 0 failures before any code deployment.
