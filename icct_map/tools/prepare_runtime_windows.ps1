$ErrorActionPreference = "Stop"
$PluginRoot = Split-Path -Parent $PSScriptRoot
$LeafletDir = Join-Path $PluginRoot "vendor\leaflet"
$DataDir = Join-Path $PluginRoot "data"
New-Item -ItemType Directory -Force $LeafletDir, $DataDir | Out-Null

function Download-WithFallback([string[]]$Urls, [string]$Target) {
    $last = $null
    foreach ($url in $Urls) {
        try {
            Write-Host "Downloading $url"
            Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $Target -TimeoutSec 90
            if ((Test-Path $Target) -and ((Get-Item $Target).Length -gt 100)) { return }
        } catch { $last = $_ }
    }
    throw "Failed to download $Target. Last error: $last"
}

function Download-ValidatedIndiaStates([string[]]$Urls, [string]$Target) {
    $last = $null
    foreach ($url in $Urls) {
        $tmp = "$Target.download"
        Remove-Item $tmp -Force -ErrorAction SilentlyContinue
        try {
            Write-Host "Trying India states/UT GeoJSON: $url"
            Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $tmp -TimeoutSec 120
            if (-not (Test-Path $tmp) -or (Get-Item $tmp).Length -lt 1000) {
                throw "Downloaded file is unexpectedly small."
            }
            $geo = Get-Content $tmp -Raw | ConvertFrom-Json
            if ($geo.type -ne "FeatureCollection" -or $null -eq $geo.features -or $geo.features.Count -lt 30) {
                throw "GeoJSON does not contain at least 30 state/UT features."
            }
            Move-Item $tmp $Target -Force
            Write-Host "Accepted India states/UT dataset with $($geo.features.Count) features." -ForegroundColor Green
            return
        } catch {
            $last = $_
            Remove-Item $tmp -Force -ErrorAction SilentlyContinue
            Write-Host "Dataset rejected: $($_.Exception.Message)" -ForegroundColor Yellow
        }
    }
    throw "Unable to obtain a valid India states/UT GeoJSON. Last error: $last"
}

Download-WithFallback @(
  "https://unpkg.com/leaflet@1.9.4/dist/leaflet.js",
  "https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"
) (Join-Path $LeafletDir "leaflet.js")

Download-WithFallback @(
  "https://unpkg.com/leaflet@1.9.4/dist/leaflet.css",
  "https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css"
) (Join-Path $LeafletDir "leaflet.css")

# Qualification sources only. For final operational use, replace with the project/customer-approved India boundary dataset.
# The first source is preferred because it contains a more detailed all-India GeoJSON. Every candidate is validated before use.
Download-ValidatedIndiaStates @(
  "https://cdn.jsdelivr.net/gh/udit-001/india-maps-data@HEAD/geojson/india.geojson",
  "https://raw.githubusercontent.com/iamaanahmad/GeoJson-of-Indian-States/master/indian-states.geojson",
  "https://cdn.jsdelivr.net/gh/iamaanahmad/GeoJson-of-Indian-States@master/indian-states.geojson"
) (Join-Path $DataDir "india_states.geojson")

Write-Host ""
Write-Host "Runtime preparation complete." -ForegroundColor Green
Write-Host "Leaflet 1.9.4 is local under vendor\leaflet."
Write-Host "India state/UT GeoJSON is local under data\india_states.geojson."
Write-Host "The topology viewer no longer requires Internet access."
