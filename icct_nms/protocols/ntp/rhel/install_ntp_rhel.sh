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

SERVICE_IN="$PLUGIN/protocols/ntp/rhel/icct-nms-ntp-worker.service.in"
TIMER_FILE="$PLUGIN/protocols/ntp/rhel/icct-nms-ntp-worker.timer"

sed -e "s|@@CACTI_ROOT@@|$CACTI_ROOT|g" \
    -e "s|@@WEB_USER@@|$WEB_USER|g" \
    -e "s|@@WEB_GROUP@@|$WEB_GROUP|g" \
    "$SERVICE_IN" > /etc/systemd/system/icct-nms-ntp-worker.service

cp -f "$TIMER_FILE" /etc/systemd/system/icct-nms-ntp-worker.timer
chmod 0644 /etc/systemd/system/icct-nms-ntp-worker.service /etc/systemd/system/icct-nms-ntp-worker.timer

if [[ "$OPEN_FIREWALL" == "1" ]] && command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld; then
  firewall-cmd --permanent --add-service=ntp >/dev/null 2>&1 || true
  firewall-cmd --permanent --add-port=123/udp >/dev/null 2>&1 || true
  firewall-cmd --reload >/dev/null 2>&1 || true
fi

systemctl daemon-reload
systemctl enable --now icct-nms-ntp-worker.timer
systemctl start icct-nms-ntp-worker.service || true

echo "ICCT NTP runtime installed."
echo "Remote NTP polling uses PHP UDP directly; chronyc is optional and only enriches the LNMS local-clock card."
if command -v chronyc >/dev/null 2>&1; then
  echo "chronyc: available"
else
  echo "chronyc: not installed (remote UDP polling remains fully active)"
fi
systemctl status icct-nms-ntp-worker.timer --no-pager || true

