<?php
/* Audit & Kalkulationstool – gemeinsame Hilfsfunktionen für die Server-Skripte auf Strato */
declare(strict_types=1);
date_default_timezone_set('Europe/Zurich');

const ALLOWED_ORIGINS = ['https://go-range.de', 'https://www.go-range.de', 'https://audit-maddin.netlify.app'];

/* Datenordner: wird bei Bedarf angelegt und gegen Zugriffe von aussen gesperrt */
function dataDir(string $sub = ''): string {
    $base = __DIR__ . '/_data';
    if (!is_dir($base)) { @mkdir($base, 0755, true); }
    $ht = $base . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
    }
    $d = $sub === '' ? $base : $base . '/' . $sub;
    if (!is_dir($d)) { @mkdir($d, 0755, true); }
    return $d;
}

function cors(): void {
    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($o, ALLOWED_ORIGINS, true) || preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $o)) {
        header('Access-Control-Allow-Origin: ' . $o);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

function jsonOut($x, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readJson(string $file, $def = null) {
    if (!is_file($file)) return $def;
    $j = json_decode((string)@file_get_contents($file), true);
    return $j === null ? $def : $j;
}

function writeJson(string $file, $x): bool {
    $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false) return false;
    return @rename($tmp, $file);
}

/* Ordner rekursiv löschen */
function rmTree(string $d): void {
    if (!is_dir($d)) return;
    foreach (scandir($d) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $d . '/' . $f;
        is_dir($p) ? rmTree($p) : @unlink($p);
    }
    @rmdir($d);
}

/* Geräteart grob aus dem Browser-Kennzeichen ableiten (keine Speicherung des Kennzeichens selbst) */
function deviceOf(string $ua): string {
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|monitor|curl|wget|python|headless/i', $ua)) return 'bot';
    if (strpos($ua, 'AuditAppAndroid') !== false) return 'Android-App';
    if (preg_match('/iPhone|iPod/', $ua)) return 'iPhone';
    if (preg_match('/iPad/', $ua) || (strpos($ua, 'Macintosh') !== false && strpos($ua, 'Mobile') !== false)) return 'iPad';
    if (strpos($ua, 'Android') !== false) return 'Android';
    if (strpos($ua, 'Windows') !== false) return 'Windows';
    if (strpos($ua, 'Macintosh') !== false) return 'Mac';
    if (strpos($ua, 'Linux') !== false) return 'Linux';
    return 'Andere';
}
