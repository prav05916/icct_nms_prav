#!/usr/bin/env bash
set -u

echo "ICCT LNMS protocol preflight"
echo "============================"

check_cmd() {
  if command -v "$1" >/dev/null 2>&1; then
    printf "[OK]   %-14s %s\n" "$1" "$(command -v "$1")"
  else
    printf "[MISS] %-14s\n" "$1"
  fi
}

for cmd in snmpget snmpwalk snmptrap logger ssh curl chronyc chronyd ss firewall-cmd; do
  check_cmd "$cmd"
done

echo
for svc in rsyslog chronyd; do
  if systemctl is-active --quiet "$svc"; then
    echo "[OK]   service $svc is active"
  else
    echo "[WARN] service $svc is not active"
  fi
done

echo
rpm -q net-snmp net-snmp-utils rsyslog openssh-clients curl chrony 2>/dev/null || true

echo
ss -lunpt 2>/dev/null | grep -E '(:514|:162|:123)' || echo "No listener currently shown on UDP/TCP 514, UDP 162, or UDP 123. This may be normal before configuration."
