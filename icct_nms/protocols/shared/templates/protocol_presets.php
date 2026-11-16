<?php /** Protocol presets are rendered through shared fields to keep device editors consistent. */
require_once dirname(__DIR__, 3) . '/inventory/services/inventory.php';
require_once dirname(__DIR__, 3) . '/inventory/services/device_service.php';
require_once __DIR__ . '/../../syslog/services/backend/syslog.php';
require_once __DIR__ . '/../../syslog/services/syslog_service.php';
require_once __DIR__ . '/../../netflow/services/netflow_service.php';
require_once __DIR__ . '/../../ntp/services/ntp_service.php';
$presetMode = true;
$wizard = true;
$id = 0;
$protocolPresets = icct_nms_protocol_presets();
$failedProtocol = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($error ?? false)) {
    $key = $_POST['preset_protocol'] ?? '';
    if (
        is_string($key) &&
        in_array($key, ['cdp', 'lldp', 'snmp', 'snmp_notifications', 'snmp_discovery', 'ssh', 'serial', 'syslog', 'netflow', 'ntp'], true)
    ) {
        $protocolPresets[$key] = array_intersect_key(
            array_filter($_POST, 'is_scalar'),
            array_flip(icct_nms_protocol_preset_fields($key)),
        );
        $failedProtocol = $key;
    }
}
$protocolDraft = ['cdp', 'lldp', 'snmp', 'ssh', 'serial', 'syslog', 'netflow', 'ntp', 'tacacs'];
$host = array_replace(
    icct_nms_defaults(),
    [
        'snmp_version' => 2,
        'snmp_community' => 'public',
        'snmp_port' => 161,
        'snmp_timeout' => 500,
        'snmp_retries' => 3,
        'max_oids' => 10,
        'availability_method' => 2,
        'ping_method' => 1,
        'ping_port' => 23,
        'ping_timeout' => 400,
        'ping_retries' => 1,
        'snmp_auth_protocol' => '[None]',
        'snmp_priv_protocol' => '[None]',
        'snmp_username' => '',
        'snmp_password' => '',
        'snmp_priv_passphrase' => '',
        'snmp_context' => '',
        'snmp_engine_id' => '',
        'description' => 'Protocol defaults',
    ],
    $protocolPresets['snmp'] ?? []
);
$discovery = [];
$ssh = $protocolPresets['ssh'] ?? [];
$serial = [];
$connections = []; ?>
<section
    id="protocol-defaults"
    class="protocol-defaults">
    <?php require __DIR__ . '/protocol.php'; ?>
</section>
<script
    type="application/json"
    id="protocol-preset-profiles"><?= json_encode(
        [
            'saved' => icct_nms_protocol_presets(),
            'profiles' => icct_nms_protocol_profiles(),
            'variants' => icct_nms_protocol_variants(),
            'fields' => array_combine(
                ['snmp', 'ssh', 'serial', 'syslog', 'cdp', 'lldp', 'ntp'],
                array_map('icct_nms_protocol_preset_fields', [
                    'snmp',
                    'ssh',
                    'serial',
                    'syslog',
                    'cdp',
                    'lldp',
                    'ntp',
                ]),
            ),
        ],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
    ) ?></script>
<?php $wizard = false; ?>
