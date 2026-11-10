# ICCT India Geographic Topology v1.6.0

Production-oriented India-only geographic topology for Cacti 1.2.30.

## Source of truth

- ICCT/Site master: native Cacti `sites`
- Site coordinates: native Cacti `sites.latitude` / `sites.longitude`
- Device-to-site relation: native Cacti `host.site_id`
- Device availability: native Cacti `host.status` / `host.disabled`
- GNSS history only: plugin table `plugin_icct_map_site_positions`
- Map audit only: plugin table `plugin_icct_map_audit`

No duplicate site or device master is created.

## Viewer

- India-only Leaflet viewport.
- Local states/UT GeoJSON; no Internet at runtime.
- Drag/pan, wheel/pinch zoom, Fit India.
- Minimize/restore map height.
- Maximize/restore workspace.
- Show/hide details panel.
- Adaptive state labels; small states/UTs appear as the operator zooms in.
- Cacti Site markers and Cacti device-health aggregation.
- AJAX health refresh without changing the operator's current viewport.

## v1.6.0 critical fixes

- Cacti 1.2.30 `sites` does **not** have a `disabled` column. v1.5.0 selected that non-existent column, which could make the map report zero Sites. v1.6.0 uses the exact native Cacti 1.2.30 Sites schema.
- Permanent state labels are no longer bound to every geometry feature. They are generated once per unique state/UT and shown adaptively by zoom level.
- Settings and runtime pages explicitly suppress Cacti's full-page refresh timer.
