<?php
/**
 * Per-device NTP tab panel — included by device_view.php.
 * Expects $id (int) to be set by the parent template.
 */
require_once dirname(__DIR__) . '/services/ntp_service.php';
$ntpStatus  = icct_nms_ntp_status($id);
$ntpPeers   = icct_nms_ntp_peers($id);
$ntpHistory = icct_nms_ntp_history($id, 25);

$ntpDisplay = static function($value) { return icct_nms_h(trim((string)$value) !== '' ? $value : '—'); };
?>
<section id="view-ntp" data-device-view-panel="ntp" hidden>
    <h2>NTP</h2>
<?php if ($ntpStatus): ?>
    <section class="device-view-section">
        <h3>NTP Summary</h3>
        <dl class="device-detail-grid">
            <div><dt>NTP Server</dt><dd><?= $ntpDisplay($ntpStatus['ntp_server']) ?></dd></div>
            <div><dt>Mode</dt><dd><?= $ntpDisplay($ntpStatus['ntp_mode']) ?></dd></div>
            <div><dt>Stratum</dt><dd><?= $ntpDisplay($ntpStatus['stratum'] ?? '') ?></dd></div>
            <div><dt>Reference ID</dt><dd><?= $ntpDisplay($ntpStatus['reference_id']) ?></dd></div>
            <div><dt>Offset (ms)</dt><dd><?= $ntpStatus['offset_ms'] !== null ? icct_nms_h(number_format((float)$ntpStatus['offset_ms'], 3)) : '—' ?></dd></div>
            <div><dt>Jitter (ms)</dt><dd><?= $ntpStatus['jitter_ms'] !== null ? icct_nms_h(number_format((float)$ntpStatus['jitter_ms'], 3)) : '—' ?></dd></div>
            <div><dt>Delay (ms)</dt><dd><?= $ntpStatus['delay_ms']  !== null ? icct_nms_h(number_format((float)$ntpStatus['delay_ms'],  3)) : '—' ?></dd></div>
            <div><dt>Last Polled</dt><dd><?= $ntpDisplay($ntpStatus['last_polled'] ?? '') ?></dd></div>
        </dl>
    </section>
    <section class="device-view-section">
        <h3>NTP Peers</h3>
<?php if ($ntpPeers): ?>
        <div class="site-table-wrap">
        <table class="site-table">
            <thead>
                <tr>
                    <th>Peer IP</th>
                    <th>Name</th>
                    <th>Stratum</th>
                    <th>Offset (ms)</th>
                    <th>Delay (ms)</th>
                    <th>Jitter (ms)</th>
                    <th>Reachable</th>
                    <th>Selected</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ntpPeers as $peer): ?>
            <tr>
                <td><?= icct_nms_h($peer['peer_ip']) ?></td>
                <td><?= icct_nms_h($peer['peer_name']) ?></td>
                <td><?= $ntpDisplay($peer['stratum'] ?? '') ?></td>
                <td><?= $peer['offset_ms'] !== null ? icct_nms_h(number_format((float)$peer['offset_ms'], 3)) : '—' ?></td>
                <td><?= $peer['delay_ms']  !== null ? icct_nms_h(number_format((float)$peer['delay_ms'],  3)) : '—' ?></td>
                <td><?= $peer['jitter_ms'] !== null ? icct_nms_h(number_format((float)$peer['jitter_ms'], 3)) : '—' ?></td>
                <td><?= (int)$peer['reachable'] ? '✔' : '✘' ?></td>
                <td><?= (int)$peer['selected'] ? '★' : '' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
<?php else: ?>
        <p>No NTP peers recorded for this device.</p>
<?php endif; ?>
    </section>
    <section class="device-view-section">
        <h3>Statistical History (Requirement 5.3.3.3)</h3>
<?php if (!empty($ntpHistory)): ?>
        <div class="site-table-wrap">
        <table class="site-table">
            <thead>
                <tr>
                    <th>Recorded At</th>
                    <th>Status</th>
                    <th>Stratum</th>
                    <th>Ref ID</th>
                    <th>Offset (ms)</th>
                    <th>Jitter (ms)</th>
                    <th>Delay (ms)</th>
                    <th>Dispersion (ms)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ntpHistory as $h): ?>
            <tr>
                <td><?= icct_nms_h($h['recorded_at'] ?? '—') ?></td>
                <td><span class="badge badge-<?= $h['status'] === 'synchronized' ? 'success' : ($h['status'] === 'reachable' ? 'info' : 'danger') ?>"><?= icct_nms_h($h['status'] ?? '—') ?></span></td>
                <td><?= $ntpDisplay($h['stratum'] ?? '') ?></td>
                <td><?= $ntpDisplay($h['reference_id'] ?? '') ?></td>
                <td><?= $h['offset_ms'] !== null ? icct_nms_h(number_format((float)$h['offset_ms'], 3)) : '—' ?></td>
                <td><?= $h['jitter_ms'] !== null ? icct_nms_h(number_format((float)$h['jitter_ms'], 3)) : '—' ?></td>
                <td><?= $h['delay_ms']  !== null ? icct_nms_h(number_format((float)$h['delay_ms'],  3)) : '—' ?></td>
                <td><?= $h['dispersion_ms'] !== null ? icct_nms_h(number_format((float)$h['dispersion_ms'], 3)) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
<?php else: ?>
        <p>No statistical polling history recorded yet for this device.</p>
<?php endif; ?>
    </section>
<?php else: ?>
    <p>No NTP data available for this device. Ensure the NTP poller has run and collected data.</p>
<?php endif; ?>
</section>
