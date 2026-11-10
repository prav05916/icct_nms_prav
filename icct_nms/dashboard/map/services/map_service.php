<?php
/** Geographic topology uses native Cacti sites and this plugin's permitted inventory. */
function icct_nms_map_coordinates($latitude, $longitude) {
    if (!is_numeric($latitude) || !is_numeric($longitude)) return null;
    $lat=(float)$latitude; $lon=(float)$longitude;
    if (!is_finite($lat) || !is_finite($lon) || abs($lat)>90 || abs($lon)>180 || ($lat==0 && $lon==0)) return null;
    return [$lat,$lon];
}
function icct_nms_map_metrics($readings) {
    $cpu=$readings['cpu_percent'] ?? $readings['5min_cpu'] ?? null;
    if ($cpu===null && isset($readings['ssCpuIdle'])) $cpu=100-(float)$readings['ssCpuIdle'];
    $memory=$readings['memory_percent'] ?? null;
    if ($memory===null && isset($readings['mem_total'],$readings['mem_free']) && $readings['mem_total']>0) $memory=100*(1-$readings['mem_free']/$readings['mem_total']);
    $out=['cpu'=>$cpu,'memory'=>$memory,'packet_loss'=>null];
    foreach ($out as $key=>$value) $out[$key]=is_numeric($value) && $value>=0 && $value<=100 ? round((float)$value,2) : null;
    return $out;
}
/** Read only fresh AVERAGE values from graph data sources accessible to this account. */
function icct_nms_map_readings($id) {
    global $config;
    $graphs=icct_nms_device_graphs($id);
    if (!$graphs) return [];
    $ids=implode(',',array_map('intval',array_column($graphs,'local_graph_id')));
    $sources=db_fetch_assoc_prepared("SELECT DISTINCT dtr.local_data_id,dtr.data_source_name FROM graph_local gl JOIN graph_templates_item gti ON gti.local_graph_id=gl.id JOIN data_template_rrd dtr ON dtr.id=gti.task_item_id WHERE gl.host_id=? AND gl.id IN ($ids) AND dtr.data_source_name IN ('cpu_percent','5min_cpu','ssCpuIdle','memory_percent','mem_total','mem_free')",[$id]);
    if (!$sources) return [];
    require_once $config['base_path'].'/lib/rrd.php';
    $now=time(); $interval=max(60,(int)read_config_option('poller_interval')); $cache=[]; $values=[];
    foreach ($sources as $source) {
        try {
            $key=(int)$source['local_data_id'];
            if (!isset($cache[$key])) $cache[$key]=rrdtool_function_fetch($key,$now-3*$interval,$now,$interval,true,null,'AVERAGE');
            $fetch=$cache[$key]; $column=array_search($source['data_source_name'],$fetch['data_source_names'] ?? [],true);
            $points=$column===false?[]:($fetch['values'][$column] ?? []); krsort($points,SORT_NUMERIC);
            foreach ($points as $stamp=>$point) {
                if ((int)$stamp>$now) continue;
                if ($now-(int)$stamp<=2*$interval && is_numeric($point) && is_finite((float)$point)) $values[$source['data_source_name']]=(float)$point;
                break;
            }
        } catch (Throwable $failure) { /* Missing RRDs remain unknown. */ }
    }
    return $values;
}
/** Diagnostic results stay private to their requesting account; expose measured fields only. */
function icct_nms_map_measurement($host) {
    $empty=['state'=>'Not measured','packet_loss'=>null,'latency_ms'=>null,'method'=>null,'target'=>null,'collector_id'=>null,'collected_at'=>null];
    if (($host['disabled'] ?? '')!=='') return $empty;
    $job=db_fetch_row_prepared("SELECT * FROM plugin_icct_nms_diagnostic_jobs WHERE host_id=? AND user_id=? AND tool='ping' AND status IN ('complete','failed') ORDER BY id DESC LIMIT 1",[(int)$host['id'],icct_backend_current_user_id()]);
    if (!$job) return $empty;
    try { $row=icct_backend_diag_assignment((int)$host['id'],'ping'); } catch (Throwable $error) { return $empty; }
    if (!hash_equals($job['config_hash'],icct_backend_diag_signature($row)) || (int)$job['poller_id']!==(int)$host['poller_id']) return $empty;
    $result=json_decode($job['result_json'],true);
    if (!is_array($result) || !empty($result['truncated']) || !empty($result['timed_out']) || ($result['target'] ?? '')!==$host['hostname']) return $empty;
    $age=time()-strtotime($job['finished_at'] ?? '');
    if ($age<0 || $age>900) return array_replace($empty,['state'=>'Stale']);
    $output=$result['output'] ?? '';
    if (!preg_match('/(\d+) packets transmitted,\s*(\d+) (?:packets )?received.*?([0-9.]+)% packet loss/s',$output,$loss) || (int)$loss[1]<1 || (int)$loss[2]>(int)$loss[1] || (float)$loss[3]>100) return $empty;
    $latency=null;
    if (preg_match('/(?:rtt|round-trip)[^=]*=\s*[0-9.]+\/([0-9.]+)\/[0-9.]+/',$output,$rtt)) $latency=(float)$rtt[1];
    return ['state'=>'Current','packet_loss'=>round(100*(1-(int)$loss[2]/(int)$loss[1]),2),'latency_ms'=>$latency,'method'=>'ping','target'=>$host['hostname'],'collector_id'=>(int)$job['poller_id'],'collected_at'=>$job['finished_at']];
}
function icct_nms_map_data() {
    $sites=[]; $unlocated=[]; $counts=['total'=>0,'online'=>0,'offline'=>0,'disabled'=>0,'other'=>0];
    $types=icct_nms_device_types();
    foreach (icct_nms_inventory() as $row) {
        $id=(int)$row['id'];
        $location=db_fetch_row_prepared('SELECT name,address1,city,state,country,latitude,longitude FROM sites WHERE id=?',[(int)$row['site_id']]);
        $native=db_fetch_row_prepared("SELECT * FROM host WHERE id=? AND deleted=''",[$id]);
        $device=['id'=>$id,'site_id'=>(int)$row['site_id'],'name'=>$row['description'],'status'=>$row['status_label'],'address'=>$row['hostname'],'system'=>$native['snmp_sysDescr'] ?? '', 'category'=>$row['segment'] ?? '', 'serial'=>$row['manual_serial_number'] ?? '', 'shape'=>icct_nms_device_shape($row['category_id'],$row['device_type'],$types),'image'=>'', 'alarm'=>null,'response_ms'=>null];
        $portView=icct_backend_ports_view($native ?: $row);
        $device['ports']=['fresh'=>$portView['fresh'],'collected'=>$portView['collected'],'items'=>array_map(static fn($port)=>array_intersect_key($port,array_flip(['index','name','admin','oper','status','speed_bps','high_speed_mbps'])),$portView['ports'])];
        $counts['total']++; $counts[$device['status']==='Up'?'online':($device['status']==='Down'?'offline':($device['status']==='Disabled'?'disabled':'other'))]++;
        if (icct_backend_config_connection_status($id)===null && $device['status']==='Up' && icct_backend_parameter_is_fresh($row['last_updated']) && ($native['total_polls'] ?? 0)>0) $device['response_ms']=(float)$native['cur_time'];
        if ($device['serial']==='') $device['serial']=(string)db_fetch_cell_prepared("SELECT observed_value FROM plugin_icct_nms_device_inventory WHERE host_id=? AND inventory_key='serial_number' AND status IN ('ok','changed') AND last_success>=DATE_SUB(NOW(),INTERVAL 10 MINUTE)",[$id]);
        foreach ($types as $type) if ((int)$type['category_id']===(int)$row['category_id'] && $type['name']===$row['device_type']) { $device['image']=icct_nms_type_asset($type,'map'); break; }
        $device+=icct_nms_map_metrics(icct_nms_map_readings($id));
        $device['diagnostics']=is_realm_allowed(3)?icct_backend_diag_selected_labels($id):[];
        $device['diagnostic_measurement']=icct_nms_map_measurement($row); $device['packet_loss']=$device['diagnostic_measurement']['packet_loss'];
        $device['fault_counts']=array_fill_keys(['Critical','Major','Minor','Warning','Information'],0);
        $faults=icct_nms_fault_observations($row);
        foreach($faults as $fault)if(isset($device['fault_counts'][$fault['state']]))$device['fault_counts'][$fault['state']]++;
        $portStates=[];
        foreach($faults as $fault)if(strpos((string)$fault['rule'],'port:')===0)$portStates[substr($fault['rule'],5)]=$fault['state'];
        foreach($device['ports']['items'] as &$port)$port['alarm_state']=$portStates[$port['index']] ?? 'Unmonitored';
        unset($port);
        $rules=icct_nms_fault_rules($id);
        $device['fault_alarms']=[];
        foreach($faults as $fault)if(isset($device['fault_counts'][$fault['state']])){
            $rule=$rules[$fault['rule']] ?? [];
            $device['fault_alarms'][]=['name'=>trim($rule['name'] ?? '') ?: ($fault['graph'] ?? 'Fault threshold exceeded'),'severity'=>$fault['state'],'source'=>strpos((string)$fault['rule'],'port:')===0?'Port':'Fault','time'=>(int)($fault['sample_time'] ?? 0),'value'=>$fault['value'] ?? null,'graph'=>$fault['graph'] ?? '', 'graph_id'=>(int)($fault['graph_id'] ?? 0),'corrective_action'=>$rule['corrective_action'] ?? ''];
        }
        $rank=['Information'=>1,'Minor'=>2,'Warning'=>3,'Major'=>4,'Critical'=>5]; $highest=0;
        foreach ($faults as $fault) if (($rank[$fault['state']] ?? 0)>$highest) {
            $highest=$rank[$fault['state']]; $rule=$rules[$fault['rule']] ?? [];
            $device['alarm']=['severity'=>$fault['state'],'message'=>(($rule['name'] ?? '') ?: $fault['graph']).' — '.$fault['value']];
        }
        $point=icct_nms_map_coordinates($location['latitude'] ?? null,$location['longitude'] ?? null);
        if (!$point || empty($location['name'])) { $device['site']=$location['name'] ?? 'No site'; $unlocated[]=$device; continue; }
        $siteId=(int)$row['site_id'];
        if (!isset($sites[$siteId])) $sites[$siteId]=['id'=>$siteId,'name'=>$location['name'],'coordinates'=>$point,'location'=>implode(', ',array_filter([$location['address1'],$location['city'],$location['state'],$location['country']])),'devices'=>[]];
        $sites[$siteId]['devices'][]=$device;
    }
    return ['sites'=>array_values($sites),'unlocated'=>$unlocated,'counts'=>$counts];
}


