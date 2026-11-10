#!/usr/bin/env bash
set -euo pipefail

CACTI_ROOT="${1:-/usr/share/cacti}"
PLUGIN="$CACTI_ROOT/plugins/icct_nms"

echo "=== ICCT NetFlow RHEL diagnostics ==="
for bin in php systemctl ss awk; do
  p="$(command -v "$bin" 2>/dev/null || echo "missing")"
  printf "%-12s %s\n" "$bin" "$p"
done

echo "--- Systemd Services ---"
systemctl status icct-nms-netflow-collector.service --no-pager || true
systemctl status icct-nms-netflow-worker.timer --no-pager || true

echo "--- Listening UDP Ports (2055 / 9995) ---"
if command -v ss >/dev/null 2>&1; then
  ss -lunp | grep -E ':2055|:9995' || echo "No listener found on UDP 2055/9995"
fi

echo "--- Sync Worker ---"
if [[ -f "$PLUGIN/protocols/netflow/cli/netflow_worker.php" ]]; then
  WEB_USER="$(ps -eo user=,comm= | awk '$2 ~ /^httpd$/ && $1 != "root" {print $1; exit}')"
  WEB_USER="${WEB_USER:-apache}"
  runuser -u "$WEB_USER" -- php "$PLUGIN/protocols/netflow/cli/netflow_worker.php" --sync --limit=10 2>/dev/null || php "$PLUGIN/protocols/netflow/cli/netflow_worker.php" --sync --limit=10
fi

echo "=== End diagnostics ==="

