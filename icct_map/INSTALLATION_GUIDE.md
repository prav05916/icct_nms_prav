# ICCT India Geographic Topology v1.6.0 — Windows and Offline RHEL

## Important upgrade rule

If v1.5.0 already has working local files under:

- `vendor/leaflet/`
- `data/india_states.geojson`

preserve those runtime assets when updating the code.

### Windows upgrade from v1.5.0

Disable `icct_map` from Cacti Plugin Management.

Back up the runtime only:

```powershell
New-Item -ItemType Directory -Force C:\CactiBackup\icct_map_runtime | Out-Null
Copy-Item "C:\Apache24\htdocs\cacti\plugins\icct_map\vendor" "C:\CactiBackup\icct_map_runtime\vendor" -Recurse -Force
Copy-Item "C:\Apache24\htdocs\cacti\plugins\icct_map\data\india_states.geojson" "C:\CactiBackup\icct_map_runtime\india_states.geojson" -Force
```

Replace the old `icct_map` code with v1.6.0, then restore:

```powershell
Copy-Item "C:\CactiBackup\icct_map_runtime\vendor\*" "C:\Apache24\htdocs\cacti\plugins\icct_map\vendor\" -Recurse -Force
Copy-Item "C:\CactiBackup\icct_map_runtime\india_states.geojson" "C:\Apache24\htdocs\cacti\plugins\icct_map\data\india_states.geojson" -Force
```

Or, on an Internet-connected Windows qualification laptop, simply run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "C:\Apache24\htdocs\cacti\plugins\icct_map\tools\prepare_complete_offline_package_windows.ps1"
```

That prepares local Leaflet + India state/UT data and builds `icct_map_complete_offline.zip` for the disconnected RHEL system.

Restart Apache, upgrade/enable the plugin in Cacti, logout/login once, then `Ctrl+F5`.

## Cacti Sites

The viewer uses native Cacti Sites only:

`Console -> Management -> Sites` / `sites.php`

For every ICCT Site set real India coordinates. Sites with `0,0` are counted as **Missing Position** and are not plotted.

Assign monitored devices to Sites using native Cacti Device Management (`host.site_id`).

## Offline RHEL

Use the `icct_map_complete_offline.zip` produced on Windows. Do not download runtime files on RHEL.

After copying to the Cacti plugin folder, set ownership/permissions to match Cacti, restore SELinux context, run PHP syntax checks, restart Apache, then upgrade/enable from Cacti Plugin Management.
