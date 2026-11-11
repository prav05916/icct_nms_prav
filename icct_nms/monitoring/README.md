# ICCT NMS — Monitoring Subsystem (NTP & NetFlow/IPFIX)

Complete architecture, backend data flow, UI navigation, RHEL 9.4 offline setup, and testing guide for the **NTP** (Requirement 5.3.3.3) and **NetFlow / IPFIX** (Requirements 5.3.3.4.1–5.3.3.4.6, 5.3.3.5) subsystems in the ICCT NMS Cacti plugin.

---

## Table of Contents

1. [Requirement Traceability Matrix](#1-requirement-traceability-matrix)
2. [File & Directory Structure](#2-file--directory-structure)
3. [NTP — Complete Working Flow](#3-ntp--complete-working-flow)
4. [NetFlow — Complete Working Flow](#4-netflow--complete-working-flow)
5. [Reports & Export — How It Works](#5-reports--export--how-it-works)
6. [UI Navigation Guide — What You See](#6-ui-navigation-guide--what-you-see)
7. [RHEL 9.4 Offline Setup Guide](#7-rhel-94-offline-setup-guide)
8. [Testing on RHEL 9.4](#8-testing-on-rhel-94)
9. [Automated Unit Tests](#9-automated-unit-tests)
10. [Troubleshooting](#10-troubleshooting)

---

## 1. Requirement Traceability Matrix

| Requirement | Specification | Implementation | Status |
|---|---|---|---|
| **5.3.3.3** | NTP monitoring: sync status, sync source, offset, jitter, delay | RFC 5905 native UDP poller (`icct_nms_ntp_query`), chrony fallback, `plugin_icct_nms_ntp_history` table | ✅ Real Data |
| **5.3.3.4.1** | NetFlow v5, v7, v9, IPFIX (v10) support | Binary decoder `icct_nms_netflow_decode_packet()` supporting all 4 versions | ✅ Real Data |
| **5.3.3.4.2** | WAN and LAN bandwidth trends | RFC 1918 classifier `icct_nms_is_private_ip()` + `icct_nms_netflow_wan_lan_trends()` | ✅ Real Data |
| **5.3.3.4.3** | Persistent storage of NetFlow data | `plugin_icct_nms_netflow_records` table with indexing | ✅ Real Data |
| **5.3.3.4.4 (a)** | Report: Top hosts | `icct_nms_netflow_top_hosts()` with percentage shares | ✅ Real Data |
| **5.3.3.4.4 (b)** | Report: Protocols | `icct_nms_netflow_protocols_breakdown()` (TCP, UDP, ICMP, etc.) | ✅ Real Data |
| **5.3.3.4.4 (c)** | Report: Applications | `icct_nms_port_to_app()` maps 40+ well-known ports | ✅ Real Data |
| **5.3.3.4.4 (d)** | Report: Conversations | `icct_nms_netflow_conversations()` src↔dst pairs | ✅ Real Data |
| **5.3.3.4.5 (a)** | Anomaly: Network attacks | Port scans (vertical ≥10 ports, horizontal ≥8 targets), stealth scans | ✅ Real Data |
| **5.3.3.4.5 (b)** | Anomaly: DoS attacks | SYN floods (≥50 pkts), UDP floods (≥200 pkts), volume surges (>25 MB) | ✅ Real Data |
| **5.3.3.4.5 (c)** | Anomaly: Worms/viruses | SMB:445, Telnet:23/2323, MS-SQL:1433, Backdoor:4444, RDP:3389 | ✅ Real Data |
| **5.3.3.4.6** | Over-utilization & top talkers | `icct_nms_netflow_overutilization_analysis()` with congestion flags | ✅ Real Data |
| **5.3.3.5** | CSV and JSON export reports | One-click download from NTP and NetFlow controllers | ✅ Real Data |

---

## 2. File & Directory Structure

```
monitoring/
├── controllers/
│   ├── ntp.php              ← NTP overview + CSV/JSON export handler
│   └── netflow.php          ← NetFlow overview + CSV/JSON export handler
├── services/
│   ├── ntp_service.php      ← NTP poller, RFC 5905 query, chrony, upsert, history, CSV/JSON
│   └── netflow_service.php  ← NetFlow decoder (v5/v7/v9/IPFIX), anomaly engine, analytics, CSV/JSON
├── templates/
│   ├── ntp_view.php         ← NTP overview table, Reports & Export buttons
│   ├── netflow_view.php     ← NetFlow multi-tab analytics console
│   └── device_view_ntp.php  ← Per-device NTP detail (summary, peers, history)
├── rhel_offline_test.php    ← Offline RHEL test: injects synthetic NetFlow v5 packets
├── requirement.txt          ← Original requirements specification
└── README.md                ← This file
```

---

## 3. NTP — Complete Working Flow

### Step-by-Step: From Configuration to Display

```
┌──────────────────────────────────────────────────────────────────┐
│  STEP 1: USER CONFIGURES NTP ON A DEVICE                        │
│                                                                  │
│  User opens: device.php?id=1 → "Protocol Config" tab            │
│  Fills in: NTP Server (e.g. 127.0.0.1), Port (123), Version     │
│  Clicks: "Save Protocol Configuration"                          │
│                                                                  │
│  Code path:                                                      │
│    protocols/services/protocol_save_handler.php                  │
│      → icct_nms_ntp_upsert($hostId, $data)                      │
│          → INSERT/UPDATE plugin_icct_nms_ntp_devices             │
│          → IMMEDIATELY calls icct_nms_ntp_poll_device($hostId)   │
└──────────────────┬───────────────────────────────────────────────┘
                   │
                   ▼
┌──────────────────────────────────────────────────────────────────┐
│  STEP 2: LIVE NTP POLLING (RFC 5905)                             │
│                                                                  │
│  icct_nms_ntp_poll_device($hostId)                               │
│    1. Reads device config from plugin_icct_nms_ntp_devices       │
│    2. Calls icct_nms_ntp_query($target, $port, $timeout)         │
│       ┌─────────────────────────────────────────────────────┐    │
│       │ Creates a 48-byte NTPv4 Mode 3 (Client) UDP packet  │    │
│       │ First byte: 0x23 (LI=0, VN=4, Mode=3)               │    │
│       │ Sends to udp://<target>:123                          │    │
│       │ Records transmit time t1, receive time t4            │    │
│       │                                                      │    │
│       │ From 48-byte response, extracts:                     │    │
│       │   • Stratum (byte 1)                                 │    │
│       │   • Leap Indicator (byte 0, bits 6-7)                │    │
│       │   • Reference ID (bytes 12-15)                       │    │
│       │   • Receive timestamp t2 (bytes 32-39)               │    │
│       │   • Transmit timestamp t3 (bytes 40-47)              │    │
│       │                                                      │    │
│       │ Computes (RFC 5905 formulas):                        │    │
│       │   Delay  = (t4 - t1) - (t3 - t2)                    │    │
│       │   Offset = ((t2 - t1) + (t3 - t4)) / 2              │    │
│       │   Jitter = derived from root dispersion              │    │
│       └─────────────────────────────────────────────────────┘    │
│                                                                  │
│  If UDP query fails and target is 127.0.0.1 (Linux):             │
│    → Falls back to `chronyc tracking` command                    │
│    → Parses: Reference ID, Stratum, Last Offset, Root Delay      │
└──────────────────┬───────────────────────────────────────────────┘
                   │
                   ▼
┌──────────────────────────────────────────────────────────────────┐
│  STEP 3: DATABASE STORAGE                                        │
│                                                                  │
│  On successful poll:                                             │
│    1. UPSERT → plugin_icct_nms_ntp_state                         │
│       (status, stratum, offset_ms, jitter_ms, delay_ms,          │
│        reference_id, last_success, sample_count++)                │
│    2. INSERT → plugin_icct_nms_ntp_history                       │
│       (statistical record for trend analysis)                    │
│    3. REPLACE → plugin_icct_nms_ntp_peers                        │
│       (peer association table with reachable/selected flags)      │
│                                                                  │
│  On failure:                                                     │
│    1. UPDATE ntp_state → status='unreachable', stratum=16         │
│    2. INSERT ntp_history → status='unreachable', offset=NULL      │
└──────────────────┬───────────────────────────────────────────────┘
                   │
                   ▼
┌──────────────────────────────────────────────────────────────────┐
│  STEP 4: USER VIEWS NTP MONITORING PAGE                          │
│                                                                  │
│  URL: monitoring/controllers/ntp.php                             │
│                                                                  │
│  1. Controller calls icct_nms_ntp_all()                          │
│  2. icct_nms_ntp_all() checks for stale devices (>60s old)       │
│     → Automatically re-polls stale devices                       │
│  3. JOIN query: ntp_devices ↔ ntp_state ↔ host                   │
│  4. Passes $ntpDevices array to ntp_view.php template            │
│  5. Template renders:                                            │
│     • Device Name (links to device detail page)                  │
│     • IP / Hostname                                              │
│     • NTP Server                                                 │
│     • Mode (server)                                              │
│     • Stratum (color-coded badge: 1-2=Green, 3-5=Yellow, 16=Red) │
│     • Offset (ms) (color-coded: <1ms=good, <10ms=warn, else=crit)│
│     • Jitter (ms)                                                │
│     • Delay (ms)                                                 │
│     • Last Polled timestamp                                      │
│     • Status badge (Good/Warning/Critical/Unreachable)           │
│     • Export CSV and Export JSON buttons                          │
└──────────────────────────────────────────────────────────────────┘
```

### Auto-Refresh Behavior

- When the NTP overview page loads, `icct_nms_ntp_all(true)` automatically detects devices whose `last_attempt` is older than 60 seconds.
- These devices are re-polled in real-time via `icct_nms_ntp_poll_device()`, so the page always shows **live data**, not cached/static values.
- The per-device NTP detail page (`device_view_ntp.php`) shows the last 25 statistical history samples from `plugin_icct_nms_ntp_history`.

---

## 4. NetFlow — Complete Working Flow

### Step-by-Step: From Configuration to Display

```
┌──────────────────────────────────────────────────────────────────┐
│  STEP 1: USER CONFIGURES NETFLOW ON A DEVICE                    │
│                                                                  │
│  User opens: device.php?id=1 → "Protocol Config" tab            │
│  Fills in: Exporter IP, Flow Version (v5/v7/v9/IPFIX),          │
│            Collector Port (2055)                                 │
│  Clicks: "Save Protocol Configuration"                          │
│                                                                  │
│  Code path:                                                      │
│    protocols/services/protocol_save_handler.php                  │
│      → icct_nms_netflow_upsert($hostId, $data)                  │
│          → INSERT/UPDATE plugin_icct_nms_netflow_devices         │
│          → INSERT/UPDATE plugin_icct_nms_netflow_state           │
│          → IMMEDIATELY calls icct_nms_netflow_sample_device()    │
└──────────────────┬───────────────────────────────────────────────┘
                   │
                   ▼
┌──────────────────────────────────────────────────────────────────┐
│  STEP 2: REAL DATA ACQUISITION (Three Sources)                   │
│                                                                  │
│  Source A — NetFlow Collector Daemon (Primary, RHEL):             │
│    File: protocols/netflow/cli/netflow_worker.php                │
│    Runs as: systemd service (icct-nms-netflow-collector.service) │
│    Listens on: UDP port 2055                                     │
│    When a UDP datagram arrives from a router/switch/host:         │
│      1. Reads raw binary buffer $buf from socket                 │
│      2. Calls icct_nms_netflow_decode_packet($buf, $fromIp)      │
│      3. Calls icct_nms_netflow_record_flows($hostId, $flows)     │
│                                                                  │
│  Source B — Local Network Counters (Linux fallback):              │
│    File: monitoring/services/netflow_service.php                 │
│    Function: icct_nms_netflow_sample_device($hostId)             │
│    If exporter is 127.0.0.1/localhost on Linux:                  │
│      1. Reads /proc/net/dev for real rx/tx byte & packet counts  │
│      2. Runs `ss -nt` for real active TCP socket connections     │
│      3. Creates structured flow records from actual OS data      │
│      4. Calls icct_nms_netflow_record_flows() to persist         │
│                                                                  │
│  Source C — Stored Flow Records (Cache check):                   │
│    If recent records exist in plugin_icct_nms_netflow_records     │
│    (within last 1 hour), updates state summary from those.        │
└──────────────────┬───────────────────────────────────────────────┘
                   │
                   ▼
┌──────────────────────────────────────────────────────────────────┐
│  STEP 3: BINARY PACKET DECODING                                  │
│                                                                  │
│  icct_nms_netflow_decode_packet($buf, $fromIp)                   │
│                                                                  │
│  Version detected from first 2 bytes of datagram:                │
│  ┌────────────────────────────────────────────────────────┐      │
│  │  v5:   Header 24 bytes + N × 48-byte records           │      │
│  │        Fields: srcIP, dstIP, nextHop, input/output iface│      │
│  │        packets(4B), bytes(4B), first(4B), last(4B),     │      │
│  │        srcPort(2B), dstPort(2B), pad, tcpFlags, proto   │      │
│  │                                                         │      │
│  │  v7:   Header 24 bytes + N × 52-byte records           │      │
│  │        Same as v5 but with 4 extra bytes for flags1     │      │
│  │                                                         │      │
│  │  v9:   Header 20 bytes + FlowSets                       │      │
│  │        FlowSet ID ≥ 256 → Data records                  │      │
│  │        Falls back to metadata flow if no records parsed  │      │
│  │                                                         │      │
│  │  IPFIX (v10): Header 16 bytes + Sets (RFC 7011)         │      │
│  │        Set ID ≥ 256 → Data Set records                  │      │
│  │        Falls back to metadata flow if no records parsed  │      │
│  └────────────────────────────────────────────────────────┘      │
│                                                                  │
│  Each record is normalized to:                                   │
│    { src_ip, dst_ip, src_port, dst_port, protocol, proto_num,    │
│      bytes, packets, tcp_flags, tos, version,                    │
│      network_type (lan/wan), app_name }                          │
└──────────────────┬───────────────────────────────────────────────┘
                   │
                   ▼
┌──────────────────────────────────────────────────────────────────┐
│  STEP 4: PERSISTENT STORAGE + ANOMALY DETECTION                  │
│                                                                  │
│  icct_nms_netflow_record_flows($hostId, $flows)                  │
│                                                                  │
│  For each flow record:                                           │
│    1. INSERT → plugin_icct_nms_netflow_records                   │
│    2. INSERT → plugin_icct_nms_netflow_top_talkers               │
│    3. Aggregate into in-memory top talkers map                   │
│                                                                  │
│  Anomaly Detection Engine: icct_nms_netflow_detect_anomalies()   │
│    ┌──────────────────────────────────────────────────────┐      │
│    │ Scans all flows in the batch for:                     │      │
│    │                                                       │      │
│    │ WORM/VIRUS SIGNATURES:                                │      │
│    │   Port 445  → WannaCry/EternalBlue/Conficker          │      │
│    │   Port 23/2323 → Mirai botnet                         │      │
│    │   Port 1433/1434 → SQL Slammer                        │      │
│    │   Port 4444 → Metasploit backdoor                     │      │
│    │   Port 3389 (>30 pkts) → RDP BlueKeep brute-force     │      │
│    │                                                       │      │
│    │ TCP FLAG ANOMALIES:                                   │      │
│    │   Flags 0x00 → TCP Null Scan                          │      │
│    │   FIN+PSH+URG (0x29) → TCP Xmas Scan                  │      │
│    │   SYN+FIN (0x03) → Invalid combination                │      │
│    │                                                       │      │
│    │ DoS ATTACKS:                                          │      │
│    │   ≥50 SYN without ACK → SYN Flood                     │      │
│    │   ≥200 UDP pkts (non-DNS/NTP) → UDP Flood             │      │
│    │   >25 MB single flow → Volume Surge                   │      │
│    │                                                       │      │
│    │ PORT SCANNING:                                        │      │
│    │   ≥10 distinct ports on single target → Vertical Scan  │      │
│    │   ≥8 distinct targets on single port → Horizontal Sweep│      │
│    └──────────────────────────────────────────────────────┘      │
│                                                                  │
│  Each detected anomaly is:                                       │
│    INSERT → plugin_icct_nms_netflow_anomalies                    │
│    (host_id, type, severity, source/target IPs, description)     │
│                                                                  │
│  Device state updated:                                           │
│    UPDATE plugin_icct_nms_netflow_state                          │
│    (status='active', total_bytes+=, anomaly_status=worst)        │
└──────────────────┬───────────────────────────────────────────────┘
                   │
                   ▼
┌──────────────────────────────────────────────────────────────────┐
│  STEP 5: USER VIEWS NETFLOW MONITORING PAGE                      │
│                                                                  │
│  URL: monitoring/controllers/netflow.php                         │
│                                                                  │
│  1. Controller calls icct_nms_netflow_all()                      │
│  2. icct_nms_netflow_all() checks for stale devices (>60s old)   │
│     → Automatically runs icct_nms_netflow_sample_device()        │
│  3. Template renders 9 tabs:                                     │
│     Devices | Top Hosts | Protocols | Applications |             │
│     Conversations | WAN/LAN Trends | Anomalies |                 │
│     Over-utilization | Reports                                   │
│                                                                  │
│  Each analytics tab calls its own data function:                 │
│    Top Hosts      → icct_nms_netflow_top_hosts(0, 15)            │
│    Protocols      → icct_nms_netflow_protocols_breakdown(0)      │
│    Applications   → icct_nms_netflow_applications_breakdown(0,15)│
│    Conversations  → icct_nms_netflow_conversations(0, 15)        │
│    WAN/LAN Trends → icct_nms_netflow_wan_lan_trends(0, 24)       │
│    Anomalies      → icct_nms_netflow_anomalies(0, 20)            │
│    Over-util      → icct_nms_netflow_overutilization_analysis(0) │
└──────────────────────────────────────────────────────────────────┘
```

### Auto-Refresh Behavior

- When the NetFlow overview page loads, `icct_nms_netflow_all(true)` automatically detects devices whose `last_data_at` is older than 60 seconds.
- On Linux, if the exporter is `127.0.0.1`, the system reads real data from `/proc/net/dev` and `ss -nt`.
- On RHEL with the collector daemon running, real NetFlow datagrams from routers/switches are decoded and stored in real-time.

---

## 5. Reports & Export — How It Works

### Export Button → Controller → Download

```
User clicks Export CSV (NTP page)
   │
   ▼ Browser navigates to:
   /cacti/plugins/icct_nms/monitoring/controllers/ntp.php?export=csv
   │
   ▼ Controller detects $_GET['export'] = 'csv'
   │
   ▼ Calls icct_nms_ntp_export_csv()
   │   → Fetches all NTP devices via icct_nms_ntp_all(false)
   │   → Writes CSV header: Device Name, Hostname, NTP Server, ...
   │   → Writes one row per device with real metric values
   │
   ▼ Sets headers:
   │   Content-Type: text/csv
   │   Content-Disposition: attachment; filename="ntp_report_20261111.csv"
   │
   ▼ Browser downloads the file
```

### Available Export Endpoints

| Button | URL (relative to plugin base) | What's Exported |
|---|---|---|
| NTP CSV | `monitoring/controllers/ntp.php?export=csv` | All NTP device metrics |
| NTP JSON | `monitoring/controllers/ntp.php?export=json` | Full NTP report with metadata |
| NetFlow Top Hosts CSV | `monitoring/controllers/netflow.php?export=csv&type=top_hosts` | Top talker IPs by bytes |
| NetFlow Protocols CSV | `monitoring/controllers/netflow.php?export=csv&type=protocols` | Protocol distribution |
| NetFlow Applications CSV | `monitoring/controllers/netflow.php?export=csv&type=applications` | Application breakdown |
| NetFlow Conversations CSV | `monitoring/controllers/netflow.php?export=csv&type=conversations` | Source↔Destination pairs |
| NetFlow WAN/LAN CSV | `monitoring/controllers/netflow.php?export=csv&type=trends` | Hourly WAN vs LAN trends |
| NetFlow Anomalies CSV | `monitoring/controllers/netflow.php?export=csv&type=anomalies` | Security alerts & anomalies |
| NetFlow JSON | `monitoring/controllers/netflow.php?export=json` | Complete multi-section report |

---

## 6. UI Navigation Guide — What You See

### NTP Monitoring Page
**URL**: `http://<server>/cacti/plugins/icct_nms/monitoring/controllers/ntp.php`

| Column | What It Shows | Data Source |
|---|---|---|
| Device Name | Clickable link to device detail | `host.description` |
| IP / Hostname | Device IP address | `host.hostname` |
| NTP Server | Target NTP server being polled | `ntp_devices.target_address` |
| Mode | Always "server" (Mode 3 client query) | Static |
| Stratum | 1-2=Green, 3-5=Yellow, 16=Red | Live UDP query |
| Offset (ms) | Clock offset — <1ms=good, <10ms=warn, else=crit | RFC 5905 calculation |
| Jitter (ms) | Clock stability measure | Derived from root dispersion |
| Delay (ms) | Network round-trip time | RFC 5905 calculation |
| Last Polled | Timestamp of latest successful poll | `ntp_state.last_success` |
| Status | Good / Warning / Critical / Unreachable | Computed from offset thresholds |

### NetFlow Monitoring Page
**URL**: `http://<server>/cacti/plugins/icct_nms/monitoring/controllers/netflow.php`

9 tabs, each showing real data from `plugin_icct_nms_netflow_records`:

1. **Devices** — Configured exporters, status badges, flows/s, throughput
2. **Top Hosts** — IP addresses consuming most bandwidth, with % bars
3. **Protocols** — TCP/UDP/ICMP breakdown by bytes
4. **Applications** — HTTP, HTTPS, DNS, SSH, etc. by bytes
5. **Conversations** — Source↔Destination pairs with bytes/packets
6. **WAN/LAN Trends** — Hourly LAN vs WAN bandwidth comparison
7. **Anomalies & Security** — Worm signatures, DoS detections, port scans
8. **Over-utilization** — Congestion flag + top talker identification
9. **Reports** — CSV/JSON download buttons

---

## 7. RHEL 9.4 Offline Setup Guide

This guide covers setting up NTP and NetFlow monitoring on an air-gapped RHEL 9.4 server with **no internet access**. Everything uses built-in OS packages and pure PHP — no external downloads needed.

### 7.1 Prerequisites

```bash
# Verify PHP is installed with required extensions
php -v
php -m | grep -E 'sockets|pdo_mysql|json|mbstring'

# If php-sockets is missing (needed for NetFlow daemon):
# Install from the RHEL 9.4 DVD/ISO repo (offline)
sudo mount /dev/cdrom /mnt
sudo dnf install --disablerepo=* --enablerepo=media php-sockets -y
# OR if using AppStream:
sudo dnf install php-sockets -y
```

### 7.2 Setting Up NTP (chrony)

Chrony is installed by default on RHEL 9.4. For an offline/air-gapped server, configure it as a local master clock.

```bash
# 1. Verify chrony is installed
rpm -q chrony
# If not installed, install from DVD/ISO:
# sudo dnf install --disablerepo=* --enablerepo=media chrony -y

# 2. Configure as local master clock (no upstream NTP server needed)
sudo cp /etc/chrony.conf /etc/chrony.conf.bak
sudo tee -a /etc/chrony.conf > /dev/null << 'EOF'

# ICCT NMS: Act as local stratum 10 clock for air-gapped networks
local stratum 10
allow 127.0.0.1
allow ::1
# Allow other devices on the local network to sync to this server
allow 10.0.0.0/8
allow 172.16.0.0/12
allow 192.168.0.0/16
EOF

# 3. Start and enable chrony
sudo systemctl enable --now chronyd
sudo systemctl restart chronyd

# 4. Verify it's working
chronyc tracking
# You should see: Leap status: Not synchronised (normal for offline)
# Stratum: 10 (local master clock)

# 5. Verify NTP port 123 is listening
sudo ss -lunp | grep ':123'
```

**Firewall (if enabled):**
```bash
sudo firewall-cmd --add-service=ntp --permanent
sudo firewall-cmd --reload
```

### 7.3 Setting Up NetFlow Collector

The NetFlow collector is a pure PHP UDP listener daemon — **no external software needed**.

```bash
# 1. Create the systemd service file
sudo tee /etc/systemd/system/icct-nms-netflow-collector.service > /dev/null << 'EOF'
[Unit]
Description=ICCT NMS NetFlow/IPFIX Collector Daemon
After=network.target mariadb.service

[Service]
Type=simple
ExecStart=/usr/bin/php /usr/share/cacti/plugins/icct_nms/protocols/netflow/cli/netflow_worker.php --port=2055
Restart=always
RestartSec=5
User=apache
Group=apache
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

# 2. Reload systemd and start the collector
sudo systemctl daemon-reload
sudo systemctl enable --now icct-nms-netflow-collector.service

# 3. Verify it's running
sudo systemctl status icct-nms-netflow-collector.service
sudo ss -lunp | grep ':2055'
```

**Firewall (if enabled):**
```bash
# Open UDP 2055 for NetFlow datagrams from network devices
sudo firewall-cmd --add-port=2055/udp --permanent
sudo firewall-cmd --reload
```

### 7.4 Configure Network Devices to Send NetFlow

On your routers/switches, configure them to export NetFlow to the RHEL server:

**Cisco Router Example:**
```
! Enable NetFlow on interface
interface GigabitEthernet0/0
 ip flow ingress
 ip flow egress

! Configure NetFlow export
ip flow-export version 9
ip flow-export destination <RHEL_SERVER_IP> 2055
ip flow-export source Loopback0
```

**Linux host (softflowd — if installed):**
```bash
softflowd -i eth0 -n <RHEL_SERVER_IP>:2055 -v 5
```

### 7.5 Database Tables

The required tables are created automatically by `setup.php` when the plugin is enabled. If you need to verify:

```sql
-- Check NTP tables exist
SHOW TABLES LIKE 'plugin_icct_nms_ntp%';
-- Expected: plugin_icct_nms_ntp_devices, plugin_icct_nms_ntp_state,
--           plugin_icct_nms_ntp_history, plugin_icct_nms_ntp_peers

-- Check NetFlow tables exist
SHOW TABLES LIKE 'plugin_icct_nms_netflow%';
-- Expected: plugin_icct_nms_netflow_devices, plugin_icct_nms_netflow_state,
--           plugin_icct_nms_netflow_records, plugin_icct_nms_netflow_anomalies,
--           plugin_icct_nms_netflow_top_talkers
```

---

## 8. Testing on RHEL 9.4

### 8.1 Test NTP (No External Server Needed)

```bash
# 1. In ICCT NMS UI, go to device.php?id=1 → Protocol Config tab
#    Set NTP Server: 127.0.0.1
#    Port: 123
#    Click Save

# 2. Verify in monitoring page:
#    Open: monitoring/controllers/ntp.php
#    You should see the device with:
#      - Stratum: 10 (local master clock)
#      - Status: Synced or Degraded (depending on offset)
#      - Offset, Jitter, Delay: real measured values

# 3. Verify chrony data is being used:
chronyc tracking
# Compare the Stratum and Last Offset values with what the UI shows
```

### 8.2 Test NetFlow (Inject Synthetic Packets)

Use the included offline test script to inject real NetFlow v5 packets into the collector:

```bash
cd /usr/share/cacti/plugins/icct_nms

# Run the offline test (injects 4 types of traffic)
php monitoring/rhel_offline_test.php
```

This script injects:
1. **Normal HTTP traffic** (192.168.1.10 → 10.0.0.1:80) — Should appear in Top Hosts, Applications (HTTP)
2. **Worm signature** (10.0.0.99 → 10.0.0.1:445) — Should trigger CRITICAL anomaly
3. **Port scan** (10.0.0.50 scanning 12 ports on 10.0.0.1) — Should trigger port scan alert
4. **SYN flood** (172.16.0.1 → 10.0.0.5:80, 55 SYN packets) — Should trigger DoS alert

After running, refresh the NetFlow monitoring page and verify all tabs show real data.

### 8.3 Manual NetFlow v5 Packet Injection

You can also inject a single test packet manually:

```bash
php -r '
$hdr = pack("n", 5) . pack("n", 1) . str_repeat("\0", 20);
$rec = pack("N", ip2long("192.168.1.10")) . pack("N", ip2long("10.0.0.1"))
     . pack("N", 0) . pack("n", 0) . pack("n", 0)
     . pack("N", 250) . pack("N", 125000) . pack("N", 0) . pack("N", 0)
     . pack("n", 49152) . pack("n", 80) . "\0" . "\x18" . chr(6) . chr(0)
     . pack("n", 0) . pack("n", 0) . chr(0) . chr(0) . pack("n", 0);
$sock = stream_socket_client("udp://127.0.0.1:2055", $e, $s);
fwrite($sock, $hdr . $rec);
fclose($sock);
echo "Injected 1 NetFlow v5 record (HTTP traffic).\n";
'
```

### 8.4 Verify Everything in the UI

After setup and testing, verify each section:

| Check | URL | Expected |
|---|---|---|
| NTP Overview | `monitoring/controllers/ntp.php` | Device with stratum, offset, status badge |
| NTP Export CSV | Click "Export CSV" button | Downloads `.csv` file with NTP metrics |
| NTP Export JSON | Click "Export JSON" button | Downloads `.json` file with full report |
| NetFlow Devices | `monitoring/controllers/netflow.php` (Devices tab) | Device with status Active/Listening |
| Top Hosts | Click "Top Hosts" tab | IP addresses with byte counts and % bars |
| Protocols | Click "Protocols" tab | TCP/UDP breakdown |
| Applications | Click "Applications" tab | HTTP, SMB, etc. |
| Conversations | Click "Conversations" tab | Source↔Destination pairs |
| WAN/LAN | Click "WAN/LAN Trends" tab | Hourly LAN vs WAN bytes |
| Anomalies | Click "Anomalies & Security" tab | Worm/scan/DoS alerts (after test injection) |
| Over-utilization | Click "Over-utilization" tab | Top talker and congestion status |
| NetFlow CSV | Click any CSV button in Reports tab | Downloads typed CSV file |
| NetFlow JSON | Click "Full JSON Report" in Reports tab | Downloads comprehensive JSON |

---

## 9. Automated Unit Tests

Run from the plugin root directory. All tests use lightweight PHP stubs — no database, network, or external test runner required.

```bash
cd /usr/share/cacti/plugins/icct_nms  # RHEL
# OR
cd c:\Apache24\htdocs\cacti\plugins\icct_nms  # Windows

# NetFlow & IPFIX (v5, v7, v9, IPFIX, Anomaly Engine, Analytics)
php shared/tests/netflow_service_test.php
# Expected: NetFlow service tests: 68 passed, 0 failed.

# NTP Monitoring (RFC 5905, History, CSV/JSON Export)
php shared/tests/ntp_service_test.php
# Expected: NTP service tests: 23 passed, 0 failed.

# Rack & Placement Tests
php shared/tests/rack_unit_count_test.php
php shared/tests/device_view_placement_test.php
php shared/tests/rack_placement_test.php

# Feature Paths & Permission Registration
php shared/tests/feature_paths_test.php
```

**All tests must show 0 failures before deployment.**

---

## 10. Troubleshooting

### NTP shows "Unreachable"

1. Check chrony is running: `systemctl status chronyd`
2. Check port 123 is listening: `ss -lunp | grep ':123'`
3. Check firewall: `firewall-cmd --list-all | grep ntp`
4. Verify the NTP server address in device config is correct
5. On Windows dev: 127.0.0.1 may not respond to UDP NTP; this is expected — use a real NTP server

### NetFlow shows "Listening" but no data

1. Check collector daemon is running: `systemctl status icct-nms-netflow-collector.service`
2. Check port 2055 is listening: `ss -lunp | grep ':2055'`
3. Check firewall allows UDP 2055: `firewall-cmd --list-ports`
4. Inject a test packet (see section 8.3) to verify the pipeline
5. Check Cacti DB log for errors: `journalctl -u icct-nms-netflow-collector.service -f`

### Export buttons show directory listing

This is fixed in the current version. The export buttons now use absolute paths:
- NTP: `monitoring/controllers/ntp.php?export=csv`
- NetFlow: `monitoring/controllers/netflow.php?export=csv&type=top_hosts`

If you see a directory listing, clear your browser cache (Ctrl+Shift+R).

### Database tables missing

Re-enable the plugin in Cacti → Configuration → Plugin Management → Disable then Enable `icct_nms`. The `setup.php` will recreate missing tables.
