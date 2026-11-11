<?php
/**
 * NetFlow protocol configuration template for devices and presets.
 */
$netflow = is_array($netflowData ?? null) ? $netflowData : [];
$defaultHost = $host['hostname'] ?? $values['hostname'] ?? $old['hostname'] ?? '';
$netflowHelp = static function($label, $help) { ?>
<span class="field-info" tabindex="0" role="img" aria-label="Information about <?= icct_nms_h($label) ?>" data-tooltip="<?= icct_nms_h($help) ?>"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 6v.5"/></svg></span>
<?php };
?>
<div class="protocol-grid cols-3">
    <?php
    icct_nms_input(
        'Flow Exporter IP',
        'exporter_ip',
        $netflow['exporter_ip'] ?? ($defaultHost ?: '127.0.0.1'),
        'text',
        'required placeholder="Exporter IP Address"'
    );
    icct_nms_input(
        'Collector IP Address',
        'collector_ip',
        $netflow['collector_ip'] ?? '127.0.0.1',
        'text',
        'required placeholder="Collector IP Address"'
    );
    icct_nms_input(
        'Collector UDP Port',
        'collector_port',
        $netflow['collector_port'] ?? 2055,
        'number',
        'required min="1" max="65535"'
    );
    icct_nms_select(
        'NetFlow Version',
        'flow_version',
        [9 => 'NetFlow v9', 7 => 'NetFlow v7', 5 => 'NetFlow v5', 10 => 'IPFIX (v10)'],
        $netflow['flow_version'] ?? 9
    );
    icct_nms_input(
        'Sampling Rate (1:N)',
        'sampling_rate',
        $netflow['sampling_rate'] ?? 1,
        'number',
        'required min="1" max="10000"'
    );
    icct_nms_input(
        'Active Flow Timeout (s)',
        'active_timeout',
        $netflow['active_timeout'] ?? 300,
        'number',
        'required min="30" max="3600"'
    );
    icct_nms_input(
        'Inactive Flow Timeout (s)',
        'inactive_timeout',
        $netflow['inactive_timeout'] ?? 15,
        'number',
        'required min="5" max="600"'
    );
    ?>
</div>
<?php if (!empty($netflow['last_polled']) || !empty($netflow['status'])): ?>
<div class="protocol-status-callout">
    <strong>Live Status:</strong>
    <span class="badge <?= ($netflow['status'] ?? '') === 'active' ? 'badge-green' : (($netflow['status'] ?? '') === 'listening' ? 'badge-yellow' : 'badge-grey') ?>">
        <?= icct_nms_h(ucfirst($netflow['status'] ?? 'Listening')) ?>
    </span>
    <span>Active Flows: <?= icct_nms_h(number_format((int)($netflow['active_flows'] ?? 0))) ?></span>
    <span>Throughput: <?= icct_nms_h(icct_nms_netflow_bytes_human((int)($netflow['bytes_per_second'] ?? 0))) ?>/s</span>
    <?php if (!empty($netflow['last_polled'])): ?>
        <span>Last Data: <?= icct_nms_h($netflow['last_polled']) ?></span>
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="protocol-actions split-actions">
    <button class="button primary" type="submit">Save</button>
</div>
