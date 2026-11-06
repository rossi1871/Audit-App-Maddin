<?php
/* Zugriffsstatistik ohne Cookies: pro App-Start ein Eintrag mit Tag, Gerät, Sprache, Version, Modus.
   Keine IP-Adressen, kein Browser-Kennzeichen gespeichert. Wiederkehrende Besucher werden nur innerhalb
   desselben Tages über einen täglich wechselnden, danach gelöschten Zufallswert gezählt. */
require __DIR__ . '/lib.php';
cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonOut(['error' => 'method'], 405);

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$dev = deviceOf($ua);
if ($dev === 'bot') jsonOut(['ok' => true]);

$in = json_decode((string)file_get_contents('php://input', false, null, 0, 2000), true) ?: [];
$clean = fn($s, $re, $max) => (is_string($s) || is_int($s)) && preg_match($re, (string)$s) ? substr((string)$s, 0, $max) : '';
$ver  = $clean($in['v'] ?? '', '/^\d{1,5}$/', 5);
$lang = $clean($in['l'] ?? '', '/^[a-z]{2,3}$/', 3);
$mode = $clean($in['m'] ?? '', '/^(app|pwa|web)$/', 3);

$dir = dataDir('stats');
$day = date('Y-m-d');
/* täglicher Zufallswert, alte werden gelöscht */
$saltF = $dir . '/salt-' . $day;
if (!is_file($saltF)) {
    foreach (glob($dir . '/salt-*') ?: [] as $f) if ($f !== $saltF) @unlink($f);
    @file_put_contents($saltF, bin2hex(random_bytes(16)), LOCK_EX);
}
$salt = (string)@file_get_contents($saltF);
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$ipNet = strpos($ip, ':') !== false ? implode(':', array_slice(explode(':', $ip), 0, 3)) : preg_replace('/\.\d+$/', '', $ip);
$vis = substr(hash('sha256', $salt . '|' . $ipNet . '|' . $ua), 0, 12);

$row = ['t' => date('H:i'), 'd' => $dev, 'l' => $lang, 'v' => $ver, 'm' => $mode, 'u' => $vis];
@file_put_contents($dir . '/' . $day . '.jsonl', json_encode($row) . "\n", FILE_APPEND | LOCK_EX);

/* Dateien älter als 400 Tage entfernen (selten prüfen) */
if (random_int(1, 50) === 1) {
    foreach (glob($dir . '/*.jsonl') ?: [] as $f) if (filemtime($f) < time() - 400 * 86400) @unlink($f);
}
jsonOut(['ok' => true]);
