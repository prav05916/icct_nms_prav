# v1.2.14 detailed offline basemap

Map View still uses native Cacti Sites (`sites.latitude`, `sites.longitude`) and `host.site_id`. v1.2.14 adds a detailed raster basemap source so zooming can reveal locality/road/building detail when the approved map dataset provides it.

Two fully offline production modes are supported:

### Local XYZ tile tree

Provision an approved tile package on the RHEL server, for example:

```text
/opt/icct-nms-map/tiles/{z}/{x}/{y}.png
```

The plugin auto-detects that path when numeric zoom directories exist. You can also set:

```php
$config['nms_map_xyz_dir'] = '/opt/icct-nms-map/tiles';
$config['nms_map_xyz_min_zoom'] = 2;
$config['nms_map_xyz_max_zoom'] = 18;
$config['nms_map_xyz_extension'] = 'png';
```

### Local GeoServer WMS

```php
$config['nms_geoserver_wms_url'] = 'http://127.0.0.1:8080/geoserver/wms';
$config['nms_geoserver_layer'] = 'workspace:approved_basemap';
```

The browser never contacts those files/services directly; authenticated plugin endpoints relay the tiles. Public Internet tiles are not enabled by default.

If neither detailed basemap source is installed, the bundled India state/UT GeoJSON remains available but cannot provide Google-like street detail. Roads/buildings require actual approved map tile data.

---

# ICCT NMS v1.2.13 — Map View topology

## Production data ownership

Map View is part of:

```text
/cacti/plugins/icct_nms/dashboard/controllers/topology.php
```

It reuses native Cacti data instead of maintaining a duplicate site master:

```text
Cacti sites.id/name/address/city/state/country/latitude/longitude
                     ^
                     |
                host.site_id
```

Only devices the logged-in Cacti account is permitted to access contribute to Map View.

## Offline frontend

- `shared/assets/vendor/leaflet/leaflet.js`
- `shared/assets/vendor/leaflet/leaflet.css`
- `shared/assets/maps/india-states.json`
- `dashboard/map/js/map.js`
- `dashboard/map/css/map.css`
- `dashboard/map/services/map_service.php`
- `dashboard/map/templates/map.php`

No CDN, Google Maps, OpenStreetMap tile request, external font, or Internet connection is required for the India state/site view.

## Operator behavior

The Dashboard page exposes:

```text
Topology | Map View | Rack View | Image View
```

Map View provides:

- India state/UT polygons and labels from bundled local data.
- Native Cacti Site markers at `sites.latitude/sites.longitude`.
- Marker state from authorized Cacti device statuses.
- 30-second lightweight AJAX refresh for marker status/device totals.
- Rich hover detail: site name, location, coordinates, overall status, device totals/up/down/disabled and alarm counts by severity.
- Click detail dialog with the same operational summary and a drill-down to the site's device topology.
- Fit India, zoom and fullscreen controls.
- Keyboard-accessible markers.
- On-demand fresh site summary retrieval; expensive alarm/detail data is not polled for every site every 30 seconds.

## Site status rule

- Offline if any device is Down.
- Online when all managed devices at the site are Up.
- Disabled when every device at the site is Disabled.
- Other for mixed/unknown/no-device conditions.

Alarm totals are aggregated from the existing ICCT fault observations for devices at that site.

## Configure a site

Use native Cacti Sites. Provide a meaningful site name and valid latitude/longitude, then associate managed devices through native `host.site_id`.

Example Bengaluru coordinate for lab testing only:

```text
Latitude:  12.9716
Longitude: 77.5946
```

Do not use example coordinates as operational GNSS/site truth.

## Windows test

1. Create/edit at least two Cacti Sites with valid test coordinates.
2. Associate LWM/device records to those sites.
3. Open Dashboard -> Map View.
4. Verify both markers appear.
5. Hover a marker and verify location, coordinates, status, device counts and alarm counts.
6. Click marker and verify site summary dialog and View Topology drill-down.
7. Change a Cacti device's actual status through a real poll/monitoring test; after refresh, marker state should reflect the new Cacti status.
8. Remove/zero a test site's coordinates; it must no longer be plotted after reload.

## Offline RHEL test

No map-specific Internet setup is required. After the plugin is copied/upgraded:

```bash
ls -lh /usr/share/cacti/plugins/icct_nms/shared/assets/vendor/leaflet/leaflet.js
ls -lh /usr/share/cacti/plugins/icct_nms/shared/assets/vendor/leaflet/leaflet.css
ls -lh /usr/share/cacti/plugins/icct_nms/shared/assets/maps/india-states.json
```

Confirm Apache can read the plugin and SELinux contexts match the Cacti tree:

```bash
sudo chown -R --reference=/usr/share/cacti/plugins/index.php /usr/share/cacti/plugins/icct_nms
sudo restorecon -RFv /usr/share/cacti/plugins/icct_nms
```

Then open Dashboard -> Map View from a browser connected to the offline network.

### RHEL scenarios

1. **Two located sites:** both markers render at correct coordinates.
2. **Hover:** operational summary appears without a click.
3. **Device down:** after Cacti marks a device Down, site marker changes to Offline/red.
4. **Mixed device state:** site reports Other when appropriate.
5. **All disabled:** site marker reports Disabled.
6. **Alarm aggregation:** active device faults are reflected in hover/dialog severity totals.
7. **Access control:** a user without access to a device must not see that device included in site totals.
8. **Missing coordinates:** site remains absent from the geographic plot rather than being placed at an invented position.
9. **Offline proof:** disconnect Internet/default route and reload; local Leaflet/state assets and site markers continue to work.
10. **Drill-down:** click marker -> View Topology opens the same site's logical topology.
11. **Live refresh:** leave Map View open and verify marker/device totals update through the authenticated local `map_status` endpoint without a full page reload.

## Production boundary

The map uses native Cacti Sites as the current site position source. If later GNSS history/tracking is required, store history in a plugin-owned table while keeping the current authoritative position synchronized with the approved Cacti Site coordinates; do not duplicate the site identity itself.
