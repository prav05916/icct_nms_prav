<?php
/**
 * Unit tests for rack_unit_count field — pure PHP stubs, no framework, no network.
 *
 * Verifies that:
 *  1. icct_backend_topology_integer enforces the min/max bounds used in device_service.php.
 *  2. The INSERT … ON DUPLICATE KEY UPDATE upsert call stores the correct value.
 *  3. Values outside 1–42 are rejected.
 */

/* ---- Minimal stubs ---- */
$executed = [];

function icct_backend_topology_integer($value, $min, $max, $label) {
    $int = filter_var($value, FILTER_VALIDATE_INT);
    if ($int === false || $int < $min || $int > $max) {
        throw new InvalidArgumentException($label . ' must be between ' . $min . ' and ' . $max . '.');
    }
    return $int;
}

function icct_backend_current_user_id() { return 1; }

function icct_backend_category_execute($sql, $args = []) {
    $GLOBALS['executed'][] = ['sql' => $sql, 'args' => $args];
}

/* ---- Helper that mimics the save logic in device_service.php ---- */
function test_save_rack_unit_count($hostId, $input) {
    $rawUnitCount = $input['rack_unit_count'] ?? 1;
    if (is_string($rawUnitCount) && preg_match('/^([0-9]+)/', trim($rawUnitCount), $matches)) {
        $rawUnitCount = (int)$matches[1];
    }
    $rackUnitCount = icct_backend_topology_integer($rawUnitCount, 1, 42, 'Rack Unit Count');
    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_device_metadata(host_id,rack_unit_count,updated_by,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE rack_unit_count=VALUES(rack_unit_count),updated_at=NOW()',
        [$hostId, $rackUnitCount, icct_backend_current_user_id()]
    );
    return $rackUnitCount;
}

function reject_ruc($callback) {
    try {
        $callback();
        throw new LogicException('Expected InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $expected) {
        // pass
    }
}

/* ---- Test 1: default value of 1 when input is omitted ---- */
$result = test_save_rack_unit_count(10, []);
assert($result === 1, 'Default rack_unit_count should be 1');

/* ---- Test 2: valid value stored correctly ---- */
$executed = [];
$result = test_save_rack_unit_count(10, ['rack_unit_count' => '2']);
assert($result === 2, 'rack_unit_count 2 should be stored');
assert(count($executed) === 1, 'Exactly one execute call expected');
assert($executed[0]['args'][0] === 10, 'host_id must be first arg');
assert($executed[0]['args'][1] === 2, 'rack_unit_count must be second arg');
assert($executed[0]['args'][2] === 1, 'user_id must be third arg');

/* ---- Test 3: maximum boundary (42U) ---- */
$result = test_save_rack_unit_count(10, ['rack_unit_count' => '42']);
assert($result === 42, 'rack_unit_count 42 (max) should be accepted');

/* ---- Test 4: minimum boundary (1U) ---- */
$result = test_save_rack_unit_count(10, ['rack_unit_count' => '1']);
assert($result === 1, 'rack_unit_count 1 (min) should be accepted');

/* ---- Test 5: value 0 rejected ---- */
reject_ruc(fn() => test_save_rack_unit_count(10, ['rack_unit_count' => '0']));

/* ---- Test 6: value 43 rejected ---- */
reject_ruc(fn() => test_save_rack_unit_count(10, ['rack_unit_count' => '43']));

/* ---- Test 7: negative value rejected ---- */
reject_ruc(fn() => test_save_rack_unit_count(10, ['rack_unit_count' => '-1']));

/* ---- Test 8: non-numeric value rejected ---- */
reject_ruc(fn() => test_save_rack_unit_count(10, ['rack_unit_count' => 'two']));

/* ---- Test 9: slash/fraction like 3/4 cleanly takes 3 ---- */
$result = test_save_rack_unit_count(10, ['rack_unit_count' => '3/4']);
assert($result === 3, 'rack_unit_count 3/4 should take 3');

/* ---- Test 10: unit suffix like 4U cleanly takes 4 ---- */
$result = test_save_rack_unit_count(10, ['rack_unit_count' => '4U']);
assert($result === 4, 'rack_unit_count 4U should take 4');

echo "Rack unit count field validation and storage tests passed.\n";
