# Database Design — v2.3.0

## Reused Cacti table
- `host` remains the canonical monitored-device inventory and source of live device status.

## Plugin tables
- `plugin_icct_rack_racks` — physical racks. Operator-facing fields are only Name and Rack Units.
- `plugin_icct_rack_placements` — monitored Cacti-device placement, start U, U-height and front stencil category.
- `plugin_icct_rack_peripheral_placements` — passive rack peripherals such as patch panel, PDU, KVM, shelf and blanking panel.
- `plugin_icct_rack_audit` — configuration and placement audit trail.

Collision validation checks device and peripheral placements together so two rack items cannot occupy the same U range.
