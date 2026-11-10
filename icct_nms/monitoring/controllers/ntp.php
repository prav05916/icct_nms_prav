<?php
/**
 * NTP Monitoring overview — shows NTP status for all monitored devices.
 * Handles CSV and JSON export (Requirement 5.3.3.5).
 */
require dirname(__DIR__, 2) . '/../../include/auth.php';
require_once dirname(__DIR__, 2) . '/shared/services/bootstrap.php';
require_once dirname(__DIR__, 2) . '/monitoring/services/ntp_service.php';

// Handle CSV/JSON export (Requirement 5.3.3.5)
if (isset($_GET['export'])) {
    icct_nms_backend();
    $format = $_GET['export'];

    if ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="ntp_report_' . date('Ymd_His') . '.json"');
        echo icct_nms_ntp_export_json();
        exit;
    } elseif ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="ntp_report_' . date('Ymd_His') . '.csv"');
        echo icct_nms_ntp_export_csv();
        exit;
    }
}

try {
    icct_nms_backend();
    $title = 'NTP Monitoring';
    require dirname(__DIR__, 2) . '/shared/templates/header.php';
    $ntpDevices = icct_nms_ntp_all();
    require dirname(__DIR__, 2) . '/monitoring/templates/ntp_view.php';
    require dirname(__DIR__, 2) . '/shared/templates/footer.php';
} catch (Throwable $e) {
    icct_nms_failure($e);
}
