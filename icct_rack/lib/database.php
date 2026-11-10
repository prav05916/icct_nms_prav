<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */

function icct_rack_column_exists($table, $column) {
    return (int)db_fetch_cell_prepared(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        [$table, $column]
    ) === 1;
}

function icct_rack_index_exists($table, $index) {
    return (int)db_fetch_cell_prepared(
        'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
        [$table, $index]
    ) > 0;
}

function icct_rack_setup_database() {
    db_execute("CREATE TABLE IF NOT EXISTS `plugin_icct_rack_racks` (
        `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
        `code` varchar(64) NOT NULL,
        `name` varchar(128) NOT NULL,
        `icct_name` varchar(128) NOT NULL DEFAULT '',
        `location` varchar(255) NOT NULL DEFAULT '',
        `rack_units` tinyint(3) unsigned NOT NULL DEFAULT 42,
        `enabled` char(2) NOT NULL DEFAULT 'on',
        `created_by` int(10) unsigned NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `ux_icct_rack_code` (`code`),
        KEY `ix_icct_rack_enabled` (`enabled`)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic COMMENT='ICCT rack definitions'");

    db_execute("CREATE TABLE IF NOT EXISTS `plugin_icct_rack_placements` (
        `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
        `rack_id` int(10) unsigned NOT NULL,
        `host_id` int(10) unsigned NOT NULL,
        `label` varchar(128) NOT NULL DEFAULT '',
        `start_u` tinyint(3) unsigned NOT NULL,
        `height_u` tinyint(3) unsigned NOT NULL DEFAULT 1,
        `category` varchar(32) NOT NULL DEFAULT 'generic',
        `notes` text NULL,
        `created_by` int(10) unsigned NOT NULL DEFAULT 0,
        `updated_by` int(10) unsigned NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `ux_icct_rack_host` (`host_id`),
        KEY `ix_icct_rack_placement_rack` (`rack_id`),
        KEY `ix_icct_rack_placement_start` (`rack_id`,`start_u`)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic COMMENT='Cacti device placement in ICCT racks'");

    db_execute("CREATE TABLE IF NOT EXISTS `plugin_icct_rack_peripheral_placements` (
        `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
        `rack_id` int(10) unsigned NOT NULL,
        `peripheral_key` varchar(32) NOT NULL,
        `label` varchar(128) NOT NULL DEFAULT '',
        `start_u` tinyint(3) unsigned NOT NULL,
        `height_u` tinyint(3) unsigned NOT NULL DEFAULT 1,
        `stencil` varchar(32) NOT NULL DEFAULT 'generic',
        `created_by` int(10) unsigned NOT NULL DEFAULT 0,
        `updated_by` int(10) unsigned NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `ix_icct_rack_peripheral_rack` (`rack_id`),
        KEY `ix_icct_rack_peripheral_start` (`rack_id`,`start_u`)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic COMMENT='Passive peripheral placement in ICCT racks'");

    db_execute("CREATE TABLE IF NOT EXISTS `plugin_icct_rack_audit` (
        `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        `user_id` int(10) unsigned NOT NULL DEFAULT 0,
        `action` varchar(64) NOT NULL,
        `entity_type` varchar(32) NOT NULL,
        `entity_id` int(10) unsigned NOT NULL DEFAULT 0,
        `details` text NULL,
        `ip_address` varchar(64) NOT NULL DEFAULT '',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `ix_icct_rack_audit_entity` (`entity_type`,`entity_id`),
        KEY `ix_icct_rack_audit_user` (`user_id`),
        KEY `ix_icct_rack_audit_created` (`created_at`)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic COMMENT='Rack topology audit trail'");

    /* Legacy v1.x cleanup, preserving the current front-view placements. */
    if (icct_rack_column_exists('plugin_icct_rack_placements', 'face')) {
        if (icct_rack_index_exists('plugin_icct_rack_placements', 'ix_icct_rack_placement_face_start')) {
            db_execute('ALTER TABLE plugin_icct_rack_placements DROP INDEX ix_icct_rack_placement_face_start');
        }
        db_execute("DELETE FROM plugin_icct_rack_placements WHERE face = 'rear'");
        db_execute('ALTER TABLE plugin_icct_rack_placements DROP COLUMN face');
    }

    foreach ([
        ['plugin_icct_rack_racks', 'room'],
        ['plugin_icct_rack_racks', 'description'],
        ['plugin_icct_rack_placements', 'asset_tag'],
        ['plugin_icct_rack_placements', 'power_feed'],
    ] as $legacy) {
        if (icct_rack_column_exists($legacy[0], $legacy[1])) {
            db_execute('ALTER TABLE ' . $legacy[0] . ' DROP COLUMN ' . $legacy[1]);
        }
    }

    if (read_config_option('icct_rack_refresh_seconds') === '') {
        set_config_option('icct_rack_refresh_seconds', '30');
    }

    set_config_option('icct_rack_schema_version', '2.3.0');
}

function icct_rack_tables_ready() {
    foreach ([
        'plugin_icct_rack_racks',
        'plugin_icct_rack_placements',
        'plugin_icct_rack_peripheral_placements',
        'plugin_icct_rack_audit',
    ] as $table) {
        $exists = db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        if ((int)$exists !== 1) {
            return false;
        }
    }
    return true;
}