/** Convert authorized device counts into the site state shown by map markers. */
function icct_nms_map_site_state_from_counts($counts) {
    $total=(int)($counts['total'] ?? 0);
    if ((int)($counts['offline'] ?? 0)>0) return 'Offline';
    if ($total>0 && (int)($counts['online'] ?? 0)===$total) return 'Online';
    if ($total>0 && (int)($counts['disabled'] ?? 0)===$total) return 'Disabled';
    return 'Other';
}

/**
 * Lightweight AJAX snapshot for marker status refresh.
 * It deliberately avoids RRD, diagnostics and fault evaluation; detailed alarm data is fetched
 * on demand through site_summary when the operator hovers/clicks a site.
 */
function icct_nms_map_live_status() {
    $inventory=icct_nms_inventory();
    $global=['total'=>0,'online'=>0,'offline'=>0,'disabled'=>0,'other'=>0];
    $bySite=[];
    foreach($inventory as $row){
        $status=$row['status_label'] ?? 'Other';
        $key=$status==='Up'?'online':($status==='Down'?'offline':($status==='Disabled'?'disabled':'other'));
        $global['total']++;$global[$key]++;
        $siteId=(int)($row['site_id'] ?? 0);
        if($siteId<=0)continue;
        if(!isset($bySite[$siteId]))$bySite[$siteId]=['total'=>0,'online'=>0,'offline'=>0,'disabled'=>0,'other'=>0];
        $bySite[$siteId]['total']++;$bySite[$siteId][$key]++;
    }
    if(!$bySite)return ['sites'=>[],'counts'=>$global,'refreshed_at'=>date('Y-m-d H:i:s')];
    $ids=implode(',',array_map('intval',array_keys($bySite)));
    $siteRows=db_fetch_assoc("SELECT id,name,address1,city,state,country,latitude,longitude FROM sites WHERE id IN ($ids) ORDER BY name");
    $sites=[];
    foreach((array)$siteRows as $site){
        $id=(int)$site['id'];$point=icct_nms_map_coordinates($site['latitude'],$site['longitude']);
        if(!$point||trim((string)$site['name'])==='')continue;
        $counts=$bySite[$id] ?? ['total'=>0,'online'=>0,'offline'=>0,'disabled'=>0,'other'=>0];
        $sites[]=['id'=>$id,'name'=>$site['name'],'coordinates'=>$point,
            'location'=>implode(', ',array_filter([$site['address1'],$site['city'],$site['state'],$site['country']])),
            'counts'=>$counts,'status'=>icct_nms_map_site_state_from_counts($counts)];
    }
    return ['sites'=>$sites,'counts'=>$global,'refreshed_at'=>date('Y-m-d H:i:s')];
}

