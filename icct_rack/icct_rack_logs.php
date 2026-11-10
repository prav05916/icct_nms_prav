<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */
chdir('../..');
include('./include/auth.php');
include_once($config['base_path'] . '/plugins/icct_rack/lib/functions.php');
include_once($config['base_path'] . '/plugins/icct_rack/include/admin_nav.php');

if (!icct_rack_can_manage()) {
    raise_message('icct_rack_denied', 'You do not have permission to view rack audit logs.', MESSAGE_LEVEL_ERROR);
    header('Location: ' . $config['url_path'] . 'plugins/icct_rack/icct_rack.php');
    exit;
}

icct_rack_disable_full_page_refresh('icct_rack_logs.php');

$action = icct_rack_request_string('action');
$id = icct_rack_request_int('id');
$filter = icct_rack_request_string('filter');
$rows = icct_rack_page_rows();
$page = icct_rack_page_number();
$total_rows = 0;
$records = $action === 'detail' ? [] : icct_rack_list_audit($filter, $rows, $page, $total_rows);
$detail = ($action === 'detail' && $id > 0) ? icct_rack_get_audit($id) : [];
$plugin_url = $config['url_path'] . 'plugins/icct_rack';

top_header();
icct_rack_stop_browser_refresh_timer();
print '<link rel="stylesheet" type="text/css" href="' . html_escape($plugin_url . '/css/icct_rack_v230.css') . '">';
print '<div class="icct-rack-page">';
icct_rack_admin_nav('logs');

if ($action === 'detail') {
    html_start_box('Audit Detail', '100%', false, 3, 'center', '');
    if (!$detail) {
        print '<div class="icct-rack-empty">Audit record not found.</div>';
    } else {
        $pretty = (string)$detail['details'];
        $decoded = json_decode($pretty, true);
        if (is_array($decoded)) {
            $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        print '<table class="icct-form-table">';
        print '<tr><td class="icct-form-label">Time</td><td>' . icct_rack_h($detail['created_at']) . '</td></tr>';
        print '<tr><td class="icct-form-label">User</td><td>' . icct_rack_h($detail['username'] ?: ('User #' . (int)$detail['user_id'])) . '</td></tr>';
        print '<tr><td class="icct-form-label">Action</td><td>' . icct_rack_h($detail['action']) . '</td></tr>';
        print '<tr><td class="icct-form-label">Object</td><td>' . icct_rack_h($detail['entity_type']) . ' #' . (int)$detail['entity_id'] . '</td></tr>';
        print '<tr><td class="icct-form-label">Client IP</td><td>' . icct_rack_h($detail['ip_address']) . '</td></tr>';
        print '<tr><td class="icct-form-label">Details</td><td><pre class="icct-log-detail">' . icct_rack_h($pretty) . '</pre></td></tr>';
        print '</table>';
    }
    print '<div class="icct-form-actions"><a class="icct-button" href="icct_rack_logs.php">Back to Audit Log</a></div>';
    html_end_box(true, true);
} else {
    html_start_box('Rack Topology Audit Log', '100%', false, 3, 'center', '');
    print '<form method="get" class="icct-filter-bar">';
    print '<label>Search <input type="text" name="filter" value="' . icct_rack_h($filter) . '"></label>';
    print '<button class="button" type="submit">Go</button><a class="button" href="icct_rack_logs.php">Clear</a>';
    print '</form>';
    html_end_box(true, true);

    $base = 'icct_rack_logs.php?filter=' . rawurlencode($filter);
    $nav = html_nav_bar($base, MAX_DISPLAY_PAGES, $page, $rows, $total_rows, 6, 'Entries', 'page', 'main');
    print $nav;
    html_start_box('', '100%', false, 3, 'center', '');
    html_header(['Time', 'User', 'Action', 'Object', 'Client IP', 'Details']);
    if (cacti_sizeof($records)) {
        $i = 0;
        foreach ($records as $row) {
            form_alternate_row('audit_' . (int)$row['id'], true);
            form_selectable_cell(icct_rack_h($row['created_at']), $i);
            form_selectable_cell(icct_rack_h($row['username'] ?: ('User #' . (int)$row['user_id'])), $i);
            form_selectable_cell(icct_rack_h($row['action']), $i);
            form_selectable_cell(icct_rack_h($row['entity_type']) . ' #' . (int)$row['entity_id'], $i);
            form_selectable_cell(icct_rack_h($row['ip_address']), $i);
            form_selectable_cell('<a href="icct_rack_logs.php?action=detail&id=' . (int)$row['id'] . '">View</a>', $i);
            form_end_row();
            $i++;
        }
    } else {
        print '<tr><td colspan="6"><em>No audit entries found.</em></td></tr>';
    }
    html_end_box(false);
    print $nav;
}

print '</div>';
bottom_footer();
