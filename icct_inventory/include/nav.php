<?php
function icct_inventory_nav($active) {
    global $config;
    $base = $config['url_path'] . 'plugins/icct_inventory/';
    $items = [
        'overview' => ['inventory.php', 'Overview'],
        'devices' => ['devices.php', 'Actual Devices'],
        'types' => ['device_types.php', 'Device Types'],
        'segments' => ['segments.php', 'Segments'],
        'oids' => ['oids.php', 'FCAPS / OIDs'],
        'protocols' => ['protocols.php', 'Protocol Diagnostics'],
        'audit' => ['audit.php', 'Audit Log'],
    ];
    print '<div class="icct-inv-nav">';
    foreach ($items as $key => $item) {
        $class = $active === $key ? ' active' : '';
        print '<a class="icct-inv-nav-item' . $class . '" href="' . html_escape($base . $item[0]) . '">' . html_escape($item[1]) . '</a>';
    }
    print '</div>';
}
