<?php

/**
 * Escaped form controls built from saved values and installed Cacti field metadata.
 */

/**
 * Render an escaped input; type, name and extra attributes are trusted template arguments.
 */
function icct_nms_input($label, $name, $value = '', $type = 'text', $extra = '')
{
    print '<label class="field"><span class="field-label">' .
        icct_nms_h($label) .
        '</span><input type="' .
        $type .
        '" name="' .
        $name .
        '" value="' .
        icct_nms_h($value) .
        '" ' .
        $extra .
        '></label>';
}

/**
 * Render choices and retain an unavailable saved selection for explicit user review.
 */
function icct_nms_select($label, $name, $choices, $value, $extra = '')
{
    print '<label class="field"><span class="field-label">' .
        icct_nms_h($label) .
        '</span><span class="select-wrap"><select name="' .
        $name .
        '" ' .
        $extra .
        '>';
    if ((string) $value !== '' && !array_key_exists((string) $value, $choices)) {
        $choices[$value] = 'Saved value unavailable: ' . $value;
    }
    foreach ($choices as $key => $text) {
        print '<option value="' .
            icct_nms_h($key) .
            '"' .
            ((string) $key === (string) $value ? ' selected' : '') .
            '>' .
            icct_nms_h($text) .
            '</option>';
    }
    print '</select></span></label>';
}

/**
 * Use the installed Cacti field definition as the authoritative list of choices.
 */
function icct_nms_core_select($label, $name, $value)
{
    global $fields_host_edit;
    if (!isset($fields_host_edit[$name])) {
        $fallbacks = [
            'max_oids' => [1 => '1', 5 => '5', 10 => '10', 20 => '20', 30 => '30', 40 => '40', 50 => '50', 60 => '60'],
            'device_threads' => [1 => '1', 2 => '2', 3 => '3', 4 => '4'],
            'availability_method' => [1 => 'Ping', 2 => 'SNMP', 3 => 'SNMP or Ping'],
            'ping_method' => [1 => 'ICMP Ping', 2 => 'UDP Ping', 3 => 'TCP Ping'],
            'snmp_auth_protocol' => ['[None]' => 'None', 'MD5' => 'MD5', 'SHA' => 'SHA', 'SHA-224' => 'SHA-224', 'SHA-256' => 'SHA-256', 'SHA-384' => 'SHA-384', 'SHA-512' => 'SHA-512'],
            'snmp_priv_protocol' => ['[None]' => 'None', 'DES' => 'DES', 'AES-128' => 'AES-128', 'AES-192' => 'AES-192', 'AES-256' => 'AES-256'],
        ];
        if (isset($fallbacks[$name])) {
            icct_nms_select($label, $name, $fallbacks[$name], $value);
            return;
        }
        throw new RuntimeException('Cacti form field is unavailable: ' . $name);
    }
    icct_nms_select(
        $label,
        $name,
        icct_backend_core_field_choices($fields_host_edit[$name]),
        $value,
    );
}

/**
 * Read native defaults when creating a device instead of copying another host.
 */
function icct_nms_defaults()
{
    global $fields_host_edit;
    $values = ['location' => '']; // Cacti's optional free-text location has no form default.
    foreach ($fields_host_edit as $name => $field) {
        if (array_key_exists('default', $field)) {
            $values[$name] = $field['default'];
        }
    }
    return $values;
}
