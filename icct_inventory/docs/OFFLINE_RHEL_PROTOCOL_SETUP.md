# ICCT LNMS — Offline RHEL 9.4 Protocol Integration and Real Device Tests

Target: RHEL 9.4 office LNMS, no Internet access.

Initial five-protocol baseline:

1. SNMP v2c/v3 (plus traps)
2. Syslog
3. SSH
4. HTTP/HTTPS
5. NTP

Optional topology validation: LLDP and Cisco CDP through SNMP MIB walks.

The client device catalogue contains network devices that declare SNMP, Syslog, TACACS+, NTP, CDP, LLDP, SSH and Serial; VSAT/environmental equipment also declares combinations of SNMP, WebUI, RS-232/485, MODBUS and proprietary protocols. Start with the five protocols below because they can be integrated using RHEL/Cacti-native components with no online package source.

---

## A. Offline package preflight

Run first:

```bash
cd /usr/share/cacti/plugins/icct_inventory
sudo ./tools/protocol_preflight.sh
```

Check installed packages:

```bash
rpm -q net-snmp net-snmp-utils rsyslog openssh-clients curl chrony
```

Required package mapping:

- SNMP: `net-snmp`, `net-snmp-utils`
- Syslog: `rsyslog`
- SSH client: `openssh-clients`
- HTTP/HTTPS: `curl`
- NTP: `chrony`

### If a package is missing: use the RHEL 9.4 DVD/ISO as an offline repository

Mount the exact RHEL 9.4 x86_64 DVD ISO used/approved in your environment:

```bash
sudo mkdir -p /mnt/rhel94
sudo mount -o loop /path/to/rhel-9.4-x86_64-dvd.iso /mnt/rhel94
```

Create `/etc/yum.repos.d/rhel94-offline.repo`:

```ini
[rhel94-baseos]
name=RHEL 9.4 BaseOS Offline
baseurl=file:///mnt/rhel94/BaseOS
enabled=1
gpgcheck=0

[rhel94-appstream]
name=RHEL 9.4 AppStream Offline
baseurl=file:///mnt/rhel94/AppStream
enabled=1
gpgcheck=0
```

Then:

```bash
sudo dnf clean all
sudo dnf repolist
sudo dnf install -y net-snmp net-snmp-utils rsyslog openssh-clients curl chrony
```

Do not point the offline production server at Internet repositories.

---

# 1. SNMP integration

## 1.1 Verify tools

```bash
snmpget --version
snmpwalk --version
php -m | grep -i '^snmp$'
```

## 1.2 Real test against a device using SNMP v2c

Replace `<DEVICE_IP>` and `<COMMUNITY>`:

```bash
snmpget -On -v2c -c '<COMMUNITY>' -t 2 -r 1 <DEVICE_IP> .1.3.6.1.2.1.1.1.0
snmpget -On -v2c -c '<COMMUNITY>' -t 2 -r 1 <DEVICE_IP> .1.3.6.1.2.1.1.3.0
snmpwalk -On -v2c -c '<COMMUNITY>' -t 2 -r 1 <DEVICE_IP> .1.3.6.1.2.1.2.2.1.8
```

Expected:

- `sysDescr.0` returns device/system description.
- `sysUpTime.0` returns uptime.
- `ifOperStatus` returns indexed interface states; value `1` means up.

## 1.3 SNMP v3 real test

Example authPriv test:

```bash
snmpget -On -v3 -l authPriv \
  -u '<SNMP_USER>' \
  -a SHA -A '<AUTH_PASSWORD>' \
  -x AES -X '<PRIV_PASSWORD>' \
  -t 2 -r 1 <DEVICE_IP> \
  .1.3.6.1.2.1.1.1.0
```

Use the algorithms actually configured on the device.

## 1.4 Add the device to Cacti

Cacti UI:

```text
Console → Management → Devices → Add
```

Configure hostname/IP, SNMP version and credentials. Use a matching device template where available.

