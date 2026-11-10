<?php
$text = file_get_contents(__DIR__ . '/../assets/mibs/SNMPv2-TC.txt');
require_once __DIR__ . '/../../protocols/snmp/mibs/services/mib_offline_parser.php';
$clean = IcctMibOfflineParser::clean($text);

// Test old regex:
$matches = [];
$res = preg_match_all('/"(?:""|[^\"])*"|::=|\.\.|[A-Za-z][A-Za-z0-9-]*|-?[0-9]+|[^\s]/s', $clean, $matches);
echo "Old regex match count: " . count($matches[0]) . " error: " . preg_last_error() . PHP_EOL;

// Test possessive / atomic regex:
$matches2 = [];
$res2 = preg_match_all('/"(?>[^"]+|"")*"|::=|\.\.|[A-Za-z][A-Za-z0-9-]*|-?[0-9]+|[^\s]/s', $clean, $matches2);
echo "Atomic regex match count: " . count($matches2[0]) . " error: " . preg_last_error() . PHP_EOL;
