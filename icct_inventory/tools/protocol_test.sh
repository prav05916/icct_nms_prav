#!/usr/bin/env bash
set -euo pipefail

usage() {
cat <<'TXT'
Usage:
  protocol_test.sh snmpv2 <device_ip> <community>
  protocol_test.sh ssh <device_ip> [user]
  protocol_test.sh http <http-or-https-url>
  protocol_test.sh ntp <device_ip>
  protocol_test.sh syslog-local <lnms_ip>
  protocol_test.sh lldp <device_ip> <community>
  protocol_test.sh cdp <device_ip> <community>

Examples:
  ./protocol_test.sh snmpv2 10.10.10.2 public
  ./protocol_test.sh ssh 10.10.10.2 admin
  ./protocol_test.sh http https://10.10.10.2/
  ./protocol_test.sh ntp 10.10.10.10
  ./protocol_test.sh syslog-local 127.0.0.1
TXT
}

[[ $# -ge 2 ]] || { usage; exit 2; }
mode="$1"; target="$2"

case "$mode" in
  snmpv2)
    [[ $# -eq 3 ]] || { usage; exit 2; }
    command -v snmpget >/dev/null
    community="$3"
    echo "== sysDescr =="
    snmpget -On -v2c -c "$community" -t 2 -r 1 "$target" .1.3.6.1.2.1.1.1.0
    echo "== sysUpTime =="
    snmpget -On -v2c -c "$community" -t 2 -r 1 "$target" .1.3.6.1.2.1.1.3.0
    echo "== ifOperStatus table =="
    snmpwalk -On -v2c -c "$community" -t 2 -r 1 "$target" .1.3.6.1.2.1.2.2.1.8 | head -30
    ;;
  ssh)
    user="${3:-admin}"
    command -v ssh >/dev/null
    exec ssh -o ConnectTimeout=5 "$user@$target"
    ;;
  http)
    command -v curl >/dev/null
    curl --connect-timeout 5 --max-time 10 -I "$target"
    ;;
  ntp)
    command -v chronyd >/dev/null
    sudo chronyd -Q -t 3 "server $target iburst"
    ;;
  syslog-local)
    command -v logger >/dev/null
    logger --udp -n "$target" -P 514 -t ICCT_TEST "ICCT LNMS syslog protocol test $(date -Is)"
    echo "Sent. Verify with: sudo tail -n 20 /var/log/icct-lnms/remote.log"
    ;;
  lldp)
    [[ $# -eq 3 ]] || { usage; exit 2; }
    snmpwalk -On -v2c -c "$3" -t 2 -r 1 "$target" .1.0.8802.1.1.2.1.4.1.1
    ;;
  cdp)
    [[ $# -eq 3 ]] || { usage; exit 2; }
    snmpwalk -On -v2c -c "$3" -t 2 -r 1 "$target" .1.3.6.1.4.1.9.9.23.1.2.1.1
    ;;
  *) usage; exit 2;;
esac
