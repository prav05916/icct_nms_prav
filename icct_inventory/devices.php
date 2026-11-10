<?php
chdir('../..');
include('./include/auth.php');
include_once($config['base_path'] . '/plugins/icct_inventory/lib/functions.php');
include_once($config['base_path'] . '/plugins/icct_inventory/include/nav.php');
$filter=icct_inv_req('filter'); $rows=icct_inv_rows(); $page=icct_inv_page(); $total=0;
$data=icct_inv_list_actual_devices($filter,$rows,$page,$total);
$plugin_url=$config['url_path'].'plugins/icct_inventory';
top_header();
print '<link rel="stylesheet" href="'.html_escape($plugin_url.'/css/icct_inventory.css').'">';
print '<div class="icct-inv-page">'; icct_inventory_nav('devices');
html_start_box('Actual Devices','100%',false,3,'center','');
print '<form method="get" class="icct-filter"><label>Search <input type="text" name="filter" value="'.icct_inv_h($filter).'" placeholder="Name / IP / type / segment"></label><button type="submit">Go</button><a class="button" href="devices.php">Clear</a></form>';
html_end_box(true,true);
$nav=html_nav_bar('devices.php?filter='.rawurlencode($filter),MAX_DISPLAY_PAGES,$page,$rows,$total,9,'Devices','page','main'); print $nav;
html_start_box('','100%',false,3,'center','');
html_header(['Device','IP / Hostname','Status','Device Type','Segment','Serial No','Serviceability','FCAPS','Actions']);
if(cacti_sizeof($data)){
  $i=0; foreach($data as $r){$st=icct_inv_status_info($r); form_alternate_row('dev_'.$r['id'],true);
    form_selectable_cell(icct_inv_h($r['description'] ?: ('Host #'.$r['id'])),$i);
    form_selectable_cell(icct_inv_h($r['hostname']),$i);
    form_selectable_cell('<span class="icct-badge status-'.icct_inv_h($st['key']).'">'.icct_inv_h($st['label']).'</span>',$i);
    form_selectable_cell(icct_inv_h($r['device_type_name'] ?: 'Unmapped'),$i);
    form_selectable_cell(icct_inv_h($r['segment_name'] ?: '—'),$i);
    form_selectable_cell(icct_inv_h($r['serial_number'] ?: '—'),$i);
    form_selectable_cell(icct_inv_h($r['serviceability'] ?: 'Unknown'),$i);
    form_selectable_cell(icct_inv_h($r['fcaps_summary'] ?: '—'),$i);
    $links='<a href="device_edit.php?id='.(int)$r['id'].'">Inventory</a> | <a href="'.$config['url_path'].'host.php?action=edit&id='.(int)$r['id'].'">Cacti</a>';
    if($r['cross_launch_url']) $links.=' | <a target="_blank" rel="noopener" href="'.icct_inv_h($r['cross_launch_url']).'">Cross-launch</a>';
    form_selectable_cell($links,$i); form_end_row(); $i++;
  }
}else print '<tr><td colspan="9"><em>No devices found.</em></td></tr>';
html_end_box(false); print $nav; print '</div>'; bottom_footer();
