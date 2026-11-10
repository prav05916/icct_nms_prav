<?php
function clean_scanner(string $text): string {
    if (str_starts_with($text, "\xEF\xBB\xBF")) $text = substr($text, 3);
    $len = strlen($text);
    $out = '';
    $i = 0;
    while ($i < $len) {
        $ch = $text[$i];
        if ($ch === '"') {
            $start = $i;
            $i++;
            while ($i < $len) {
                if ($text[$i] === '"') {
                    if ($i + 1 < $len && $text[$i + 1] === '"') {
                        $i += 2;
                        continue;
                    }
                    $i++;
                    break;
                }
                $i++;
            }
            $out .= substr($text, $start, $i - $start);
        } elseif ($ch === '-' && $i + 1 < $len && $text[$i + 1] === '-') {
            $i += 2;
            while ($i < $len) {
                $c = $text[$i];
                if ($c === "\r" || $c === "\n") {
                    break;
                }
                if ($c === '-' && $i + 1 < $len && $text[$i + 1] === '-') {
                    $i += 2;
                    break;
                }
                $i++;
            }
            $out .= ' ';
        } else {
            $out .= $ch;
            $i++;
        }
    }
    return $out;
}

$dir = __DIR__ . '/../assets/mibs/';
$files = glob($dir . '*.txt');
foreach ($files as $file) {
    $t = file_get_contents($file);
    $start = microtime(true);
    $res = clean_scanner($t);
    $elapsed = round((microtime(true) - $start) * 1000, 2);
    echo basename($file) . " (" . strlen($t) . " bytes) cleaned in " . $elapsed . " ms -> result " . strlen($res) . " bytes\n";
}
