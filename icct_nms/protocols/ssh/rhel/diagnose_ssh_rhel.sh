#!/usr/bin/env bash
# ==============================================================================
# ICCT NMS — SSH Subsystem Diagnostics (RHEL 9.4)
# Inspects services, sockets, permissions, and configuration.
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CACTI_DETECTED=""
if [[ -n "${1:-}" ]]; then
  CACTI_DETECTED="$1"
elif [[ -f "/var/www/html/cacti/include/global.php" ]]; then
  CACTI_DETECTED="/var/www/html/cacti"
elif [[ -f "/usr/share/cacti/include/global.php" ]]; then
  CACTI_DETECTED="/usr/share/cacti"
elif [[ -f "$SCRIPT_DIR/../../../../../include/global.php" ]]; then
  CACTI_DETECTED="$(cd "$SCRIPT_DIR/../../../../.." && pwd)"
fi

CACTI_ROOT="${CACTI_DETECTED:-/var/www/html/cacti}"
PLUGIN="$CACTI_ROOT/plugins/icct_nms"

echo "=== ICCT NMS — SSH RHEL Diagnostics ==="
echo "Cacti Root : $CACTI_ROOT"
echo "Plugin     : $PLUGIN"

echo "--- Core Utilities ---"
for bin in php python3 systemctl make gcc restorecon semodule; do
  p="$(command -v "$bin" 2>/dev/null || echo "missing")"
  printf "%-15s %s\n" "$bin" "$p"
done

echo "--- Native Guacamole Binary ---"
GUACD_PATH="/opt/icct-nms/native/guacamole-1.6.0/sbin/guacd"
if [[ -x "$GUACD_PATH" ]]; then
  echo "guacd binary: $GUACD_PATH [OK]"
  "$GUACD_PATH" -v 2>&1 | head -n 2 || true
else
  echo "guacd binary: NOT FOUND at $GUACD_PATH"
fi

echo "--- Systemd Services ---"
systemctl status icct-nms-guacd.service --no-pager || true
systemctl status icct-nms-ssh.service --no-pager || true

echo "--- Sockets & Ports ---"
if [[ -S "/run/icct-nms-ssh/control.sock" ]]; then
  ls -la "/run/icct-nms-ssh/control.sock"
else
  echo "Control socket /run/icct-nms-ssh/control.sock NOT FOUND"
fi

if command -v ss >/dev/null 2>&1; then
  echo "Guacd TCP loopback listener (port 4823):"
  ss -tlnp | grep ':4823' || echo "  guacd port 4823 not listening"
fi

echo "--- Config & Master Key ---"
CONFIG_FILE="$PLUGIN/ssh.config.local.json"
if [[ -f "$CONFIG_FILE" ]]; then
  echo "Configuration file: $CONFIG_FILE [OK]"
  ls -la "$CONFIG_FILE"
else
  echo "Configuration file: $CONFIG_FILE NOT FOUND"
fi

KEY_FILE="/var/lib/icct-nms-ssh/master.key"
if [[ -f "$KEY_FILE" ]]; then
  echo "Master key file: $KEY_FILE [OK]"
  ls -la "$KEY_FILE"
else
  echo "Master key: $KEY_FILE NOT FOUND"
fi

echo "--- SELinux Policy ---"
if command -v semodule >/dev/null 2>&1; then
  semodule -l | grep -E 'icct|guac' || echo "No custom icct SELinux module found"
fi

echo "--- Recent SSH Service Logs ---"
journalctl -u icct-nms-ssh.service -n 15 --no-pager 2>/dev/null || true
