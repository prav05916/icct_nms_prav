# ICCT Inventory & Protocol Integration

Cacti 1.2.30 plugin for the ICCT LNMS inventory/protocol workflow.

## Design

This plugin intentionally does **not** copy the supplied NMS_SAIMA plugin. It follows the project requirement and the updated client device list directly:

1. **Device Types** = client/BOM capability catalogue.
2. **Actual Devices** = real Cacti `host` rows.
3. **Segments** = plugin-owned logical network groups/subnets.
4. **FCAPS / OIDs** = generic or device-type-specific numeric OID policy.
5. **Protocol Diagnostics** = commands for real offline RHEL validation.

Cacti remains authoritative for device IP, SNMP settings, polling status, templates, data sources and graph history.

## Zero external runtime dependencies

The PHP plugin requires no Composer, npm, CDN or Internet connection.

## Main pages

- Management → ICCT Inventory
- Actual Devices
- Device Types
- Segments
- FCAPS / OIDs
- Protocol Diagnostics
- Audit Log

## Offline RHEL protocol guide

See `docs/OFFLINE_RHEL_PROTOCOL_SETUP.md`.
