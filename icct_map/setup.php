<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */

function plugin_icct_map_install($upgrade = false) {
    global $config;
    $plugin = 'icct_map';

    api_plugin_register_hook($plugin, 'config_arrays', 'icct_map_config_arrays', 'setup.php');
    api_plugin_register_hook($plugin, 'draw_navigation_text', 'icct_map_draw_navigation_text', 'setup.php');
    api_plugin_register_hook($plugin, 'top_header_tabs', 'icct_map_show_tab', 'setup.php');
    api_plugin_register_hook($plugin, 'top_graph_header_tabs', 'icct_map_show_tab', 'setup.php');

    api_plugin_register_realm($plugin, 'icct_map.php,ajax.php', 'View ICCT India Geographic Topology', 1);
    api_plugin_register_realm($plugin, 'icct_map_admin.php,icct_map_runtime.php,icct_map_logs.php', 'Manage ICCT India Geographic Topology', 1);

    include_once($config['base_path'] . '/plugins/icct_map/lib/database.php');
    icct_map_setup_database();

    if ($upgrade && api_plugin_is_enabled($plugin)) {
        api_plugin_enable_hooks($plugin);
    }
    return true;
}

function plugin_icct_map_uninstall() {
    /* Preserve history/audit. Only remove plugin settings. */
    db_execute("DELETE FROM settings WHERE name IN ('icct_map_refresh_seconds','icct_map_show_labels','icct_map_track_enabled','icct_map_schema_version')");
    return true;
}

function plugin_icct_map_check_config() {
    global $config;
    include_once($config['base_path'] . '/plugins/icct_map/lib/database.php');
    icct_map_setup_database();
    return true;
}

function plugin_icct_map_upgrade() {
    global $config;
    plugin_icct_map_install(true);
    include_once($config['base_path'] . '/plugins/icct_map/lib/database.php');
    icct_map_setup_database();
    $info = plugin_icct_map_version();
    db_execute_prepared('UPDATE plugin_config SET version = ? WHERE directory = ?', [$info['version'], 'icct_map']);
    return true;
}

function plugin_icct_map_version() {
    global $config;
    $parsed = @parse_ini_file($config['base_path'] . '/plugins/icct_map/INFO', true);
    $info = is_array($parsed) && isset($parsed['info']) ? $parsed['info'] : [];
    return [
        'name' => $info['name'] ?? 'icct_map',
        'version' => $info['version'] ?? '1.6.0',
        'longname' => $info['longname'] ?? 'ICCT India Geographic Topology',
        'author' => $info['author'] ?? 'ICCT NMS Team',
        'homepage' => $info['homepage'] ?? '',
        'email' => $info['email'] ?? '',
        'compat' => $info['compat'] ?? '1.2.30',
    ];
}

function icct_map_config_arrays() {
    global $menu;
    $menu[__('Management')]['plugins/icct_map/icct_map.php'] = __('India Geographic Topology');
    $menu[__('Configuration')]['plugins/icct_map/icct_map_admin.php'] = __('India Map Settings');
    $menu[__('Utilities')]['plugins/icct_map/icct_map_logs.php'] = __('India Map Audit Log');

    if (function_exists('auth_augment_roles')) {
        auth_augment_roles(__('General Administration'), ['icct_map.php','icct_map_admin.php','icct_map_runtime.php','icct_map_logs.php']);
    }
}

function icct_map_draw_navigation_text($nav) {
    $nav['icct_map.php:'] = ['title' => __('India Geographic Topology'), 'mapping' => 'index.php:', 'url' => 'icct_map.php', 'level' => '1'];
    $nav['icct_map_admin.php:'] = ['title' => __('India Map Settings'), 'mapping' => 'index.php:,icct_map.php:', 'url' => 'icct_map_admin.php', 'level' => '2'];
    $nav['icct_map_runtime.php:'] = ['title' => __('India Map Runtime'), 'mapping' => 'index.php:,icct_map.php:', 'url' => 'icct_map_runtime.php', 'level' => '2'];
    $nav['icct_map_logs.php:'] = ['title' => __('India Map Audit Log'), 'mapping' => 'index.php:,icct_map.php:', 'url' => 'icct_map_logs.php', 'level' => '2'];
    return $nav;
}

function icct_map_show_tab() {
    global $config;
    if (!function_exists('api_user_realm_auth') || !api_user_realm_auth('icct_map.php')) return;
    $active = in_array(get_current_page(), ['icct_map.php','icct_map_admin.php','icct_map_runtime.php','icct_map_logs.php'], true);
    $image = $active ? 'tab_icct_map_down.gif' : 'tab_icct_map.gif';
    $url = $config['url_path'] . 'plugins/icct_map/icct_map.php';
    $src = $config['url_path'] . 'plugins/icct_map/images/' . $image;
    print '<a href="' . html_escape($url) . '"><img src="' . html_escape($src) . '" alt="' . __esc('India Map') . '" title="' . __esc('ICCT India Geographic Topology') . '"></a>';
}
