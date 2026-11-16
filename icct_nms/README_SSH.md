# ICCT NMS — SSH Subsystem: Complete Architecture, Offline Installation & User Guide

Complete operational guide for the **SSH Subsystem** in ICCT NMS, designed specifically for an air-gapped **RHEL 9.4** offline environment with **zero internet access**.

---

## Table of Contents

1. [Architecture & Implementation Logic](#1-architecture--implementation-logic)
2. [Offline Prerequisites & Packages (RHEL 9.4)](#2-offline-prerequisites--packages-rhel-94)
3. [Step-by-Step Installation on RHEL 9.4](#3-step-by-step-installation-on-rhel-94)
4. [Step-by-Step: Adding an SSH Device in Cacti](#4-step-by-step-adding-an-ssh-device-in-cacti)
5. [Step-by-Step: Connecting to the Device](#5-step-by-step-connecting-to-the-device)
6. [Security Model & Session Lifecycle](#6-security-model--session-lifecycle)
7. [Diagnostics & Troubleshooting](#7-diagnostics--troubleshooting)

---

## 1. Architecture & Implementation Logic

The ICCT NMS SSH subsystem provides a secure, in-browser pseudo-terminal (PTY) console without exposing device credentials or private keys to the client browser.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           WEB BROWSER (HTTPS CLIENT)                        │
│                                                                             │
│  • Guacamole JavaScript Display Client (assets/guacamole-1.6.0.js)           │
│  • Terminal UI & Canvas (js/terminal.js + css/terminal.css)                 │
│  • HTTPS Session Cookie + Single-Use Console Ticket                         │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │ HTTPS / POST / WebSocket
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                       APACHE HTTPD (CACTI WEB SERVER)                       │
│                                                                             │
│  • controllers/ssh_terminal.php  (Initializes secure terminal view)          │
│  • controllers/ssh_console.php   (API endpoint for open/read/write/close)   │
│  • Validates Cacti user permissions, CSRF token, and HTTPS origin            │
│  • Refreshes 30-second rolling authorization lease                           │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │ Unix Domain Socket (/run/icct-nms-ssh/control.sock)
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                       ICCT NMS SSH BROKER DAEMON                            │
│                       (icct-nms-ssh.service)                                │
│                                                                             │
│  • PHP CLI Event-Driven Daemon (gateway.php / worker.php)                    │
│  • Decrypts device credentials using master.key (AES-256-GCM)               │
│  • Validates pinned host-key fingerprint against device database            │
│  • Connects to local strict-auth Guacamole daemon                           │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │ Loopback TCP (127.0.0.1:4823)
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                     STRICT-AUTH GUACAMOLE DAEMON (guacd)                    │
│                     (icct-nms-guacd.service)                                │
│                                                                             │
│  • Compiled from bundled guacamole-server-1.6.0.tar.gz + strict-auth.patch  │
│  • Isolated system daemon listening ONLY on 127.0.0.1:4823                  │
│  • Translates SSH terminal protocol into Guacamole rendering instructions   │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │ SSHv2 Protocol (Port 22)
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         TARGET NETWORK DEVICE                               │
│                                                                             │
│  • Router, Switch, Firewall, or Linux/Unix Host                             │
│  • Authenticated via Password or SSH Public Key                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Key Implementation Principles:
1. **Zero Credential Exposure**: Passwords and private keys never leave the server; they are decrypted in memory solely by the root-owned broker daemon.
2. **Pinned Host-Key Enforcement**: Terminal access requires explicit approval and pinning of the target device's SSH host key fingerprint (`plugin_icct_nms_ssh_devices.host_key`) to eliminate Man-in-the-Middle (MitM) attacks.
3. **Session Binding**: A console ticket is strictly bound to the authenticated user ID and browser session ID. If browser cookies or IP change, access is immediately revoked.
4. **30-Second Bounded Leases**: Terminal sessions must refresh their authorization lease every 30 seconds. Inactive sessions terminate automatically.
5. **Auditing**: All interactive sessions, parameter queries, and disconnect events are persistently audited in `plugin_icct_nms_meta`.

---

## 2. Offline Prerequisites & Packages (RHEL 9.4)

In an air-gapped RHEL 9.4 machine with **no internet access**, all required files are already included in the plugin archive or available on the local RHEL 9.4 DVD/ISO.

### 2.1 Files Bundled Inside `plugins/icct_nms`
The following files are already included inside the plugin:
* **Guacamole Source Tarball**: `installation/native-source/guacamole-server-1.6.0.tar.gz` (11.2 MB)
* **Strict-Auth Patch**: `protocols/ssh/guacamole/strict-auth.patch`
* **PHP Vendor Libraries**: `protocols/ssh/vendor/` (ReactPHP, Ratchet Pawl, EventLoop, Sockets, Streams)
* **Web Terminal Assets**: `protocols/ssh/assets/guacamole-1.6.0.js`, `protocols/ssh/js/terminal.js`, `protocols/ssh/css/terminal.css`
* **SELinux Policy**: `protocols/ssh/icct_nms_ssh.te`
* **Service Definitions**: `protocols/ssh/icct-nms-ssh.service.in`, `protocols/ssh/guacamole/icct-nms-guacd.service.in`
* **Automation Scripts**: `protocols/ssh/rhel/install_ssh_rhel.sh`, `protocols/ssh/rhel/diagnose_ssh_rhel.sh`

### 2.2 Standard RHEL 9.4 RPM Packages
Mount the official RHEL 9.4 DVD or ISO to access `BaseOS` and `AppStream` repositories offline:

```bash
# Mount the DVD / ISO
sudo mkdir -p /mnt/rhel
sudo mount -o loop /path/to/rhel-baseos-9.4-x86_64-dvd.iso /mnt/rhel

# Install build tools, libraries, and PHP extensions
sudo dnf --disablerepo=* \
  --enablerepo=media-baseos \
  --enablerepo=media-appstream \
  install -y \
  gcc make patch cairo-devel libpng-devel libjpeg-turbo-devel \
  pango-devel libssh2-devel libuuid-devel openssl-devel \
  selinux-policy-devel policycoreutils-python-utils \
  php-process php-sockets
```

---

## 3. Step-by-Step Installation on RHEL 9.4

### Option A: 1-Command Automated Script (Recommended)

Run the included offline installation script as root:

```bash
cd /var/www/html/cacti/plugins/icct_nms

# Run automated installer (auto-detects Cacti and Web user)
sudo bash protocols/ssh/rhel/install_ssh_rhel.sh
```

If your Cacti is accessed under a specific domain or IP (e.g., `https://192.168.1.100`), specify the origin:
```bash
sudo SSH_ORIGIN="https://192.168.1.100" bash protocols/ssh/rhel/install_ssh_rhel.sh
```

---

### Option B: Step-by-Step Manual Installation

If you prefer executing each step manually:

#### Step 1: Compile the Bundled Strict-Auth Guacamole Daemon
```bash
cd /var/www/html/cacti/plugins/icct_nms

# Compile native binary as the web user (apache)
su -s /bin/bash apache -c "python3 installation/cli/native.py build --component guacamole-strict --bundle /tmp/guac-bundle"

# Install compiled binary into /opt/icct-nms/native/guacamole-1.6.0
sudo python3 installation/cli/native.py install --bundle /tmp/guac-bundle
```

#### Step 2: Configure Systemd Services, SELinux, and Master Key
```bash
sudo python3 protocols/ssh/configure.py \
  --cacti-path /var/www/html/cacti \
  --origin https://localhost \
  --web-user apache \
  --web-group apache \
  --poller-id 1 \
  --native-bundle /tmp/guac-bundle
```

#### Step 3: Enable and Start Services
```bash
sudo systemctl daemon-reload
sudo systemctl enable --now icct-nms-guacd.service
sudo systemctl enable --now icct-nms-ssh.service
```

#### Step 4: Verify Service Health
```bash
# Run diagnostics script
sudo bash protocols/ssh/rhel/diagnose_ssh_rhel.sh
```
Expected output:
* `guacd binary: /opt/icct-nms/native/guacamole-1.6.0/sbin/guacd [OK]`
* `icct-nms-guacd.service: active (running)`
* `icct-nms-ssh.service: active (running)`
* `Control socket /run/icct-nms-ssh/control.sock [ACTIVE]`

---

## 4. Step-by-Step: Adding an SSH Device in Cacti

Follow these steps to add and configure an SSH device:

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Open Cacti Web Interface                                 │
│    URL: https://<rhel-server-ip>/cacti/                     │
│    Log in as administrator                                  │
├─────────────────────────────────────────────────────────────┤
│ 2. Create or Open Device                                    │
│    Navigate to: Management → Devices → Add                  │
│    OR use: ICCT NMS Device Wizard                           │
│    • Description: Core Switch 01                            │
│    • Hostname / IP: 192.168.1.1 (or local device IP)        │
│    • Click "Create"                                         │
├─────────────────────────────────────────────────────────────┤
│ 3. Configure SSH Protocol Settings                          │
│    In Device Edit View, navigate to "Protocol Config" tab   │
│    OR Device Wizard Step 2/7 ("Protocol Settings"):         │
│    • Enable Protocol: [✓] SSH                               │
│    • Port: 22                                               │
│    • Username: admin                                        │
│    • Authentication Method: Password OR Public Key          │
│    • Password: Enter device password                        │
│    • Timeout: 10 seconds                                    │
│    • Click "Save Protocol Configuration"                    │
├─────────────────────────────────────────────────────────────┤
│ 4. Host Key Verification (Crucial Security Requirement)     │
│    • Click the button: "Test saved credential"              │
│    • The broker connects to port 22 and retrieves the       │
│      device's public host key.                              │
│    • A confirmation dialog displays the fingerprint:        │
│      SHA256:4a8b... (ED25519)                               │
│    • Click "Approve Host Key"                               │
│    • The key is pinned to the database                      │
└─────────────────────────────────────────────────────────────┘
```

> [!IMPORTANT]
> A terminal cannot be opened until the target device's host key has been verified and approved. This prevents unauthorized servers from intercepting sessions.

---

## 5. Step-by-Step: Connecting to the Device

Once the host key is approved:

1. **Open the Terminal**:
   * On the device overview page, click the green button: **Open SSH Terminal (HTTPS)**.
   * Or navigate directly to:
     `https://<server>/cacti/plugins/icct_nms/protocols/ssh/controllers/ssh_terminal.php?host_id=<DEVICE_ID>`
2. **Terminal Interaction**:
   * A full VT100/ANSI canvas terminal initializes.
   * Type commands directly (e.g., `show ip interface brief`, `uname -a`, `top`).
   * Window resize events automatically update terminal columns and rows (`stty cols X rows Y`).
   * Copy/Paste is supported via native browser clipboard events.
3. **Audit Tracking**:
   * To query or audit a setting with an immutable log entry, enter the setting name in the "Audit Setting" field and click "Record Audit".
4. **Clean Disconnect**:
   * Click **Disconnect** or close the browser tab.
   * The authorization ticket is instantly destroyed and the PTY process is killed cleanly.

---

## 6. Security Model & Session Lifecycle

| Security Layer | Implementation Detail |
|---|---|
| **Transport** | Strictly HTTPS-only. Rejected on non-HTTPS origins. |
| **Origin Verification** | `HTTP_ORIGIN` header must match configured `origin`. |
| **Authentication** | User must have Cacti realm auth for `protocol.php` plus device access permissions. |
| **Single-Use Ticket** | Random 256-bit connection token consumed upon connection. |
| **Rolling Lease** | 30-second lease renewed every poll. Inactivity triggers automatic expiration. |
| **Credential Storage** | Encrypted with AES-256-GCM using `master.key` (0600 root-owned). |
| **SELinux** | Scoped `icct_backend_ssh.pp` policy grants access only to the Unix control socket. |
| **File Permissions** | `ssh.config.local.json` is protected against web-account writes; Apache denies HTTP access. |

---

## 7. Diagnostics & Troubleshooting

Run the diagnostics script at any time to verify system health:
```bash
sudo bash /var/www/html/cacti/plugins/icct_nms/protocols/ssh/rhel/diagnose_ssh_rhel.sh
```

### Common Issues & Resolutions:

1. **`SSH backend unavailable. Check the nms-ssh service.`**:
   * **Cause**: `icct-nms-ssh.service` is not running or socket is missing.
   * **Fix**:
     ```bash
     sudo systemctl restart icct-nms-ssh.service
     sudo systemctl status icct-nms-ssh.service
     ```

2. **`SSH credentials and consoles require HTTPS.`**:
   * **Cause**: Page accessed over plain `http://` instead of `https://`.
   * **Fix**: Access Cacti via `https://<ip-or-domain>/cacti/`. If using a self-signed certificate, accept the browser certificate warning.

3. **`SSH request origin rejected.`**:
   * **Cause**: Browser URL origin (e.g. `https://192.168.1.50`) does not match the configured origin in `ssh.config.local.json`.
   * **Fix**: Re-run the installer with the exact URL:
     ```bash
     sudo SSH_ORIGIN="https://192.168.1.50" bash protocols/ssh/rhel/install_ssh_rhel.sh
     ```

4. **`Approve the device host key before opening its terminal.`**:
   * **Cause**: Device host key has not been verified yet.
   * **Fix**: In Device edit view, click "Test saved credential", review the fingerprint, and click "Approve Host Key".

5. **`Native SSH files must be root-owned and protected.`**:
   * **Cause**: File permissions under `/opt/icct-nms/native/guacamole-1.6.0` were altered.
   * **Fix**:
     ```bash
     sudo chown -R root:root /opt/icct-nms/native/guacamole-1.6.0
     sudo chmod -R 755 /opt/icct-nms/native/guacamole-1.6.0
     ```






as per gpt : 

# **Implementing SSH protocol in ICCT NMS on offline RHEL 9.4**

For your ICCT NMS project, SSH should work as a **real device communication and data collection protocol**, not just as a button that checks whether port 22 is open.

Your expected workflow should be:

1. Add a device in Cacti, for example `192.168.1.100`.
2. Open its ICCT NMS protocol configuration.
3. Enable **SSH**.
4. Enter the SSH connection details and credentials.
5. Click **Test Connection**.
6. When the connection succeeds, save the configuration.
7. The NMS collector connects to that device over SSH, executes approved read-only commands, collects the output, parses it and stores the actual device metrics.
8. The SSH page displays the connection status, collected information, last poll time and errors. Historical metrics should also be available.



Target

&#x20;SSH must work without internet access, using the local RHEL 9.4 server and the network between the ICCT NMS and monitored devices.



## **1. First understand what SSH can collect**

SSH is different from SNMP. SNMP provides standardized object identifiers for many device metrics. SSH gives you a remote command-line session. The commands and returned data depend on the device's operating system and vendor.

For example:

| Device type    | Example commands                                             | Possible collected data                       |
| -------------- | ------------------------------------------------------------ | --------------------------------------------- |
| Linux server   | `uptime`, `free -m`, `df -P`, `ip -s link`                   | CPU/load, memory, disk, interfaces            |
| Cisco router   | `show version`, `show interfaces`, `show ip interface brief` | Model, uptime, interfaces, traffic and errors |
| Cisco switch   | `show version`, `show interfaces status`                     | Model, ports, interface status                |
| Juniper router | `show version`, `show interfaces terse`                      | Version, interfaces, status                   |
| Other vendors  | Vendor-specific CLI commands                                 | Depends on supported commands                 |

**Important:** Do not assume that one SSH command works on every router, switch, server or network appliance. Your plugin needs a command profile and parser for each supported device platform.

## **2. Recommended architecture**

I recommend this architecture for your existing Cacti-based ICCT NMS:

**Cacti Device Inventory**

Host ID · IP address · hostname · device type

**ICCT NMS SSH Configuration**

Port · username · authentication · credentials · platform profile

**SSH Collector Service**

Connect · authenticate · verify host key · execute approved commands

**Command Profiles**

Linux · Cisco · Juniper · other vendors

**Output Parsers**

Convert raw CLI output into metrics

**MariaDB + NMS Dashboard**

Current status · metric history · events · logs · graphs

Keep the SSH collector separate from the page request. The web page should configure and display data; a scheduled background collector should perform the actual polling. This is important when monitoring hundreds of devices.

## **3. Prepare your offline RHEL 9.4 machine**

First, understand the two sides of an SSH connection:

- **ICCT NMS RHEL server:** SSH client. It connects to monitored devices.
- **Monitored device:** SSH server. It accepts the connection on TCP port 22, or its configured SSH port.

You do not need to install `openssh-server` on the ICCT NMS machine just to connect to other devices. You need the SSH client.

Run these commands on your RHEL 9.4 machine:

```
cat /etc/redhat-release
php -v
php -m | grep -i openssl
rpm -q openssh-clients
which ssh
ssh -V
```

Expected: RHEL 9.4, your installed PHP version, OpenSSL support and an SSH client executable.

RHEL provides separate `openssh-clients` and `openssh-server` packages. Its OpenSSH implementation supports SSH version 2.&#x20;

[image](https://www.google.com/s2/favicons?domain=https://docs.redhat.com\&sz=32)

Red Hat Documentation+1



If the client is missing, install it from your local RHEL repository or offline RPM media:

```
sudo dnf install openssh-clients
```

Since your machine has no internet, DNF must have access to a local repository or the required RPM packages and dependencies.

### **If you want to test using another RHEL machine**

On the machine you want to monitor, install and start the SSH server:

```
sudo dnf install openssh-server
sudo systemctl enable --now sshd
sudo systemctl status sshd
```

If firewalld is enabled, permit SSH:

```
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --reload
```

These commands are for the monitored test machine, not necessarily your Cacti server.

## **4. Choose the SSH library for your PHP plugin**

There are two practical approaches.

**Option A — phpseclib 3.0**

Recommended for your plugin

A PHP SSH library supporting password and public-key authentication. You can integrate it directly into the ICCT NMS PHP backend without relying on the system SSH command for every connection.

It is particularly useful for integrating SSH into your protocol configuration, test-connection page and scheduled collector.

Documentation: [phpseclib 3.0 installation](https://phpseclib.com/docs/3.0/intro/install?utm_source=chatgpt.com) · [SSH authentication](https://phpseclib.com/docs/3.0/ssh2/auth?utm_source=chatgpt.com)

**Option B — Native OpenSSH client**

Use `/usr/bin/ssh` through PHP `proc_open()`. This is a good option when you use SSH key authentication and want to rely on RHEL's system SSH implementation.

Documentation: [PHP proc_open](https://www.php.net/manual/en/function.proc-open.php?utm_source=chatgpt.com)

For your ICCT NMS, I would use **phpseclib 3.0**, provided it is compatible with the PHP version installed on your Cacti server.

### **How to deploy it without internet on RHEL**

Do not try to download Composer packages on the production RHEL machine.

On your internet-connected development machine:

1. Create a temporary PHP project.
2. Install the selected phpseclib 3.0 dependency using Composer.
3. Run Composer's production installation.
4. Copy `composer.json`, `composer.lock` and the complete `vendor/` directory into your ICCT NMS plugin package.
5. Transfer the package to the offline RHEL machine.
6. Test the PHP autoloader locally.

Installation command on the connected development machine:

```
composer require phpseclib/phpseclib:~3.0
```

The deployed plugin should contain a dependency structure similar to:

```
icct_nms/
├── vendor/
│   ├── autoload.php
│   └── phpseclib/
├── composer.json
├── composer.lock
└── protocols/
    └── ssh/
```

The phpseclib documentation describes installing dependencies with Composer and deploying the generated `vendor/` directory, so Composer itself is not required on the offline production machine.&#x20;

[image](https://www.google.com/s2/favicons?domain=https://phpseclib.com\&sz=32)

phpseclib+1



## **5. Design the SSH configuration when adding a device**

When a user opens a device in Cacti and selects the ICCT NMS SSH protocol, your plugin should display a configuration form.

I recommend these fields:

**SSH configuration — proposed UI**

**Enable SSH monitoring**

Enabled

Device IP / Hostname

`192.168.1.100`

SSH Port

22

SSH Version

SSH v2

Username

nmsreadonly

Authentication method

Public Key / Password

Device platform profile

Linux – Generic / Cisco IOS / Juniper

Connection timeout

5 seconds

Command timeout

10 seconds

Host key fingerprint

Verified device fingerprint

First-time enrollment must verify and approve the device's SSH host key.

Test Connection

Save Configuration

This is a proposed interface, not a live connection to a device.

The configuration must be saved against the Cacti `host_id`. This is important: do not create a separate, unrelated device record when the device already exists in Cacti.

The SSH configuration should inherit the Cacti device's hostname/IP by default, while allowing an authorized administrator to override the connection address when needed.

## **6. Implement the SSH connection service**

Create a reusable SSH service in your plugin. For example:

```
icct_nms/
└── protocols/
    └── ssh/
        ├── controllers/
        │   ├── ssh.php
        │   └── test_connection.php
        ├── services/
        │   ├── ssh_connection_service.php
        │   ├── ssh_credential_service.php
        │   ├── ssh_host_key_service.php
        │   ├── ssh_collector_service.php
        │   ├── ssh_command_service.php
        │   └── ssh_parser_service.php
        ├── profiles/
        │   ├── linux_generic.php
        │   ├── cisco_ios.php
        │   └── juniper_junos.php
        └── models/
            └── ssh_model.php
```

These are suggested file names; adapt them to your existing plugin structure.

The connection service should perform the following sequence:

1. Read the Cacti host ID.
2. Retrieve the saved SSH configuration.
3. Retrieve the credentials securely.
4. Open a connection to the device IP and port.
5. Verify the server's SSH host key against the enrolled fingerprint.
6. Authenticate using the configured method.
7. Execute the approved commands for that device profile.
8. Collect standard output, errors and exit status.
9. Disconnect safely.

For example, the phpseclib connection sequence conceptually looks like this:

```
use phpseclib3\Net\SSH2;

$ssh = new SSH2($host, $port, 5);

// Verify the key against the fingerprint/key
// enrolled and approved by an administrator.
$serverKey = $ssh->getServerPublicHostKey();

if (!$serverKey || !hash_equals($expectedHostKey, $serverKey)) {
    throw new RuntimeException('SSH host key verification failed');
}

// Use credentials obtained from your secure credential service.
if (!$ssh->login($username, $password)) {
    throw new RuntimeException('SSH authentication failed');
}

$ssh->setTimeout(10);

// Command must come from a trusted device profile.
$output = $ssh->exec('uptime');

$exitStatus = $ssh->getExitStatus();

$ssh->disconnect();
```

This is an illustrative connection example, not a complete production collector. In particular, `$expectedHostKey` must be the verified host public key or a correctly implemented pinned-key comparison value; do not accept whatever key the server presents automatically. phpseclib documents host-key verification, password authentication, public-key authentication and command execution.&#x20;

[image](https://www.google.com/s2/favicons?domain=https://phpseclib.com\&sz=32)

phpseclib+2



**Security requirements:**

- Prefer public-key authentication where supported.
- Use read-only device accounts.
- Never store passwords or private keys in plaintext.
- Encrypt stored passwords using a protected encryption key kept outside the web root and source repository.
- Store private keys outside the plugin's web-accessible directory.
- Never log passwords, private keys or passphrases.
- Never execute arbitrary commands submitted through the browser.
- Never disable host-key verification to make a connection succeed.

## **7. Create device command profiles and fetch real data**

This is the most important part of the implementation.

An SSH connection by itself only confirms access. It does not mean you have collected monitoring data.

Your NMS needs predefined profiles containing supported commands and corresponding parsers.

### **Example: Generic Linux profile**

For a monitored Linux server, your profile could collect:

| Information        | Example command       | What your NMS should calculate                |
| ------------------ | --------------------- | --------------------------------------------- |
| Hostname           | `hostname`            | Device hostname                               |
| OS version         | `cat /etc/os-release` | Distribution and version                      |
| Uptime             | `cat /proc/uptime`    | Uptime in seconds                             |
| CPU                | `cat /proc/stat`      | CPU usage calculated using successive samples |
| Load               | `cat /proc/loadavg`   | 1, 5 and 15-minute load                       |
| Memory             | `cat /proc/meminfo`   | Total, available and used memory              |
| Disk               | `df -P -B1`           | Filesystem usage and capacity                 |
| Network interfaces | `ip -s link`          | Interface state and traffic counters          |
| Kernel             | `uname -r`            | Kernel version                                |

Your parser must turn the returned output into structured values. For example, `cat /proc/meminfo` returns raw information, not a ready-made memory utilization percentage. Your plugin must calculate it.

For CPU utilization, take successive CPU counter readings and calculate the difference between samples. Do not treat the cumulative CPU counters as percentages.

### **Example: Cisco router profile**

You could start with:

```
terminal length 0
show version
show ip interface brief
show interfaces
show processes cpu
```

The commands and privilege level need to be validated on the specific Cisco platform and software version.

### **Example: Juniper profile**

You could start with:

```
show version
show system uptime
show interfaces terse
show chassis routing-engine
```

Again, profile compatibility must be tested against the actual device model and software release.

**Important:** Some routers and switches use interactive CLI sessions, require pagination to be disabled and return prompts or ANSI control sequences. Your collector must handle these cases; a simple one-command execution method will not work universally.

## **8. Connect it to the existing NMS polling pipeline**

Do not fetch hundreds of devices synchronously when someone opens the SSH page.

Use your existing ICCT NMS collection scheduler or worker architecture.

Recommended flow:

1. **Read enabled SSH device configurations**

   Identify devices whose next polling time is due.
2. **Create polling jobs**

   Queue each device using its Cacti host ID and its selected platform profile.
3. **Connect and authenticate**

   Verify reachability, host key and credentials.
4. **Execute approved commands**

   Run only the command profile for that device.
5. **Parse and validate results**

   Reject malformed or incomplete data rather than reporting it as successful.
6. **Save collected metrics**

   Update the latest values and append historical measurements.
7. **Generate events**

   Record connectivity loss, authentication failure, command failure and recovery.

For your scale of over 80 vehicles/nodes and potentially 500 or more devices per node, design for controlled concurrency, per-device timeouts, retry limits, per-host polling locks and queue monitoring. Do not open hundreds of SSH sessions without a concurrency limit.

## **9. Database design**

Keep SSH settings separate from the Cacti core tables. Link everything to the Cacti `host_id`.

I recommend these logical tables:

| Table                     | Purpose                                          |
| ------------------------- | ------------------------------------------------ |
| `icct_ssh_config`         | SSH settings and device-to-profile mapping       |
| `icct_ssh_credentials`    | Encrypted credentials or credential references   |
| `icct_ssh_host_keys`      | Approved SSH host keys/fingerprints              |
| `icct_ssh_poll_status`    | Latest connection and collection status          |
| `icct_ssh_metric_current` | Latest parsed metrics                            |
| `icct_ssh_metric_history` | Historical metric records                        |
| `icct_ssh_events`         | Authentication, connection and collection events |

Use a credential vault or encrypted credential references rather than storing secrets directly in `icct_ssh_config`.

Suggested SSH statuses:

| Status                | Meaning                                                      |
| --------------------- | ------------------------------------------------------------ |
| Connected             | SSH authenticated successfully                               |
| Authentication Failed | Username, password or key authentication failed              |
| Unreachable           | Connection to the configured port failed                     |
| Host Key Mismatch     | Device identity did not match the approved key               |
| Command Failed        | Login worked but a command failed                            |
| Collection Failed     | Output could not be parsed or validated                      |
| Stale                 | No successful collection within the allowed freshness period |
| Disabled              | SSH monitoring is disabled                                   |

Do not display `Connected` as equivalent to `Data Collection Successful`. Keep both states separately.

## **10. Test SSH manually on your offline RHEL server**

Before implementing the plugin connection, verify that the RHEL server can reach a test SSH device.

Replace the sample IP with the IP of a device where SSH has been configured:

```
ip route get 192.168.1.100
```

Check TCP port 22:

```
timeout 5 bash -c '</dev/tcp/192.168.1.100/22' \
  && echo "SSH port reachable" \
  || echo "SSH port not reachable"
```

Try connecting:

```
ssh -vvv -o ConnectTimeout=5 nmsreadonly@192.168.1.100
```

Do not disable host-key checks. For initial enrollment, you can retrieve the host public key using:

```
ssh-keyscan -p 22 192.168.1.100
```

This only retrieves the presented key; verify its fingerprint through a trusted device-management channel before approving it. Red Hat documents `ssh-keyscan` as a utility for gathering SSH public host keys.&#x20;

[image](https://www.google.com/s2/favicons?domain=https://docs.redhat.com\&sz=32)

Red Hat Documentation+1



Once connected, try:

```
hostname
uptime
free -m
df -h
ip -s link
```

If these commands return usable results under the monitoring account, you have a suitable first Linux test profile.

## **11. Integrate SSH with your NTP requirement**

You previously mentioned that SSH is a dependency for the NTP module. The right design is to create one reusable SSH connection service and let the NTP collector use it.

For Linux devices running Chrony, for example, the NTP profile can execute:

```
chronyc tracking
chronyc sources -v
```

These commands can provide information about synchronization state, reference source, stratum, offset and source selection, subject to the installed Chrony version and account permissions.

Your NTP collector should parse these values into the existing NTP metrics rather than implementing another independent SSH connection engine.

This way:

- SSH handles secure communication and authentication.
- The NTP adapter handles NTP-specific commands.
- The parser converts command output into NTP metrics.
- The existing NTP dashboard displays the collected values.

Not every network appliance has Chrony; its NTP commands need their own vendor-specific profiles.

## **12. Production testing checklist**

Before marking the SSH implementation complete, test these cases on RHEL 9.4:

**SSH implementation test checklist**

## **0/17**

SSH client is available on RHEL 9.4 without internet access

TCP connection succeeds on the configured SSH port

Correct password authentication succeeds

Correct public-key authentication succeeds

Incorrect password is rejected

Incorrect private key is rejected

Unapproved or changed host key is rejected

Linux hostname, OS and uptime are collected correctly

CPU, memory and disk values are parsed correctly

Interface data is collected and stored

Unsupported commands are reported as command failures

Connection timeout is handled without hanging the poller

Device unreachable event and recovery event are generated

Repeated polling does not create duplicate jobs

SSH credentials are not exposed in logs, URLs or browser responses

NTP collector successfully uses the shared SSH service

Multiple devices can be polled within the configured concurrency limit

Reset checklist

## **My recommended implementation order**

Do not implement every vendor profile at once. Build and test the functionality in this order:

| Phase | Implementation                    | Completion condition                               |
| ----- | --------------------------------- | -------------------------------------------------- |
| 1     | RHEL SSH prerequisites            | RHEL can connect to a test device                  |
| 2     | SSH configuration in Cacti device | Enable, configure and save SSH                     |
| 3     | Secure SSH connection service     | Test Connection works                              |
| 4     | Linux Generic profile             | Real Linux information is collected                |
| 5     | Output parsers and database       | Values are stored accurately                       |
| 6     | Background polling and events     | Scheduled collection and recovery work             |
| 7     | NTP SSH adapter                   | Real NTP metrics appear on the NTP page            |
| 8     | Cisco and Juniper profiles        | Vendor-specific collection is validated            |
| 9     | Scale and security testing        | Concurrency, timeouts and credentials are verified |

**One thing I need to establish first on your actual RHEL machine:** run the following commands and share their output:

```
cat /etc/redhat-release
php -v
rpm -q openssh-clients
php -m | grep -Ei 'openssl|sockets|ssh2|sodium'
```

With those results, we can select the correct SSH dependency and PHP implementation for your actual offline RHEL 9.4 environment, rather than installing something that may conflict with your existing Cacti setup.