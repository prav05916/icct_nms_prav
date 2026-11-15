<?php
/**
 * NetFlow reading windows, rule evaluation and historical separation.
 */

if (!function_exists('icct_nms_netflow_rules')) {
    function icct_nms_netflow_rules($id) {
        if (!function_exists('db_fetch_cell_prepared')) return [];
        $stored = db_fetch_cell_prepared(
            'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
            ['netflow_rules_' . (int)$id]
        );
        if (!$stored || !is_string($stored)) return [];
        try {
            $decoded = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('icct_nms_netflow_live_readings')) {
    function icct_nms_netflow_live_readings($hostId): array {
        if (!function_exists('db_fetch_cell_prepared')) {
            return ['flows' => 0, 'values' => []];
        }
        $latest = (int) db_fetch_cell_prepared(
            'SELECT MAX(epoch) FROM plugin_icct_nms_netflow_readings WHERE host_id = ?',
            [$hostId]
        );
        $window = 300;
        $now = time();
        if ($latest <= 0 || ($now - $latest) > $window) {
            return ['flows' => 0, 'values' => []];
        }
        $row = db_fetch_row_prepared(
            'SELECT * FROM plugin_icct_nms_netflow_readings WHERE host_id = ? AND epoch >= ? AND epoch <= ?',
            [$hostId, $latest - $window, $latest]
        );
        if (!$row) {
            return ['flows' => 0, 'values' => []];
        }
        $flows = (int)($row['flows'] ?? 0);
        $values = $row;
        unset($values['flows'], $values['latest_epoch'], $values['host_id']);
        return ['flows' => $flows, 'values' => $values];
    }
}

if (!function_exists('icct_nms_netflow_last_readings')) {
    function icct_nms_netflow_last_readings($hostId): ?array {
        if (!function_exists('db_fetch_cell_prepared')) {
            return null;
        }
        $latest = (int) db_fetch_cell_prepared(
            'SELECT MAX(epoch) FROM plugin_icct_nms_netflow_readings WHERE host_id = ?',
            [$hostId]
        );
        if ($latest <= 0) {
            return null;
        }
        $window = 300;
        $now = time();
        if (($now - $latest) <= $window) {
            return null;
        }
        $row = db_fetch_row_prepared(
            'SELECT * FROM plugin_icct_nms_netflow_readings WHERE host_id = ? AND epoch >= ? AND epoch <= ?',
            [$hostId, $latest - $window, $latest]
        );
        if (!$row) {
            return null;
        }
        $flows = (int)($row['flows'] ?? 0);
        $values = $row;
        unset($values['flows'], $values['latest_epoch'], $values['host_id']);
        return ['flows' => $flows, 'values' => $values];
    }
}

