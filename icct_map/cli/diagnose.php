<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */
if (php_sapi_name() !== 'cli') { exit(1); }
chdir('../../..');
include('./include/cli_check.php');
include_once($config['base_path'] . '/plugins/icct_map/lib/functions.php');

$runtime = icct_map_runtime_status();
$snapshot = icct_map_sites_snapshot();

fwrite(STDOUT, "ICCT India Map diagnostic\n");
fwrite(STDOUT, "Cacti root: " . $config['base_path'] . "\n");
fwrite(STDOUT, "Runtime: " . ($runtime['ready'] ? 'READY' : 'NOT READY') . "\n");
foreach ($runtime['files'] as $row) {
    fwrite(STDOUT, sprintf("  %-24s %s (%d bytes)\n", $row['label'], $row['ok'] ? 'OK' : 'MISSING', (int)$row['bytes']));
}
fwrite(STDOUT, "State/UT features: " . (int)$runtime['feature_count'] . "\n");
fwrite(STDOUT, "Cacti Sites: " . (int)$snapshot['summary']['total_sites'] . "\n");
fwrite(STDOUT, "Mapped Sites: " . (int)$snapshot['summary']['mapped_sites'] . "\n");
fwrite(STDOUT, "Missing/invalid position: " . (int)$snapshot['summary']['missing_coordinates'] . "\n");
fwrite(STDOUT, "Up: " . (int)$snapshot['summary']['up'] . "\n");
fwrite(STDOUT, "Critical: " . (int)$snapshot['summary']['down'] . "\n");

if (cacti_sizeof($snapshot['missing'])) {
    fwrite(STDOUT, "\nSites requiring coordinates:\n");
    foreach ($snapshot['missing'] as $site) {
        fwrite(STDOUT, '  #' . (int)$site['id'] . ' ' . $site['name'] . "\n");
    }
}
