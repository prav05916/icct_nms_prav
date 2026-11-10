#!/usr/bin/env bash
set -euo pipefail

CACTI_ROOT="${1:-/usr/share/cacti}"
PLUGIN="$CACTI_ROOT/plugins/icct_nms"

echo "=== ICCT NTP RHEL diagnostics ==="
if [[ -f "$PLUGIN/setup.php" ]]; then
  VER="$(grep 'version' "$PLUGIN/setup.php" | head -n1 | tr -d " \t\r\n\',\"" || echo "unknown")"
  echo "Plugin: $VER"
fi

for bin in php systemctl runuser; do
  p="$(command -v "$bin" 2>/dev/null || echo "missing")"
  printf "%-12s %s\n" "$bin" "$p"
done

if php -r 'exit(function_exists("stream_socket_client") ? 0 : 1);' >/dev/null 2>&1; then
  echo "PHP UDP stream: available"
else
  echo "PHP UDP stream: NOT available"
fi

systemctl status icct-nms-ntp-worker.timer --no-pager || true
systemctl status icct-nms-ntp-worker.service --no-pager || true

echo "--- last worker log ---"
journalctl -u icct-nms-ntp-worker.service -n 20 --no-pager 2>/dev/null || true

echo "--- local chrony evidence (optional LNMS clock status) ---"
if command -v chronyc >/dev/null 2>&1; then
  chronyc tracking 2>/dev/null || true
  chronyc sources -v 2>/dev/null || true
else
  echo "chronyc not found"
fi

echo "--- one-shot worker ---"
if [[ -f "$PLUGIN/protocols/ntp/cli/ntp_worker.php" ]]; then
  WEB_USER="$(ps -eo user=,comm= | awk '$2 ~ /^httpd$/ && $1 != "root" {print $1; exit}')"
  WEB_USER="${WEB_USER:-apache}"
  runuser -u "$WEB_USER" -- php "$PLUGIN/protocols/ntp/cli/ntp_worker.php" --limit=10 2>/dev/null || php "$PLUGIN/protocols/ntp/cli/ntp_worker.php" --limit=10
fi
echo "=== End diagnostics ==="

