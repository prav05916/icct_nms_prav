<?php
chdir('../..');
include('./include/auth.php');
include_once($config['base_path'] . '/plugins/icct_inventory/lib/functions.php');
include_once($config['base_path'] . '/plugins/icct_inventory/include/nav.php');
$plugin_url=$config['url_path'].'plugins/icct_inventory';
$counts=icct_inv_get_counts();
top_header();
print '<link rel="stylesheet" href="'.html_escape($plugin_url.'/css/icct_inventory.css').'">';
print '<div class="icct-inv-page">';
icct_inventory_nav('overview');
?>
<div class="icct-inv-grid">
  <div class="icct-kpi"><div class="n"><?php print $counts['types']; ?></div><div class="l">Client device types</div></div>
  <div class="icct-kpi"><div class="n"><?php print $counts['hosts']; ?></div><div class="l">Actual Cacti devices</div></div>
  <div class="icct-kpi"><div class="n"><?php print $counts['mapped']; ?></div><div class="l">Mapped actual devices</div></div>
  <div class="icct-kpi"><div class="n"><?php print $counts['segments']; ?></div><div class="l">Segments</div></div>
  <div class="icct-kpi"><div class="n"><?php print $counts['oids']; ?></div><div class="l">FCAPS / OID definitions</div></div>
</div>

<div class="icct-note"><strong>Authoritative data model:</strong> Cacti <code>host</code> remains the live device/status source. This plugin adds only ICCT client catalogue, classification, segment, inventory metadata and FCAPS/OID policy.</div>

<div class="icct-flow">
  <div class="box"><strong>1. Device Types</strong><br>Updated client/BOM catalogue: make, model, management interfaces, protocols and FCAPS applicability.</div>
  <div class="box"><strong>2. Actual Devices</strong><br>Cacti hosts mapped to a client device type. Add serial/MAC, maintenance/serviceability and cross-launch URL.</div>
  <div class="box"><strong>3. Segment</strong><br>Assign an actual device to a logical network segment/subnet without changing Cacti core tables.</div>
  <div class="box"><strong>4. FCAPS → OID</strong><br>Attach generic or device-type-specific numeric OIDs for fault, configuration, accounting, performance, security/inventory/topology use.</div>
</div>

<?php
html_start_box('Recommended operational flow','100%',false,3,'center','');
print '<table class="cactiTable"><tr><th>Step</th><th>Action</th><th>Result</th></tr>';
$steps=[
['1','Add/discover the real device in Cacti','Cacti owns IP, SNMP settings, reachability and status.'],
['2','Open Actual Devices and classify the host','Map host → client Device Type + Segment; record serial/MAC/serviceability if required.'],
['3','Review FCAPS / OIDs','Use generic OIDs first; add vendor OIDs only from approved vendor MIB documentation.'],
['4','Run Protocol Diagnostics','Validate SNMP, Syslog, SSH, HTTP/HTTPS and NTP from the offline RHEL LNMS.'],
['5','Bind Cacti data queries/templates','Use proven OIDs for continuous polling, thresholds, graphs and alarms.'],
];
foreach($steps as $s){print '<tr><td>'.icct_inv_h($s[0]).'</td><td>'.icct_inv_h($s[1]).'</td><td>'.icct_inv_h($s[2]).'</td></tr>';}
print '</table>';
html_end_box(true,true);
print '</div>';
bottom_footer();
