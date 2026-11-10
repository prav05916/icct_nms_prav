# ICCT NMS — RHEL 9.4 System Setup & Diagnostic Report

This document addresses the exact system messages, errors, and daemon states observed in your terminal session on the offline **RHEL 9.4** server (`172.195.121.193`).

---

## 1. Executive Summary of Terminal Findings

| Subsystem | Observed Terminal State | Root Cause | Status & Required Action |
|---|---|---|---|
| **Syslog** | `Active: active (running)`, Spool capture: `PASSED`, UDP/TCP 514 listening | Fully configured and operational | **Operational**. No further action needed. |
| **DNF / Packages** | `Couldn't open file /mnt/rhel/BaseOS/repodata/repomd.xml` | ISO / DVD media is not mounted at `/mnt/rhel` | Mount the RHEL 9.4 ISO to `/mnt/rhel` (instructions below). |
| **NTP (`chronyd`)** | `Stratum: 0`, `Leap status: Not synchronised`, `Reference ID: 00000000` | Machine is isolated with no internet access to `pool.ntp.org` | Configure `local stratum 10` in `/etc/chrony.conf` so chronyd acts as local master clock. |
| **NTP Worker** | `{"polled":0,"healthy":0,"failed":0}` | Zero devices were configured/enabled in `plugin_icct_nms_ntp_devices` | Add NTP protocol to devices in Cacti UI; worker will poll immediately. |
| **NetFlow (`nfdump`)** | `Missing nfcapd. Install the approved offline nfdump package` | `nfdump` not installed, repository unmounted | **Resolved**: ICCT NMS now includes a built-in pure PHP UDP collector daemon (`netflow_worker.php`). No `nfdump` needed! |

---

## 2. Step-by-Step Fixes for RHEL 9.4

### A. Fix Offline DNF Repository (`/mnt/rhel/BaseOS`)
Your terminal showed:
```
Errors during downloading metadata for repository 'BASEOS':
  - Curl error (37): Couldn't read a file:// file for file:///mnt/rhel/BaseOS/repodata/repomd.xml
```

To resolve this on your offline machine:
1. Locate your RHEL 9.4 DVD ISO (e.g., in `/root` or `/home/bstc`):
   ```bash
   sudo mkdir -p /mnt/rhel
   # If using an ISO file:
   sudo mount -o loop /path/to/rhel-9.4-x86_64-dvd.iso /mnt/rhel
   # OR if using a virtual CD/DVD drive:
   sudo mount /dev/sr0 /mnt/rhel
   ```
2. Verify the repository is accessible:
   ```bash
   ls -la /mnt/rhel/BaseOS/repodata/repomd.xml
   sudo dnf clean all
   sudo dnf repolist
   ```

---

### B. Configure `chronyd` as Local Master Clock (No Internet)
Your terminal showed:
```
Leap status     : Not synchronised
Stratum         : 0
Reference ID    : 00000000 ()
```
Because the server is in an offline network with no route to internet NTP pools, `chronyd` marks itself unsynchronized.

To make this server the authoritative master clock for your network:
1. Edit `/etc/chrony.conf`:
   ```bash
   sudo nano /etc/chrony.conf
   ```
2. Add or uncomment the following lines:
   ```ini
   # Serve time to local network devices even if not synchronized to external internet source
   local stratum 10

   # Allow NTP queries from localhost and your subnet (172.195.120.0/21 based on your enp0s25 IP)
   allow 127.0.0.1
   allow ::1
   allow 172.195.120.0/21
   ```
3. Restart `chronyd`:
   ```bash
   sudo systemctl restart chronyd
   ```
4. Verify synchronization status:
   ```bash
   chronyc tracking
   ```
   *Expected Output*: `Stratum : 10`, `Leap status : Normal`.

---

### C. Install and Enable ICCT NTP Worker
The NTP worker polls devices via standard UDP NTP queries (RFC 5905):
```bash
sudo chmod +x /usr/share/cacti/plugins/icct_nms/protocols/ntp/rhel/*.sh
sudo /usr/share/cacti/plugins/icct_nms/protocols/ntp/rhel/install_ntp_rhel.sh /usr/share/cacti
```

To test polling immediately:
```bash
sudo -u apache php /usr/share/cacti/plugins/icct_nms/protocols/ntp/cli/ntp_worker.php --limit=500
```
*(Once you enable NTP on a device in Cacti, this will output e.g. `{"polled":1,"healthy":1,"failed":0}`)*.

---

### D. Install and Enable ICCT NetFlow Collector (Built-in Daemon)
Previously, the install script failed with:
`Missing nfcapd. Install the approved offline nfdump package before enabling NetFlow/IPFIX.`

**This is now fixed**: ICCT NMS now features a native pure-PHP UDP NetFlow collector (`protocols/netflow/cli/netflow_worker.php`) that runs as a systemd daemon on UDP port 2055 with zero dependencies on `nfdump` or `nfcapd`.

1. Run the installer:
   ```bash
   sudo chmod +x /usr/share/cacti/plugins/icct_nms/protocols/netflow/rhel/*.sh
   sudo OPEN_FIREWALL=1 /usr/share/cacti/plugins/icct_nms/protocols/netflow/rhel/install_netflow_rhel.sh /usr/share/cacti
   ```
2. Verify the collector is listening:
   ```bash
   sudo systemctl status icct-nms-netflow-collector.service
   sudo ss -lunp | grep ':2055'
   ```
   *Output will show*:
   ```
   UNCONN 0 0 0.0.0.0:2055 0.0.0.0:* users:(("php",pid=...,fd=...))
   ```

---

### E. Firewall Ports (Firewalld)
If `firewalld` is active, ensure UDP ports 123, 2055, and 9995 are open:
```bash
sudo firewall-cmd --permanent --add-port=123/udp
sudo firewall-cmd --permanent --add-port=2055/udp
sudo firewall-cmd --permanent --add-port=9995/udp
sudo firewall-cmd --reload
sudo firewall-cmd --list-ports
```

---

## 3. End-to-End Verification in the Web UI

1. **Add Protocols to Device**:
   - Open: `http://localhost/cacti/plugins/icct_nms/inventory/controllers/device.php?id=1#protocol`
   - Under **NTP**: Set NTP Server Address to `127.0.0.1` (or your switch/router IP) and click **Save**.
   - Under **NetFlow**: Set Exporter IP to `127.0.0.1` (or your switch/router IP), Collector Port to `2055`, and click **Save**.
2. **Automatic Real Data Reading**:
   - Navigate to **NTP Monitoring**:
     `http://localhost/cacti/plugins/icct_nms/monitoring/controllers/ntp.php`
     The controller automatically polls all enabled devices on load and displays live Stratum, live Offset (ms), Delay, Jitter, and Status.
   - Navigate to **NetFlow Monitoring**:
     `http://localhost/cacti/plugins/icct_nms/monitoring/controllers/netflow.php`
     The controller samples live interface traffic and collected NetFlow flows, displaying real Active Flows, Throughput, and Top Talkers.

