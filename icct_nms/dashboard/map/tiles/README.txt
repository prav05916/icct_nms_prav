ICCT NMS OFFLINE XYZ BASEMAP
============================

This map folder now includes an offline city/town label layer, so city names appear
progressively when you zoom even when no street-map tiles are installed.

For Google-like roads, streets, building footprints and locality detail at deep zoom,
use an approved/licensed offline raster basemap. A nationwide street-map tile tree can
be many gigabytes, so it is intentionally not bundled inside the PHP plugin ZIP.

FASTEST OPTION (NO CONFIG CHANGE)
---------------------------------
Copy an approved XYZ tile tree directly below this directory:

  icct_nms/dashboard/map/tiles/{z}/{x}/{y}.png

Example:
  map/tiles/5/23/14.png
  map/tiles/6/46/28.png

If numeric zoom directories are present, ICCT NMS auto-detects this local tile tree.

EXTERNAL TILE DIRECTORY OPTION
------------------------------
Add to Cacti configuration (or the approved site-local include):

  $config['nms_map_xyz_dir'] = '/opt/icct-nms-map/tiles';
  $config['nms_map_xyz_min_zoom'] = 2;
  $config['nms_map_xyz_max_zoom'] = 18;
  $config['nms_map_xyz_extension'] = 'png';

Tile tree format:
  /opt/icct-nms-map/tiles/{z}/{x}/{y}.png

LOCAL GEOSERVER / WMS OPTION
----------------------------
  $config['nms_geoserver_wms_url'] = 'http://127.0.0.1:8080/geoserver/wms';
  $config['nms_geoserver_layer'] = 'workspace:approved_basemap';

The browser never contacts a public map provider by default. Cacti authenticates tile
requests and the plugin relays only local basemap data. If no detailed basemap is
configured, the bundled India states plus bundled city/town labels remain available.