/** Build a bounded WMS request; destination and layer come only from administrator configuration. */
function icct_nms_map_wms_parameters($input, $layer)
{
    if (!isset($input['bbox']) || !is_string($input['bbox'])) throw new InvalidArgumentException('Invalid map bounds.');
    $bbox = explode(',', $input['bbox']);
    if (count($bbox) !== 4) throw new InvalidArgumentException('Invalid map bounds.');
    foreach ($bbox as $v) if (!is_numeric($v) || !is_finite((float) $v) || abs((float) $v) > 20037509) throw new InvalidArgumentException('Invalid map bounds.');
    if ((float) $bbox[0] >= (float) $bbox[2] || (float) $bbox[1] >= (float) $bbox[3]) throw new InvalidArgumentException('Invalid map bounds.');
    $size = [];
    foreach (['width', 'height'] as $key) {
        $value = filter_var($input[$key] ?? 256, FILTER_VALIDATE_INT);
        if ($value === false || $value < 1 || $value > 2048) throw new InvalidArgumentException('Invalid map size.');
        $size[$key] = $value;
    }
    return ['service' => 'WMS', 'version' => '1.1.1', 'request' => 'GetMap', 'layers' => $layer,
        'styles' => '', 'srs' => 'EPSG:3857', 'bbox' => implode(',', $bbox), 'width' => $size['width'], 'height' => $size['height'],
        'format' => 'image/png', 'transparent' => 'false', 'bgcolor' => '0xe8eef1'];
}

