$ErrorActionPreference = "Stop"
$PluginRoot = Split-Path -Parent $PSScriptRoot
$files = @(
  "vendor\leaflet\leaflet.js",
  "vendor\leaflet\leaflet.css",
  "data\india_states.geojson"
)
$ok = $true
foreach ($rel in $files) {
    $p = Join-Path $PluginRoot $rel
    if ((Test-Path $p) -and ((Get-Item $p).Length -gt 100)) {
        Write-Host "OK      $p"
    } else {
        Write-Host "MISSING $p" -ForegroundColor Red
        $ok = $false
    }
}
if ($ok) {
    $geo = Get-Content (Join-Path $PluginRoot "data\india_states.geojson") -Raw | ConvertFrom-Json
    if ($geo.type -ne "FeatureCollection" -or $geo.features.Count -lt 30) { throw "India state GeoJSON validation failed." }
    Write-Host "Map runtime is ready for offline use." -ForegroundColor Green
} else { throw "Map runtime is incomplete." }
