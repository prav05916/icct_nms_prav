$ErrorActionPreference = "Stop"
$PluginRoot = Split-Path -Parent $PSScriptRoot
& (Join-Path $PSScriptRoot "verify_runtime_windows.ps1")
$Parent = Split-Path -Parent $PluginRoot
$Target = Join-Path $Parent "icct_map_complete_offline.zip"
if (Test-Path $Target) { Remove-Item $Target -Force }
Compress-Archive -Path $PluginRoot -DestinationPath $Target -CompressionLevel Optimal
Write-Host "Offline package created:" -ForegroundColor Green
Write-Host $Target
