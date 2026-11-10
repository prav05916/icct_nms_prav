<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */
chdir('../..');
include('./include/auth.php');
include_once($config['base_path'] . '/plugins/icct_map/lib/functions.php');
include_once($config['base_path'] . '/plugins/icct_map/include/admin_nav.php');
if (!icct_map_can_manage()) { header('Location: ' . $config['url_path'] . 'plugins/icct_map/icct_map.php'); exit; }
icct_map_disable_full_page_refresh('icct_map_runtime.php');
$runtime=icct_map_runtime_status();$plugin_url=$config['url_path'].'plugins/icct_map';
top_header();icct_map_stop_browser_refresh_timer();print '<link rel="stylesheet" href="'.html_escape($plugin_url.'/css/icct_map_v160.css').'">';print '<div class="icct-map-page">';icct_map_admin_nav('runtime');
?>
<div class="icct-map-toolbar"><div><div class="icct-map-title">Offline Map Runtime</div><div class="icct-map-subtitle">Runtime assets must be prepared on the connected Windows qualification machine before deployment to offline RHEL.</div></div></div>
<div class="icct-admin-card"><h3>Runtime status: <span class="<?php print $runtime['ready']?'ok-text':'bad-text'; ?>"><?php print $runtime['ready']?'READY':'NOT READY'; ?></span></h3><table class="icct-simple-table"><thead><tr><th>Asset</th><th>Path</th><th>Status</th><th>Size</th></tr></thead><tbody><?php foreach($runtime['files'] as $row){ ?><tr><td><?php print icct_map_h($row['label']); ?></td><td><code><?php print icct_map_h($row['relative']); ?></code></td><td><?php print $row['ok']?'<span class="ok-text">Ready</span>':'<span class="bad-text">Missing</span>'; ?></td><td><?php print $row['ok']?number_format((int)$row['bytes']).' bytes':'—'; ?></td></tr><?php } ?></tbody></table><p><strong>Administrative features detected:</strong> <?php print (int)$runtime['feature_count']; ?>. Production validation requires at least 30 state/UT features.</p></div>
<div class="icct-admin-grid"><div class="icct-admin-card"><h3>Windows preparation</h3><p>Run once on the Internet-connected Windows development laptop:</p><code>powershell -NoProfile -ExecutionPolicy Bypass -File "C:\Apache24\htdocs\cacti\plugins\icct_map\tools\prepare_complete_offline_package_windows.ps1"</code><p>This prepares and validates the local runtime and state map, then builds the complete offline RHEL package.</p></div><div class="icct-admin-card"><h3>Offline RHEL package</h3><p>The command creates <code>icct_map_complete_offline.zip</code> beside the plugin folder.</p><p>Transfer that ZIP to RHEL. Do not download Leaflet, map data, or CDN assets on the offline server.</p></div></div>
<div class="icct-admin-card"><h3>Production boundary data</h3><p>The plugin renders a local RFC 7946 GeoJSON state layer. If the project authority provides an approved India boundary dataset, replace <code>data/india_states.geojson</code> with that approved FeatureCollection; no PHP/JavaScript changes are required.</p></div>
</div><?php bottom_footer(); ?>
