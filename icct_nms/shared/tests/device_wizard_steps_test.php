<?php

/**
 * Unit test for Device Wizard Steps 1/7 through 7/7, step switching, and protocol additions.
 */

$root = dirname(__DIR__, 2);
if (!function_exists('html_escape')) {
    function html_escape($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('csrf_get_tokens')) {
    function csrf_get_tokens() { return 'test_token'; }
}
if (!function_exists('db_fetch_cell_prepared')) {
    function db_fetch_cell_prepared($sql, $args = []) { return null; }
}
if (!function_exists('db_fetch_row_prepared')) {
    function db_fetch_row_prepared($sql, $args = []) {
        if (str_contains($sql, 'host WHERE id') || str_contains($sql, 'FROM host WHERE') || str_contains($sql, 'host h WHERE')) {
            return $GLOBALS['old'];
        }
        return [];
    }
}
if (!function_exists('db_fetch_assoc')) {
    function db_fetch_assoc($sql) { return []; }
}
if (!function_exists('db_fetch_assoc_prepared')) {
    function db_fetch_assoc_prepared($sql, $args = []) { return []; }
}
if (!function_exists('read_config_option')) {
    function read_config_option($opt) { return 0; }
}
if (!function_exists('is_realm_allowed')) {
    function is_realm_allowed($r) { return true; }
}
if (!function_exists('api_user_realm_auth')) {
    function api_user_realm_auth($r) { return true; }
}
if (!function_exists('icct_nms_discovery_assignment')) {
    function icct_nms_discovery_assignment($id) { return null; }
}
if (!function_exists('icct_backend_nd_host_methods')) {
    function icct_backend_nd_host_methods($d, $s = false) { return []; }
}
if (!function_exists('icct_nms_graph_associations')) {
    function icct_nms_graph_associations($id) { return []; }
}
if (!function_exists('icct_nms_data_query_associations')) {
    function icct_nms_data_query_associations($id) { return []; }
}
if (!function_exists('icct_nms_available_data_queries')) {
    function icct_nms_available_data_queries($id, $h = []) { return []; }
}
if (!function_exists('icct_nms_data_query_methods')) {
    function icct_nms_data_query_methods($h) { return [0 => 'None']; }
}
require_once $root . '/shared/services/bootstrap.php';
require_once $root . '/shared/services/forms.php';
require_once $root . '/inventory/services/inventory.php';
require_once $root . '/presets/services/backend/core_form_options.php';
require_once $root . '/configuration/services/backend/configuration_equipment.php';
require_once $root . '/configuration/services/backend/configuration_jobs.php';
require_once $root . '/configuration/services/backend/configuration_validation.php';
require_once $root . '/configuration/services/backend/configuration_service.php';
require_once $root . '/protocols/shared/services/protocol_preset_service.php';
require_once $root . '/protocols/syslog/services/backend/syslog.php';
require_once $root . '/inventory/services/device_service.php';

// Mock test environment
$id = 9;
$wizard = true;
$readonly = false;
$snmp_auth_protocols = ['[None]' => 'None', 'SHA' => 'SHA'];
$snmp_priv_protocols = ['[None]' => 'None', 'AES' => 'AES'];
$fields_host_edit = [
    'site_id' => ['array' => [0 => 'None', 1 => 'Main Datacenter']],
    'poller_id' => ['array' => [1 => 'Main Poller']],
    'host_template_id' => ['array' => [0 => 'None', 1 => 'Generic SNMP']],
    'device_threads' => ['array' => [1 => '1 Thread']],
    'max_oids' => ['array' => [10 => '10 OIDs']],
    'availability_method' => ['array' => [1 => 'Ping', 2 => 'SNMP', 3 => 'SNMP or Ping']],
    'ping_method' => ['array' => [1 => 'ICMP Ping', 2 => 'UDP Ping', 3 => 'TCP Ping']],
    'snmp_auth_protocol' => ['array' => ['[None]' => 'None', 'SHA' => 'SHA']],
    'snmp_priv_protocol' => ['array' => ['[None]' => 'None', 'AES' => 'AES']],
];
$old = [
    'id' => 9,
    'description' => 'Core-Router-01',
    'hostname' => '192.168.1.1',
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
    'disabled' => '',
    'poller_id' => 1,
    'site_id' => 1,
    'host_template_id' => 1,
    'device_threads' => 1,
    'status' => 3,
];
$host = $old;
$values = $old + [
    'enabled' => true,
    'short_name' => 'CR01',
    'category_id' => 1,
    'device_type' => 'Router',
    'rack_id' => 0,
    'rack_position' => '',
    'notes' => '',
    'serial_number' => '',
    'mac_address' => '',
    'chassis_id' => '',
    'cross_launch_url' => '',
];
$shortNameAuto = false;
$title = 'Edit Device';
$discovery = null;
$discoveryResult = 0;
$cloneId = 0;
$failedProtocol = '';
$presetMode = false;
$protocolDraft = [];
$diag = ['tools' => 'ping,traceroute', 'mtr_interval' => 300, 'mtr_background' => 0];
$ssh = ['port' => 22];
$serial = ['revision' => 1];
$connections = [];
$graphAssociations = [
    ['id' => 1, 'name' => 'Host MIB - CPU Utilization'],
    ['id' => 2, 'name' => 'Interface - Traffic (bits/sec)'],
];
$availableGraphTemplates = [
    ['id' => 3, 'name' => 'Cisco - Memory Pool'],
];
$dataQueryAssociations = [
    ['id' => 1, 'name' => 'SNMP - Interface Statistics', 'reindex_method' => 1],
];
$availableDataQueries = [
    ['id' => 2, 'name' => 'SNMP - Disk Space'],
];
$dataQueryMethods = [0 => 'None', 1 => 'Uptime Go Back', 2 => 'Index Count Changed', 3 => 'Verify All Fields'];
$defaultDataQueryMethod = 1;

if (!function_exists('icct_backend_require_management')) {
    function icct_backend_require_management($r = 3) {}
}
if (!function_exists('icct_backend_require_device_access')) {
    function icct_backend_require_device_access($id) {}
}
if (!function_exists('icct_backend_protocol_enabled')) {
    function icct_backend_protocol_enabled($id, $p) { return true; }
}
if (!function_exists('icct_backend_device_status_name')) {
    function icct_backend_device_status_name($h) { return 'Up'; }
}
if (!function_exists('icct_nms_token')) {
    function icct_nms_token() { echo '<input type="hidden" name="__csrf_magic" value="token">'; }
}
if (!function_exists('icct_nms_port_monitoring')) {
    function icct_nms_port_monitoring($id) { return ['enabled' => true, 'ports' => []]; }
}
if (!function_exists('icct_backend_ports_view')) {
    function icct_backend_ports_view($h) { return []; }
}
if (!function_exists('icct_nms_fault_catalogue')) {
    function icct_nms_fault_catalogue() { return []; }
}
if (!function_exists('icct_nms_fault_rules')) {
    function icct_nms_fault_rules($id) { return []; }
}
if (!function_exists('icct_nms_fault_observations')) {
    function icct_nms_fault_observations($h) { return []; }
}
if (!function_exists('icct_nms_configuration_history')) {
    function icct_nms_configuration_history($id) { return []; }
}
if (!function_exists('icct_nms_fault_parameters')) {
    function icct_nms_fault_parameters($id) { return []; }
}
if (!function_exists('icct_nms_fault_presets')) {
    function icct_nms_fault_presets($id) { return []; }
}
if (!function_exists('icct_nms_passive_fault_rules')) {
    function icct_nms_passive_fault_rules($id) { return []; }
}
if (!function_exists('icct_nms_netflow_fault_rules')) {
    function icct_nms_netflow_fault_rules($id) { return []; }
}
if (!function_exists('icct_nms_ntp_current')) {
    function icct_nms_ntp_current($id) { return ['status' => 'synced', 'attempted_epoch' => time()]; }
}
if (!function_exists('icct_nms_ntp_assignment')) {
    function icct_nms_ntp_assignment($id) { return ['stale_seconds' => 300]; }
}
if (!function_exists('icct_nms_ntp_fault_parameters')) {
    function icct_nms_ntp_fault_parameters() { return []; }
}
if (!function_exists('icct_nms_ntp_fault_values')) {
    function icct_nms_ntp_fault_values($cur) { return []; }
}
if (!function_exists('icct_nms_netflow_assignment')) {
    function icct_nms_netflow_assignment($id) { return ['port' => 2055]; }
}
if (!function_exists('icct_nms_graph_origin')) {
    function icct_nms_graph_origin($id) { return ['label' => 'Cacti Native', 'edited' => false]; }
}
if (!function_exists('icct_backend_diag_tools')) {
    function icct_backend_diag_tools($s) { return ['ping']; }
}
if (!function_exists('icct_nms_input')) {
    function icct_nms_input($l, $n, $v, $t = 'text', $a = '') { echo "<input type=\"$t\" name=\"$n\" value=\"$v\" $a>"; }
}

$expectedSteps = [
    'basic' => ['step' => '1/7', 'label' => 'Basic Information'],
    'protocol' => ['step' => '2/7', 'label' => 'Protocol Config'],
    'diagnostics' => ['step' => '3/7', 'label' => 'Device Diagnostics'],
    'graphs' => ['step' => '4/7', 'label' => 'Graphs'],
    'data-query' => ['step' => '5/7', 'label' => 'Data Query'],
    'ports' => ['step' => '6/7', 'label' => 'Port Config'],
    'fcaps' => ['step' => '7/7', 'label' => 'FCAPS'],
];

// Helper to render the complete wizard markup for a given initial step
function renderWizardStep(string $step): string {
    global $root, $id, $wizard, $readonly, $old, $host, $values, $title, $discovery, $discoveryResult;
    global $cloneId, $failedProtocol, $presetMode, $protocolDraft, $diag, $ssh, $serial, $connections;
    global $graphAssociations, $availableGraphTemplates, $dataQueryAssociations, $availableDataQueries;
    global $dataQueryMethods, $defaultDataQueryMethod, $shortNameAuto;

    $initialStep = $step;
    $activeStep = $step;

    ob_start();
    echo '<div id="device-wizard" data-device-id="' . (int) $id . '" data-initial-step="' . icct_nms_h($initialStep) . '">';
    require $root . '/inventory/templates/device.php';
    echo '<div id="wizard-panels"' . ($initialStep === 'basic' ? ' hidden' : '') . '>';
    require $root . '/protocols/shared/templates/protocol.php';
    require $root . '/ports/templates/ports.php';
    require $root . '/fcaps/templates/fcaps.php';
    echo '</div></div>';
    return ob_get_clean();
}

// 1. Test each of the 7 steps:
foreach ($expectedSteps as $stepKey => $meta) {
    $html = renderWizardStep($stepKey);

    // Verify nav contains all 7 steps with proper numbers and labels
    foreach ($expectedSteps as $checkKey => $checkMeta) {
        assert(strpos($html, $checkMeta['label']) !== false, "Step label {$checkMeta['label']} missing in step $stepKey");
        assert(strpos($html, $checkMeta['step']) !== false, "Step number {$checkMeta['step']} missing in step $stepKey");
    }

    // Verify active step is marked with aria-current="step"
    assert(preg_match('~<li class="[^"]*current[^"]*" aria-current="step">(?:(?!</li>).)*step=' . preg_quote($stepKey, '~') . '~s', $html) === 1,
        "Step $stepKey was not marked as current with aria-current='step'");

    // Verify visibility rules for step 1 vs other steps
    if ($stepKey === 'basic') {
        assert(strpos($html, '<fieldset class="device-fields"  ') !== false || strpos($html, '<fieldset class="device-fields" >') !== false || !preg_match('~<fieldset class="device-fields"\s+hidden~', $html),
            "Basic step fieldset should NOT be hidden");
        assert(strpos($html, '<div id="wizard-panels" hidden>') !== false,
            "Wizard panels should be hidden on basic step");
    } else {
        assert(preg_match('~<fieldset class="device-fields"\s+hidden~', $html) === 1,
            "Basic step fieldset MUST be hidden on step $stepKey");
        assert(strpos($html, '<div id="wizard-panels">') !== false,
            "Wizard panels MUST be visible on step $stepKey");
    }

    // Verify panel visibility on step 2 (protocol)
    if ($stepKey === 'protocol') {
        assert(preg_match('~<div id="protocol-workspace"\s*>~', $html) === 1, "protocol-workspace must be visible on step protocol");
        assert(preg_match('~<section\s+id="diagnostics"[^>]*hidden~', $html) === 1, "diagnostics must be hidden on step protocol");
        assert(preg_match('~<section\s+id="device-graphs"[^>]*hidden~', $html) === 1, "device-graphs must be hidden on step protocol");
        assert(preg_match('~<section\s+id="device-data-queries"[^>]*hidden~', $html) === 1, "device-data-queries must be hidden on step protocol");
        assert(preg_match('~<section\s+id="device-ports"[^>]*hidden~', $html) === 1, "device-ports must be hidden on step protocol");
        assert(preg_match('~<section\s+id="device-fcaps"[^>]*hidden~', $html) === 1, "device-fcaps must be hidden on step protocol");
    }

    // Verify panel visibility on step 3 (diagnostics)
    if ($stepKey === 'diagnostics') {
        assert(preg_match('~<div id="protocol-workspace"[^>]*hidden~', $html) === 1, "protocol-workspace must be hidden on step diagnostics");
        assert(preg_match('~<section\s+id="diagnostics"\s+class="diagnostics-page"\s*>~', $html) === 1, "diagnostics must be visible on step diagnostics");
        assert(preg_match('~<section\s+id="device-graphs"[^>]*hidden~', $html) === 1, "device-graphs must be hidden on step diagnostics");
        assert(preg_match('~<section\s+id="device-data-queries"[^>]*hidden~', $html) === 1, "device-data-queries must be hidden on step diagnostics");
        assert(preg_match('~<section\s+id="device-ports"[^>]*hidden~', $html) === 1, "device-ports must be hidden on step diagnostics");
        assert(preg_match('~<section\s+id="device-fcaps"[^>]*hidden~', $html) === 1, "device-fcaps must be hidden on step diagnostics");
    }

    // Verify panel visibility on step 4 (graphs)
    if ($stepKey === 'graphs') {
        assert(preg_match('~<section\s+id="device-graphs"\s+class="graphs-page"\s*>~', $html) === 1, "device-graphs must be visible on step graphs");
        assert(preg_match('~<section\s+id="diagnostics"[^>]*hidden~', $html) === 1, "diagnostics must be hidden on step graphs");
        assert(preg_match('~<section\s+id="device-data-queries"[^>]*hidden~', $html) === 1, "device-data-queries must be hidden on step graphs");
        assert(preg_match('~<section\s+id="device-ports"[^>]*hidden~', $html) === 1, "device-ports must be hidden on step graphs");
        assert(preg_match('~<section\s+id="device-fcaps"[^>]*hidden~', $html) === 1, "device-fcaps must be hidden on step graphs");
    }

    // Verify panel visibility on step 5 (data-query)
    if ($stepKey === 'data-query') {
        assert(preg_match('~<section\s+id="device-data-queries"\s+class="graphs-page data-queries-page"\s*>~', $html) === 1, "device-data-queries must be visible on step data-query");
        assert(preg_match('~<section\s+id="diagnostics"[^>]*hidden~', $html) === 1, "diagnostics must be hidden on step data-query");
        assert(preg_match('~<section\s+id="device-graphs"[^>]*hidden~', $html) === 1, "device-graphs must be hidden on step data-query");
        assert(preg_match('~<section\s+id="device-ports"[^>]*hidden~', $html) === 1, "device-ports must be hidden on step data-query");
        assert(preg_match('~<section\s+id="device-fcaps"[^>]*hidden~', $html) === 1, "device-fcaps must be hidden on step data-query");
    }

    // Verify panel visibility on step 6 (ports)
    if ($stepKey === 'ports') {
        assert(preg_match('~<section\s+id="device-ports"\s+class="device-ports"\s*>~', $html) === 1, "device-ports must be visible on step ports");
        assert(preg_match('~<section\s+id="diagnostics"[^>]*hidden~', $html) === 1, "diagnostics must be hidden on step ports");
        assert(preg_match('~<section\s+id="device-graphs"[^>]*hidden~', $html) === 1, "device-graphs must be hidden on step ports");
        assert(preg_match('~<section\s+id="device-data-queries"[^>]*hidden~', $html) === 1, "device-data-queries must be hidden on step ports");
        assert(preg_match('~<section\s+id="device-fcaps"[^>]*hidden~', $html) === 1, "device-fcaps must be hidden on step ports");
    }

    // Verify panel visibility on step 7 (fcaps)
    if ($stepKey === 'fcaps') {
        assert(preg_match('~<section\s+id="device-fcaps"\s+class="device-fcaps"\s*>~', $html) === 1, "device-fcaps must be visible on step fcaps");
        assert(preg_match('~<section\s+id="diagnostics"[^>]*hidden~', $html) === 1, "diagnostics must be hidden on step fcaps");
        assert(preg_match('~<section\s+id="device-graphs"[^>]*hidden~', $html) === 1, "device-graphs must be hidden on step fcaps");
        assert(preg_match('~<section\s+id="device-data-queries"[^>]*hidden~', $html) === 1, "device-data-queries must be hidden on step fcaps");
        assert(preg_match('~<section\s+id="device-ports"[^>]*hidden~', $html) === 1, "device-ports must be hidden on step fcaps");
    }
}

// 2. Test Protocol Add Controls and Picker Options
$protocolHtml = renderWizardStep('protocol');
assert(strpos($protocolHtml, 'id="add-protocol"') !== false, "Add protocol button missing");
assert(strpos($protocolHtml, 'id="protocol-picker"') !== false, "Protocol picker missing");
assert(strpos($protocolHtml, 'id="confirm-protocols"') !== false, "Confirm protocols button missing");

$expectedProtocols = ['cdp', 'lldp', 'snmp', 'ssh', 'serial', 'syslog', 'netflow', 'ntp', 'tacacs'];
foreach ($expectedProtocols as $proto) {
    assert(strpos($protocolHtml, "data-protocol-target=\"$proto\"") !== false, "Picker target $proto missing");
    assert(strpos($protocolHtml, "id=\"protocol-$proto\"") !== false, "Protocol container for $proto missing");
}

echo "PASS: all 7 wizard steps (1/7 to 7/7), server-side visibility, navigation links, and protocol picker options.\n";
