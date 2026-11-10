<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */

function icct_inventory_setup_database() {
    db_execute("CREATE TABLE IF NOT EXISTS plugin_icct_inv_device_types (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        bom_ref VARCHAR(32) NOT NULL DEFAULT '',
        item_description VARCHAR(512) NOT NULL,
        make_name VARCHAR(191) NOT NULL DEFAULT '',
        model VARCHAR(512) NOT NULL DEFAULT '',
        part_no VARCHAR(512) NOT NULL DEFAULT '',
        management_interface VARCHAR(255) NOT NULL DEFAULT '',
        management_protocol VARCHAR(512) NOT NULL DEFAULT '',
        fcaps_summary VARCHAR(32) NOT NULL DEFAULT '',
        syslog_supported VARCHAR(16) NOT NULL DEFAULT '',
        cross_launch_supported VARCHAR(16) NOT NULL DEFAULT '',
        general_remarks VARCHAR(512) NOT NULL DEFAULT '',
        enabled CHAR(2) NOT NULL DEFAULT 'on',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY ux_bom_item (bom_ref, item_description(180)),
        KEY enabled (enabled)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic COMMENT='Client device type catalogue for ICCT LNMS'");

    db_execute("CREATE TABLE IF NOT EXISTS plugin_icct_inv_segments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(128) NOT NULL,
        cidr VARCHAR(64) NOT NULL DEFAULT '',
        description VARCHAR(255) NOT NULL DEFAULT '',
        enabled CHAR(2) NOT NULL DEFAULT 'on',
        created_by INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY ux_segment_name (name)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic COMMENT='Logical network segments for ICCT LNMS'");

    db_execute("CREATE TABLE IF NOT EXISTS plugin_icct_inv_device_meta (
        host_id MEDIUMINT UNSIGNED NOT NULL,
        device_type_id INT UNSIGNED NOT NULL DEFAULT 0,
        segment_id INT UNSIGNED NOT NULL DEFAULT 0,
        serial_number VARCHAR(191) NOT NULL DEFAULT '',
        mac_address VARCHAR(64) NOT NULL DEFAULT '',
        maintenance_due_date DATE NULL DEFAULT NULL,
        serviceability VARCHAR(32) NOT NULL DEFAULT 'Unknown',
        cross_launch_url VARCHAR(512) NOT NULL DEFAULT '',
        notes TEXT NULL,
        updated_by INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (host_id),
        KEY device_type_id (device_type_id),
        KEY segment_id (segment_id),
        KEY serviceability (serviceability)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic COMMENT='Plugin-owned metadata for actual Cacti devices'");

    db_execute("CREATE TABLE IF NOT EXISTS plugin_icct_inv_oids (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        device_type_id INT UNSIGNED NOT NULL DEFAULT 0,
        area VARCHAR(16) NOT NULL DEFAULT 'F',
        parameter_name VARCHAR(191) NOT NULL,
        oid VARCHAR(255) NOT NULL,
        collection_method VARCHAR(32) NOT NULL DEFAULT 'poll',
        data_type VARCHAR(32) NOT NULL DEFAULT 'string',
        unit VARCHAR(32) NOT NULL DEFAULT '',
        severity VARCHAR(16) NOT NULL DEFAULT 'info',
        comparison VARCHAR(24) NOT NULL DEFAULT '',
        threshold_value VARCHAR(64) NOT NULL DEFAULT '',
        notes VARCHAR(512) NOT NULL DEFAULT '',
        enabled CHAR(2) NOT NULL DEFAULT 'on',
        created_by INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY device_type_id (device_type_id),
        KEY area (area),
        KEY enabled (enabled),
        UNIQUE KEY ux_type_oid (device_type_id, oid, parameter_name)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic COMMENT='FCAPS and inventory OID catalogue'");

    db_execute("CREATE TABLE IF NOT EXISTS plugin_icct_inv_audit (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL DEFAULT 0,
        action VARCHAR(64) NOT NULL,
        entity_type VARCHAR(32) NOT NULL,
        entity_id INT UNSIGNED NOT NULL DEFAULT 0,
        details TEXT NULL,
        ip_address VARCHAR(64) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY created_at (created_at),
        KEY user_id (user_id),
        KEY entity_ref (entity_type, entity_id)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic COMMENT='ICCT inventory audit trail'");

    icct_inventory_seed_device_types();
    icct_inventory_seed_oids();
}

function icct_inventory_seed_device_types() {
    global $config;
    $file = $config['base_path'] . '/plugins/icct_inventory/data/device_types_seed.json';
    if (!is_file($file)) return;
    $items = json_decode((string)file_get_contents($file), true);
    if (!is_array($items)) return;

    foreach ($items as $item) {
        db_execute_prepared(
            "INSERT INTO plugin_icct_inv_device_types
            (bom_ref, item_description, make_name, model, part_no, management_interface, management_protocol, fcaps_summary, syslog_supported, cross_launch_supported, general_remarks, enabled, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'on', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                make_name = VALUES(make_name), model = VALUES(model), part_no = VALUES(part_no),
                management_interface = VALUES(management_interface), management_protocol = VALUES(management_protocol),
                fcaps_summary = VALUES(fcaps_summary), syslog_supported = VALUES(syslog_supported),
                cross_launch_supported = VALUES(cross_launch_supported), general_remarks = VALUES(general_remarks),
                updated_at = NOW()",
            [
                (string)($item['bom_ref'] ?? ''),
                (string)($item['item_description'] ?? ''),
                (string)($item['make'] ?? ''),
                (string)($item['model'] ?? ''),
                (string)($item['part_no'] ?? ''),
                (string)($item['management_interface'] ?? ''),
                (string)($item['management_protocol'] ?? ''),
                (string)($item['fcaps_summary'] ?? ''),
                (string)($item['syslog_supported'] ?? ''),
                (string)($item['cross_launch_supported'] ?? ''),
                (string)($item['general_remarks'] ?? ''),
            ]
        );
    }
}

function icct_inventory_seed_oids() {
    $defaults = [
        [0, 'INV', 'System description', '.1.3.6.1.2.1.1.1.0', 'inventory', 'string', '', 'info', '', '', 'SNMPv2-MIB::sysDescr.0'],
        [0, 'F', 'System uptime', '.1.3.6.1.2.1.1.3.0', 'poll', 'timeticks', '', 'warning', '', '', 'SNMPv2-MIB::sysUpTime.0'],
        [0, 'C', 'System name', '.1.3.6.1.2.1.1.5.0', 'inventory', 'string', '', 'info', '', '', 'SNMPv2-MIB::sysName.0'],
        [0, 'C', 'System location', '.1.3.6.1.2.1.1.6.0', 'inventory', 'string', '', 'info', '', '', 'SNMPv2-MIB::sysLocation.0'],
        [0, 'F', 'Interface operational status', '.1.3.6.1.2.1.2.2.1.8', 'walk', 'integer', '', 'critical', 'not_equals', '1', 'IF-MIB::ifOperStatus; indexed table'],
        [0, 'C', 'Interface administrative status', '.1.3.6.1.2.1.2.2.1.7', 'walk', 'integer', '', 'warning', '', '', 'IF-MIB::ifAdminStatus; indexed table'],
        [0, 'A', 'Interface input octets', '.1.3.6.1.2.1.2.2.1.10', 'walk', 'counter32', 'bytes', 'info', '', '', 'IF-MIB::ifInOctets'],
        [0, 'A', 'Interface output octets', '.1.3.6.1.2.1.2.2.1.16', 'walk', 'counter32', 'bytes', 'info', '', '', 'IF-MIB::ifOutOctets'],
        [0, 'P', 'High-capacity input octets', '.1.3.6.1.2.1.31.1.1.1.6', 'walk', 'counter64', 'bytes', 'info', '', '', 'IF-MIB::ifHCInOctets'],
        [0, 'P', 'High-capacity output octets', '.1.3.6.1.2.1.31.1.1.1.10', 'walk', 'counter64', 'bytes', 'info', '', '', 'IF-MIB::ifHCOutOctets'],
        [0, 'INV', 'Physical serial number', '.1.3.6.1.2.1.47.1.1.1.1.11', 'walk', 'string', '', 'info', '', '', 'ENTITY-MIB::entPhysicalSerialNum; indexed table'],
        [0, 'TOPO', 'LLDP remote systems table', '.1.0.8802.1.1.2.1.4.1.1', 'walk', 'table', '', 'info', '', '', 'LLDP-MIB::lldpRemTable'],
        [0, 'TOPO', 'Cisco CDP cache table', '.1.3.6.1.4.1.9.9.23.1.2.1.1', 'walk', 'table', '', 'info', '', '', 'CISCO-CDP-MIB::cdpCacheTable; Cisco only'],
        [0, 'S', 'SNMP engine ID', '.1.3.6.1.6.3.10.2.1.1.0', 'inventory', 'octet-string', '', 'info', '', '', 'SNMP-FRAMEWORK-MIB::snmpEngineID.0; useful for SNMPv3 identity'],
    ];

    foreach ($defaults as $row) {
        db_execute_prepared(
            "INSERT IGNORE INTO plugin_icct_inv_oids
            (device_type_id, area, parameter_name, oid, collection_method, data_type, unit, severity, comparison, threshold_value, notes, enabled, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'on', 0, NOW(), NOW())",
            $row
        );
    }
}

function icct_inventory_tables_ready() {
    foreach (['plugin_icct_inv_device_types','plugin_icct_inv_segments','plugin_icct_inv_device_meta','plugin_icct_inv_oids','plugin_icct_inv_audit'] as $table) {
        $count = db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
        if ((int)$count !== 1) return false;
    }
    return true;
}