Cacti then owns polling configuration. `icct_inventory` only maps the host to the client Device Type/Segment and defines approved OID policy.

## 1.5 SNMP trap reception test

Enable trap receiver only if required on this LNMS:

```bash
sudo firewall-cmd --permanent --add-port=162/udp
sudo firewall-cmd --reload
```

Configure `/etc/snmp/snmptrapd.conf` according to your approved SNMP security policy. For an isolated v2c functional test only:

```text
authCommunity log <TRAP_COMMUNITY>
```

Then:

```bash
sudo systemctl enable --now snmptrapd
sudo journalctl -u snmptrapd -f
```

From an authorized test host/device:

```bash
snmptrap -v2c -c '<TRAP_COMMUNITY>' <LNMS_IP> '' \
  .1.3.6.1.6.3.1.1.5.3 \
  .1.3.6.1.2.1.1.3.0 t 12345
```

For production prefer SNMPv3 notifications when supported.

---

# 2. Syslog integration

The LNMS design expects NE security/device logs to be sent to the LNMS and processed for alerts.

## 2.1 Verify rsyslog

```bash
rpm -q rsyslog
systemctl status rsyslog --no-pager
```

## 2.2 Install the supplied dedicated receiver config

```bash
sudo cp /usr/share/cacti/plugins/icct_inventory/tools/30-icct-lnms-rsyslog.conf \
  /etc/rsyslog.d/30-icct-lnms.conf
```

Validate syntax:

```bash
sudo rsyslogd -N1
```

Create/open firewall ports:

```bash
sudo firewall-cmd --permanent --add-port=514/udp
sudo firewall-cmd --permanent --add-port=514/tcp
sudo firewall-cmd --reload
```

Restart:

```bash
sudo systemctl restart rsyslog
sudo systemctl status rsyslog --no-pager
sudo ss -lunpt | grep ':514'
```

## 2.3 Test locally

```bash
logger --udp -n 127.0.0.1 -P 514 -t ICCT_TEST 'local syslog integration test'
sudo tail -n 20 /var/log/icct-lnms/remote.log
```

## 2.4 Test from a real device

On the network device configure the LNMS address as the remote syslog server on port 514. Generate a safe test event, such as an administrative login/logout or approved test message.

On LNMS:

```bash
sudo tail -f /var/log/icct-lnms/remote.log
```

Confirm source IP, timestamp, facility/severity and message are present.

---

# 3. SSH integration

SSH is required for network-device CLI/cross-management and configuration backup workflows.

## 3.1 Verify client

```bash
rpm -q openssh-clients
ssh -V
```

## 3.2 Network reachability

```bash
timeout 5 bash -c '</dev/tcp/<DEVICE_IP>/22' && echo 'TCP/22 reachable' || echo 'TCP/22 failed'
```

## 3.3 Real login test

```bash
ssh -o ConnectTimeout=5 <USER>@<DEVICE_IP>
```

Accept the fingerprint only after verifying it using your operational process.

For Cisco/network devices, after login use harmless commands first, for example:

```text
show version
show interfaces status
show ip interface brief
```

Exact commands are vendor/model dependent.

## 3.4 Configuration-backup proof test

If the device supports one-shot SSH commands and policy permits it:

```bash
sudo mkdir -p /var/lib/icct-lnms/config-backup
sudo chmod 750 /var/lib/icct-lnms/config-backup
ssh <USER>@<DEVICE_IP> 'show running-config' \
  > /var/lib/icct-lnms/config-backup/device-running-config.txt
```

Do not automate passwords in shell scripts. Use approved key/AAA/device credential handling.

---

# 4. HTTP / HTTPS integration

Used for WebUI cross-launch and API/device management where supported.

## 4.1 Verify curl

```bash
curl --version
```

## 4.2 Real HTTP test

```bash
curl --connect-timeout 5 --max-time 10 -I http://<DEVICE_IP>/
```

## 4.3 Real HTTPS test

Preferred production validation with trusted CA:

