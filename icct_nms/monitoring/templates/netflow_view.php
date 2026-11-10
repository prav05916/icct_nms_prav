<?php
/**
 * NetFlow Monitoring overview template — lists all NetFlow-monitored devices
 * plus full analytics tabs: Top Hosts, Protocols, Applications, Conversations,
 * WAN vs LAN Bandwidth Trends, Anomalies & Security, Over-utilization, and Reports.
 *
 * Data: $netflowDevices (array from icct_nms_netflow_all())
 */

// Gather analytics data
$topHosts      = icct_nms_netflow_top_hosts(0, 15);
$protocols     = icct_nms_netflow_protocols_breakdown(0);
$applications  = icct_nms_netflow_applications_breakdown(0, 15);
$conversations = icct_nms_netflow_conversations(0, 15);
$wanLanTrends  = icct_nms_netflow_wan_lan_trends(0, 24);
$anomalies     = icct_nms_netflow_anomalies(0, 20);
$overUtil      = icct_nms_netflow_overutilization_analysis(0);
?>
<div class="titlebar">
    <h1><?= icct_nms_h($title) ?></h1>
</div>

<!-- Analytics Tabs -->
<div class="netflow-tabs" style="margin-bottom:12px;border-bottom:2px solid #0073aa">
    <button class="netflow-tab-btn active" data-tab="devices">Devices</button>
    <button class="netflow-tab-btn" data-tab="top-hosts">Top Hosts</button>
    <button class="netflow-tab-btn" data-tab="protocols">Protocols</button>
    <button class="netflow-tab-btn" data-tab="applications">Applications</button>
    <button class="netflow-tab-btn" data-tab="conversations">Conversations</button>
    <button class="netflow-tab-btn" data-tab="trends">WAN/LAN Trends</button>
    <button class="netflow-tab-btn" data-tab="anomalies">Anomalies & Security</button>
    <button class="netflow-tab-btn" data-tab="overutil">Over-utilization</button>
    <button class="netflow-tab-btn" data-tab="reports">Reports</button>
</div>

<!-- Tab: Devices -->
<div class="netflow-panel" id="panel-devices">
<div class="site-table-controls">
    <label class="site-table-search-label" for="netflow-search">
        <span class="sr-only">Filter devices</span>
        <input type="search" id="netflow-search" class="site-table-search" placeholder="Filter devices…" aria-label="Filter NetFlow devices">
    </label>
    <span class="site-table-count" id="netflow-count">
        <?= count($netflowDevices) ?> device<?= count($netflowDevices) !== 1 ? 's' : '' ?>
    </span>
</div>

