#!/usr/bin/env bash
set -euo pipefail

CACTI_ROOT="${1:-/usr/share/cacti}"
OPEN_FIREWALL="${OPEN_FIREWALL:-0}"
PLUGIN="$CACTI_ROOT/plugins/icct_nms"

if [[ $EUID -ne 0 ]]; then
  echo "Run as root." >&2
  exit 1
fi
if [[ ! -f "$PLUGIN/setup.php" || ! -f "$CACTI_ROOT/include/global.php" ]]; then
  echo "Cacti/ICCT plugin not found under $CACTI_ROOT" >&2
  exit 1
fi

WEB_USER="$(ps -eo user=,comm= | awk '$2 ~ /^httpd$/ && $1 != "root" {print $1; exit}')"
WEB_USER="${WEB_USER:-apache}"
WEB_GROUP="$(id -gn "$WEB_USER" 2>/dev/null || echo apache)"

echo "Cacti root : $CACTI_ROOT"
echo "Web account: $WEB_USER:$WEB_GROUP"

# Install collector service
COLLECTOR_IN="$PLUGIN/protocols/netflow/rhel/icct-nms-netflow-collector.service.in"
sed -e "s|@@CACTI_ROOT@@|$CACTI_ROOT|g" \
    -e "s|@@WEB_USER@@|$WEB_USER|g" \
    -e "s|@@WEB_GROUP@@|$WEB_GROUP|g" \
    "$COLLECTOR_IN" > /etc/systemd/system/icct-nms-netflow-collector.service

# Install sync timer
WORKER_IN="$PLUGIN/protocols/netflow/rhel/icct-nms-netflow-worker.service.in"
WORKER_TIMER="$PLUGIN/protocols/netflow/rhel/icct-nms-netflow-worker.timer"

sed -e "s|@@CACTI_ROOT@@|$CACTI_ROOT|g" \
    -e "s|@@WEB_USER@@|$WEB_USER|g" \
    -e "s|@@WEB_GROUP@@|$WEB_GROUP|g" \
    "$WORKER_IN" > /etc/systemd/system/icct-nms-netflow-worker.service

cp -f "$WORKER_TIMER" /etc/systemd/system/icct-nms-netflow-worker.timer
chmod 0644 /etc/systemd/system/icct-nms-netflow-collector.service \
           /etc/systemd/system/icct-nms-netflow-worker.service \
           /etc/systemd/system/icct-nms-netflow-worker.timer

if [[ "$OPEN_FIREWALL" == "1" ]] && command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld; then
  firewall-cmd --permanent --add-port=2055/udp >/dev/null 2>&1 || true
  firewall-cmd --permanent --add-port=9995/udp >/dev/null 2>&1 || true
  firewall-cmd --reload >/dev/null 2>&1 || true
fi

systemctl daemon-reload
systemctl enable --now icct-nms-netflow-collector.service
systemctl enable --now icct-nms-netflow-worker.timer
systemctl start icct-nms-netflow-worker.service || true

echo "ICCT NetFlow runtime installed and active."
echo "Native PHP NetFlow collector listening on UDP 2055."
if command -v nfcapd >/dev/null 2>&1; then
  echo "nfcapd: available (nfdump toolchain active)"
else
  echo "nfcapd: not present. Built-in ICCT NetFlow UDP daemon is active."
fi
systemctl status icct-nms-netflow-collector.service --no-pager || true
systemctl status icct-nms-netflow-worker.timer --no-pager || true

