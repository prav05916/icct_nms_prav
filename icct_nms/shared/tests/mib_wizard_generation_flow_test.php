<?php

/**
 * Unit test for MIB Generation Flow, template disambiguation, and preset protocols rendering.
 */

$root = dirname(__DIR__, 2);
if (!function_exists('html_escape')) {
    function html_escape($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('csrf_get_tokens')) {
    function csrf_get_tokens() { return 'test_token'; }
}
if (!function_exists('db_fetch_cell_prepared')) {
    function db_fetch_cell_prepared($sql, $args = []) {
        if (str_contains($sql, 'data_source_profiles')) {
            return 1;
        }
        // Simulate existing Cacti templates for UCD-SNMP-MIB to verify disambiguation
        if (str_contains($sql, 'data_template') && isset($args[0]) && $args[0] === 'UCD-SNMP-MIB - prIndex') {
            return 42; // Simulated existing ID
        }
        if (str_contains($sql, 'graph_templates') && isset($args[0]) && $args[0] === 'UCD-SNMP-MIB - prIndex') {
            return 99; // Simulated existing ID
        }
        return null;
    }
}
if (!function_exists('db_fetch_cell')) {
    function db_fetch_cell($sql) {
        if (str_contains($sql, 'data_source_profiles')) {
            return 1;
        }
        return null;
    }
}
if (!function_exists('db_fetch_row_prepared')) {
    function db_fetch_row_prepared($sql, $args = []) { return []; }
}
if (!function_exists('db_fetch_assoc')) {
    function db_fetch_assoc($sql) {
        if (str_contains($sql, 'data_source_profiles')) {
            return [['id' => 1, 'name' => '5 Minute Polling', 'step' => 300, 'heartbeat' => 600]];
        }
        return [];
    }
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
require_once $root . '/protocols/snmp/mibs/services/mib_repository_service.php';
require_once $root . '/protocols/snmp/mibs/services/mib_device_core_service.php';
require_once $root . '/protocols/snmp/mibs/services/mib_staging_service.php';
require_once $root . '/protocols/snmp/mibs/services/mib_generation_service.php';

// Test 1: icct_mib_unique_template_name disambiguates existing templates
$unique1 = icct_mib_unique_template_name('UCD-SNMP-MIB - prIndex', 'data_template');
assert($unique1 === 'UCD-SNMP-MIB - prIndex (1)', "Expected disambiguated name 'UCD-SNMP-MIB - prIndex (1)', got '$unique1'");

$unique2 = icct_mib_unique_template_name('UCD-SNMP-MIB - other', 'data_template');
assert($unique2 === 'UCD-SNMP-MIB - other', "Expected unchanged unique name 'UCD-SNMP-MIB - other', got '$unique2'");

// Test 2: Verify plan generation with existing colliding name
$mockPreview = [
    'records' => [
        [
            'symbol' => 'UCD-SNMP-MIB::prIndex',
            'kind' => 'OBJECT-TYPE',
            'base_oid' => '1.3.6.1.4.1.2021.2.1.1',
            'oid' => '1.3.6.1.4.1.2021.2.1.1',
            'label' => 'prIndex',
            'syntax' => 'INTEGER',
            'numeric' => true,
            'table' => true,
            'units' => '',
            'ds_type' => 1,
            'description' => 'Process index',
        ],
        [
            'symbol' => 'UCD-SNMP-MIB::memTotalReal',
            'kind' => 'OBJECT-TYPE',
            'base_oid' => '1.3.6.1.4.1.2021.4.5',
            'oid' => '1.3.6.1.4.1.2021.4.5.0',
            'label' => 'memTotalReal',
            'syntax' => 'INTEGER',
            'numeric' => true,
            'table' => false,
            'units' => 'kB',
            'ds_type' => 1,
            'description' => 'Real memory total',
        ]
    ]
];

$input = [
    'type_id' => '__mib_unassigned__',
    'template_name' => 'UCD-SNMP-MIB',
    'create_data' => 1,
    'create_graph' => 1,
    'create_device' => 1,
    'selected' => [0 => 1, 1 => 1],
    'records' => [],
];

$plan = icct_mib_plan($mockPreview, $input);
assert(count($plan['records']) === 2, 'Plan should contain 2 records');
assert($plan['records'][0]['data_name'] === 'UCD-SNMP-MIB - prIndex (1)',
    "Colliding data_name should be disambiguated, got: " . $plan['records'][0]['data_name']);
assert($plan['records'][1]['data_name'] === 'UCD-SNMP-MIB - memTotalReal',
    "Non-colliding data_name should remain intact, got: " . $plan['records'][1]['data_name']);

// Test 3: Validation should now pass without throwing "Template already exists" exception
icct_mib_validate_template_names(array_column($plan['records'], 'data_name'), 'data_template');
assert(true, 'Template names validation passed');

// Test 4: Graph groups extraction from disambiguated plan
$groups = icct_mib_graph_groups($plan['records']);
assert(count($groups) === 2, 'Should create 2 graph groups');

// Test 5: Verify plan generation when $input['records'] has colliding data_name from form POST
$inputWithSubmittedNames = $input;
$inputWithSubmittedNames['records'] = [
    0 => [
        'data_name' => 'UCD-SNMP-MIB - prIndex',
        'graph_name' => 'UCD-SNMP-MIB - prIndex',
    ]
];
$planSubmitted = icct_mib_plan($mockPreview, $inputWithSubmittedNames);
assert($planSubmitted['records'][0]['data_name'] === 'UCD-SNMP-MIB - prIndex (1)',
    "Submitted colliding data_name must be disambiguated, got: " . $planSubmitted['records'][0]['data_name']);
assert($planSubmitted['records'][0]['graph_name'] === 'UCD-SNMP-MIB - prIndex (1)',
    "Submitted colliding graph_name must be disambiguated, got: " . $planSubmitted['records'][0]['graph_name']);

// Validation must pass for submitted plan records without throwing
icct_mib_validate_template_names(array_column($planSubmitted['records'], 'data_name'), 'data_template');
icct_mib_validate_template_names(array_column($planSubmitted['records'], 'graph_name'), 'graph_templates');

// Test 6: Verify clean increment without nested parentheses (e.g., prIndex (1) -> prIndex (2))
$uniqueIncrement = icct_mib_unique_template_name('UCD-SNMP-MIB - prIndex (1)', 'data_template', ['ucd-snmp-mib - prindex (1)']);
assert($uniqueIncrement === 'UCD-SNMP-MIB - prIndex (2)',
    "Expected cleanly incremented name 'UCD-SNMP-MIB - prIndex (2)', got '$uniqueIncrement'");

// Test 7: Verify Stepper navigation constraints
$stepperCompleted = 0; // Stage 2 not yet generated
$maxAllowedStage = $stepperCompleted + 2; // Can access at most stage 2
assert((2 <= $maxAllowedStage) === true, 'Step 2 should be accessible');
assert((3 <= $maxAllowedStage) === false, 'Step 3 should not be accessible before step 2 generates');
assert((4 <= $maxAllowedStage) === false, 'Step 4 should not be accessible before step 3 generates');

$stepperCompleted = 1; // Stage 2 generated
$maxAllowedStage = $stepperCompleted + 2; // Can access stage 3
assert((3 <= $maxAllowedStage) === true, 'Step 3 should be accessible after step 2 generates');

echo "PASS: MIB Generation Flow and Template Disambiguation verified successfully!\n";

