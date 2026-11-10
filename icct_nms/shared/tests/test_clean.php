<?php
require_once __DIR__ . '/../../protocols/snmp/mibs/services/mib_offline_parser.php';
$text = file_get_contents(__DIR__ . '/../assets/mibs/SNMPv2-TC.txt');
$res = IcctMibOfflineParser::clean($text);
var_dump($res === null);
echo "Error: " . preg_last_error() . PHP_EOL;
echo "Error name: " . [
    PREG_NO_ERROR => 'PREG_NO_ERROR',
    PREG_INTERNAL_ERROR => 'PREG_INTERNAL_ERROR',
    PREG_BACKTRACK_LIMIT_ERROR => 'PREG_BACKTRACK_LIMIT_ERROR',
    PREG_RECURSION_LIMIT_ERROR => 'PREG_RECURSION_LIMIT_ERROR',
    PREG_BAD_UTF8_ERROR => 'PREG_BAD_UTF8_ERROR',
    PREG_BAD_UTF8_OFFSET_ERROR => 'PREG_BAD_UTF8_OFFSET_ERROR',
    PREG_JIT_STACKLIMIT_ERROR => 'PREG_JIT_STACKLIMIT_ERROR'
][preg_last_error()] ?? 'Unknown';
echo PHP_EOL;
