<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */
chdir('../..');
include('./include/auth.php');
include_once($config['base_path'] . '/plugins/icct_map/lib/functions.php');

/* Prevent Cacti full-document refresh. Topology health refresh is AJAX-only. */
icct_map_disable_full_page_refresh('icct_map.php');

$snapshot = icct_map_sites_snapshot();
$refresh = (int)read_config_option('icct_map_refresh_seconds');
if (!in_array($refresh, [15,30,60,120,300], true)) $refresh = 30;
$show_labels = read_config_option('icct_map_show_labels') === 'on';
$can_manage = icct_map_can_manage();
$runtime = icct_map_runtime_status();
$plugin_url = $config['url_path'] . 'plugins/icct_map';

$cfg = [
    'baseUrl' => $plugin_url,
    'cactiUrl' => $config['url_path'],
    'refreshSeconds' => $refresh,
    'showLabels' => $show_labels,
    'canManage' => $can_manage,
    'initial' => $snapshot,
    'statesUrl' => $plugin_url . '/data/india_states.geojson',
];

top_header();
icct_map_stop_browser_refresh_timer();
print '<link rel="stylesheet" href="' . html_escape($plugin_url . '/css/icct_map_v160.css') . '">';
if ($runtime['ready']) {
    print '<link rel="stylesheet" href="' . html_escape($plugin_url . '/vendor/leaflet/leaflet.css') . '">';
}
?>
<div class="icct-map-page">
    <div class="icct-map-toolbar">
        <div>
            <div class="icct-map-title">India Geographic Topology</div>
            <div class="icct-map-subtitle">Native Cacti Sites • state/UT boundaries • GNSS positions • live Cacti device health</div>
        </div>
        <div class="icct-map-filters">
            <label>Status
                <select id="icct-status-filter">
                    <option value="">All</option>
                    <option value="up">Up</option>
                    <option value="down">Critical</option>
                    <option value="recovering">Recovering</option>
                    <option value="unknown">Unknown</option>
                    <option value="disabled">Disabled</option>
                </select>
            </label>
            <label>Search <input id="icct-map-search" type="search" placeholder="Site / city / state"></label>
        </div>
        <span class="spacer"></span>
        <span class="updated">Updated <b id="icct-map-refreshed"><?php print icct_map_h($snapshot['refreshed_at']); ?></b></span>
        <?php if ($can_manage) { ?><a class="button" href="<?php print html_escape($plugin_url . '/icct_map_admin.php'); ?>">Settings</a><?php } ?>
    </div>

    <div class="icct-map-summary">
        <div><span>ICCT Sites</span><strong data-summary="total_sites"><?php print (int)$snapshot['summary']['total_sites']; ?></strong></div>
        <div><span>Mapped</span><strong data-summary="mapped_sites"><?php print (int)$snapshot['summary']['mapped_sites']; ?></strong></div>
        <div class="summary-up"><span>Up</span><strong data-summary="up"><?php print (int)$snapshot['summary']['up']; ?></strong></div>
        <div class="summary-down"><span>Critical</span><strong data-summary="down"><?php print (int)$snapshot['summary']['down']; ?></strong></div>
        <div><span>Missing Position</span><strong data-summary="missing_coordinates"><?php print (int)$snapshot['summary']['missing_coordinates']; ?></strong></div>
    </div>

    <?php if (!$runtime['ready']) { ?>
        <div class="icct-map-error"><strong>Offline India map runtime is not prepared.</strong> Local Leaflet files and <code>data/india_states.geojson</code> are required. <?php if ($can_manage) { ?><a href="<?php print html_escape($plugin_url . '/icct_map_runtime.php'); ?>">Runtime details</a><?php } ?></div>
    <?php } else { ?>
        <?php if ((int)$snapshot['summary']['missing_coordinates'] > 0) { ?>
            <div class="icct-map-warning"><strong><?php print (int)$snapshot['summary']['missing_coordinates']; ?></strong> Cacti site(s) are not plotted because latitude/longitude is missing or outside the India operational extent. <?php if ($can_manage) { ?><a href="<?php print html_escape($config['url_path'] . 'sites.php'); ?>">Edit Cacti Sites</a><?php } ?></div>
        <?php } ?>

        <div id="icct-map-workspace" class="icct-map-workspace">
            <div class="icct-map-stage">
                <div class="icct-map-stage-head">
                    <div class="map-hint">Drag to pan • wheel/pinch to zoom • India-only bounds</div>
                    <div class="map-actions">
                        <button type="button" id="icct-map-fit" title="Fit India">Fit India</button>
                        <button type="button" id="icct-map-compact" title="Minimize map height">Minimize</button>
                        <button type="button" id="icct-map-details-toggle" title="Show or hide the details panel">Hide Details</button>
                        <button type="button" id="icct-map-maximize" title="Maximize map workspace">Maximize</button>
                    </div>
                </div>
                <div id="icct-map-canvas" class="icct-map-canvas" aria-label="India geographic topology"></div>
                <div class="icct-map-legend">
                    <strong>ICCT health</strong>
                    <span><i class="dot status-up"></i>Up</span>
                    <span><i class="dot status-down"></i>Critical</span>
                    <span><i class="dot status-recovering"></i>Recovering</span>
                    <span><i class="dot status-unknown"></i>Unknown</span>
                    <span><i class="dot status-disabled"></i>Disabled</span>
                </div>
            </div>

            <aside id="icct-site-drawer" class="icct-site-drawer">
                <div class="drawer-empty"><strong>Select an ICCT site</strong><span>Click a marker to view native Cacti site/device health.</span></div>
            </aside>
        </div>

        <script id="icct-map-config" type="application/json"><?php print json_encode($cfg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?></script>
        <script src="<?php print html_escape($plugin_url . '/vendor/leaflet/leaflet.js'); ?>"></script>
        <script src="<?php print html_escape($plugin_url . '/js/icct_map_v160.js'); ?>"></script>
    <?php } ?>
</div>
<?php bottom_footer(); ?>
