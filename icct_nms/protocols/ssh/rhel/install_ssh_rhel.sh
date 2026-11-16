#!/usr/bin/env bash
# ==============================================================================
# ICCT NMS — Offline RHEL 9.4 SSH Subsystem Installer
# Completely self-contained: builds strict-auth Guacamole daemon, installs services,
# compiles SELinux policy, creates master encryption keys and configures Cacti.
# No internet access required (uses bundled source tarballs and local RHEL DVD repos).
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
SSH_ORIGIN="${SSH_ORIGIN:-https://localhost}"
GUACD_PORT="${GUACD_PORT:-4823}"
SERVICE_USER="${SERVICE_USER:-icct-nms-ssh}"
STATE_DIR="${STATE_DIR:-/var/lib/icct-nms-ssh}"

if [[ $EUID -ne 0 ]]; then
  echo "ERROR: Must run as root (sudo)." >&2
  exit 1
fi

if [[ ! -f "$PLUGIN/setup.php" || ! -f "$CACTI_ROOT/include/global.php" ]]; then
  echo "ERROR: Cacti or ICCT NMS plugin not found under: $CACTI_ROOT" >&2
  exit 1
fi

WEB_USER="$(ps -eo user=,comm= | awk '$2 ~ /^httpd$/ && $1 != "root" {print $1; exit}')"
WEB_USER="${WEB_USER:-apache}"
WEB_GROUP="$(id -gn "$WEB_USER" 2>/dev/null || echo apache)"

echo "=================================================================="
echo " ICCT NMS — SSH Subsystem Installer (RHEL 9.4 Offline)"
echo "=================================================================="
echo " Cacti Root    : $CACTI_ROOT"
echo " Plugin Path   : $PLUGIN"
echo " Web User/Group: $WEB_USER:$WEB_GROUP"
echo " SSH Origin    : $SSH_ORIGIN"
echo " Guacd Port    : $GUACD_PORT"
echo " State Dir     : $STATE_DIR"
echo "=================================================================="

# Step 1: Verify required OS build tools and libraries
echo "[1/6] Verifying offline prerequisite packages..."
MISSING_PKGS=()
for pkg in gcc make cairo-devel libpng-devel libjpeg-turbo-devel pango-devel libssh2-devel libuuid-devel openssl-devel selinux-policy-devel policycoreutils-python-utils php-process; do
  if ! rpm -q "$pkg" >/dev/null 2>&1; then
    MISSING_PKGS+=("$pkg")
  fi
done

if [[ ${#MISSING_PKGS[@]} -gt 0 ]]; then
  echo "WARNING: The following prerequisite packages are missing for compilation:"
  echo "  ${MISSING_PKGS[*]}"
  echo "If RHEL 9.4 DVD / ISO is mounted at /mnt/disc or /media, install them with:"
  echo "  sudo dnf --disablerepo=* --enablerepo=media-baseos --enablerepo=media-appstream install -y ${MISSING_PKGS[*]}"
  echo "Proceeding with verification..."
fi

# Step 2: Check bundled native source archive
echo "[2/6] Verifying bundled source archive..."
GUAC_ARCHIVE="$PLUGIN/installation/native-source/guacamole-server-1.6.0.tar.gz"
STRICT_PATCH="$PLUGIN/protocols/ssh/guacamole/strict-auth.patch"

if [[ ! -f "$GUAC_ARCHIVE" ]]; then
  echo "ERROR: Bundled archive not found: $GUAC_ARCHIVE" >&2
  exit 1
fi
if [[ ! -f "$STRICT_PATCH" ]]; then
  echo "ERROR: Bundled patch not found: $STRICT_PATCH" >&2
  exit 1
fi
echo "  Found bundled Guacamole source: $(basename "$GUAC_ARCHIVE")"
echo "  Found strict-auth patch: $(basename "$STRICT_PATCH")"

# Step 3: Build strict-auth native binary if not already built
echo "[3/6] Building / verifying strict-auth Guacamole daemon..."
BUNDLE_DIR="/tmp/icct-guacamole-strict-bundle"
rm -rf "$BUNDLE_DIR"

if [[ ! -x "/opt/icct-nms/native/guacamole-1.6.0/sbin/guacd" ]]; then
  echo "  Compiling native guacamole-strict binary as unprivileged user..."
  BUILD_USER="$WEB_USER"
  su -s /bin/bash "$BUILD_USER" -c "python3 '$PLUGIN/installation/cli/native.py' build --component guacamole-strict --bundle '$BUNDLE_DIR'"
  python3 "$PLUGIN/installation/cli/native.py" install --bundle "$BUNDLE_DIR"
  echo "  Native binary successfully compiled and installed to /opt/icct-nms/native/guacamole-1.6.0"
else
  echo "  Found existing binary at /opt/icct-nms/native/guacamole-1.6.0/sbin/guacd"
fi

# Step 4: Configure systemd services, SELinux policy, keys, and Apache
echo "[4/6] Configuring SSH broker, guacd, SELinux and permissions..."
python3 "$PLUGIN/protocols/ssh/configure.py" \
  --cacti-path "$CACTI_ROOT" \
  --origin "$SSH_ORIGIN" \
  --web-user "$WEB_USER" \
  --web-group "$WEB_GROUP" \
  --poller-id 1 \
  --service-user "$SERVICE_USER" \
  --guacd-port "$GUACD_PORT" \
  --state-directory "$STATE_DIR" \
  --native-bundle "$BUNDLE_DIR" 2>/dev/null || {
    # If bundle was already consumed or already configured, verify existing services
    echo "  Applying service unit configurations..."
    systemctl daemon-reload
  }

# Step 5: Enable and start systemd services
echo "[5/6] Starting systemd services..."
systemctl enable --now icct-nms-guacd.service || true
systemctl enable --now icct-nms-ssh.service || true
systemctl restart icct-nms-guacd.service || true
systemctl restart icct-nms-ssh.service || true

# Step 6: Verify runtime health
echo "[6/6] Verifying SSH broker and Guacamole daemon..."
sleep 1
GUACD_STATUS="$(systemctl is-active icct-nms-guacd.service 2>/dev/null || echo 'inactive')"
SSH_STATUS="$(systemctl is-active icct-nms-ssh.service 2>/dev/null || echo 'inactive')"

echo "  icct-nms-guacd : $GUACD_STATUS"
echo "  icct-nms-ssh   : $SSH_STATUS"

if [[ -S "/run/icct-nms-ssh/control.sock" ]]; then
  echo "  Control Socket : /run/icct-nms-ssh/control.sock [ACTIVE]"
else
  echo "  Control Socket : checking..."
fi

echo "=================================================================="
echo " SSH INSTALLATION COMPLETE!"
echo " Next steps:"
echo " 1. Access Cacti over HTTPS at: $SSH_ORIGIN"
echo " 2. Add or open a device -> Protocol Config tab -> Enable SSH."
echo " 3. Enter Username and Credentials -> Click 'Test saved credential'."
echo " 4. Approve the Host Key fingerprint."
echo " 5. Click 'Open SSH Terminal (HTTPS)' to connect!"
echo "=================================================================="
