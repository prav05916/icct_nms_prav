<?php
/**
 * NetFlow Monitoring overview — shows NetFlow stats, analytics, anomalies, and reports for all exporters.
 * Handles CSV and JSON export (Requirement 5.3.3.5).
 */
require dirname(__DIR__, 2) . '/../../include/auth.php';
require_once dirname(__DIR__, 2) . '/shared/services/bootstrap.php';
require_once dirname(__DIR__, 2) . '/monitoring/services/netflow_service.php';

// Handle CSV/JSON export (Requirement 5.3.3.5)
if (isset($_GET['export'])) {
    icct_nms_backend();
    $format = $_GET['export'];
    $type   = $_GET['type'] ?? 'top_hosts';
    $hostId = (int)($_GET['host_id'] ?? 0);

    if ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="netflow_report_' . date('Ymd_His') . '.json"');
        echo icct_nms_netflow_export_json($hostId);
        exit;
    } elseif ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="netflow_' . $type . '_' . date('Ymd_His') . '.csv"');
        echo icct_nms_netflow_export_csv($type, $hostId);
        exit;
    }
}

try {
    icct_nms_backend();
    $forceSample = isset($_GET['sample']) || isset($_GET['poll']) || isset($_GET['refresh']);
    $netflowDevices = icct_nms_netflow_all($forceSample || true);

    if (isset($_GET['ajax']) || (isset($_GET['format']) && $_GET['format'] === 'json_api')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'        => true,
            'timestamp' => date('Y-m-d H:i:s'),
            'devices'   => $netflowDevices,
            'top_hosts' => icct_nms_netflow_top_hosts(0, 15),
            'anomalies' => icct_nms_netflow_anomalies(0, 10),
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $title = 'NetFlow Monitoring';
    require dirname(__DIR__, 2) . '/shared/templates/header.php';
    require dirname(__DIR__, 2) . '/monitoring/templates/netflow_view.php';
    require dirname(__DIR__, 2) . '/shared/templates/footer.php';
} catch (Throwable $e) {
    icct_nms_failure($e);
}
