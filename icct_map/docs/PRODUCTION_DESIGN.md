# Production design notes — v1.6.0

The geographic viewer is intentionally limited to India and uses Cacti's existing data model.

## Native Cacti reuse

- `sites`: ICCT name/location/current coordinates.
- `host.site_id`: site/device relationship.
- `host.status`, `host.disabled`: device health.
- `sites.php`: site administration.
- `host.php`: device administration.

## Plugin-owned data only where Cacti has no equivalent

- `plugin_icct_map_site_positions`: historical GNSS positions.
- `plugin_icct_map_audit`: map/GNSS/settings audit trail.

## Interaction

The map supports drag-to-pan, wheel/pinch zoom, India fit, compact height, details collapse, and maximized workspace. AJAX status refresh updates markers only and never resets the current viewport.

State labels are not bound permanently to every polygon feature. The viewer aggregates bounds by unique state/UT name and draws one clean label per state, with smaller labels appearing only at closer zoom levels.
