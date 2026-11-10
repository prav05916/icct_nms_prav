# ICCT NMS Map folder replacement — zoom/city-label build

Replace only:

`icct_nms/dashboard/map/`

with this `map/` folder. No DB/schema change is required.

## What changed

- State labels are used at country/state zoom.
- From zoom 5 onward, major city names appear automatically.
- Further zoom levels progressively add regional cities and towns so the map no longer remains a state-name-only view.
- City labels are bundled locally in `data/india-cities.json`; no Internet/CDN request is made.
- Existing Cacti Site markers, status colours, hover site details, alarm/device counts, 30-second status refresh and topology drill-down are unchanged.
- Smooth Leaflet pan/zoom and a metric distance scale are enabled.
- If an approved detailed local XYZ tile tree or GeoServer WMS is configured, road/building/locality imagery remains underneath these labels.

## Important production boundary

The bundled city/town point layer adds geographic labels, but it is not a street-routing database. To obtain Google-like roads, streets and building detail at deep zoom while remaining offline, stage an approved local XYZ tile set or local GeoServer and configure the existing ICCT map basemap settings. Public Internet tiles are deliberately not enabled by this folder.

## Quick check

1. Replace the map folder and hard-refresh the browser (`Ctrl+F5`).
2. Open Dashboard -> Map View.
3. Fit India: states are visible.
4. Zoom to 5-6: metro/state-capital labels appear.
5. Zoom to 7-8: more regional city labels appear.
6. Zoom to 9+: additional town labels appear around the current viewport.
7. Hover/click a Cacti Site marker: existing operational site details continue to work.
