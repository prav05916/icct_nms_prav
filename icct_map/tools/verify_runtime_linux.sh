#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
for f in vendor/leaflet/leaflet.js vendor/leaflet/leaflet.css data/india_states.geojson; do
  if [ -s "$ROOT/$f" ]; then echo "READY   $f"; else echo "MISSING $f"; exit 1; fi
done
python3 - <<'PY' "$ROOT/data/india_states.geojson"
import json,sys
p=sys.argv[1]
d=json.load(open(p,encoding='utf-8'))
assert d.get('type')=='FeatureCollection' and len(d.get('features',[]))>=30
print('GeoJSON validation OK')
PY
echo "Map runtime is ready for offline use."
