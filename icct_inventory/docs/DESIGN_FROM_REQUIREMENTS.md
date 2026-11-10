# ICCT Inventory Plugin Design

## Why this is a separate plugin

The plugin models the workflow shown in the updated client notes:

```text
Inventory Data
    ↓
Device Type  ─────────→ Actual Devices (Cacti host)
    │                         │
    ├────────→ Segment ───────┘
    │
    └────────→ FCAPS / OID policy
```

It does not replace Cacti device management.

## Ownership

### Cacti owns

- Actual host/device row
- IP/hostname
- SNMP credentials/version
- Reachability/status
- Device template/data queries/data sources
- Poller and RRD history
- Graphs

### icct_inventory owns

- Client/BOM Device Type catalogue
- Mapping from actual Cacti host → Device Type
- Segment/subnet assignment
- Serial number, MAC, maintenance date and serviceability metadata
- Cross-launch URL
- FCAPS/OID policy catalogue
- Inventory audit history

## Device Type seed

The package contains `data/device_types_seed.json`, generated from rows in the supplied updated client device list where `Monitored by LNMS` is Yes. Contact-person fields are deliberately not copied into the runtime NMS catalogue because they are procurement/support metadata rather than monitoring metadata.

## OID policy

The supplied client list identifies protocols/interfaces but does not provide vendor-specific MIB OIDs. The plugin therefore seeds only standards-based numeric OIDs (MIB-II / IF-MIB / ENTITY-MIB / LLDP-MIB and Cisco CDP base for Cisco topology testing).

Vendor-specific OIDs must be added only after approved vendor MIB documentation is available and a real `snmpget`/`snmpwalk` test succeeds.

## NMS_SAIMA handling

The supplied `NMS_SAIMA` package was reviewed only as a reference for general Cacti-plugin separation and offline deployment ideas. This plugin is independent code and does not copy NMS_SAIMA database schema, pages, classes or runtime logic.
