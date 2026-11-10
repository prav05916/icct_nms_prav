<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */
function icct_map_setup_database() {
    db_execute("CREATE TABLE IF NOT EXISTS `plugin_icct_map_site_positions` (
      `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      `site_id` int(10) unsigned NOT NULL,
      `latitude` decimal(11,8) NOT NULL,
      `longitude` decimal(11,8) NOT NULL,
      `source` varchar(32) NOT NULL DEFAULT 'gnss',
      `recorded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `ix_icct_map_site_position_site_time` (`site_id`,`recorded_at`)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic COMMENT='Historical GNSS positions for Cacti Sites'");

    db_execute("CREATE TABLE IF NOT EXISTS `plugin_icct_map_audit` (
      `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      `user_id` int(10) unsigned NOT NULL DEFAULT 0,
      `action` varchar(64) NOT NULL,
      `entity_type` varchar(32) NOT NULL,
      `entity_id` int(10) unsigned NOT NULL DEFAULT 0,
      `details` text NULL,
      `ip_address` varchar(64) NOT NULL DEFAULT '',
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `ix_icct_map_audit_entity` (`entity_type`,`entity_id`),
      KEY `ix_icct_map_audit_created` (`created_at`)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic COMMENT='ICCT map audit trail'");

    if (read_config_option('icct_map_refresh_seconds') === '') set_config_option('icct_map_refresh_seconds', '30');
    if (read_config_option('icct_map_show_labels') === '') set_config_option('icct_map_show_labels', 'on');
    if (read_config_option('icct_map_track_enabled') === '') set_config_option('icct_map_track_enabled', 'on');
    set_config_option('icct_map_schema_version', '1.6.0');
}
