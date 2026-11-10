<?php
/**
 * Per-device NetFlow tab panel — included by device_view.php.
 * Expects $id (int) to be set by the parent template.
 * Displays all analytics: Top Hosts, Protocols, Applications, Conversations,
 * WAN/LAN Trends, Anomalies, Over-utilization (Requirements 5.3.3.4.1–5.3.3.4.6).
 */
require_once dirname(__DIR__) . '/services/netflow_service.php';
$netflowStatus  = icct_nms_netflow_status($id);
$netflowTalkers = icct_nms_netflow_top_talkers($id);
$nfTopHosts     = icct_nms_netflow_top_hosts($id, 10);
$nfProtocols    = icct_nms_netflow_protocols_breakdown($id);
$nfApps         = icct_nms_netflow_applications_breakdown($id, 10);
$nfConvs        = icct_nms_netflow_conversations($id, 10);
$nfTrends       = icct_nms_netflow_wan_lan_trends($id, 12);
$nfAnomalies    = icct_nms_netflow_anomalies($id, 10);
$nfOverUtil     = icct_nms_netflow_overutilization_analysis($id);

$nfDisplay = static function($value) { return icct_nms_h(trim((string)$value) !== '' ? $value : '—'); };
?>
<section id="view-netflow" data-device-view-panel="netflow" hidden>
    <h2>NetFlow</h2>
<?php if ($netflowStatus): ?>
    <section class="device-view-section">
        <h3>NetFlow Summary</h3>
        <dl class="device-detail-grid">
            <div><dt>Exporter IP</dt><dd><?= $nfDisplay($netflowStatus['exporter_ip']) ?></dd></div>
            <div><dt>Collector IP:Port</dt><dd><?= icct_nms_h($netflowStatus['collector_ip']) ?>:<?= (int)$netflowStatus['collector_port'] ?></dd></div>
            <div><dt>Flow Version</dt><dd><?= $netflowStatus['flow_version'] !== null ? 'v' . (int)$netflowStatus['flow_version'] : '—' ?></dd></div>
            <div><dt>Active Flows</dt><dd><?= icct_nms_h(number_format((int)$netflowStatus['active_flows'])) ?></dd></div>
            <div><dt>Flows/s</dt><dd><?= $netflowStatus['flows_per_second'] !== null ? icct_nms_h(number_format((float)$netflowStatus['flows_per_second'], 1)) : '—' ?></dd></div>
            <div><dt>Throughput</dt><dd><?= $netflowStatus['bytes_per_second'] !== null ? icct_nms_h(icct_nms_netflow_bytes_human((int)$netflowStatus['bytes_per_second'])) . '/s' : '—' ?></dd></div>
            <div><dt>Top Talker</dt><dd><?= $nfDisplay($netflowStatus['top_talker_ip']) ?><?= $netflowStatus['top_talker_bytes'] !== null ? ' (' . icct_nms_h(icct_nms_netflow_bytes_human((int)$netflowStatus['top_talker_bytes'])) . ')' : '' ?></dd></div>
            <div><dt>Anomaly Status</dt><dd><span class="badge <?= ($netflowStatus['anomaly_status'] ?? 'normal') === 'normal' ? 'badge-green' : 'badge-red' ?>"><?= ucfirst(icct_nms_h($netflowStatus['anomaly_status'] ?? 'normal')) ?></span></dd></div>
            <div><dt>Last Polled</dt><dd><?= $nfDisplay($netflowStatus['last_polled'] ?? '') ?></dd></div>
        </dl>
    </section>

    <!-- Over-utilization Analysis -->
    <section class="device-view-section">
        <h3>Over-utilization Analysis</h3>
        <div style="padding:8px;border:1px solid <?= $nfOverUtil['has_congestion'] ? '#e74c3c' : '#27ae60' ?>;border-radius:4px;background:<?= $nfOverUtil['has_congestion'] ? '#fdf2f2' : '#f0faf0' ?>">
            <p><?= icct_nms_h($nfOverUtil['summary']) ?></p>
        </div>
    </section>

    <!-- Top Talkers -->
    <section class="device-view-section">
        <h3>Top Talkers</h3>
