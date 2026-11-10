# ICCT Rack Topology v2.3.0

Production-oriented Cacti rack topology for the ICCT NMS project.

## Operator workflow

- Open **Management → Rack Topology**.
- All enabled racks are visible horizontally in one workspace.
- Authorized administrators see a left sidebar with **Devices** and **Peripherals** tabs.
- Choose the required rack-unit height on an item, drag it, and drop it on a rack U-position.
- Click a placed item to open the inspector, change U-height/stencil, or remove it.
- Device status comes from the Cacti `host` table and refreshes through AJAX.

## Rack definition

Only two operator fields are required:

- Rack Name
- Rack Units (1–100U)

## No Cacti core changes

All files remain under `plugins/icct_rack/`.
