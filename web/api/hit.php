<?php
/* Zugriffsstatistik ohne Cookies.
   Arten (k):  s = App-Start, e = Sitzungsende (Dauer), x = Funktion benutzt, q = Fehler, a = KI-Aufruf (Kosten)
   Gespeichert werden nur grobe, nicht persönliche Angaben: Uhrzeit, Land, Gerät, System, Browser, Sprache,
   App-Version, Nutzungsart, Bildschirmklasse, Herkunfts-Website, Kampagne. KEINE IP-Adressen, KEIN
   Browser-Kennzeichen. Verschiedene Geräte werden nur innerhalb desselben Tages über einen täglich
   wechselnden, danach gelöschten Zufallswert unterschieden. */
require __DIR__ . '/lib.php';
require __DIR__ . '/geo.php';
cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonOut(['error' => 'method'], 405);

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$dev = deviceOf($ua);
if ($dev === 'bot') jsonOut(['ok' => true]);

$in = json_decode((string)file_get_contents('php://input', false, null, 0, 4000), true);
if (!is_array($in)) jsonOut(['error' => 'json'], 400);
$s = function ($k, $re, $max = 40) use ($in) { $x = $in[$k] ?? ''; if (!is_string($x) && !is_int($x) && !is_float($x)) return ''; $x = (string)$x; return preg_match($re, $x) ? substr($x, 0, $max) : ''; };

$k = $s('k', '/^[sexqa]$/', 1) ?: 's';
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

/* System und Browser grob bestimmen */
$os = 'Andere';
if (preg_match('/iPhone OS (\d+)|CPU OS (\d+)/', $ua, $m)) $os = 'iOS ' . ($m[1] ?: $m[2]);
elseif (preg_match('/Android (\d+)/', $ua, $m)) $os = 'Android ' . $m[1];
elseif (strpos($ua, 'Windows') !== false) $os = 'Windows';
elseif (strpos($ua, 'Mac OS X') !== false) $os = 'macOS';
elseif (strpos($ua, 'CrOS') !== false) $os = 'ChromeOS';
elseif (strpos($ua, 'Linux') !== false) $os = 'Linux';
$br = 'Andere';
if (strpos($ua, 'AuditAppAndroid') !== false) $br = 'Android-App';
elseif (preg_match('/EdgA?\//', $ua)) $br = 'Edge';
elseif (preg_match('/OPR\//', $ua)) $br = 'Opera';
elseif (preg_match('/SamsungBrowser/', $ua)) $br = 'Samsung Internet';
elseif (preg_match('/Firefox|FxiOS/', $ua)) $br = 'Firefox';
elseif (preg_match('/CriOS|Chrome\//', $ua)) $br = 'Chrome';
elseif (strpos($ua, 'Safari') !== false) $br = 'Safari';
if (($in['brave'] ?? 0) == 1) $br = 'Brave';

$dir = dataDir('stats');
$day = date('Y-m-d');
$saltF = $dir . '/salt-' . $day;
if (!is_file($saltF)) {
    foreach (glob($dir . '/salt-*') ?: [] as $f) if ($f !== $saltF) @unlink($f);
    @file_put_contents($saltF, bin2hex(random_bytes(16)), LOCK_EX);
}
$salt = (string)@file_get_contents($saltF);
$ipNet = strpos($ip, ':') !== false ? implode(':', array_slice(explode(':', $ip), 0, 3)) : preg_replace('/\.\d+$/', '', $ip);

$row = [
    'k'  => $k,
    't'  => date('H:i'),
    'w'  => (int)date('N'),
    'c'  => geoCountry($ip),
    'd'  => $dev, 'os' => $os, 'br' => $br,
    'l'  => $s('l', '/^[a-z]{2,3}$/', 3),
    'v'  => $s('v', '/^\d{1,5}$/', 5),
    'm'  => $s('m', '/^(app|pwa|web)$/', 3),
    'u'  => substr(hash('sha256', $salt . '|' . $ipNet . '|' . $ua), 0, 12),
];
if ($k === 's') {
    $row['sc'] = $s('sc', '/^(phone|tablet|desktop)$/', 7);
    $row['f']  = ($in['f'] ?? 0) == 1 ? 1 : 0;
    $row['ms'] = min(120000, max(0, (int)($in['ms'] ?? 0)));
    $ref = $s('r', '/^[a-z0-9.-]{3,80}$/i', 80);
    if ($ref !== '' && !preg_match('/(^|\.)go-range\.de$|netlify\.app$/i', $ref)) $row['r'] = strtolower(preg_replace('/^www\./i', '', $ref));
    $cp = $s('cp', '/^[\w .\-]{1,40}$/u', 40); if ($cp !== '') $row['cp'] = $cp;
}
if ($k === 'e') $row['du'] = min(86400, max(0, (int)($in['du'] ?? 0)));
if ($k === 'x') { $row['n'] = $s('n', '/^[a-z0-9]{1,16}$/', 16); $row['nv'] = $s('nv', '/^[a-z0-9_-]{1,16}$/i', 16); if ($row['n'] === '') jsonOut(['ok' => true]); }
if ($k === 'q') { $row['msg'] = mb_substr(preg_replace('/\s+/', ' ', (string)($in['msg'] ?? '')), 0, 160); $row['src'] = $s('src', '/^[\w.\/:-]{0,60}$/', 60); }
if ($k === 'a') { $row['md'] = $s('md', '/^[\w.\-:]{1,48}$/', 48); $row['ak'] = $s('ak', '/^[a-z]{1,8}$/', 8); $row['usd'] = round(min(100, max(0, (float)($in['usd'] ?? 0))), 6); }

@file_put_contents($dir . '/' . $day . '.jsonl', json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

if (random_int(1, 50) === 1) {
    foreach (glob($dir . '/*.jsonl') ?: [] as $f) if (filemtime($f) < time() - 800 * 86400) @unlink($f);
}
jsonOut(['ok' => true]);
