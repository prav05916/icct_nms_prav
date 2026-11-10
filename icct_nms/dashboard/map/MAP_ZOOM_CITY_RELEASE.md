# ICCT Map Zoom/City release

Map-only replacement for ICCT NMS v1.3.0.

## Immediate visible improvement

- India state names remain at country/state zoom.
- Zoom 5: major metros appear.
- Zoom 6: state capitals and large regional cities appear.
- Zoom 7: additional important cities appear.
- Zoom 8+: additional towns/cities are rendered around the current viewport.
- Labels are de-cluttered so high-priority city labels remain visible first.
- City/town data is bundled locally; no Internet request is used.
- Existing Cacti Site markers and rich site hover/click details remain unchanged.
- Existing optional local XYZ/GeoServer detailed basemap support remains unchanged.

## Production boundary

The city/town label layer is not a road/street database. If road/building-level detail is
required at deep zoom, copy an approved local XYZ tile tree into `tiles/{z}/{x}/{y}.png`
or configure the approved local GeoServer. Public Internet map tiles are deliberately
not enabled.
