<?php
// Test rendering presets page with tab=protocols
$root = 'C:/Apache24/htdocs/cacti/plugins/icct_nms';
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
    function db_fetch_row_prepared($sql, $args = []) { return []; }
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
require_once $root . '/shared/services/bootstrap.php';
require_once $root . '/shared/services/forms.php';
require_once $root . '/protocols/shared/services/protocol_preset_service.php';
require_once $root . '/protocols/shared/services/protocol_service.php';
require_once $root . '/presets/services/backend/core_form_options.php';

$presetTabs = [
    'rack-config' => 'Rack Config',
    'protocols' => 'Protocols',
    'site' => 'Site',
    'segment' => 'Segment',
    'device-type' => 'Device Type',
    'network-connections' => 'Network Connections',
];
$activePreset = 'protocols';
$management = true;
$snmp_auth_protocols = [];
$snmp_priv_protocols = [];
$fields_host_edit = [];

ob_start();
require $root . '/protocols/shared/templates/protocol_presets.php';
$html = ob_get_clean();

assert(strpos($html, 'id="protocol-workspace"') !== false, 'protocol-workspace must exist');
assert(strpos($html, '<div id="protocol-workspace" hidden>') === false, 'protocol-workspace MUST NOT be hidden in presets');
assert(strpos($html, 'id="protocol-snmp"') !== false, 'SNMP protocol item must be present');
assert(strpos($html, 'id="protocol-ssh"') !== false, 'SSH protocol item must be present');
assert(strpos($html, 'id="protocol-syslog"') !== false, 'Syslog protocol item must be present');
assert(strpos($html, 'id="protocol-netflow"') !== false, 'Netflow protocol item must be present');
assert(strpos($html, 'id="protocol-ntp"') !== false, 'NTP protocol item must be present');

echo "PASS: Presets Protocols tab renders cleanly with protocol-workspace fully visible!\n";

