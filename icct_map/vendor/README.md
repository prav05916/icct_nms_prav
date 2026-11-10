# Local map runtime

The production viewer loads Leaflet only from this directory.
Run `tools/prepare_runtime_windows.ps1` on the connected qualification/development machine, then build the offline package with `tools/build_offline_package_windows.ps1`.
The office RHEL system must not download runtime assets.
