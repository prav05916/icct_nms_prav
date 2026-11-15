# ICCT NMS: Monitoring Protocols Architecture, Implementation Flow & Field Guide

This document provides a complete technical reference for the architecture, packet processing pipelines, working flows, implementation flows, and user interface column definitions for **Syslog**, **NTP**, and **NetFlow / IPFIX (v5, v7, v9, IPFIX v10)** in the ICCT NMS Cacti plugin. It also details the exact mechanics of the **Count** column in the Syslog event console.

All mechanisms described herein are **100% offline-compatible** for Red Hat Enterprise Linux (RHEL) 9.4 and air-gapped production environments, requiring zero external CDN, cloud service, or internet access.

---

## Table of Contents
1. [Syslog Architecture & Implementation Flow](#1-syslog-architecture--implementation-flow)
   - [Overview & Transport Mechanics](#11-overview--transport-mechanics)
   - [Working Flow: From Packet Arrival to Alarm Escalation](#12-working-flow-from-packet-arrival-to-alarm-escalation)
   - [The "Count" Column & Deduplication Window](#13-the-count-column--deduplication-window)
   - [UI Field & Column Dictionary: Syslog](#14-ui-field--column-dictionary-syslog)
2. [NTP (Network Time Protocol) Architecture & Implementation Flow](#2-ntp-network-time-protocol-architecture--implementation-flow)
   - [Overview & Protocol Mechanics (RFC 5905)](#21-overview--protocol-mechanics-rfc-5905)
   - [Working Flow: Polling, Drift Analysis & Threshold Checks](#22-working-flow-polling-drift-analysis--threshold-checks)
   - [Clock Metric Hierarchy & Stratum Evaluation](#23-clock-metric-hierarchy--stratum-evaluation)
   - [UI Field & Column Dictionary: NTP](#24-ui-field--column-dictionary-ntp)
3. [NetFlow & IPFIX Architecture & Implementation Flow](#3-netflow--ipfix-architecture--implementation-flow)
   - [Supported Versions: v5, v7, v9, and IPFIX (v10)](#31-supported-versions-v5-v7-v9-and-ipfix-v10)
   - [Working Flow: UDP Reception, Template Caching & Record Decoding](#32-working-flow-udp-reception-template-caching--record-decoding)
   - [Aggregation Engine, Sliding Windows & Top Talkers](#33-aggregation-engine-sliding-windows--top-talkers)
   - [UI Field & Column Dictionary: NetFlow / IPFIX](#34-ui-field--column-dictionary-netflow--ipfix)
4. [FCAPS Alarms & Cross-Protocol Event Correlation](#4-fcaps-alarms--cross-protocol-event-correlation)
5. [Offline RHEL 9.4 Deployment & Service Management](#5-offline-rhel-94-deployment--service-management)

---

## 1. Syslog Architecture & Implementation Flow

### 1.1 Overview & Transport Mechanics
The ICCT NMS Syslog subsystem provides centralized log collection, message normalization, cryptographic deduplication, and automated fault-alarm generation from all managed network infrastructure (routers, switches, firewalls, and Linux/Unix servers).

- **Transports Supported**:
  - **UDP Port 514**: High-throughput, stateless reception for network switches and routers (Cisco IOS, Junos, Arista EOS, VyOS).
  - **TCP Port 514**: Reliable, connection-oriented streaming with TLS/plaintext options for servers and security appliances.
  - **Dual-Stack IP**: Full IPv4 and IPv6 socket binding.
- **Standards Implemented**:
  - **RFC 3164** (BSD Syslog Protocol): `<PRI>TIMESTAMP HOSTNAME PROGRAM[PID]: MSG`
  - **RFC 5424** (The Syslog Protocol): `<PRI>VERSION TIMESTAMP HOSTNAME APP-NAME PROCID MSGID [STRUCTURED-DATA] MSG`
  - **Systemd Journal Native Integration**: Direct ingestion from local or forwarded `systemd-journald`.

### 1.2 Working Flow: From Packet Arrival to Alarm Escalation

```
[Network Device / Host]
          │
          │ UDP / TCP port 514
          ▼
┌──────────────────────────────────────────────┐
│  syslog_worker.php (Non-blocking Listener)    │
└──────────────────────────────────────────────┘
          │
          ▼
┌──────────────────────────────────────────────┐
│  1. Wire Parser: Extract PRI, Fac, Sev,      │
│     Header, Timestamp, App/Program & Payload │
└──────────────────────────────────────────────┘
          │
          ▼
┌──────────────────────────────────────────────┐
│  2. Identity Resolver: Match Source IP with  │
│     authorized host_id in Cacti Inventory    │
└──────────────────────────────────────────────┘
          │
          ▼
┌──────────────────────────────────────────────┐
│  3. Fingerprint Generator (SHA-256):         │
│     hash(host_id | fac | sev | prog | msg)   │
└──────────────────────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────────────────────────────┐
│  4. Deduplication Check (Window = 60s):                     │
│     SELECT id FROM syslog_events WHERE                      │
│     host_id=? AND fingerprint=? AND last_seen >= (NOW - 60s)│
└─────────────────────────────────────────────────────────────┘
          │                                  │
     [Match Found]                      [No Match]
          │                                  │
          ▼                                  ▼
┌────────────────────────────────┐ ┌────────────────────────────────┐
│ Update Existing Event:         │ │ Insert New Event Row:          │
│ • repeat_count = repeat_count+1│ │ • repeat_count = 1             │
│ • last_seen = NOW()            │ │ • event_time = packet timestamp│
│ • sync_count incremented       │ │ • last_seen = NOW()            │
└────────────────────────────────┘ └────────────────────────────────┘
          │                                  │
          └─────────────────┬────────────────┘
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  5. FCAPS Alarm Rules Evaluation:                           │
│     Match facility, severity & keyword rules.               │
│     If triggered, update or create alarm in active table.   │
└─────────────────────────────────────────────────────────────┘
```

1. **Reception**: The worker daemon (`syslog_worker.php`) listens on socket `0.0.0.0:514` and `[::]:514`. Packets are read in non-blocking batches.
2. **Parsing**: The message is unpacked. The priority integer `PRI` is decomposed:
   $$\text{Facility} = \lfloor \text{PRI} / 8 \rfloor, \quad \text{Severity} = \text{PRI} \pmod 8$$
3. **Identity Resolution**: The sender's socket IP is checked against the Cacti `host` and `plugin_icct_nms_syslog_devices` tables. If the sender IP belongs to a recognized device, its internal `host_id` is assigned; otherwise, it is attributed to the authorized subnetwork gateway.
4. **Fingerprint Calculation**: A canonical fingerprint is computed:
   $$\text{Fingerprint} = \text{SHA256}(\text{host\_id} \parallel \text{facility\_code} \parallel \text{severity\_code} \parallel \text{strtolower}(\text{program}) \parallel \text{normalize}(\text{message}))$$
5. **Deduplication Sliding Window**: If an identical event was recorded within the deduplication window (default: 60 seconds), the existing row is incremented in-place rather than creating a new row.
6. **Alarm Escalation**: Active FCAPS fault rules matching the facility, severity, or trigger keywords generate or update active alarms in `plugin_icct_nms_alarms`.

---

### 1.3 The "Count" Column & Deduplication Window

#### What the "Count" Column Represents
In the Syslog event table (`#view-syslog` on `device.php?id=X&view=1#view-syslog` and the global Syslog console), the **Count** column displays `(int)$event['repeat_count']`.

- **Why all rows currently show `1`**:
  - When an incoming syslog event arrives that has a unique message, or when identical messages arrive with intervals greater than the deduplication window (e.g. 60 seconds apart), each message represents a distinct occurrence.
  - For each new distinct message, the collector executes:
    ```sql
    INSERT INTO plugin_icct_nms_syslog_events
      (host_id, event_time, last_seen, repeat_count, ...)
    VALUES
      (?, ?, NOW(), 1, ...);
    ```
    This sets `Count = 1`, and `Event Time` equals `Last Seen`.
- **When `Count` increments (> 1)**:
  - If a device experiences a recurring issue (e.g. OSPF adjacency flapping, BGP peer reset, interface link bounces, power-supply warnings, authentication failures) and sends multiple identical messages within 60 seconds, the collector executes:
    ```sql
    UPDATE plugin_icct_nms_syslog_events
    SET repeat_count = repeat_count + 1,
        last_seen = NOW(),
        received_at = NOW()
    WHERE id = ?;
    ```
  - **Results in the UI**:
    1. The **Count** column displays `2`, `3`, `5`, `42`, etc.
    2. The **Event Time** column retains the timestamp of the **first** occurrence.
    3. The **Last Seen** column reflects the timestamp of the **most recent** occurrence.
  - **Why this design is critical**:
    Without this deduplication mechanism, a network device in a flapping loop could generate 50,000 log rows per minute, exhausting database storage, causing browser slowdowns, and flooding operational consoles. The sliding window aggregates bursts into a single intelligible row with an accurate counter.

---

### 1.4 UI Field & Column Dictionary: Syslog

#### Device View Syslog Console (`#view-syslog`)
| Column / Field | Database Column | Type | Purpose & Meaning |
|---|---|---|---|
| **Event Time** | `event_time` | `DATETIME` | The original timestamp when the first event in this sequence occurred, extracted from the syslog header or assigned upon initial receipt. |
| **Severity** | `severity` / `nms_severity` | `VARCHAR(16)` | RFC Syslog severity name (Emergency, Alert, Critical, Error, Warning, Notice, Informational, Debug). Styled with standard NMS color-coded badges (Red = Critical, Orange = Warning, Blue = Info). |
| **Origin** | `event_origin` | `VARCHAR(16)` | Ingestion source channel: `syslog` (network socket), `journal` (systemd-journald), or `trap` (SNMP trap conversion). |
| **Source IP** | `source_ip` | `VARCHAR(45)` | IPv4 or IPv6 network address of the interface transmitting the packet. |
| **Facility / Program** | `facility` / `program` | `VARCHAR(64)` | The operational subsystem (e.g. `kernel`, `daemon`, `auth`, `local0`–`local7`) concatenated with the originating daemon/process name (e.g. `sshd`, `ospfd`, `systemd`, `bgpd`). |
| **Message** | `message` | `TEXT` | The normalized, human-readable text payload of the log message emitted by the device. |
| **Count** | `repeat_count` | `INT` | Total number of identical occurrences received within the deduplication window. Shows `1` for isolated events, and increments on repeated bursts. |
| **Last Seen** | `last_seen` | `DATETIME` | Timestamp when the most recent identical occurrence of this log event was captured by the collector. |

#### Syslog Settings & Filter Controls
| Setting / Control | Form Parameter | Meaning & Behavioral Impact |
|---|---|---|
| **Facility Filter** | `facility_codes[]` | Multiselect filter allowing operators to monitor specific subsystems (e.g., only `daemon` and `auth`). |
| **Severity Threshold** | `severity_codes[]` | Minimum severity level required to display or trigger alarms. |
| **Trigger Keywords** | `match_strings[]` | Case-insensitive substrings (e.g., `LINK-DOWN`, `BGP-5-ADJCHANGE`). Only matching messages are stored or escalated. |
| **Deduplication Window** | `syslog_dedupe_seconds` | Sliding window in seconds (default: 60, range: 0–3600). Setting to 0 disables deduplication; higher values compress high-frequency flapping. |

---

## 2. NTP (Network Time Protocol) Architecture & Implementation Flow

### 2.1 Overview & Protocol Mechanics (RFC 5905)
The NTP monitoring subsystem verifies clock synchronization, oscillator stability, and network latency across all infrastructure nodes. Accurate time synchronization is required for cryptographic authentication (SSH/Kerberos/TLS), multi-device syslog correlation, and audit compliance.

- **Protocol**: NTP Version 4 (RFC 5905) over **UDP Port 123**.
- **Mode**: Symmetric Active / Client-Server mode.
- **Engine**: PHP socket poller communicating with device NTP daemons (Chrony, ntpd, or network OS embedded NTP clients).

### 2.2 Working Flow: Polling, Drift Analysis & Threshold Checks

```
┌──────────────────────────────────────────────┐
│  Cacti Poller Cycle (Every 60s / 300s)       │
└──────────────────────────────────────────────┘
          │
          ▼
┌──────────────────────────────────────────────┐
│  icct_nms_ntp_poll($host)                    │
│  Send RFC 5905 Packet (UDP 123) to Peer      │
└──────────────────────────────────────────────┘
          │
          ▼
┌──────────────────────────────────────────────┐
│  Receive 48-byte NTP Timestamp Reply:        │
│  T1 (Originate), T2 (Receive),               │
│  T3 (Transmit),  T4 (Destination)            │
└──────────────────────────────────────────────┘
          │
          ▼
┌──────────────────────────────────────────────┐
│  Calculate Core Clock Variables:             │
│  • Round-trip Delay = (T4 - T1) - (T3 - T2)  │
│  • Clock Offset     = ((T2 - T1) + (T3 - T4))/2│
│  • Jitter           = Running variance of θ  │
│  • Stratum          = Extract byte from hdr  │
└──────────────────────────────────────────────┘
          │
          ▼
┌──────────────────────────────────────────────┐
│  Store in plugin_icct_nms_ntp_readings       │
└──────────────────────────────────────────────┘
          │
          ▼
┌──────────────────────────────────────────────┐
│  Evaluate FCAPS NTP Fault Rules:             │
│  • |Offset| > Max Offset Threshold (ms)      │
│  • Jitter   > Max Jitter Threshold (ms)      │
│  • Stratum  > Max Stratum (e.g. > 4 or 16)   │
│  • Server Reachability (Status = Unsynced)   │
└──────────────────────────────────────────────┘
```

---

### 2.3 Clock Metric Hierarchy & Stratum Evaluation

The mathematical calculations performed by the NTP engine adhere to RFC 5905:

1. **Round-Trip Delay ($\delta$)**:
   $$\delta = (T_4 - T_1) - (T_3 - T_2)$$
   Measures network latency between the NMS poller and the device's clock peer.
2. **Clock Offset ($\theta$)**:
   $$\theta = \frac{(T_2 - T_1) + (T_3 - T_4)}{2}$$
   Measures the time discrepancy between local clock and remote reference. A positive offset indicates the local clock is behind the reference; negative indicates it is ahead.
3. **Jitter ($j$)**:
   Statistical dispersion of successive offset measurements:
   $$j_i = j_{i-1} + \frac{|\theta_i - \theta_{i-1}| - j_{i-1}}{16}$$
4. **Stratum Levels**:
   - **Stratum 0**: High-precision atomic clocks, GPS, or CDMA receivers (unconnected to IP network).
   - **Stratum 1**: Primary network time servers directly attached to Stratum 0 devices.
   - **Stratum 2–15**: Secondary servers synchronized over network paths.
   - **Stratum 16**: Device clock is **unsynchronized** (triggers Critical alarm).

---

### 2.4 UI Field & Column Dictionary: NTP

#### Device View NTP Console (`#view-ntp`) & Global Console
| Column / Field | Data Source | Unit | Purpose & Meaning |
|---|---|---|---|
| **Server / Peer Address** | `server_address` | FQDN / IP | IP address or hostname of the reference NTP server to which the device synchronizes. |
| **Stratum** | `stratum` | Integer (1–16) | Hierarchical distance of the peer from the reference atomic clock. Stratum 1 = Master, Stratum 16 = Unsynchronized. |
| **Offset** | `offset_ms` | Milliseconds (ms) | Time discrepancy between the device clock and the reference server. Values exceeding $\pm 100\text{ ms}$ degrade transaction safety. |
| **Jitter** | `jitter_ms` | Milliseconds (ms) | Short-term variability/noise in clock frequency. High jitter indicates network congestion or oscillator instability. |
| **Delay** | `delay_ms` | Milliseconds (ms) | Total round-trip network transit delay between the device and its time server. |
| **Poll Interval** | `poll_interval_s` | Seconds (s) | Cadence between successive poll packets (typically $64\text{ s}$ to $1024\text{ s}$). |
| **Reachability / Status** | `reachability_status` | Status Badge | Operational condition: `Synchronized` (Clock locked), `Reachable` (Packets exchanged, syncing in progress), or `Unsynchronized / Stale` (Alarm active). |
| **Last Sync** | `last_sync_time` | Timestamp | Date and time when the most recent valid NTP synchronization exchange completed. |

---

## 3. NetFlow & IPFIX Architecture & Implementation Flow

### 3.1 Supported Versions: v5, v7, v9, and IPFIX (v10)

The ICCT NMS flow engine supports all major flow export formats simultaneously over **UDP Port 2055**:

| Protocol Version | RFC / Spec | Frame Format | Capabilities & Key Features |
|---|---|---|---|
| **NetFlow v5** | Cisco Standard | Fixed 48-byte records | IPv4 unicast only, 30 flows per datagram, 32-bit byte/packet counters, fixed fields (Src/Dst IP, Src/Dst Port, Protocol, TCP flags, ToS). |
| **NetFlow v7** | Cisco Catalyst | Fixed 52-byte records | Extension of v5 with router/switch fabric tags, source/destination route masks, and flags. |
| **NetFlow v9** | **RFC 3954** | Dynamic Template FlowSets | Template-driven record layouts. Dynamic Field IDs (IPv4, IPv6, MAC addresses, MPLS labels, BGP AS numbers, 64-bit byte counters). |
| **IPFIX (v10)** | **RFC 7011 / 7012** | IETF Standard Information Elements (IE) | Vendor-neutral IP Flow Information Export. Supports Enterprise Numbers (PEN), variable-length strings, IPv6 flow labels, application IDs, and unidirectional/bidirectional flows. |

---

### 3.2 Working Flow: UDP Reception, Template Caching & Record Decoding

```
[Routers / Firewalls / Switches]
               │
               │ NetFlow v5 / v7 / v9 / IPFIX v10 (UDP 2055)
               ▼
┌────────────────────────────────────────────────────────┐
│  netflow_worker.php (High-Performance UDP Listener)    │
└────────────────────────────────────────────────────────┘
               │
               ▼
┌────────────────────────────────────────────────────────┐
│  Version Header Sniffer: Read first 2 bytes (Version)  │
└────────────────────────────────────────────────────────┘
          │                                  │
    [v5 or v7]                         [v9 or IPFIX v10]
          │                                  │
          ▼                                  ▼
┌───────────────────────────┐      ┌───────────────────────────┐
│ Fixed-Offset Decoder:     │      │ FlowSet Type Inspection:  │
│ Unpack 48/52-byte records │      │ • Set ID 0/2: Templates   │
└───────────────────────────┘      │ • Set ID >= 256: Data     │
          │                        └───────────────────────────┘
          │                                  │
          │                       ┌──────────┴──────────┐
          │                       ▼                     ▼
          │             [Template FlowSet]      [Data FlowSet]
          │                       │                     │
          │                       ▼                     ▼
          │             Update Template Cache:  Lookup Template by
          │             (src_ip, engine_id,     (src_ip, engine_id,
          │              template_id)            template_id)
          │                       │                     │
          │                       └──────────┬──────────┘
          │                                  ▼
          │                        Dynamic Offset Decoding:
          │                        Unpack fields per Template
          │                                  │
          └─────────────────┬────────────────┘
                            ▼
┌────────────────────────────────────────────────────────┐
│  Normalized Flow Record:                               │
│  src_ip, dst_ip, src_port, dst_port, protocol, bytes,  │
│  packets, duration_ms, tcp_flags, ingress, egress      │
└────────────────────────────────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────┐
│  Sliding-Window Aggregator & Top Talkers Cache         │
│  • Application/Service Port Classification             │
│  • Bandwidth utilization rate calculation (bps / pps)  │
│  • Volume ranking (Top 100 Talkers, Protocols)         │
└────────────────────────────────────────────────────────┘
```

1. **Header Identification**: The listener examines the first 16 bits of each UDP datagram:
   - `0x0005` $\to$ NetFlow v5
   - `0x0007` $\to$ NetFlow v7
   - `0x0009` $\to$ NetFlow v9
   - `0x000A` $\to$ IPFIX (v10)
2. **Template Management (v9 & IPFIX)**:
   - Flow exports send **Template FlowSets** periodically (Set ID `0` in v9; Set ID `2` in IPFIX).
   - The engine stores template definitions in memory and the database:
     $$\text{TemplateKey} = \langle \text{ExporterIP}, \text{SourceID}, \text{TemplateID} \rangle$$
   - When a **Data FlowSet** (Set ID $\ge 256$) arrives, the record fields are decoded based on the stored template schema.
3. **Record Normalization**: Every record is mapped into a uniform internal format, resolving IP addresses, interface indices, port numbers, L4 protocols, and 64-bit byte counters.
4. **Bandwidth & Rate Calculation**:
   $$\text{Bitrate (bps)} = \frac{\text{Octets} \times 8}{\max(1, \text{FlowDuration (s)})}, \quad \text{PacketRate (pps)} = \frac{\text{Packets}}{\max(1, \text{FlowDuration (s)})}$$

---

### 3.3 Aggregation Engine, Sliding Windows & Top Talkers

To avoid database bloat while maintaining sub-second query performance:
1. **Sliding Time Windows**: Flows are aggregated into 5-minute, 30-minute, and 24-hour windows.
2. **Top Talkers Aggregation**: Grouped by:
   - **By Source IP**: Identifies nodes generating the heaviest egress traffic.
   - **By Destination IP**: Identifies heavily loaded internal servers or destinations.
   - **By Application / Port**: Identifies bandwidth consumption per service (HTTPS, SSH, Database, VoIP).
   - **By Flow Conversation (`Source` $\leftrightarrow$ `Destination`)**: End-to-end communication matrix.

---

### 3.4 UI Field & Column Dictionary: NetFlow / IPFIX

#### Device View NetFlow Console (`#view-netflow`) & Global Top Talkers
| Column / Field | Data Field | Unit | Purpose & Meaning |
|---|---|---|---|
| **Source IP** | `src_ip` | IPv4 / IPv6 | Originating client, server, or router interface IP address initiating the flow. |
| **Source Port** | `src_port` | Port Number (1–65535) | Transport layer source port (e.g. ephemeral client port `54128` or service port). |
| **Destination IP** | `dst_ip` | IPv4 / IPv6 | Target host or service IP address receiving the traffic. |
| **Destination Port** | `dst_port` | Port Number (1–65535) | Target service port (e.g., `443` for HTTPS, `22` for SSH, `53` for DNS). |
| **Protocol** | `protocol` | Protocol Name | L4 Transport Protocol (TCP, UDP, ICMP, GRE, ESP, OSPF). |
| **Application** | `application` | Service Name | Resolved application signature based on standard IANA port registries and enterprise presets (e.g. `HTTPS`, `DNS`, `SSH`, `BGP`). |
| **Packets** | `packets` | Packet Count | Total number of packets transferred during the flow duration. |
| **Bytes (Octets)** | `octets` | Bytes / MB / GB | Total data volume transferred in the flow, automatically scaled (B, KB, MB, GB, TB). |
| **Bitrate** | `bitrate_bps` | bps / Mbps / Gbps | Calculated bandwidth throughput rate during the active flow lifetime. |
| **Flow Duration** | `duration_ms` | Seconds / ms | Active lifetime of the flow: difference between `Last Switched` and `First Switched`. |
| **Ingress Interface** | `input_snmp` | ifIndex / Name | Incoming physical or logical router/switch port (matches SNMP `ifIndex` and `ifDescr`). |
| **Egress Interface** | `output_snmp` | ifIndex / Name | Outgoing physical or logical router/switch port where the traffic was forwarded. |
| **TCP Flags** | `tcp_flags` | Hex / Flag String | Cumulative TCP flags observed during the flow (e.g. `SYN`, `ACK`, `FIN`, `RST`). Useful for identifying port scans or half-open attack attempts. |
| **ToS / DSCP** | `tos` | Hex / DSCP Class | Type of Service / Differentiated Services Code Point marking for QoS traffic classification. |
| **Exporter** | `exporter_ip` | IPv4 / IPv6 | The management IP of the router or switch that collected and exported the NetFlow packet. |

---

## 4. FCAPS Alarms & Cross-Protocol Event Correlation

The ICCT NMS FCAPS (Fault, Configuration, Accounting, Performance, Security) engine correlates events across all three protocols:

1. **Syslog Fault Rules**:
   - Matches incoming syslog messages against defined alert criteria.
   - Supports rule-based severity overrides (e.g. escalate an informational message about link down to `Critical`).
   - Deduplicated messages increment the active alarm's occurrence count rather than spamming duplicate alarms.
2. **NTP Fault Rules**:
   - Monitored continuously by the Cacti poller bottom hook (`icct_nms_poller_bottom`).
   - If offset exceeds threshold (e.g. $> 100\text{ ms}$), an active `Major` alarm is opened. If server becomes unreachable (Stratum 16), a `Critical` alarm is raised.
   - Clears automatically when clock synchronization returns to within tolerances.
3. **NetFlow Fault Rules & Thresholds**:
   - Bandwidth spike detection: Triggers warning when flow volume on an interface exceeds a configured limit.
   - Unusual talker alert: Flags unapproved external IPs or unexpected traffic volumes.

---

## 5. Offline RHEL 9.4 Deployment & Service Management

### Systemd Services for Background Receivers
On offline RHEL 9.4 environments, collectors run as dedicated systemd services managed via `systemctl`:

```bash
# Verify collector services status
systemctl status icct-nms-syslog.service
systemctl status icct-nms-netflow.service

# Restart services after configuration updates
systemctl restart icct-nms-syslog.service
systemctl restart icct-nms-netflow.service

# Enable automatic boot startup
systemctl enable icct-nms-syslog.service icct-nms-netflow.service
```

### Network Ports Reference
| Protocol | Port | Transport | Purpose |
|---|---|---|---|
| **Syslog** | `514` | UDP & TCP | Syslog message collector |
| **NTP** | `123` | UDP | NTP time synchronization polling |
| **NetFlow / IPFIX** | `2055` | UDP | NetFlow v5, v7, v9, and IPFIX datagram collector |
| **SNMP Traps** | `162` | UDP | SNMP Trap listener |
| **Web Console** | `80` / `443` | TCP | Cacti web server & ICCT NMS GUI |

