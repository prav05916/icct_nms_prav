<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */
chdir('../..');
include('./include/auth.php');
include_once($config['base_path'] . '/plugins/icct_map/lib/functions.php');
include_once($config['base_path'] . '/plugins/icct_map/include/admin_nav.php');

if (!icct_map_can_manage()) {
    header('Location: ' . $config['url_path'] . 'plugins/icct_map/icct_map.php');
    exit;
}

/* Cacti can inject a global page refresh timer. Settings pages must never auto-reload. */
icct_map_disable_full_page_refresh('icct_map_admin.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && icct_map_get_string('action') === 'save_settings') {
    $refresh = icct_map_get_int('refresh_seconds', 30);
    if (!in_array($refresh, [15,30,60,120,300], true)) $refresh = 30;
    $show_labels = icct_map_get_string('show_labels') === 'on' ? 'on' : '';
    $track = icct_map_get_string('track_enabled') === 'on' ? 'on' : '';

    set_config_option('icct_map_refresh_seconds', (string)$refresh);
    set_config_option('icct_map_show_labels', $show_labels);
    set_config_option('icct_map_track_enabled', $track);
    icct_map_audit('settings.update', 'settings', 0, [
        'refresh_seconds' => $refresh,
        'show_labels' => $show_labels,
        'track_enabled' => $track,
    ]);

    header('Location: ' . $config['url_path'] . 'plugins/icct_map/icct_map_admin.php?saved=1');
    exit;
}

$snapshot = icct_map_sites_snapshot();
$refresh = (int)read_config_option('icct_map_refresh_seconds');
$show_labels = read_config_option('icct_map_show_labels') === 'on';
$track = read_config_option('icct_map_track_enabled') === 'on';
$runtime = icct_map_runtime_status();
$plugin_url = $config['url_path'] . 'plugins/icct_map';

top_header();
icct_map_stop_browser_refresh_timer();
print '<link rel="stylesheet" href="' . html_escape($plugin_url . '/css/icct_map_v160.css') . '">';
print '<div class="icct-map-page">';
icct_map_admin_nav('settings');

if (icct_map_get_int('saved') === 1) {
    print '<div class="icct-notice success">India map settings saved.</div>';
}
?>
<div class="icct-map-toolbar">
    <div>
        <div class="icct-map-title">India Map Settings</div>
        <div class="icct-map-subtitle">Cacti Sites and Cacti Devices are the source of truth. This page does not auto-refresh.</div>
    </div>
    <span class="spacer"></span>
    <a class="button" href="<?php print html_escape($plugin_url . '/icct_map.php'); ?>">View India Map</a>
</div>

<div class="icct-admin-grid">
    <div class="icct-admin-card">
        <h3>Viewer behaviour</h3>
        <?php form_start('icct_map_admin.php', 'icct_map_settings_form'); ?>
        <input type="hidden" name="action" value="save_settings">
        <div class="icct-form-grid">
            <label for="refresh_seconds">Health refresh</label>
            <select id="refresh_seconds" name="refresh_seconds">
                <?php foreach ([15=>'15 seconds',30=>'30 seconds',60=>'1 minute',120=>'2 minutes',300=>'5 minutes'] as $v=>$l) { ?>
                    <option value="<?php print (int)$v; ?>"<?php print $refresh === (int)$v ? ' selected' : ''; ?>><?php print icct_map_h($l); ?></option>
                <?php } ?>
            </select>

            <label for="show_labels">ICCT labels</label>
            <label class="normal"><input id="show_labels" type="checkbox" name="show_labels" value="on" <?php print $show_labels ? 'checked' : ''; ?>> Show ICCT site names beside markers</label>

            <label for="track_enabled">GNSS history</label>
            <label class="normal"><input id="track_enabled" type="checkbox" name="track_enabled" value="on" <?php print $track ? 'checked' : ''; ?>> Keep historical GNSS positions</label>

            <div class="icct-form-actions"><button type="submit">Save Settings</button></div>
        </div>
        <?php form_end(); ?>
    </div>

    <div class="icct-admin-card">
        <h3>Cacti Sites</h3>
        <p><strong><?php print (int)$snapshot['summary']['total_sites']; ?></strong> native Cacti site(s) found; <strong><?php print (int)$snapshot['summary']['mapped_sites']; ?></strong> have usable India coordinates.</p>
        <p>The map reads <code>sites.id/name/city/state/country/latitude/longitude</code> directly. No duplicate ICCT-site table is used.</p>
        <a class="button" href="<?php print html_escape($config['url_path'] . 'sites.php'); ?>">Manage Cacti Sites</a>
    </div>

    <div class="icct-admin-card">
        <h3>Cacti Devices</h3>
        <p>Device health is aggregated from native Cacti hosts linked by <code>host.site_id</code>.</p>
        <a class="button" href="<?php print html_escape($config['url_path'] . 'host.php'); ?>">Manage Cacti Devices</a>
    </div>

    <div class="icct-admin-card">
        <h3>Local India map data</h3>
        <p>Status: <strong class="<?php print $runtime['ready'] ? 'ok-text' : 'bad-text'; ?>"><?php print $runtime['ready'] ? 'Ready' : 'Not prepared'; ?></strong></p>
        <p>Leaflet and the India states/UT boundary GeoJSON are served locally. The operational viewer does not require Internet access.</p>
        <a class="button" href="<?php print html_escape($plugin_url . '/icct_map_runtime.php'); ?>">Runtime Details</a>
    </div>
</div>

<?php if (cacti_sizeof($snapshot['missing'])) { ?>
<div class="icct-admin-card">
    <h3>Sites requiring coordinates</h3>
    <table class="icct-simple-table">
        <thead><tr><th>Site</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($snapshot['missing'] as $site) { ?>
            <tr>
                <td><?php print icct_map_h($site['name']); ?></td>
                <td><a href="<?php print html_escape($config['url_path'] . 'sites.php?action=edit&id=' . (int)$site['id']); ?>">Edit in Cacti</a></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</div>
<?php } ?>
</div>
<?php bottom_footer(); ?>
