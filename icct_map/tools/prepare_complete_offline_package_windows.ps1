$ErrorActionPreference = "Stop"
$Tools = $PSScriptRoot
Write-Host "Step 1/3 - Preparing local Leaflet and India state/UT map data..." -ForegroundColor Cyan
& (Join-Path $Tools "prepare_runtime_windows.ps1")
Write-Host "Step 2/3 - Verifying runtime..." -ForegroundColor Cyan
& (Join-Path $Tools "verify_runtime_windows.ps1")
Write-Host "Step 3/3 - Building the complete offline package..." -ForegroundColor Cyan
& (Join-Path $Tools "build_offline_package_windows.ps1")
Write-Host "Complete. Transfer icct_map_complete_offline.zip to the disconnected RHEL system." -ForegroundColor Green
