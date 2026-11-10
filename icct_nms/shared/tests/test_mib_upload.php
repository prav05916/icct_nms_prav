<?php
if (PHP_SAPI !== 'cli') exit(1);
define('IN_CACTI_INSTALL', true);
require '/Apache24/htdocs/cacti/include/cli_check.php';
require_once __DIR__ . '/../services/bootstrap.php';
icct_nms_backend();
require_once __DIR__ . '/../../protocols/snmp/mibs/services/mib_repository_service.php';
require_once __DIR__ . '/../../protocols/snmp/mibs/services/mib_upload_session_service.php';
require_once __DIR__ . '/../../protocols/snmp/mibs/services/mib_browser_service.php';
$_SESSION['sess_user_id'] = 1;

echo "=== MIB Upload & Download Verification ===" . PHP_EOL;
$path = __DIR__ . '/../assets/mibs/IF-MIB.txt';
if (!file_exists($path)) {
    echo "ERROR: IF-MIB.txt does not exist at $path" . PHP_EOL;
    exit(1);
}
echo "Sample MIB file: IF-MIB.txt (" . filesize($path) . " bytes)" . PHP_EOL;

// 1. Preview / Parse
$preview = icct_mib_preview(['name'=>['IF-MIB.txt'],'tmp_name'=>[$path],'error'=>[UPLOAD_ERR_OK]], '__mib_unassigned__');
echo "Parsed records count: " . count($preview['records']) . PHP_EOL;

$_SESSION['sess_user_id'] = 1;
// 2. Upload session store & commit
$token = bin2hex(random_bytes(16));
icct_mib_upload_session_store($token, $preview);
echo "Upload session token generated: $token" . PHP_EOL;

$stored = icct_mib_upload_session_commit($token, false, '1');
echo "Committed bundle ID: " . $stored['id'] . PHP_EOL;
echo "Bundle name: " . $stored['name'] . PHP_EOL;
echo "Bundle files: " . count($stored['files']) . PHP_EOL;

// 3. List bundles from database
$bundles = icct_mib_list();
echo "Total MIB bundles in repository: " . count($bundles) . PHP_EOL;

// 4. Download / retrieve file
$downloadedContent = icct_mib_file($stored, 0);
echo "Retrieved downloaded content length: " . strlen($downloadedContent) . " bytes" . PHP_EOL;
$originalContent = file_get_contents($path);

if ($downloadedContent === $originalContent) {
    echo "SUCCESS: Uploaded and downloaded MIB file content matches exactly!" . PHP_EOL;
} else {
    echo "ERROR: Downloaded content does not match original file!" . PHP_EOL;
}
