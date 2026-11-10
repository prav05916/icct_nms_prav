# ICCT Inventory and Protocol Integration v1.0.0

## What this plugin adds

- Client/BOM Device Type catalogue
- Actual Cacti Device mapping
- Segment management
- FCAPS/OID catalogue
- Protocol diagnostics
- Paginated audit log
- Offline RHEL protocol setup/test scripts and documentation

## Windows/home installation (for code verification)

Copy folder to:

```text
C:\Apache24\htdocs\cacti\plugins\icct_inventory
```

Then Cacti → Console → Configuration → Plugins → Install → Enable.

## RHEL/offline installation

See `docs/INSTALL_RHEL_OFFLINE.md`.

## Protocol setup

See `docs/OFFLINE_RHEL_PROTOCOL_SETUP.md`.

## First use

1. Open Management → ICCT Inventory.
2. Confirm Device Types are seeded.
3. Create Segments.
4. Map real Cacti hosts under Actual Devices.
5. Review/add approved FCAPS/OIDs.
6. Run the offline protocol preflight on RHEL.
7. Test SNMP, Syslog, SSH, HTTP/HTTPS and NTP against real devices.