<?php if ($netflowTalkers): ?>
        <div class="site-table-wrap">
        <table class="site-table">
            <thead><tr><th>Src IP</th><th>Dst IP</th><th>Protocol</th><th>Src Port</th><th>Dst Port</th><th>Bytes</th><th>Packets</th></tr></thead>
            <tbody>
            <?php foreach ($netflowTalkers as $talker): ?>
            <tr>
                <td><?= icct_nms_h($talker['src_ip']) ?></td>
                <td><?= icct_nms_h($talker['dst_ip']) ?></td>
                <td><?= icct_nms_h($talker['protocol']) ?></td>
                <td><?= $talker['src_port'] !== null ? (int)$talker['src_port'] : '—' ?></td>
                <td><?= $talker['dst_port'] !== null ? (int)$talker['dst_port'] : '—' ?></td>
                <td><?= icct_nms_h(icct_nms_netflow_bytes_human((int)$talker['bytes'])) ?></td>
                <td><?= icct_nms_h(number_format((int)$talker['packets'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
<?php else: ?>
        <p>No top-talker records available for this device.</p>
<?php endif; ?>
    </section>

    <!-- Protocols Breakdown -->
<?php if ($nfProtocols): ?>
    <section class="device-view-section">
        <h3>Protocol Breakdown</h3>
        <div class="site-table-wrap">
        <table class="site-table">
            <thead><tr><th>Protocol</th><th>Bytes</th><th>Packets</th><th>%</th></tr></thead>
            <tbody>
            <?php foreach ($nfProtocols as $p): ?>
            <tr>
                <td><strong><?= icct_nms_h($p['protocol']) ?></strong></td>
                <td><?= icct_nms_h($p['bytes_human']) ?></td>
                <td><?= icct_nms_h(number_format((int)$p['total_packets'])) ?></td>
                <td><?= icct_nms_h($p['percentage']) ?>%</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endif; ?>

    <!-- Applications Breakdown -->
<?php if ($nfApps): ?>
    <section class="device-view-section">
        <h3>Application Breakdown</h3>
        <div class="site-table-wrap">
        <table class="site-table">
            <thead><tr><th>Application</th><th>Protocol</th><th>Bytes</th><th>%</th></tr></thead>
            <tbody>
            <?php foreach ($nfApps as $a): ?>
            <tr>
                <td><strong><?= icct_nms_h($a['app_name']) ?></strong></td>
                <td><?= icct_nms_h($a['protocol']) ?></td>
                <td><?= icct_nms_h($a['bytes_human']) ?></td>
                <td><?= icct_nms_h($a['percentage']) ?>%</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endif; ?>

    <!-- WAN/LAN Trends -->
<?php if ($nfTrends): ?>
    <section class="device-view-section">
        <h3>WAN vs LAN Bandwidth Trends</h3>
        <div class="site-table-wrap">
        <table class="site-table">
            <thead><tr><th>Time</th><th>LAN Bytes</th><th>WAN Bytes</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($nfTrends as $t): ?>
            <tr>
                <td><?= icct_nms_h($t['time_slot']) ?></td>
                <td><?= icct_nms_h(icct_nms_netflow_bytes_human((int)$t['lan_bytes'])) ?></td>
                <td><?= icct_nms_h(icct_nms_netflow_bytes_human((int)$t['wan_bytes'])) ?></td>
                <td><?= icct_nms_h(icct_nms_netflow_bytes_human((int)$t['total_bytes'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endif; ?>

    <!-- Anomalies -->
<?php if ($nfAnomalies): ?>
    <section class="device-view-section">
        <h3>Security Anomalies</h3>
        <div class="site-table-wrap">
        <table class="site-table">
            <thead><tr><th>Detected At</th><th>Type</th><th>Severity</th><th>Source</th><th>Target</th><th>Description</th></tr></thead>
            <tbody>
            <?php foreach ($nfAnomalies as $an):
                $sevBadge = ($an['severity'] === 'critical') ? 'badge-red' : (($an['severity'] === 'high') ? 'badge-orange' : 'badge-yellow');
            ?>
            <tr>
                <td><?= icct_nms_h($an['detected_at']) ?></td>
                <td><?= icct_nms_h(str_replace('_', ' ', ucfirst($an['anomaly_type']))) ?></td>
                <td><span class="badge <?= $sevBadge ?>"><?= strtoupper(icct_nms_h($an['severity'])) ?></span></td>
                <td><?= icct_nms_h($an['source_ip']) ?></td>
                <td><?= icct_nms_h($an['target_ip']) ?><?= $an['target_port'] ? ':' . (int)$an['target_port'] : '' ?></td>
                <td style="max-width:300px;word-wrap:break-word"><?= icct_nms_h($an['description']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endif; ?>

<?php else: ?>
    <p>No NetFlow data available for this device. Ensure the NetFlow poller has run and collected data.</p>
<?php endif; ?>
</section>
