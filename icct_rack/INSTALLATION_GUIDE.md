# ICCT Rack Topology v2.3.0 — Install / Upgrade

1. Back up the Cacti database and current `plugins/icct_rack` folder.
2. Disable `icct_rack` in **Console → Configuration → Plugins**.
3. Replace the old `plugins/icct_rack` folder with this package.
4. Confirm `plugins/icct_rack/setup.php` and `plugins/icct_rack/INFO` exist directly in that folder.
5. On RHEL, match ownership/permissions with other Cacti plugins and run `restorecon -RFv <cacti_root>/plugins/icct_rack`.
6. Open Cacti Plugin Management. Use **Upgrade** when offered; otherwise Install.
7. Enable the plugin.
8. Logout/login once and hard-refresh the browser.

## Pages

- `Management → Rack Topology` — interactive rack workspace
- `Configuration → Rack Topology` — rack definitions and refresh settings
- `Utilities → Rack Topology Audit Log` — paginated audit log

## Notes

- No external JavaScript library is required.
- No Cacti core file is modified.
- Existing v1.x/v2.0 device placements are preserved.
- Rear-rack data from legacy versions is not used.
