<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */
chdir('../..');
include('./include/auth.php');
include_once($config['base_path'] . '/plugins/icct_rack/lib/functions.php');

icct_rack_disable_full_page_refresh('icct_rack.php');

$racks = icct_rack_get_racks_with_items(true);
$totals = icct_rack_totals_from_racks($racks);
$available_hosts = icct_rack_can_manage() ? icct_rack_get_available_hosts() : [];
$peripherals = icct_rack_peripheral_catalog();
$categories = icct_rack_categories();
$refresh_seconds = (int)read_config_option('icct_rack_refresh_seconds');
if ($refresh_seconds < 0) $refresh_seconds = 30;
$can_manage = icct_rack_can_manage();
$plugin_url = $config['url_path'] . 'plugins/icct_rack';

top_header();
icct_rack_stop_browser_refresh_timer();
print '<link rel="stylesheet" type="text/css" href="' . html_escape($plugin_url . '/css/icct_rack_v230.css') . '">';
print '<div id="icct-rack-config" data-base-url="' . html_escape($plugin_url) . '" data-refresh-seconds="' . (int)$refresh_seconds . '" data-can-manage="' . ($can_manage ? '1' : '0') . '" data-rack-count="' . count($racks) . '"></div>';
?>
<div class="icct-rack-page" id="icct-rack-page">
    <div class="icct-rack-topbar">
        <div>
            <div class="icct-page-title">Rack Topology</div>
            <div class="icct-page-subtitle">Front view • all enabled racks • live Cacti status</div>
        </div>
        <div class="icct-rack-topbar-spacer"></div>
        <div class="icct-view-controls">
            <label>Rack width
                <input id="icct-rack-width" type="range" min="140" max="320" step="10" value="210">
            </label>
            <label>Rack height
                <input id="icct-rack-uheight" type="range" min="14" max="32" step="1" value="22">
            </label>
            <button type="button" class="icct-button" id="icct-fit-racks">Fit All</button>
            <button type="button" class="icct-button" id="icct-reset-view">Reset</button>
            <?php if ($can_manage) { ?>
                <a class="icct-button primary" href="<?php print html_escape($plugin_url . '/icct_rack_admin.php'); ?>">Manage Racks</a>
            <?php } ?>
        </div>
    </div>

    <div class="icct-status-row">
        <span class="icct-overview-pill"><strong><?php print (int)$totals['racks']; ?></strong> Racks</span>
        <span class="icct-overview-pill"><strong><?php print (int)$totals['devices']; ?></strong> Devices</span>
        <span class="icct-overview-pill"><strong><?php print (int)$totals['peripherals']; ?></strong> Peripherals</span>
        <span class="icct-status-pill status-up">Up <span data-summary-key="up"><?php print (int)$totals['up']; ?></span></span>
        <span class="icct-status-pill status-down">Down <span data-summary-key="down"><?php print (int)$totals['down']; ?></span></span>
        <span class="icct-status-pill status-recovering">Recovering <span data-summary-key="recovering"><?php print (int)$totals['recovering']; ?></span></span>
        <span class="icct-status-pill status-error">Error <span data-summary-key="error"><?php print (int)$totals['error']; ?></span></span>
        <span class="icct-status-pill status-unknown">Unknown <span data-summary-key="unknown"><?php print (int)$totals['unknown']; ?></span></span>
        <span class="icct-refresh-stamp">Updated <strong id="icct-rack-refreshed"><?php print date('H:i:s'); ?></strong></span>
    </div>

    <div class="icct-rack-workspace <?php print $can_manage ? 'editable' : 'readonly'; ?>">
        <?php if ($can_manage) { ?>
        <aside class="icct-library-panel">
            <div class="icct-library-tabs">
                <button type="button" class="icct-library-tab active" data-library-tab="devices">Devices</button>
                <button type="button" class="icct-library-tab" data-library-tab="peripherals">Peripherals</button>
            </div>

            <div class="icct-library-pane active" data-library-pane="devices">
                <div class="icct-library-heading">
                    <strong>Available Devices</strong>
                    <span><?php print count($available_hosts); ?></span>
                </div>
                <input type="text" id="icct-device-search" class="icct-input-full" placeholder="Search device / IP...">
                <div class="icct-device-library" id="icct-device-library">
                    <?php if (!cacti_sizeof($available_hosts)) { ?>
                        <div class="icct-empty-mini">All Cacti devices are already placed.</div>
                    <?php } else { foreach ($available_hosts as $host) {
                        $category = icct_rack_guess_category($host);
                        $status = icct_rack_status_info($host);
                        $stencil = icct_rack_stencil_file($category);
                    ?>
                        <div class="icct-library-card <?php print icct_rack_h($status['class']); ?>" draggable="true"
                             data-item-type="device"
                             data-host-id="<?php print (int)$host['id']; ?>"
                             data-category="<?php print icct_rack_h($category); ?>"
                             data-search="<?php print icct_rack_h(strtolower(($host['description'] ?? '') . ' ' . ($host['hostname'] ?? ''))); ?>">
                            <div class="icct-library-thumb"><img src="<?php print html_escape($plugin_url . '/images/stencils/' . $stencil); ?>" alt=""></div>
                            <div class="icct-library-body">
                                <div class="icct-library-title"><?php print icct_rack_h($host['description'] ?: ('Host #' . (int)$host['id'])); ?></div>
                                <div class="icct-library-sub"><?php print icct_rack_h($host['hostname'] ?: ''); ?></div>
                                <div class="icct-library-meta">
                                    <label>Unit size
                                        <select class="icct-height-select">
                                            <?php for ($u = 1; $u <= 20; $u++) { ?><option value="<?php print (int)$u; ?>"><?php print (int)$u; ?>U</option><?php } ?>
                                        </select>
                                    </label>
                                    <span class="icct-mini-status"><i></i><?php print icct_rack_h($status['label']); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php } } ?>
                </div>
            </div>

            <div class="icct-library-pane" data-library-pane="peripherals">
                <div class="icct-library-heading"><strong>Peripheral Library</strong><span><?php print count($peripherals); ?></span></div>
                <div class="icct-device-library">
                    <?php foreach ($peripherals as $key => $peripheral) { ?>
                        <div class="icct-library-card peripheral" draggable="true"
                             data-item-type="peripheral"
                             data-peripheral-key="<?php print icct_rack_h($key); ?>"
                             data-stencil="<?php print icct_rack_h($peripheral['stencil']); ?>">
                            <div class="icct-library-thumb"><img src="<?php print html_escape($plugin_url . '/images/stencils/' . icct_rack_stencil_file($peripheral['stencil'])); ?>" alt=""></div>
                            <div class="icct-library-body">
                                <div class="icct-library-title"><?php print icct_rack_h($peripheral['label']); ?></div>
                                <div class="icct-library-sub">Passive rack item</div>
                                <div class="icct-library-meta">
                                    <label>Unit size
                                        <select class="icct-height-select">
                                            <?php for ($u = 1; $u <= 20; $u++) { ?><option value="<?php print (int)$u; ?>"<?php print (int)$peripheral['default_u'] === $u ? ' selected' : ''; ?>><?php print (int)$u; ?>U</option><?php } ?>
                                        </select>
                                    </label>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </aside>
        <?php } ?>

        <main class="icct-rack-stage" id="icct-rack-stage">
            <?php if (!cacti_sizeof($racks)) { ?>
                <div class="icct-rack-empty">No enabled rack exists. <?php if ($can_manage) { ?><a href="<?php print html_escape($plugin_url . '/icct_rack_admin.php'); ?>">Create your first rack</a>.<?php } ?></div>
            <?php } else { ?>
            <div class="icct-rack-strip" id="icct-rack-strip">
                <?php foreach ($racks as $rack) { ?>
                    <section class="icct-rack-card" data-rack-card data-rack-id="<?php print (int)$rack['id']; ?>" data-rack-units="<?php print (int)$rack['rack_units']; ?>">
                        <header class="icct-rack-card-header">
                            <div>
                                <div class="icct-rack-name"><?php print icct_rack_h($rack['name']); ?></div>
                                <div class="icct-rack-card-sub"><?php print (int)$rack['rack_units']; ?>U • <?php print (int)$rack['free_u']; ?>U free</div>
                            </div>
                            <div class="icct-rack-health">
                                <?php if ((int)$rack['status_counts']['down'] + (int)$rack['status_counts']['error'] > 0) { ?><span class="health-dot bad" title="Rack contains down/error devices"></span><?php } else { ?><span class="health-dot good" title="No down/error device"></span><?php } ?>
                            </div>
                        </header>

                        <div class="icct-rack-shell">
                            <div class="icct-rack-top-cap"></div>
                            <div class="icct-rack-side-depth"></div>
                            <div class="icct-rack-cabinet" data-rack-dropzone data-rack-id="<?php print (int)$rack['id']; ?>" style="height:calc(<?php print (int)$rack['rack_units']; ?> * var(--u-height));">
                                <?php for ($u = (int)$rack['rack_units']; $u >= 1; $u--) { ?>
                                    <div class="icct-rack-urow" data-rack-id="<?php print (int)$rack['id']; ?>" data-u="<?php print (int)$u; ?>" style="bottom:calc(<?php print (int)$u - 1; ?> * var(--u-height)); height:var(--u-height);">
                                        <span class="u-label"><?php print (int)$u; ?></span>
                                    </div>
                                <?php } ?>

                                <?php foreach ($rack['placements'] as $p) {
                                    $status = $p['host_description'] === null ? ['key'=>'missing','label'=>'Missing','class'=>'status-missing'] : icct_rack_status_info(['status'=>$p['host_status'],'disabled'=>$p['host_disabled']]);
                                    $title = $p['label'] !== '' ? $p['label'] : ($p['host_description'] ?: ('Host #' . (int)$p['host_id']));
                                    $stencil = icct_rack_stencil_file($p['category']);
                                ?>
                                    <div class="icct-rack-item device <?php print icct_rack_h($status['class']); ?>"
                                         draggable="<?php print $can_manage ? 'true' : 'false'; ?>"
                                         data-item-type="device"
                                         data-placement-id="<?php print (int)$p['id']; ?>"
                                         data-host-id="<?php print (int)$p['host_id']; ?>"
                                         data-rack-id="<?php print (int)$rack['id']; ?>"
                                         data-start-u="<?php print (int)$p['start_u']; ?>"
                                         data-height-u="<?php print (int)$p['height_u']; ?>"
                                         data-category="<?php print icct_rack_h($p['category']); ?>"
                                         data-label="<?php print icct_rack_h($title); ?>"
                                         style="bottom:calc(<?php print (int)$p['start_u'] - 1; ?> * var(--u-height) + 3px); height:calc(<?php print (int)$p['height_u']; ?> * var(--u-height) - 6px);">
                                        <img class="icct-rack-item-image" src="<?php print html_escape($plugin_url . '/images/stencils/' . $stencil); ?>" alt="">
                                        <div class="icct-rack-item-overlay">
                                            <span class="item-name"><?php print icct_rack_h($title); ?></span>
                                            <span class="item-status"><?php print icct_rack_h($status['label']); ?></span>
                                        </div>
                                    </div>
                                <?php } ?>

                                <?php foreach ($rack['peripherals'] as $p) { ?>
                                    <div class="icct-rack-item peripheral"
                                         draggable="<?php print $can_manage ? 'true' : 'false'; ?>"
                                         data-item-type="peripheral"
                                         data-placement-id="<?php print (int)$p['id']; ?>"
                                         data-rack-id="<?php print (int)$rack['id']; ?>"
                                         data-start-u="<?php print (int)$p['start_u']; ?>"
                                         data-height-u="<?php print (int)$p['height_u']; ?>"
                                         data-peripheral-key="<?php print icct_rack_h($p['peripheral_key']); ?>"
                                         data-stencil="<?php print icct_rack_h($p['stencil']); ?>"
                                         data-label="<?php print icct_rack_h($p['label']); ?>"
                                         style="bottom:calc(<?php print (int)$p['start_u'] - 1; ?> * var(--u-height) + 3px); height:calc(<?php print (int)$p['height_u']; ?> * var(--u-height) - 6px);">
                                        <img class="icct-rack-item-image" src="<?php print html_escape($plugin_url . '/images/stencils/' . icct_rack_stencil_file($p['stencil'])); ?>" alt="">
                                        <div class="icct-rack-item-overlay peripheral-overlay">
                                            <span class="item-name"><?php print icct_rack_h($p['label']); ?></span>
                                            <span class="item-status">Peripheral</span>
                                        </div>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    </section>
                <?php } ?>
            </div>
            <?php } ?>
        </main>
    </div>

    <?php if ($can_manage) { ?>
    <aside class="icct-inspector" id="icct-placement-inspector" aria-hidden="true">
        <div class="icct-inspector-header">
            <div>
                <strong id="icct-inspector-title">Rack Item</strong>
                <div id="icct-inspector-subtitle" class="icct-page-subtitle"></div>
            </div>
            <button type="button" class="icct-inspector-close" id="icct-inspector-close">×</button>
        </div>
        <div class="icct-inspector-preview"><img id="icct-inspector-image" src="" alt=""></div>
        <div class="icct-inspector-form">
            <label>Unit size
                <select id="icct-inspector-height">
                    <?php for ($u = 1; $u <= 20; $u++) { ?><option value="<?php print $u; ?>"><?php print $u; ?>U</option><?php } ?>
                </select>
            </label>
            <label id="icct-inspector-stencil-wrap">Front stencil
                <select id="icct-inspector-stencil">
                    <?php foreach ($categories as $key => $label) { ?><option value="<?php print icct_rack_h($key); ?>"><?php print icct_rack_h($label); ?></option><?php } ?>
                </select>
            </label>
            <div class="icct-inspector-actions">
                <button type="button" class="icct-button primary" id="icct-inspector-save">Save</button>
                <button type="button" class="icct-button danger" id="icct-inspector-remove">Remove</button>
            </div>
        </div>
    </aside>
    <?php } ?>
</div>
<script src="<?php print html_escape($plugin_url . '/js/icct_rack_v230.js'); ?>"></script>
<?php bottom_footer(); ?>
