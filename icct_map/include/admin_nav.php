<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */
function icct_map_admin_nav($active) {
    global $config;
    $base = $config['url_path'] . 'plugins/icct_map/';
    $items = [
        'settings' => ['icct_map_admin.php', 'Settings'],
        'runtime' => ['icct_map_runtime.php', 'Runtime'],
        'logs' => ['icct_map_logs.php', 'Audit Log'],
    ];
    print '<div class="icct-admin-nav">';
    print '<a class="icct-admin-nav-view" href="' . html_escape($base . 'icct_map.php') . '">View India Map</a>';
    foreach ($items as $key => $item) {
        $class = $active === $key ? ' active' : '';
        print '<a class="icct-admin-nav-item' . $class . '" href="' . html_escape($base . $item[0]) . '">' . html_escape($item[1]) . '</a>';
    }
    print '</div>';
}
