<?php
chdir('../..');
include('./include/auth.php');
include_once($config['base_path'] . '/plugins/icct_inventory/lib/functions.php');
include_once($config['base_path'] . '/plugins/icct_inventory/include/nav.php');
$filter=icct_inv_req('filter'); $rows=icct_inv_rows(); $page=icct_inv_page(); $total=0;
$data=icct_inv_list_device_types($filter,$rows,$page,$total); $plugin_url=$config['url_path'].'plugins/icct_inventory';
top_header(); print '<link rel="stylesheet" href="'.html_escape($plugin_url.'/css/icct_inventory.css').'">'; print '<div class="icct-inv-page">'; icct_inventory_nav('types');
html_start_box('Client Device Types','100%',false,3,'center','');
print '<div class="icct-note">Seeded from the updated client device list. This page is a capability catalogue, not a live-device list. Actual IP devices remain in Cacti and are mapped from <strong>Actual Devices</strong>.</div>';
print '<form method="get" class="icct-filter"><label>Search <input type="text" name="filter" value="'.icct_inv_h($filter).'" placeholder="BOM / description / make / protocol"></label><button type="submit">Go</button><a class="button" href="device_types.php">Clear</a></form>';
html_end_box(true,true);
$nav=html_nav_bar('device_types.php?filter='.rawurlencode($filter),MAX_DISPLAY_PAGES,$page,$rows,$total,8,'Device Types','page','main'); print $nav;
html_start_box('','100%',false,3,'center',''); html_header(['BOM Ref','Device Type','Make / Model','Management Interface','Management Protocol','FCAPS','Syslog','Cross-launch']);
if(cacti_sizeof($data)){$i=0;foreach($data as $r){form_alternate_row('type_'.$r['id'],true);
 form_selectable_cell(icct_inv_h($r['bom_ref']),$i); form_selectable_cell(icct_inv_h($r['item_description']),$i); form_selectable_cell(icct_inv_h(trim($r['make_name'].' '.$r['model'])),$i); form_selectable_cell(icct_inv_h($r['management_interface'] ?: '—'),$i);
 $p=''; foreach(icct_inv_protocol_tokens($r['management_protocol']) as $token){$p.='<span class="icct-badge">'.icct_inv_h($token).'</span>';} if($p==='')$p=icct_inv_h($r['management_protocol'] ?: '—'); form_selectable_cell($p,$i);
 form_selectable_cell(icct_inv_h($r['fcaps_summary'] ?: '—'),$i); form_selectable_cell(icct_inv_h($r['syslog_supported'] ?: '—'),$i); form_selectable_cell(icct_inv_h($r['cross_launch_supported'] ?: ($r['general_remarks']?'See remarks':'—')),$i); form_end_row(); $i++;}}
else print '<tr><td colspan="8"><em>No device types found.</em></td></tr>';
html_end_box(false); print $nav; print '</div>'; bottom_footer();
