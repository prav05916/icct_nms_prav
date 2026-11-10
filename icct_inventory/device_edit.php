<?php
chdir('../..');
include('./include/auth.php');
include_once($config['base_path'] . '/plugins/icct_inventory/lib/functions.php');
include_once($config['base_path'] . '/plugins/icct_inventory/include/nav.php');
if(!icct_inv_can_manage()){raise_message('denied','You do not have permission to manage ICCT inventory.',MESSAGE_LEVEL_ERROR);header('Location: '.$config['url_path'].'plugins/icct_inventory/devices.php');exit;}
$host_id=icct_inv_req_int('id'); $host=icct_inv_get_host($host_id); if(!$host){header('Location: '.$config['url_path'].'plugins/icct_inventory/devices.php');exit;}
if($_SERVER['REQUEST_METHOD']==='POST'){
 $res=icct_inv_save_meta($host_id,[
  'device_type_id'=>icct_inv_req_int('device_type_id'),'segment_id'=>icct_inv_req_int('segment_id'),'serial_number'=>icct_inv_req('serial_number'),'mac_address'=>icct_inv_req('mac_address'),'maintenance_due_date'=>icct_inv_req('maintenance_due_date'),'serviceability'=>icct_inv_req('serviceability'),'cross_launch_url'=>icct_inv_req('cross_launch_url'),'notes'=>icct_inv_req('notes')]);
 if($res['ok']){raise_message('saved','Device inventory metadata saved.',MESSAGE_LEVEL_INFO);header('Location: '.$config['url_path'].'plugins/icct_inventory/device_edit.php?id='.$host_id);exit;} else raise_message('err',$res['error'],MESSAGE_LEVEL_ERROR);
}
$meta=icct_inv_get_device_meta($host_id); $types=icct_inv_get_device_types(true); $segments=icct_inv_get_segments(true); $plugin_url=$config['url_path'].'plugins/icct_inventory';
top_header(); print '<link rel="stylesheet" href="'.html_escape($plugin_url.'/css/icct_inventory.css').'">'; print '<div class="icct-inv-page">'; icct_inventory_nav('devices');
html_start_box('Actual Device Inventory - '.icct_inv_h($host['description'] ?: $host['hostname']),'100%',false,3,'center',''); print get_message_html();
form_start('device_edit.php?id='.$host_id,'device_inventory_form');
print '<div class="icct-form-grid">';
print '<label>Cacti Device</label><div><strong>'.icct_inv_h($host['description']).'</strong><br>'.icct_inv_h($host['hostname']).'</div>';
print '<label>Device Type *</label><select name="device_type_id"><option value="0">-- Unmapped --</option>'; foreach($types as $t){$sel=((int)($meta['device_type_id']??0)===(int)$t['id'])?' selected':''; print '<option value="'.(int)$t['id'].'"'.$sel.'>'.icct_inv_h(($t['bom_ref']?$t['bom_ref'].' - ':'').$t['item_description']).'</option>';} print '</select>';
print '<label>Segment</label><select name="segment_id"><option value="0">-- None --</option>'; foreach($segments as $s){$sel=((int)($meta['segment_id']??0)===(int)$s['id'])?' selected':''; print '<option value="'.(int)$s['id'].'"'.$sel.'>'.icct_inv_h($s['name'].($s['cidr']?' ['.$s['cidr'].']':'')).'</option>';} print '</select>';
print '<label>Serial Number</label><input type="text" name="serial_number" value="'.icct_inv_h($meta['serial_number']??'').'">';
print '<label>MAC Address</label><input type="text" name="mac_address" value="'.icct_inv_h($meta['mac_address']??'').'">';
print '<label>Maintenance Due</label><input type="date" name="maintenance_due_date" value="'.icct_inv_h($meta['maintenance_due_date']??'').'">';
print '<label>Serviceability</label><select name="serviceability">'; foreach(['Unknown','Serviceable','Degraded','Maintenance','Unserviceable'] as $v){$sel=(($meta['serviceability']??'Unknown')===$v)?' selected':''; print '<option'.$sel.'>'.icct_inv_h($v).'</option>';} print '</select>';
print '<label>Cross-launch URL</label><input type="url" name="cross_launch_url" placeholder="https://device/" value="'.icct_inv_h($meta['cross_launch_url']??'').'">';
print '<label>Notes</label><textarea name="notes">'.icct_inv_h($meta['notes']??'').'</textarea>';
print '<div class="icct-actions"><a class="button" href="devices.php">Back</a><button type="submit">Save</button></div></div>';
form_end(); html_end_box(true,true); print '</div>'; bottom_footer();
