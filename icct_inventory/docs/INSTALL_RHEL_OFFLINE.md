# ICCT Inventory Plugin — Offline RHEL 9.4 Install

This plugin has no external runtime dependency. It does not use npm, Composer, CDN or Internet access.

## 1. Find Cacti root

```bash
rpm -ql cacti | grep '/include/global.php$'
```

Typical RPM installation:

```bash
export CACTI_ROOT=/usr/share/cacti
```

Verify:

```bash
test -f "$CACTI_ROOT/include/global.php" && echo OK
```

## 2. Disable an older icct_inventory plugin if present

Cacti UI → Console → Configuration → Plugins → Disable.

## 3. Copy plugin

After unzipping the package from approved removable media:

```bash
sudo rm -rf "$CACTI_ROOT/plugins/icct_inventory"
sudo cp -a /path/to/icct_inventory "$CACTI_ROOT/plugins/icct_inventory"
```

Verify:

```bash
cat "$CACTI_ROOT/plugins/icct_inventory/INFO"
php -l "$CACTI_ROOT/plugins/icct_inventory/setup.php"
```

## 4. Ownership, permissions and SELinux

```bash
sudo chown -R --reference="$CACTI_ROOT/plugins/index.php" "$CACTI_ROOT/plugins/icct_inventory"
sudo find "$CACTI_ROOT/plugins/icct_inventory" -type d -exec chmod 755 {} \;
sudo find "$CACTI_ROOT/plugins/icct_inventory" -type f -exec chmod 644 {} \;
sudo chmod 755 "$CACTI_ROOT/plugins/icct_inventory/tools/"*.sh
sudo restorecon -RFv "$CACTI_ROOT/plugins/icct_inventory"
```

Do not use `chmod 777` and do not disable SELinux.

## 5. PHP syntax test

```bash
find "$CACTI_ROOT/plugins/icct_inventory" -type f -name '*.php' -exec php -l {} \;
```

Every PHP file must report `No syntax errors detected`.

## 6. Install and enable

Cacti UI → Console → Configuration → Plugins → Install → Enable.

Then logout/login once.

## 7. First workflow

1. Management → ICCT Inventory.
2. Device Types: confirm the client catalogue appears.
3. Segments: create the required subnets/logical segments.
4. Actual Devices: map Cacti hosts to Device Type and Segment.
5. FCAPS/OIDs: review generic OIDs; add approved vendor OIDs only when supplied by the vendor/MIB.
6. Protocol Diagnostics: use the test commands after OS-level protocol setup.
