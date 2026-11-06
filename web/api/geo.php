<?php
/* Land zu einer IP-Adresse aus der lokalen Datenbank (DB-IP Lite, CC BY 4.0).
   Die IP-Adresse wird nur für diese Abfrage verwendet und nirgends gespeichert.
   Dateiformat (vom GitHub-Ablauf erzeugt, sortiert):
     geo4.bin: je 10 Byte  = Start(4) Ende(4) Land(2)
     geo6.bin: je 34 Byte  = Start(16) Ende(16) Land(2) */
declare(strict_types=1);

function geoCountry(string $ip): string {
    $bin = @inet_pton($ip);
    if ($bin === false) return '';
    if (strlen($bin) === 16 && substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") $bin = substr($bin, 12); // IPv4 in IPv6
    $v4 = strlen($bin) === 4;
    $f = __DIR__ . '/_data/geo' . ($v4 ? '4' : '6') . '.bin';
    $w = $v4 ? 4 : 16; $rec = 2 * $w + 2;
    if (!is_file($f)) return '';
    $n = intdiv((int)filesize($f), $rec);
    $fh = @fopen($f, 'rb'); if (!$fh) return '';
    $lo = 0; $hi = $n - 1; $cc = '';
    while ($lo <= $hi) {
        $mid = ($lo + $hi) >> 1;
        fseek($fh, $mid * $rec);
        $r = fread($fh, $rec);
        if ($r === false || strlen($r) < $rec) break;
        $s = substr($r, 0, $w); $e = substr($r, $w, $w);
        if (strcmp($bin, $s) < 0) { $hi = $mid - 1; }
        elseif (strcmp($bin, $e) > 0) { $lo = $mid + 1; }
        else { $cc = substr($r, 2 * $w, 2); break; }
    }
    fclose($fh);
    return preg_match('/^[A-Z]{2}$/', $cc) ? $cc : '';
}
