# ICCT Rack Topology v2.3.0 — Upgrade Guide

1. Back up the Cacti database.
2. Disable `icct_rack`.
3. Replace the old `plugins/icct_rack/` folder with this package.
4. On RHEL restore the same owner/group as other Cacti plugins and run `restorecon -RFv`.
5. Use **Upgrade** in Cacti Plugin Management when shown. Otherwise install normally.
6. Enable the plugin and log in again.

The migration preserves existing front-view device placements and adds the peripheral-placement table.
