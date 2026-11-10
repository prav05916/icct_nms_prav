<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */
chdir('../..');
include('./include/auth.php');
include_once($config['base_path'] . '/plugins/icct_rack/lib/functions.php');
include_once($config['base_path'] . '/plugins/icct_rack/include/admin_nav.php');

if (!icct_rack_can_manage()) {
    raise_message('icct_rack_denied', 'You do not have permission to manage rack topology.', MESSAGE_LEVEL_ERROR);
    header('Location: ' . $config['url_path'] . 'plugins/icct_rack/icct_rack.php');
    exit;
}

icct_rack_disable_full_page_refresh('icct_rack_admin.php');
$action = icct_rack_request_string('action');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'save_rack') {
        $result = icct_rack_save_rack([
            'name' => icct_rack_request_string('name'),
            'rack_units' => icct_rack_request_int('rack_units', 42),
            'enabled' => true,
        ], icct_rack_request_int('id'));
        if ($result['ok']) {
            raise_message('icct_rack_saved', 'Rack saved successfully.', MESSAGE_LEVEL_INFO);
            header('Location: ' . $config['url_path'] . 'plugins/icct_rack/icct_rack_admin.php');
            exit;
        }
        icct_rack_raise_errors('icct_rack_save_error', $result['errors']);
    } elseif ($action === 'delete_rack') {
        $result = icct_rack_delete_rack(icct_rack_request_int('id'));
        if ($result['ok']) {
            raise_message('icct_rack_deleted', 'Rack deleted successfully.', MESSAGE_LEVEL_INFO);
        } else {
            icct_rack_raise_errors('icct_rack_delete_error', $result['error']);
        }
        header('Location: ' . $config['url_path'] . 'plugins/icct_rack/icct_rack_admin.php');
        exit;
    } elseif ($action === 'save_settings') {
        $refresh = icct_rack_request_int('refresh_seconds', 30);
        if (!in_array($refresh, [0,15,30,60,120,300], true)) $refresh = 30;
        set_config_option('icct_rack_refresh_seconds', (string)$refresh);
        raise_message('icct_rack_settings', 'Rack viewer settings saved.', MESSAGE_LEVEL_INFO);
        header('Location: ' . $config['url_path'] . 'plugins/icct_rack/icct_rack_admin.php');
        exit;
    }
}

$edit_id = icct_rack_request_int('edit_rack');
$edit_rack = $edit_id > 0 ? icct_rack_get_rack($edit_id) : [];
$racks = icct_rack_get_racks_with_items(false);
$refresh_seconds = (int)read_config_option('icct_rack_refresh_seconds');
$plugin_url = $config['url_path'] . 'plugins/icct_rack';

top_header();
icct_rack_stop_browser_refresh_timer();
print '<link rel="stylesheet" type="text/css" href="' . html_escape($plugin_url . '/css/icct_rack_v230.css') . '">';
print '<div class="icct-rack-page">';
icct_rack_admin_nav('racks');
?>
<div class="icct-admin-layout">
    <div class="icct-card">
        <h3><?php print $edit_rack ? 'Edit Rack' : 'Create Rack'; ?></h3>
        <div class="icct-helper-text">Only the physical rack name and number of rack units are required.</div>
        <?php form_start('icct_rack_admin.php', 'rack_form'); ?>
        <input type="hidden" name="action" value="save_rack">
        <input type="hidden" name="id" value="<?php print $edit_rack ? (int)$edit_rack['id'] : 0; ?>">
        <div class="icct-form-grid compact">
            <label for="rack_name">Rack Name *</label>
            <input id="rack_name" name="name" type="text" maxlength="128" required value="<?php print icct_rack_h($edit_rack['name'] ?? ''); ?>" placeholder="e.g. Rack 01">

            <label for="rack_units">Rack Units *</label>
            <input id="rack_units" name="rack_units" type="number" min="1" max="100" required value="<?php print (int)($edit_rack['rack_units'] ?? 42); ?>">

            <div class="icct-form-actions">
                <?php if ($edit_rack) { ?><a class="icct-button" href="icct_rack_admin.php">New Rack</a><?php } ?>
                <button type="submit" class="icct-button primary">Save Rack</button>
            </div>
        </div>
        <?php form_end(); ?>
    </div>

    <div class="icct-card">
        <h3>Viewer Settings</h3>
        <?php form_start('icct_rack_admin.php', 'settings_form'); ?>
        <input type="hidden" name="action" value="save_settings">
        <div class="icct-form-grid compact">
            <label for="refresh_seconds">Status Refresh</label>
            <select id="refresh_seconds" name="refresh_seconds">
                <?php foreach ([0=>'Off',15=>'15 seconds',30=>'30 seconds',60=>'1 minute',120=>'2 minutes',300=>'5 minutes'] as $value=>$label) { ?>
                    <option value="<?php print (int)$value; ?>"<?php print $refresh_seconds === (int)$value ? ' selected' : ''; ?>><?php print icct_rack_h($label); ?></option>
                <?php } ?>
            </select>
            <div class="icct-form-actions"><button type="submit" class="icct-button primary">Save</button></div>
        </div>
        <?php form_end(); ?>
    </div>
</div>

<div class="icct-card">
    <div class="icct-studio-head">
        <div>
            <h3>Racks</h3>
            <div class="icct-helper-text">Device and peripheral placement is done directly in the Rack Workspace.</div>
        </div>
        <a class="icct-button primary" href="<?php print html_escape($plugin_url . '/icct_rack.php'); ?>">Open Rack Workspace</a>
    </div>
    <table class="icct-table">
        <thead><tr><th>Rack Name</th><th>Units</th><th>Occupied</th><th>Free</th><th>Devices</th><th>Peripherals</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!cacti_sizeof($racks)) { ?>
            <tr><td colspan="8"><em>No racks created yet.</em></td></tr>
        <?php } else { foreach ($racks as $rack) { ?>
            <tr>
                <td><strong><?php print icct_rack_h($rack['name']); ?></strong></td>
                <td><?php print (int)$rack['rack_units']; ?>U</td>
                <td><?php print (int)$rack['used_u']; ?>U</td>
                <td><?php print (int)$rack['free_u']; ?>U</td>
                <td><?php print count($rack['placements']); ?></td>
                <td><?php print count($rack['peripherals']); ?></td>
                <td><?php print $rack['enabled'] === 'on' ? 'Enabled' : 'Disabled'; ?></td>
                <td class="actions">
                    <a href="icct_rack_admin.php?edit_rack=<?php print (int)$rack['id']; ?>">Edit</a>
                    <?php if ((int)$rack['used_u'] === 0) { ?>
                        &nbsp;|&nbsp;
                        <?php form_start('icct_rack_admin.php', 'delete_rack_' . (int)$rack['id']); ?>
                        <input type="hidden" name="action" value="delete_rack">
                        <input type="hidden" name="id" value="<?php print (int)$rack['id']; ?>">
                        <button type="submit" class="link-button" onclick="return confirm('Delete this rack?');">Delete</button>
                        <?php form_end(); ?>
                    <?php } ?>
                </td>
            </tr>
        <?php } } ?>
        </tbody>
    </table>
</div>
</div>
<?php bottom_footer(); ?>
