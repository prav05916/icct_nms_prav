<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */

function plugin_icct_rack_install($upgrade = false) {
    global $config;

    $cacti_version = defined('CACTI_VERSION') ? CACTI_VERSION : ($config['cacti_version'] ?? '0.0.0');
    if (version_compare($cacti_version, '1.2.30', '<')) {
        return false;
    }

    $plugin = 'icct_rack';
    api_plugin_register_hook($plugin, 'config_arrays', 'icct_rack_config_arrays', 'setup.php');
    api_plugin_register_hook($plugin, 'draw_navigation_text', 'icct_rack_draw_navigation_text', 'setup.php');
    api_plugin_register_hook($plugin, 'top_header_tabs', 'icct_rack_show_tab', 'setup.php');
    api_plugin_register_hook($plugin, 'top_graph_header_tabs', 'icct_rack_show_tab', 'setup.php');

    api_plugin_register_realm($plugin, 'icct_rack.php,ajax.php', 'View ICCT Rack Topology', 1);
    api_plugin_register_realm($plugin, 'icct_rack_admin.php,icct_rack_logs.php', 'Manage ICCT Rack Topology', 1);

    include_once($config['base_path'] . '/plugins/icct_rack/lib/database.php');
    icct_rack_setup_database();

    if ($upgrade && api_plugin_is_enabled($plugin)) {
        api_plugin_enable_hooks($plugin);
    }

    return true;
}

function plugin_icct_rack_uninstall() {
    /* Preserve topology data on ordinary uninstall. */
    db_execute("DELETE FROM settings WHERE name IN ('icct_rack_refresh_seconds', 'icct_rack_schema_version')");
    return true;
}

function plugin_icct_rack_check_config() {
    global $config;
    include_once($config['base_path'] . '/plugins/icct_rack/lib/database.php');
    icct_rack_setup_database();
    return icct_rack_tables_ready();
}

function plugin_icct_rack_upgrade() {
    global $config;
    plugin_icct_rack_install(true);
    include_once($config['base_path'] . '/plugins/icct_rack/lib/database.php');
    icct_rack_setup_database();

    $info = plugin_icct_rack_version();
    if (!empty($info['version'])) {
        db_execute_prepared('UPDATE plugin_config SET version = ? WHERE directory = ?', [$info['version'], 'icct_rack']);
    }
    return true;
}

function plugin_icct_rack_version() {
    global $config;

    $defaults = [
        'name' => 'icct_rack',
        'version' => '2.3.0',
        'longname' => 'ICCT Rack Topology',
        'author' => 'ICCT NMS Team',
        'email' => '',
        'homepage' => '',
        'compat' => '1.2.30',
        'requires' => '',
        'capabilities' => 'online_view:1, online_mgmt:1, offline_view:1, offline_mgmt:1, remote_collect:0',
    ];

    $path = $config['base_path'] . '/plugins/icct_rack/INFO';
    if (!is_file($path)) {
        return $defaults;
    }

    $parsed = parse_ini_file($path, true);
    if (!is_array($parsed) || !isset($parsed['info']) || !is_array($parsed['info'])) {
        return $defaults;
    }

    return array_merge($defaults, $parsed['info']);
}

function icct_rack_config_arrays() {
    global $menu;

    $menu[__('Management')]['plugins/icct_rack/icct_rack.php'] = __('Rack Topology');
    $menu[__('Configuration')]['plugins/icct_rack/icct_rack_admin.php'] = __('Rack Topology');
    $menu[__('Utilities')]['plugins/icct_rack/icct_rack_logs.php'] = __('Rack Topology Audit Log');

    if (function_exists('auth_augment_roles')) {
        auth_augment_roles(__('General Administration'), ['icct_rack.php', 'icct_rack_admin.php', 'icct_rack_logs.php']);
    }
}

function icct_rack_draw_navigation_text($nav) {
    $nav['icct_rack.php:'] = [
        'title'   => __('Rack Topology'),
        'mapping' => 'index.php:',
        'url'     => 'icct_rack.php',
        'level'   => '1',
    ];
    $nav['icct_rack_admin.php:'] = [
        'title'   => __('Rack Topology Configuration'),
        'mapping' => 'index.php:,icct_rack.php:',
        'url'     => 'icct_rack_admin.php',
        'level'   => '2',
    ];
    $nav['icct_rack_logs.php:'] = [
        'title'   => __('Rack Topology Audit Log'),
        'mapping' => 'index.php:,icct_rack.php:',
        'url'     => 'icct_rack_logs.php',
        'level'   => '2',
    ];
    return $nav;
}

function icct_rack_show_tab() {
    global $config;
    if (!function_exists('api_user_realm_auth') || !api_user_realm_auth('icct_rack.php')) {
        return;
    }

    $active = in_array(get_current_page(), ['icct_rack.php', 'icct_rack_admin.php', 'icct_rack_logs.php'], true);
    $image  = $active ? 'tab_icct_rack_down.gif' : 'tab_icct_rack.gif';
    $url    = $config['url_path'] . 'plugins/icct_rack/icct_rack.php';
    $src    = $config['url_path'] . 'plugins/icct_rack/images/' . $image;
    print '<a href="' . html_escape($url) . '"><img src="' . html_escape($src) . '" alt="' . __esc('Rack Topology') . '" title="' . __esc('ICCT Rack Topology') . '"></a>';
}
