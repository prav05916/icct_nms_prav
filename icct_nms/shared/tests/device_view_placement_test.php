<?php
/**
 * Unit tests for Device Placement in the Rack pre-rendering and step navigation.
 * Pure PHP, zero external dependencies, RHEL 9.4 offline compatible.
 */

// Stubs
function icct_nms_h($string) {
    return htmlspecialchars((string)$string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Simulates the server-side Placement in the Rack rendering logic from inventory/templates/device.php.
 */
function render_rack_placement_html(array $values, array $rackData): string {
    $selectedRackRecord = null;
    foreach ($rackData as $r) {
        if ((int)$r['id'] === (int)$values['rack_id']) {
            $selectedRackRecord = $r;
            break;
        }
    }
    $positionParts = explode(':', (string)$values['rack_position']);
    $startUnit = count($positionParts) === 2 ? (int)$positionParts[0] : 0;
    $unitHeight = count($positionParts) === 2 ? (int)$positionParts[1] : 0;
    $isPeripheral = ($values['rack_position'] === 'peripheral');

    $summaryText = 'Select units';
    if ($isPeripheral) {
        $summaryText = 'Peripheral slot';
    } elseif ($startUnit > 0 && $unitHeight > 0) {
        $unitLabels = [];
        for ($u = $startUnit; $u < $startUnit + $unitHeight; $u++) {
            $unitLabels[] = $u . 'U';
        }
        $summaryText = implode(', ', $unitLabels);
    }

    ob_start();
    ?>
    <label class="field rack-unit-field"><span class="field-label">Placement in the Rack</span>
    <details id="rack-unit-picker"><summary><?= icct_nms_h($summaryText) ?></summary><div class="rack-unit-options"><?php
    if ($selectedRackRecord):
        $occupied = array_flip($selectedRackRecord['occupied'] ?? []);
        for ($unit = 1; $unit <= (int)$selectedRackRecord['unit_count']; $unit++):
            $isOccupied = isset($occupied[$unit]);
            $isChecked = $startUnit > 0 && $unit >= $startUnit && $unit < ($startUnit + $unitHeight);
            $labelText = $unit . 'U' . ($isOccupied ? ' — In use' : '');
    ?><label class="<?= $isOccupied ? 'occupied' : '' ?>"><input type="checkbox" name="rack_units[]" value="<?= $unit ?>" <?= $isChecked ? 'checked' : '' ?> <?= $isOccupied ? 'disabled' : '' ?> aria-label="<?= icct_nms_h($labelText) ?>"><?= icct_nms_h($labelText) ?></label><?php
        endfor;
    ?><label><input type="checkbox" name="rack_peripheral" value="peripheral" <?= $isPeripheral ? 'checked' : '' ?> aria-label="Peripheral slot">Peripheral slot</label><?php
    else:
    ?><p class="rack-empty-notice">Select a Rack Name above to view and assign rack units.</p><?php
    endif;
    ?></div></details>
    <input type="hidden" name="rack_position" value="<?= icct_nms_h($values['rack_position']) ?>">
    <input type="hidden" name="rack_units[]" value="" disabled id="rack-empty-units">
    </label>
    <?php
    return ob_get_clean();
}

/**
 * Simulates the step navigation template from inventory/templates/device.php.
 */
function render_steps_navigation_html(): string {
    ob_start();
    ?>
    <nav class="steps" aria-label="Device configuration">
        <ol>
            <li class="current">
                <span class="step-marker"></span>
                <span>
                    Basic Information
                    <small>1/7</small>
                </span>
            </li>
            <li>
                <a href="#protocol">
                    Protocol Config
                    <small>2/7</small>
                </a>
            </li>
            <li>
                <a href="#diagnostics">
                    Device Diagnostics
                    <small>3/7</small>
                </a>
            </li>
            <li><a href="#graphs">Graphs<small>4/7</small></a></li>
            <li><a href="#data-query">Data Query<small>5/7</small></a></li>
            <li><a href="#ports">Port Config<small>6/7</small></a></li>
            <li><a href="#fcaps">FCAPS<small>7/7</small></a></li>
        </ol>
    </nav>
    <?php
    return ob_get_clean();
}

/**
 * Simulates rack unit fallback in device_service.php.
 */
function resolve_rack_position_input(array $input): string {
    if (array_key_exists('rack_units', $input)) {
        if (!empty($input['rack_peripheral'])) {
            return 'peripheral';
        } elseif ($input['rack_units']) {
            $units = array_map('intval', (array)$input['rack_units']);
            sort($units);
            $start = $units[0];
            $height = count($units);
            if ($height === 1 && !empty($input['rack_unit_count'])) {
                $rawCount = $input['rack_unit_count'];
                if (is_string($rawCount) && preg_match('/^([0-9]+)/', trim($rawCount), $matches)) {
                    $rawCount = (int)$matches[1];
                }
                $requestedUnits = (int)$rawCount;
                if ($requestedUnits > 1 && $requestedUnits <= 42) {
                    $height = $requestedUnits;
                }
            }
            return $start . ':' . $height;
        }
        return '';
    }
    return $input['rack_position'] ?? '';
}

// Fixture rack catalogue
$rackChoices = [
    [
        'id' => 77,
        'name' => 'Rack 1',
        'unit_count' => 30,
        'occupied' => [15, 16],
    ],
    [
        'id' => 78,
        'name' => 'Rack 2',
        'unit_count' => 20,
        'occupied' => [],
    ],
];

/* ---- Test 1: Device placed in Rack 77 at unit 10:1 ---- */
$html1 = render_rack_placement_html([
    'rack_id' => 77,
    'rack_position' => '10:1',
], $rackChoices);

assert(str_contains($html1, '<summary>10U</summary>'), 'Summary should display 10U');
assert(str_contains($html1, 'value="10" checked'), 'Unit 10 should be checked');
assert(str_contains($html1, 'value="15"  disabled'), 'Occupied unit 15 should be disabled');
assert(str_contains($html1, 'class="occupied"'), 'Occupied unit should have occupied CSS class');
assert(str_contains($html1, 'Peripheral slot'), 'Peripheral slot option should be present');

/* ---- Test 2: Device placed in Rack 77 spanning 3 units (10:3) ---- */
$html2 = render_rack_placement_html([
    'rack_id' => 77,
    'rack_position' => '10:3',
], $rackChoices);

assert(str_contains($html2, '<summary>10U, 11U, 12U</summary>'), 'Summary should display all 3 units');
assert(str_contains($html2, 'value="10" checked'), 'Unit 10 should be checked');
assert(str_contains($html2, 'value="11" checked'), 'Unit 11 should be checked');
assert(str_contains($html2, 'value="12" checked'), 'Unit 12 should be checked');

/* ---- Test 3: Device assigned to Peripheral slot ---- */
$html3 = render_rack_placement_html([
    'rack_id' => 77,
    'rack_position' => 'peripheral',
], $rackChoices);

assert(str_contains($html3, '<summary>Peripheral slot</summary>'), 'Summary should display Peripheral slot');
assert(str_contains($html3, 'name="rack_peripheral" value="peripheral" checked'), 'Peripheral checkbox checked');

/* ---- Test 4: Unassigned rack (rack_id = 0) ---- */
$html4 = render_rack_placement_html([
    'rack_id' => 0,
    'rack_position' => '',
], $rackChoices);

assert(str_contains($html4, '<summary>Select units</summary>'), 'Summary should display Select units');
assert(str_contains($html4, 'class="rack-empty-notice"'), 'Should render rack-empty-notice for unassigned rack');
assert(str_contains($html4, 'Select a Rack Name above to view and assign rack units.'), 'Notice text displayed');

/* ---- Test 5: Step navigation links point to in-page wizard hashes ---- */
$stepsHtml = render_steps_navigation_html();
assert(str_contains($stepsHtml, 'href="#protocol"'), 'Protocol step link must be #protocol');
assert(str_contains($stepsHtml, 'href="#diagnostics"'), 'Diagnostics step link must be #diagnostics');
assert(str_contains($stepsHtml, 'href="#graphs"'), 'Graphs step link must be #graphs');
assert(str_contains($stepsHtml, 'href="#data-query"'), 'Data Query step link must be #data-query');
assert(str_contains($stepsHtml, 'href="#ports"'), 'Ports step link must be #ports');
assert(str_contains($stepsHtml, 'href="#fcaps"'), 'FCAPS step link must be #fcaps');

/* ---- Test 6: Fallback multi-unit expansion from rack_unit_count ---- */
$pos1 = resolve_rack_position_input([
    'rack_units' => ['10'],
    'rack_unit_count' => '3',
]);
assert($pos1 === '10:3', 'Single checkbox with rack_unit_count=3 should expand to 10:3');

$pos2 = resolve_rack_position_input([
    'rack_units' => ['10'],
    'rack_unit_count' => '4U',
]);
assert($pos2 === '10:4', 'Single checkbox with rack_unit_count=4U should expand to 10:4');

$pos3 = resolve_rack_position_input([
    'rack_units' => ['10'],
    'rack_unit_count' => '3/4',
]);
assert($pos3 === '10:3', 'Single checkbox with rack_unit_count=3/4 should take 3 units: 10:3');

$pos4 = resolve_rack_position_input([
    'rack_units' => ['10', '11'],
    'rack_unit_count' => '2',
]);
assert($pos4 === '10:2', 'Explicit multiple units 10,11 should resolve to 10:2');

$pos5 = resolve_rack_position_input([
    'rack_units' => [],
    'rack_peripheral' => 'peripheral',
]);
assert($pos5 === 'peripheral', 'Peripheral flag resolves to peripheral');

echo "Device placement pre-rendering and step navigation tests passed.\n";

