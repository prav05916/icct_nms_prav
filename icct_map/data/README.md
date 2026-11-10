# India states/UT map data

`india_states.geojson` is the only geographic boundary file loaded by the viewer. It must be an RFC 7946 GeoJSON `FeatureCollection` containing Indian state/UT polygons.

For qualification, `tools/prepare_runtime_windows.ps1` can fetch the pinned development dataset documented in `docs/MAP_DATA_NOTICE.md`. For final operational deployment, replace it with the customer/project-authority-approved India state/UT GeoJSON when supplied. No PHP/JavaScript changes are needed.
