<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */
function icct_rack_admin_nav($active) {
    global $config;
    $base = $config['url_path'] . 'plugins/icct_rack/';
    $items = [
        'racks' => ['icct_rack_admin.php', 'Racks'],
        'logs'  => ['icct_rack_logs.php', 'Audit Log'],
    ];
    print '<div class="icct-admin-nav">';
    print '<a class="icct-admin-nav-view" href="' . html_escape($base . 'icct_rack.php') . '">Rack Workspace</a>';
    foreach ($items as $key => $item) {
        $class = $active === $key ? ' active' : '';
        print '<a class="icct-admin-nav-item' . $class . '" href="' . html_escape($base . $item[0]) . '">' . html_escape($item[1]) . '</a>';
    }
    print '</div>';
}
