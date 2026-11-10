<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */
if (php_sapi_name() !== 'cli') { exit(1); }
chdir('../../..');
include('./include/cli_check.php');
include_once($config['base_path'] . '/plugins/icct_map/lib/functions.php');

$options = getopt('', ['site:', 'lat:', 'lng:', 'source::']);
if (!isset($options['site'], $options['lat'], $options['lng'])) {
    fwrite(STDERR, "Usage: php plugins/icct_map/cli/update_site_position.php --site=<id> --lat=<latitude> --lng=<longitude> [--source=gnss]\n");
    exit(2);
}
$result = icct_map_update_site_position((int)$options['site'], $options['lat'], $options['lng'], $options['source'] ?? 'gnss', null, false);
if (!$result['ok']) {
    fwrite(STDERR, $result['error'] . "\n");
    exit(3);
}
fwrite(STDOUT, "Position updated for Cacti site " . (int)$options['site'] . "\n");
