# Production Test Checklist — v2.3.0

1. Plugin installs/upgrades and enables on Cacti 1.2.30.
2. Management → Rack Topology opens without a full-page refresh loop.
3. Create five racks with different unit counts.
4. All five racks render horizontally in one workspace.
5. Rack Width and Rack Height sliders resize all racks.
6. Fit All makes the rack set fit the available stage width when practical.
7. Devices tab shows only unplaced Cacti hosts and their status.
8. Peripherals tab shows the local passive-item library.
9. Drag a 1U device into an empty rack unit.
10. Drag a multi-U device and confirm occupied range is enforced.
11. Attempt overlap: backend must reject it.
12. Move a placed device between racks.
13. Click a device, change U-height/stencil in inspector and save.
14. Place/move/remove a peripheral.
15. Device status refreshes via AJAX without reloading the full Cacti page.
16. Audit Log uses pagination and detail view.
17. No Cacti core file is changed.