<div class="site-table-wrap">
<?php if ($netflowDevices): ?>
<table class="site-table" id="netflow-table">
    <thead>
        <tr>
            <th>Device</th>
            <th>Exporter IP</th>
            <th>Collector IP:Port</th>
            <th>Flow Version</th>
            <th>Active Flows</th>
            <th>Flows/s</th>
            <th>Throughput</th>
            <th>Top Talker</th>
            <th>Anomaly</th>
            <th>Status</th>
            <th>Last Polled</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($netflowDevices as $row):
        $fps        = isset($row['flows_per_second']) ? (float)$row['flows_per_second'] : null;
        $bps        = isset($row['bytes_per_second']) ? (int)$row['bytes_per_second']   : null;
        $talkerBytes = isset($row['top_talker_bytes']) ? (int)$row['top_talker_bytes']  : null;
        $st         = $row['status'] ?? 'listening';
        $stBadge    = $st === 'active' ? 'badge-green' : ($st === 'listening' ? 'badge-yellow' : 'badge-grey');
        $stLabel    = ucfirst($st);
        $anomSt     = $row['anomaly_status'] ?? 'normal';
        $anomBadge  = ($anomSt === 'critical') ? 'badge-red' : (($anomSt === 'attack' || $anomSt === 'warning') ? 'badge-orange' : 'badge-green');
    ?>
    <tr data-filter-text="<?= icct_nms_h(strtolower($row['description'] . ' ' . $row['hostname'] . ' ' . $row['exporter_ip'])) ?>">
        <td><a href="inventory/controllers/device.php?id=<?= (int)$row['host_id'] ?>&amp;view=1#view-netflow"><?= icct_nms_h($row['description']) ?></a></td>
        <td><?= icct_nms_h($row['exporter_ip']) ?></td>
        <td><?= icct_nms_h($row['collector_ip']) ?>:<?= (int)$row['collector_port'] ?></td>
        <td><?= $row['flow_version'] !== null ? 'v' . (int)$row['flow_version'] : '—' ?></td>
        <td><?= icct_nms_h(number_format((int)$row['active_flows'])) ?></td>
        <td><?= $fps !== null ? icct_nms_h(number_format($fps, 1)) : '—' ?></td>
        <td><?= $bps !== null ? icct_nms_h(icct_nms_netflow_bytes_human($bps)) . '/s' : '—' ?></td>
        <td>
            <?php if ($row['top_talker_ip'] !== ''): ?>
                <?= icct_nms_h($row['top_talker_ip']) ?>
                <?= $talkerBytes !== null ? '(' . icct_nms_h(icct_nms_netflow_bytes_human($talkerBytes)) . ')' : '' ?>
            <?php else: ?>—<?php endif; ?>
        </td>
        <td><span class="badge <?= $anomBadge ?>"><?= icct_nms_h(ucfirst($anomSt)) ?></span></td>
        <td><span class="badge <?= $stBadge ?>"><?= icct_nms_h($stLabel) ?></span></td>
        <td><?= icct_nms_h($row['last_polled'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p class="site-table-empty">No NetFlow exporters found. Configure the NetFlow poller to populate this view.</p>
<?php endif; ?>
</div>
</div>

<!-- Tab: Top Hosts (Requirement 5.3.3.4.4 (a)) -->
<div class="netflow-panel" id="panel-top-hosts" hidden>
<h2>Top Hosts (Talkers)</h2>
<?php if ($topHosts): ?>
<table class="site-table">
    <thead><tr><th>Host IP</th><th>Network</th><th>Total Bytes</th><th>Packets</th><th>Flows</th><th>% of Total</th></tr></thead>
    <tbody>
    <?php foreach ($topHosts as $h): ?>
    <tr>
        <td><?= icct_nms_h($h['host_ip']) ?></td>
        <td><span class="badge <?= $h['network_type'] === 'wan' ? 'badge-blue' : 'badge-green' ?>"><?= strtoupper($h['network_type']) ?></span></td>
        <td><?= icct_nms_h($h['bytes_human']) ?></td>
        <td><?= icct_nms_h(number_format((int)$h['total_packets'])) ?></td>
        <td><?= icct_nms_h(number_format((int)$h['flow_count'])) ?></td>
        <td>
            <div style="display:flex;align-items:center;gap:6px">
                <div style="background:#e0e0e0;border-radius:3px;width:100px;height:12px"><div style="background:#0073aa;border-radius:3px;height:12px;width:<?= min(100, (float)$h['percentage']) ?>px"></div></div>
                <?= icct_nms_h($h['percentage']) ?>%
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p>No flow records available yet. Wait for the NetFlow collector to receive data.</p>
<?php endif; ?>
</div>

<!-- Tab: Protocols (Requirement 5.3.3.4.4 (b)) -->
<div class="netflow-panel" id="panel-protocols" hidden>
<h2>Protocol Breakdown</h2>
<?php if ($protocols): ?>
<table class="site-table">
    <thead><tr><th>Protocol</th><th>Total Bytes</th><th>Packets</th><th>Flows</th><th>% of Total</th></tr></thead>
    <tbody>
    <?php foreach ($protocols as $p): ?>
    <tr>
        <td><strong><?= icct_nms_h($p['protocol']) ?></strong></td>
        <td><?= icct_nms_h($p['bytes_human']) ?></td>
        <td><?= icct_nms_h(number_format((int)$p['total_packets'])) ?></td>
        <td><?= icct_nms_h(number_format((int)$p['flow_count'])) ?></td>
        <td>
            <div style="display:flex;align-items:center;gap:6px">
                <div style="background:#e0e0e0;border-radius:3px;width:100px;height:12px"><div style="background:#27ae60;border-radius:3px;height:12px;width:<?= min(100, (float)$p['percentage']) ?>px"></div></div>
                <?= icct_nms_h($p['percentage']) ?>%
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p>No protocol data available yet.</p>
<?php endif; ?>
</div>

<!-- Tab: Applications (Requirement 5.3.3.4.4 (c)) -->
<div class="netflow-panel" id="panel-applications" hidden>
<h2>Application Breakdown</h2>
<?php if ($applications): ?>
<table class="site-table">
    <thead><tr><th>Application</th><th>Protocol</th><th>Total Bytes</th><th>Packets</th><th>Flows</th><th>% of Total</th></tr></thead>
    <tbody>
    <?php foreach ($applications as $a): ?>
    <tr>
        <td><strong><?= icct_nms_h($a['app_name']) ?></strong></td>
        <td><?= icct_nms_h($a['protocol']) ?></td>
        <td><?= icct_nms_h($a['bytes_human']) ?></td>
        <td><?= icct_nms_h(number_format((int)$a['total_packets'])) ?></td>
        <td><?= icct_nms_h(number_format((int)$a['flow_count'])) ?></td>
        <td>
            <div style="display:flex;align-items:center;gap:6px">
                <div style="background:#e0e0e0;border-radius:3px;width:100px;height:12px"><div style="background:#e67e22;border-radius:3px;height:12px;width:<?= min(100, (float)$a['percentage']) ?>px"></div></div>
                <?= icct_nms_h($a['percentage']) ?>%
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p>No application data available yet.</p>
<?php endif; ?>
</div>

<!-- Tab: Conversations (Requirement 5.3.3.4.4 (d)) -->
<div class="netflow-panel" id="panel-conversations" hidden>
<h2>Conversations (Source ↔ Destination)</h2>
<?php if ($conversations): ?>
<table class="site-table">
    <thead><tr><th>Source IP:Port</th><th>Destination IP:Port</th><th>Protocol</th><th>Application</th><th>Network</th><th>Bytes</th><th>Packets</th><th>Last Seen</th></tr></thead>
    <tbody>
    <?php foreach ($conversations as $c): ?>
    <tr>
        <td><?= icct_nms_h($c['src_ip']) ?>:<?= (int)$c['src_port'] ?></td>
        <td><?= icct_nms_h($c['dst_ip']) ?>:<?= (int)$c['dst_port'] ?></td>
        <td><?= icct_nms_h($c['protocol']) ?></td>
        <td><?= icct_nms_h($c['app_name']) ?></td>
        <td><span class="badge <?= $c['network_type'] === 'wan' ? 'badge-blue' : 'badge-green' ?>"><?= strtoupper($c['network_type']) ?></span></td>
        <td><?= icct_nms_h($c['bytes_human']) ?></td>
        <td><?= icct_nms_h(number_format((int)$c['total_packets'])) ?></td>
        <td><?= icct_nms_h($c['last_seen'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p>No conversation data available yet.</p>
<?php endif; ?>
</div>

<!-- Tab: WAN/LAN Trends (Requirement 5.3.3.4.2) -->
<div class="netflow-panel" id="panel-trends" hidden>
<h2>WAN vs LAN Bandwidth Trends</h2>
<?php if ($wanLanTrends): ?>
<table class="site-table">
    <thead><tr><th>Time Slot</th><th>LAN Bytes</th><th>WAN Bytes</th><th>LAN Packets</th><th>WAN Packets</th><th>Total Bytes</th></tr></thead>
    <tbody>
    <?php foreach ($wanLanTrends as $t): ?>
    <tr>
        <td><?= icct_nms_h($t['time_slot']) ?></td>
        <td><?= icct_nms_h(icct_nms_netflow_bytes_human((int)$t['lan_bytes'])) ?></td>
        <td><?= icct_nms_h(icct_nms_netflow_bytes_human((int)$t['wan_bytes'])) ?></td>
        <td><?= icct_nms_h(number_format((int)$t['lan_packets'])) ?></td>
        <td><?= icct_nms_h(number_format((int)$t['wan_packets'])) ?></td>
        <td><?= icct_nms_h(icct_nms_netflow_bytes_human((int)$t['total_bytes'])) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p>No historical trend data available yet. Wait for the collector to accumulate flow data over time.</p>
<?php endif; ?>
</div>

<!-- Tab: Anomalies & Security (Requirement 5.3.3.4.5) -->
<div class="netflow-panel" id="panel-anomalies" hidden>
<h2>Anomalies & Security Alerts</h2>
<?php if ($anomalies): ?>
<table class="site-table">
    <thead><tr><th>Detected At</th><th>Device</th><th>Type</th><th>Severity</th><th>Source IP</th><th>Target</th><th>Description</th></tr></thead>
    <tbody>
    <?php foreach ($anomalies as $an):
        $sevBadge = ($an['severity'] === 'critical') ? 'badge-red' : (($an['severity'] === 'high') ? 'badge-orange' : 'badge-yellow');
    ?>
    <tr>
        <td><?= icct_nms_h($an['detected_at']) ?></td>
        <td><?= icct_nms_h($an['device_name'] ?? '—') ?></td>
        <td><?= icct_nms_h(str_replace('_', ' ', ucfirst($an['anomaly_type']))) ?></td>
        <td><span class="badge <?= $sevBadge ?>"><?= strtoupper(icct_nms_h($an['severity'])) ?></span></td>
        <td><?= icct_nms_h($an['source_ip']) ?></td>
        <td><?= icct_nms_h($an['target_ip']) ?><?= $an['target_port'] ? ':' . (int)$an['target_port'] : '' ?></td>
        <td style="max-width:350px;word-wrap:break-word"><?= icct_nms_h($an['description']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p style="color:#27ae60"><strong>✔ No anomalies detected.</strong> Traffic patterns appear normal.</p>
<?php endif; ?>
</div>

<!-- Tab: Over-utilization (Requirement 5.3.3.4.6) -->
<div class="netflow-panel" id="panel-overutil" hidden>
<h2>Network Over-utilization Analysis</h2>
<div style="padding:12px;border:1px solid <?= $overUtil['has_congestion'] ? '#e74c3c' : '#27ae60' ?>;border-radius:6px;background:<?= $overUtil['has_congestion'] ? '#fdf2f2' : '#f0faf0' ?>">
    <p style="font-size:15px"><strong><?= $overUtil['has_congestion'] ? '⚠ Over-utilization Detected' : '✔ Normal Utilization' ?></strong></p>
    <p><?= icct_nms_h($overUtil['summary']) ?></p>
    <?php if ($overUtil['top_talker_ip']): ?>
    <dl class="device-detail-grid" style="margin-top:8px">
        <div><dt>Top Talker IP</dt><dd><strong><?= icct_nms_h($overUtil['top_talker_ip']) ?></strong></dd></div>
        <div><dt>Bandwidth Share</dt><dd><?= icct_nms_h($overUtil['top_percentage']) ?>%</dd></div>
        <div><dt>Total Bytes</dt><dd><?= icct_nms_h($overUtil['top_bytes_human'] ?? icct_nms_netflow_bytes_human($overUtil['top_bytes'])) ?></dd></div>
        <div><dt>Top Application</dt><dd><?= icct_nms_h($overUtil['top_app']) ?></dd></div>
        <div><dt>Destination</dt><dd><?= icct_nms_h($overUtil['destination']) ?></dd></div>
    </dl>
    <?php endif; ?>
</div>
</div>

<!-- Tab: Reports (Requirement 5.3.3.5) -->
<div class="netflow-panel" id="panel-reports" hidden>
<h2>Reports & Export</h2>
<p>Export NetFlow data as CSV or JSON for further analysis.</p>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
    <a href="?export=csv&type=top_hosts" class="ui-button ui-corner-all">CSV: Top Hosts</a>
    <a href="?export=csv&type=protocols" class="ui-button ui-corner-all">CSV: Protocols</a>
    <a href="?export=csv&type=applications" class="ui-button ui-corner-all">CSV: Applications</a>
    <a href="?export=csv&type=conversations" class="ui-button ui-corner-all">CSV: Conversations</a>
    <a href="?export=csv&type=trends" class="ui-button ui-corner-all">CSV: WAN/LAN Trends</a>
    <a href="?export=csv&type=anomalies" class="ui-button ui-corner-all">CSV: Anomalies</a>
    <a href="?export=json" class="ui-button ui-corner-all">Full JSON Report</a>
</div>
</div>

<style>
.netflow-tabs{display:flex;gap:0;overflow-x:auto}
.netflow-tab-btn{padding:8px 14px;background:#f5f5f5;border:none;border-bottom:2px solid transparent;cursor:pointer;font-size:13px;white-space:nowrap}
.netflow-tab-btn:hover{background:#e8e8e8}
.netflow-tab-btn.active{background:#fff;border-bottom-color:#0073aa;font-weight:600;color:#0073aa}
.badge-blue{background:#3498db;color:#fff}
</style>

<script>
(function () {
    var tabs = document.querySelectorAll('.netflow-tab-btn');
    var panels = document.querySelectorAll('.netflow-panel');
    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            tabs.forEach(function (b) { b.classList.remove('active'); });
            panels.forEach(function (p) { p.hidden = true; });
            btn.classList.add('active');
            var panel = document.getElementById('panel-' + btn.dataset.tab);
            if (panel) panel.hidden = false;
        });
    });

    var input = document.getElementById('netflow-search');
    var count = document.getElementById('netflow-count');
    var table = document.getElementById('netflow-table');
    if (!input || !table) return;
    input.addEventListener('input', function () {
        var q = input.value.toLowerCase();
        var rows = table.querySelectorAll('tbody tr');
        var visible = 0;
        rows.forEach(function (row) {
            var text = (row.dataset.filterText || '');
            var show = !q || text.includes(q);
            row.hidden = !show;
            if (show) visible++;
        });
        if (count) count.textContent = visible + ' device' + (visible !== 1 ? 's' : '');
    });
}());
</script>
