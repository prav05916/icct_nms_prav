<?php
chdir('../..'); include('./include/auth.php'); include_once($config['base_path'].'/plugins/icct_inventory/lib/functions.php'); include_once($config['base_path'].'/plugins/icct_inventory/include/nav.php');
$plugin_url=$config['url_path'].'plugins/icct_inventory'; $host_id=icct_inv_req_int('host_id'); $host=$host_id?icct_inv_get_host($host_id):[]; $meta=$host_id?icct_inv_get_device_meta($host_id):[]; $type=(!empty($meta['device_type_id']))?icct_inv_get_type((int)$meta['device_type_id']):[]; $hosts=db_fetch_assoc('SELECT id,description,hostname FROM host ORDER BY description,hostname');
top_header(); print '<link rel="stylesheet" href="'.html_escape($plugin_url.'/css/icct_inventory.css').'">'; print '<div class="icct-inv-page">'; icct_inventory_nav('protocols');
html_start_box('Protocol Diagnostics','100%',false,3,'center','');
print '<div class="icct-note">Select an actual Cacti device to see the client-declared management protocols and offline RHEL test commands. Credentials are intentionally not stored or displayed here.</div><form method="get"><label>Actual Device <select name="host_id"><option value="0">-- Select --</option>'; foreach($hosts as $h){$sel=$host_id===(int)$h['id']?' selected':''; print '<option value="'.(int)$h['id'].'"'.$sel.'>'.icct_inv_h(($h['description']?:'Host #'.$h['id']).' ['.$h['hostname'].']').'</option>'; } print '</select></label> <button type="submit">Show Commands</button></form>';
html_end_box(true,true);

if($host){
 $ip=$host['hostname']; $protocols=$type?icct_inv_protocol_tokens($type['management_protocol']):[];
 html_start_box('Selected Device','100%',false,3,'center','');
 print '<table class="cactiTable"><tr><th>Device</th><th>IP / Hostname</th><th>Mapped Type</th><th>Declared Protocols</th></tr><tr><td>'.icct_inv_h($host['description']).'</td><td>'.icct_inv_h($ip).'</td><td>'.icct_inv_h($type['item_description']??'Unmapped').'</td><td>';
 if($protocols){foreach($protocols as $p)print '<span class="icct-badge">'.icct_inv_h($p).'</span>';} else print 'No protocol declaration in mapped client device type.';
 print '</td></tr></table>'; html_end_box(true,true);

 $cmds=[
  ['SNMP','Use Net-SNMP to validate sysDescr and interface status.','snmpget -v2c -c <COMMUNITY> -t 2 -r 1 '.escapeshellarg($ip).' .1.3.6.1.2.1.1.1.0\nsnmpwalk -v2c -c <COMMUNITY> -t 2 -r 1 '.escapeshellarg($ip).' .1.3.6.1.2.1.2.2.1.8'],
  ['Syslog','The LNMS receives UDP/TCP 514 with rsyslog. Configure the device to use the LNMS IP, then watch the dedicated file.','sudo tail -f /var/log/icct-lnms/remote.log\n# From another RHEL host for a test:\nlogger --udp -n <LNMS_IP> -P 514 -t ICCT_TEST "syslog test from '.icct_inv_h($host['description']).'"'],
  ['SSH','Perform a real SSH handshake/login. Use the actual device admin account or key policy.','ssh -o ConnectTimeout=5 <USER>@'.escapeshellarg($ip)],
  ['HTTP/HTTPS','Validate the device WebUI/cross-launch endpoint. Use CA validation in production.','curl --connect-timeout 5 -I http://'.escapeshellarg($ip).'/\ncurl --connect-timeout 5 -I https://'.escapeshellarg($ip).'/'],
  ['NTP','Query a candidate NTP device without setting the LNMS clock.','sudo chronyd -Q -t 3 "server '.escapeshellarg($ip).' iburst"'],
  ['LLDP / CDP','Validate topology information exposed by the network device over SNMP.','snmpwalk -v2c -c <COMMUNITY> '.escapeshellarg($ip).' .1.0.8802.1.1.2.1.4.1.1\n# Cisco CDP:\nsnmpwalk -v2c -c <COMMUNITY> '.escapeshellarg($ip).' .1.3.6.1.4.1.9.9.23.1.2.1.1'],
 ];
 foreach($cmds as $c){print '<div class="icct-proto-card"><h3>'.icct_inv_h($c[0]).'</h3><div>'.icct_inv_h($c[1]).'</div><div class="icct-command">'.nl2br(icct_inv_h($c[2])).'</div></div>';}
}else{
 print '<div class="icct-proto-card"><h3>Five-protocol baseline for offline RHEL</h3><p>The included guide configures and tests: <strong>SNMP, Syslog, SSH, HTTP/HTTPS and NTP</strong>. LLDP/CDP validation is also included through SNMP walks for network topology devices.</p><p>Open <code>docs/OFFLINE_RHEL_PROTOCOL_SETUP.md</code> inside the plugin package for the complete commands.</p></div>';
}
print '</div>'; bottom_footer();