/** Relay raster tiles after Cacti authentication without exposing the GeoServer endpoint. */
function icct_nms_map_tile()
{
    global $config;
    $url = $config['nms_geoserver_wms_url'] ?? '';
    $layer = $config['nms_geoserver_layer'] ?? '';
    try {
        if (!$url || !$layer || !preg_match('~^https?://~i', $url) || !function_exists('curl_init')) throw new RuntimeException('GeoServer is not configured.');
        $params = icct_nms_map_wms_parameters($_GET, $layer);
        session_write_close();
        $handle = curl_init($url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($params));
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        $body = curl_exec($handle);
        $code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($code !== 200 || !is_string($body) || substr($body, 0, 8) !== "\x89PNG\r\n\x1a\n") throw new RuntimeException('GeoServer tile unavailable.');
        header('Content-Type: image/png');
        header('Cache-Control: private, max-age=300');
        print $body;
    } catch (Throwable $error) {
        http_response_code($error instanceof InvalidArgumentException ? 400 : 503);
        header('Content-Type: text/plain');
        print $error instanceof InvalidArgumentException ? $error->getMessage() : 'GeoServer map unavailable.';
    }
}

/**
 * Resolve the detailed basemap without requiring Internet access.
 *
 * Supported production sources:
 *  - local XYZ raster tiles, configured with $config['nms_map_xyz_dir'];
 *  - a locally reachable GeoServer WMS, configured with the existing
 *    nms_geoserver_wms_url / nms_geoserver_layer settings.
 *
 * The plugin never silently calls a public map service. When neither source is
 * configured, the bundled India state layer remains available as the fallback.
 */
