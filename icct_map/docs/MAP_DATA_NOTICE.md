# India state/UT boundary data

The viewer requires `data/india_states.geojson`, an RFC 7946 GeoJSON `FeatureCollection`.

For development/qualification, `tools/prepare_runtime_windows.ps1` downloads a web-ready dataset that advertises 36 first-level administrative features (28 states + 8 union territories). This is used so the UI can be tested with a realistic state/UT map instead of a crude country outline.

**Production boundary-control rule:** the downloaded development dataset is not asserted by this plugin to be Survey-of-India / project-authority-approved boundary data. For final operational deployment, replace `data/india_states.geojson` with the boundary dataset approved by the customer/project authority. The renderer reads standard GeoJSON, so no application code changes are required.

Do not add Internet tile services to the office system. The state layer and Leaflet runtime are served locally.