```bash
curl --connect-timeout 5 --max-time 10 \
  --cacert /path/to/approved-ca.pem \
  -I https://<DEVICE_IP>/
```

For a one-time diagnostic only, if the device uses a self-signed certificate:

```bash
curl -k --connect-timeout 5 --max-time 10 -I https://<DEVICE_IP>/
```

Do not make `-k` the normal production setting.

## 4.4 Plugin mapping

In ICCT Inventory → Actual Devices → Inventory, store the approved device WebUI URL in `Cross-launch URL`.

The Actual Devices list then exposes a cross-launch link without changing Cacti core tables.

---

# 5. NTP integration

The client list includes network devices/workstations using NTP and an NTP clock display. Use NTP for synchronized event/fault timestamps.

## 5.1 Verify chrony

```bash
rpm -q chrony
chronyc -v
systemctl status chronyd --no-pager
```

## 5.2 Query a candidate NTP server without setting the LNMS clock

```bash
sudo chronyd -Q -t 3 'server <NTP_DEVICE_IP> iburst'
```

A successful response reports the measured clock offset.

## 5.3 Configure the approved source on LNMS

Back up configuration first:

```bash
sudo cp -a /etc/chrony.conf /etc/chrony.conf.before-icct
```

Add the approved source:

```text
server <NTP_DEVICE_IP> iburst
```

Then:

```bash
sudo systemctl restart chronyd
chronyc -n sources -v
chronyc tracking
```

Verify the selected source, stratum, offset and reachability before relying on it.

---

# 6. Optional LLDP and CDP topology validation

For the Cisco core/edge/node devices, the client list declares CDP and LLDP. The LNMS can learn neighbor data through their SNMP MIBs without installing an additional LLDP daemon.

## LLDP remote table

```bash
snmpwalk -On -v2c -c '<COMMUNITY>' <DEVICE_IP> \
  .1.0.8802.1.1.2.1.4.1.1
```

## Cisco CDP cache table

```bash
snmpwalk -On -v2c -c '<COMMUNITY>' <DEVICE_IP> \
  .1.3.6.1.4.1.9.9.23.1.2.1.1
```

If the walk is empty, confirm LLDP/CDP is enabled on the device and that the relevant MIB objects are exposed through SNMP.

---

# 7. Real-device test order recommended for the current client list

## Cisco Core Switch / Edge Router / Node Switch

Test in this order:

```text
ICMP reachability
→ SNMP sysDescr/sysUpTime
→ SNMP interface status
→ Syslog reception
→ SSH login
→ NTP query/source
→ LLDP/CDP SNMP walk
→ TACACS+ only after the approved TACACS server is available
```

## VSAT BUC/LNB/Modem

Client capability includes SNMP + WebUI and, for BUC/LNB, serial RS-232/485. Start with:

```text
SNMP → HTTPS/WebUI → approved vendor OIDs → serial later
```

## NTP Clock Display / Hygro-Thermometer / CO2 Unit

Start with:

```text
HTTP/HTTPS or SSH/Telnet reachability
→ SNMP where declared
→ NTP only for devices that actually provide NTP service
```

## Antenna positioning unit

The client list contains a separate serial MODBUS capability. Do not invent the register map. Before MODBUS integration obtain:

```text
baud rate
parity
stop bits
slave/unit ID
function codes
approved register map
engineering units/scaling
```

Then integrate it through the serial device server or a dedicated adapter. This is intentionally Phase 2, not one of the first five offline protocols.

---

# 8. Cacti / plugin integration boundary

Use this ownership model:

```text
Client Device Type catalogue
        ↓
Actual Cacti host (IP/SNMP/status)
        ↓
Segment mapping
        ↓
FCAPS / OID catalogue
        ↓
Cacti Data Query / Data Source / Graph / Threshold
```

Do not duplicate Cacti's `host` SNMP/IP/status fields in the plugin.

Use numeric OIDs for initial tests. Install vendor MIB files offline only after the device vendor provides the correct files and licensing/approval permits them.
