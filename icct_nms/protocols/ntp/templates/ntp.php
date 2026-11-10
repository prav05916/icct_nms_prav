<?php
/**
 * NTP protocol configuration template for devices and presets.
 */
$ntp = is_array($ntpData ?? null) ? $ntpData : [];
$defaultHost = $host['hostname'] ?? $values['hostname'] ?? $old['hostname'] ?? '';
$ntpHelp = static function($label, $help) { ?>
<span class="field-info" tabindex="0" role="img" aria-label="Information about <?= icct_nms_h($label) ?>" data-tooltip="<?= icct_nms_h($help) ?>"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 6v.5"/></svg></span>
<?php };
?>
<div class="protocol-grid cols-3">
    <?php
    icct_nms_input(
        'NTP Server Address',
        'ntp_server',
        $ntp['ntp_server'] ?? ($defaultHost ?: 'pool.ntp.org'),
        'text',
        'required placeholder="pool.ntp.org or IP address"'
    );
    icct_nms_input(
        'UDP Port',
        'port',
        $ntp['port'] ?? 123,
        'number',
        'required min="1" max="65535"'
    );
    icct_nms_select(
        'NTP Version',
        'version',
        [4 => 'NTPv4', 3 => 'NTPv3'],
        $ntp['version'] ?? 4
    );
    icct_nms_input(
        'Timeout (Seconds)',
        'timeout_seconds',
        $ntp['timeout_seconds'] ?? 3,
        'number',
        'required min="1" max="30"'
    );
    icct_nms_input(
        'Poll Interval (Seconds)',
        'poll_interval',
        $ntp['poll_interval'] ?? 300,
        'number',
        'required min="10" max="86400"'
    );
    icct_nms_input(
        'Expected Reference Clock ID',
        'expected_source',
        $ntp['expected_source'] ?? ($ntp['reference_id'] ?? 'GPS'),
        'text',
        'maxlength="64" placeholder="GPS, PPS, LOCL"'
    );
    icct_nms_input(
        'Max Allowed Offset (ms)',
        'maximum_offset_ms',
        isset($ntp['maximum_offset_ms']) ? number_format((float)$ntp['maximum_offset_ms'], 1, '.', '') : '100.0',
        'number',
        'step="0.1" min="1"'
    );
    icct_nms_input(
        'Max Allowed Jitter (ms)',
        'maximum_jitter_ms',
        isset($ntp['maximum_jitter_ms']) ? number_format((float)$ntp['maximum_jitter_ms'], 1, '.', '') : '50.0',
        'number',
        'step="0.1" min="1"'
    );
    icct_nms_input(
        'Max Allowed Delay (ms)',
        'maximum_delay_ms',
        isset($ntp['maximum_delay_ms']) ? number_format((float)$ntp['maximum_delay_ms'], 1, '.', '') : '250.0',
        'number',
        'step="0.1" min="1"'
    );
    ?>
</div>
<?php if (!empty($ntp['last_polled']) || !empty($ntp['status'])): ?>
<div class="protocol-status-callout">
    <strong>Live Status:</strong>
    <span class="badge <?= ($ntp['status'] ?? '') === 'synced' ? 'badge-green' : (($ntp['status'] ?? '') === 'degraded' ? 'badge-yellow' : 'badge-red') ?>">
        <?= icct_nms_h(ucfirst($ntp['status'] ?? 'Unknown')) ?>
    </span>
    <?php if (isset($ntp['offset_ms']) && $ntp['offset_ms'] !== null): ?>
        <span>Offset: <?= icct_nms_h(number_format((float)$ntp['offset_ms'], 3)) ?> ms</span>
    <?php endif; ?>
    <?php if (isset($ntp['stratum'])): ?>
        <span>Stratum: <?= (int)$ntp['stratum'] ?></span>
    <?php endif; ?>
    <?php if (!empty($ntp['last_polled'])): ?>
        <span>Last Polled: <?= icct_nms_h($ntp['last_polled']) ?></span>
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="protocol-actions split-actions">
    <button class="button primary" type="submit">Save</button>
</div>