function icct_nms_map_xyz_root()
{
    global $config;
    $configured = trim((string)($config['nms_map_xyz_dir'] ?? ''));
    $candidates = array_filter([$configured, dirname(__DIR__) . '/tiles', DIRECTORY_SEPARATOR === '/' ? '/opt/icct-nms-map/tiles' : '']);
    foreach ($candidates as $candidate) {
        if (!is_dir($candidate)) continue;
        $levels = glob(rtrim($candidate, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '[0-9]*', GLOB_ONLYDIR);
        if ($levels) return $candidate;
    }
    return '';
}

function icct_nms_map_basemap_config()
{
    global $config;
    $xyz = icct_nms_map_xyz_root();
    if ($xyz !== '') {
        $min = filter_var($config['nms_map_xyz_min_zoom'] ?? 2, FILTER_VALIDATE_INT);
        $max = filter_var($config['nms_map_xyz_max_zoom'] ?? 18, FILTER_VALIDATE_INT);
        $min = $min === false ? 2 : max(0, min(22, (int)$min));
        $max = $max === false ? 18 : max($min, min(22, (int)$max));
        $ext = strtolower((string)($config['nms_map_xyz_extension'] ?? 'png'));
        if (!in_array($ext, ['png','jpg','jpeg','webp'], true)) $ext = 'png';
        return [
            'type' => 'xyz',
            'url' => 'dashboard/controllers/topology.php?map_xyz_tile=1&z={z}&x={x}&y={y}',
            'min_zoom' => $min,
            'max_zoom' => $max,
            'label' => 'Offline detailed basemap',
        ];
    }
    $url = trim((string)($config['nms_geoserver_wms_url'] ?? ''));
    $layer = trim((string)($config['nms_geoserver_layer'] ?? ''));
    if ($url !== '' && $layer !== '' && preg_match('~^https?://~i', $url)) {
        return [
            'type' => 'wms',
            'url' => 'dashboard/controllers/topology.php?map_tile=1',
            'min_zoom' => 2,
            'max_zoom' => 18,
            'label' => 'Offline GeoServer basemap',
        ];
    }
    return null;
}

/** Authenticated relay for an administrator-provisioned offline XYZ tile tree. */
function icct_nms_map_xyz_tile()
{
    global $config;
    try {
        $root = icct_nms_map_xyz_root();
        if ($root === '' || !is_dir($root)) throw new RuntimeException('Offline XYZ basemap is not configured.');
        $z = filter_var($_GET['z'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>22]]);
        if ($z === false) throw new InvalidArgumentException('Invalid tile zoom.');
        $limit = (1 << $z) - 1;
        $x = filter_var($_GET['x'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>$limit]]);
        $y = filter_var($_GET['y'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>$limit]]);
        if ($x === false || $y === false) throw new InvalidArgumentException('Invalid tile coordinate.');
        $ext = strtolower((string)($config['nms_map_xyz_extension'] ?? 'png'));
        if (!in_array($ext, ['png','jpg','jpeg','webp'], true)) $ext='png';
        $base = realpath($root);
        if ($base === false) throw new RuntimeException('Offline tile directory is unavailable.');
        $candidate = $base . DIRECTORY_SEPARATOR . $z . DIRECTORY_SEPARATOR . $x . DIRECTORY_SEPARATOR . $y . '.' . $ext;
        $real = realpath($candidate);
        if ($real === false || !is_file($real) || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) {
            http_response_code(404); header('Content-Type: text/plain'); print 'Tile unavailable.'; return;
        }
        $mime = match($ext){'jpg','jpeg'=>'image/jpeg','webp'=>'image/webp',default=>'image/png'};
        header('Content-Type: '.$mime);
        header('Content-Length: '.filesize($real));
        header('Cache-Control: private, max-age=86400');
        readfile($real);
    } catch (Throwable $error) {
        http_response_code($error instanceof InvalidArgumentException ? 400 : 503);
        header('Content-Type: text/plain');
        print $error instanceof InvalidArgumentException ? $error->getMessage() : 'Offline basemap unavailable.';
    }
}

/** Aggregate only the devices already permitted by the inventory access checks. */
function icct_nms_map_site_summary($site) {
    $counts=['total'=>0,'online'=>0,'offline'=>0,'disabled'=>0,'other'=>0];
    $alarms=array_fill_keys(['Critical','Major','Minor','Warning','Information'],0);
    foreach($site['devices'] as $device) {
        $counts['total']++;
        $counts[match($device['status']){'Up'=>'online','Down'=>'offline','Disabled'=>'disabled',default=>'other'}]++;
        foreach($alarms as $severity=>$count)$alarms[$severity]+=(int)($device['fault_counts'][$severity]??0);
    }
    $state=icct_nms_map_site_state_from_counts($counts);
    return ['id'=>(int)$site['id'],'name'=>$site['name'],'coordinates'=>$site['coordinates'],'location'=>$site['location'] ?? '',
        'counts'=>$counts,'fault_counts'=>$alarms,'status'=>$state];
}
