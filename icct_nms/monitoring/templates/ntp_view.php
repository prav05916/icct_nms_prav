<?php
/**
 * NTP Monitoring overview template — lists all NTP-monitored devices.
 * Data: $ntpDevices (array from icct_nms_ntp_all())
 */

/**
 * Returns a CSS badge class based on NTP stratum value.
 */
function icct_nms_ntp_stratum_class(?int $stratum): string {
    if ($stratum === null) return 'badge-grey';
    if ($stratum <= 2)    return 'badge-green';
    if ($stratum <= 5)    return 'badge-yellow';
    return 'badge-orange';
}

/**
 * Returns a CSS class based on absolute NTP offset in ms.
 */
function icct_nms_ntp_offset_class(?float $offset): string {
    if ($offset === null) return '';
    $abs = abs($offset);
    if ($abs < 1)  return 'ntp-offset-good';
    if ($abs < 10) return 'ntp-offset-warn';
    return 'ntp-offset-crit';
}
?>
<div class="titlebar">
    <h1><?= icct_nms_h($title) ?></h1>
</div>

<div class="site-table-controls">
    <label class="site-table-search-label" for="ntp-search">
        <span class="sr-only">Filter devices</span>
        <input type="search" id="ntp-search" class="site-table-search" placeholder="Filter devices…" aria-label="Filter NTP devices">
    </label>
    <span class="site-table-count" id="ntp-count">
        <?= count($ntpDevices) ?> device<?= count($ntpDevices) !== 1 ? 's' : '' ?>
    </span>
</div>

<div class="site-table-wrap">
<?php if ($ntpDevices): ?>
<table class="site-table" id="ntp-table">
    <thead>
        <tr>
            <th>Device Name</th>
            <th>IP / Hostname</th>
            <th>NTP Server</th>
            <th>Mode</th>
            <th>Stratum</th>
            <th>Offset (ms)</th>
            <th>Jitter (ms)</th>
            <th>Delay (ms)</th>
            <th>Last Polled</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($ntpDevices as $row):
        $offset    = isset($row['offset_ms']) ? (float)$row['offset_ms'] : null;
        $jitter    = isset($row['jitter_ms']) ? (float)$row['jitter_ms'] : null;
        $delay     = isset($row['delay_ms'])  ? (float)$row['delay_ms']  : null;
        $stratum   = isset($row['stratum'])   ? (int)$row['stratum']     : null;
        $offClass  = icct_nms_ntp_offset_class($offset);
        $strClass  = icct_nms_ntp_stratum_class($stratum);
        $rowStatus = $row['status'] ?? 'unknown';
        if ($rowStatus === 'unreachable') {
            $statusLabel = 'Unreachable';
            $statusClass = 'badge-red';
        } elseif ($rowStatus === 'unsynced') {
            $statusLabel = 'Unsynced';
            $statusClass = 'badge-orange';
        } elseif ($offset === null) {
            $statusLabel = 'Unknown';
            $statusClass = 'badge-grey';
        } elseif (abs($offset) < 1) {
            $statusLabel = 'Good';
            $statusClass = 'badge-green';
        } elseif (abs($offset) < 10) {
            $statusLabel = 'Warning';
            $statusClass = 'badge-yellow';
        } else {
            $statusLabel = 'Critical';
            $statusClass = 'badge-red';
        }
    ?>
    <tr data-filter-text="<?= icct_nms_h(strtolower($row['description'] . ' ' . $row['hostname'] . ' ' . $row['ntp_server'])) ?>">
        <td><a href="inventory/controllers/device.php?id=<?= (int)$row['host_id'] ?>&amp;view=1#view-ntp"><?= icct_nms_h($row['description']) ?></a></td>
        <td><?= icct_nms_h($row['hostname']) ?></td>
        <td><?= icct_nms_h($row['ntp_server']) ?></td>
        <td><?= icct_nms_h($row['ntp_mode']) ?></td>
        <td><span class="badge <?= $strClass ?>"><?= $stratum !== null ? icct_nms_h((string)$stratum) : '—' ?></span></td>
        <td class="<?= $offClass ?>"><?= $offset !== null ? icct_nms_h(number_format($offset, 3)) : '—' ?></td>
        <td><?= $jitter !== null ? icct_nms_h(number_format($jitter, 3)) : '—' ?></td>
        <td><?= $delay  !== null ? icct_nms_h(number_format($delay, 3))  : '—' ?></td>
        <td><?= icct_nms_h($row['last_polled'] ?? '—') ?></td>
        <td><span class="badge <?= $statusClass ?>"><?= icct_nms_h($statusLabel) ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p class="site-table-empty">No NTP-monitored devices found. Configure the NTP poller to populate this view.</p>
<?php endif; ?>
</div>

<!-- NTP Reports (Requirement 5.3.3.5) -->
<div style="margin-top:16px;padding:12px;background:#f9f9f9;border:1px solid #ddd;border-radius:6px">
    <h3 style="margin:0 0 8px 0">Reports & Export</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="monitoring/controllers/ntp.php?export=csv" class="ui-button ui-corner-all">Export CSV</a>
        <a href="monitoring/controllers/ntp.php?export=json" class="ui-button ui-corner-all">Export JSON</a>
    </div>
</div>

<script>
(function () {
    var input = document.getElementById('ntp-search');
    var count = document.getElementById('ntp-count');
    var table = document.getElementById('ntp-table');
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
