<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */

function plugin_icct_inventory_install($upgrade = false) {
    global $config;

    $cacti_version = defined('CACTI_VERSION') ? CACTI_VERSION : ($config['cacti_version'] ?? '0.0.0');
    if (version_compare($cacti_version, '1.2.30', '<')) {
        return false;
    }

    $plugin = 'icct_inventory';
    api_plugin_register_hook($plugin, 'config_arrays', 'icct_inventory_config_arrays', 'setup.php');
    api_plugin_register_hook($plugin, 'draw_navigation_text', 'icct_inventory_draw_navigation_text', 'setup.php');

    api_plugin_register_realm(
        $plugin,
        'inventory.php,devices.php,device_types.php,segments.php,oids.php,protocols.php,audit.php',
        'View ICCT Inventory',
        1
    );
    api_plugin_register_realm(
        $plugin,
        'device_edit.php',
        'Manage ICCT Inventory',
        1
    );

    include_once($config['base_path'] . '/plugins/icct_inventory/lib/database.php');
    icct_inventory_setup_database();

    if ($upgrade && api_plugin_is_enabled($plugin)) {
        api_plugin_enable_hooks($plugin);
    }

    return true;
}

function plugin_icct_inventory_uninstall() {
    // Preserve plugin-owned inventory data on uninstall. An explicit SQL cleanup file is supplied separately.
    return true;
}

function plugin_icct_inventory_check_config() {
    global $config;
    include_once($config['base_path'] . '/plugins/icct_inventory/lib/database.php');
    icct_inventory_setup_database();
    return icct_inventory_tables_ready();
}

function plugin_icct_inventory_upgrade() {
    global $config;
    plugin_icct_inventory_install(true);
    include_once($config['base_path'] . '/plugins/icct_inventory/lib/database.php');
    icct_inventory_setup_database();

    $info = plugin_icct_inventory_version();
    if (!empty($info['version'])) {
        db_execute_prepared('UPDATE plugin_config SET version = ? WHERE directory = ?', [$info['version'], 'icct_inventory']);
    }
    return true;
}

function plugin_icct_inventory_version() {
    global $config;
    $file = $config['base_path'] . '/plugins/icct_inventory/INFO';
    $parsed = is_file($file) ? parse_ini_file($file, true) : [];
    $info = $parsed['info'] ?? [];
    return array_merge([
        'name' => 'icct_inventory',
        'version' => '1.0.0',
        'longname' => 'ICCT Inventory & Protocol Integration',
        'author' => 'ICCT NMS Team',
        'homepage' => '',
        'email' => '',
        'compat' => '1.2.30',
    ], $info);
}

function icct_inventory_config_arrays() {
    global $menu;
    $menu[__('Management')]['plugins/icct_inventory/inventory.php'] = __('ICCT Inventory');
    $menu[__('Configuration')]['plugins/icct_inventory/device_types.php'] = __('ICCT Inventory Setup');
    $menu[__('Utilities')]['plugins/icct_inventory/audit.php'] = __('ICCT Inventory Audit');
}

function icct_inventory_draw_navigation_text($nav) {
    $nav['inventory.php:'] = [
        'title' => __('ICCT Inventory'),
        'mapping' => 'index.php:',
        'url' => 'inventory.php',
        'level' => '1',
    ];
    $nav['devices.php:'] = [
        'title' => __('Actual Devices'),
        'mapping' => 'index.php:,inventory.php:',
        'url' => 'devices.php',
        'level' => '2',
    ];
    $nav['device_types.php:'] = [
        'title' => __('Device Types'),
        'mapping' => 'index.php:,inventory.php:',
        'url' => 'device_types.php',
        'level' => '2',
    ];
    $nav['segments.php:'] = [
        'title' => __('Segments'),
        'mapping' => 'index.php:,inventory.php:',
        'url' => 'segments.php',
        'level' => '2',
    ];
    $nav['oids.php:'] = [
        'title' => __('FCAPS / OIDs'),
        'mapping' => 'index.php:,inventory.php:',
        'url' => 'oids.php',
        'level' => '2',
    ];
    $nav['protocols.php:'] = [
        'title' => __('Protocol Diagnostics'),
        'mapping' => 'index.php:,inventory.php:',
        'url' => 'protocols.php',
        'level' => '2',
    ];
    $nav['audit.php:'] = [
        'title' => __('Inventory Audit'),
        'mapping' => 'index.php:,inventory.php:',
        'url' => 'audit.php',
        'level' => '2',
    ];
    return $nav;
}
