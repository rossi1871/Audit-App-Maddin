<?php
/* Audit & Kalkulationstool – KI-Weiterleitung für die Beta-Version (go-range.de/audit.Beta)
 *
 * Die API-Schlüssel liegen NUR hier auf dem Server (_data/beta_secrets.php, wird beim
 * Veröffentlichen aus den GitHub-Secrets erzeugt). Die App im Browser sieht sie nie.
 * Zugang nur mit persönlichem Tester-Code; Tageslimit je Tester.
 *
 *   POST ki.php          JSON wie Anthropic /v1/messages  -> Auswertung (Claude)
 *   POST ki.php?t=stt    Formular mit Audiodatei         -> Spracherkennung (OpenAI)
 *   GET  ki.php?t=check  nur Tester-Code prüfen
 *
 * Ohne beta_secrets.php (z. B. in der normalen Version /audit) antwortet das Skript nur mit 503.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
cors();
header('X-Robots-Tag: noindex');
@set_time_limit(180);

$secFile = dataDir() . '/beta_secrets.php';
if (!is_file($secFile)) jsonOut(['error' => ['type' => 'no_beta', 'message' => 'Beta-Zugang ist hier nicht eingerichtet.']], 503);
$SEC = require $secFile;

/* ---------- Tester-Code prüfen ---------- */
$code = trim((string)($_SERVER['HTTP_X_BETA_CODE'] ?? ''));
$tester = null; $LIM = (int)($SEC['day'] ?? 150);
foreach (($SEC['codes'] ?? []) as $name => $c) {
    $cc = is_array($c) ? (string)$c['c'] : (string)$c;   // ['c' => Code, 'n' => Auswertungen pro Tag]
    if ($code !== '' && hash_equals($cc, $code)) { $tester = (string)$name; if (is_array($c) && !empty($c['n'])) $LIM = (int)$c['n']; break; }
}
if ($tester === null) jsonOut(['error' => ['type' => 'beta_code', 'message' => 'Tester-Code ungültig.']], 401);

$t = (string)($_GET['t'] ?? '');

/* ---------- Tageszähler je Tester ---------- */
/* eine Zahl für beides: Auswertungen UND Spracherkennungen pro Tag und Code (Standard 150, sonst Code*Anzahl) */
$LIM_AI = $LIM; $LIM_STT = $LIM;
$day = date('Y-m-d');
$uFile = dataDir('beta_usage') . '/' . $day . '.json';

function usage(string $file, string $who, ?array $add = null): array {
    $fh = @fopen($file, 'c+');
    if (!$fh) return [];
    flock($fh, LOCK_EX);
    $all = json_decode((string)stream_get_contents($fh), true) ?: [];
    $u = $all[$who] ?? ['ai' => 0, 'stt' => 0, 'in' => 0, 'out' => 0, 'mb' => 0];
    if ($add) {
        foreach ($add as $k => $v) $u[$k] = ($u[$k] ?? 0) + $v;
        $all[$who] = $u;
        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    flock($fh, LOCK_UN); fclose($fh);
    return $u;
}
$u = usage($uFile, $tester);
/* Tester-Code prüfen und Tageskontingent anzeigen (Zurücksetzen um Mitternacht, Zeitzone Europe/Zurich) */
if ($t === 'check') jsonOut(['ok' => true, 'name' => $tester, 'limit' => $LIM,
    'ai' => (int)($u['ai'] ?? 0), 'stt' => (int)($u['stt'] ?? 0), 'reset' => strtotime('tomorrow') * 1000]);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonOut(['error' => ['type' => 'method', 'message' => 'Nur POST.']], 405);

/* ---------- Weiterleiten ---------- */
function forward(string $url, array $headers, $body, bool $multipart = false): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 170, CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $out = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) return [502, json_encode(['error' => ['type' => 'upstream', 'message' => 'Anbieter nicht erreichbar: ' . $err]])];
        return [$code ?: 502, (string)$out];
    }
    if ($multipart) { // ohne curl: Formular von Hand zusammensetzen
        $b = '----audit' . bin2hex(random_bytes(8)); $raw = '';
        foreach ($body as $k => $v) {
            if ($v instanceof CURLFile) {
                $raw .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"; filename=\"" . $v->getPostFilename() . "\"\r\nContent-Type: " . $v->getMimeType() . "\r\n\r\n" . file_get_contents($v->getFilename()) . "\r\n";
            } else {
                $raw .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
            }
        }
        $raw .= "--$b--\r\n"; $body = $raw; $headers[] = 'Content-Type: multipart/form-data; boundary=' . $b;
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => 170, 'ignore_errors' => true]]);
    $out = @file_get_contents($url, false, $ctx);
    $code = 502;
    foreach (($http_response_header ?? []) as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
    if ($out === false) return [502, json_encode(['error' => ['type' => 'upstream', 'message' => 'Anbieter nicht erreichbar.']])];
    return [$code, (string)$out];
}
function relay(int $code, string $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo $body; exit;
}
$limitMsg = fn(string $was) => jsonOut(['error' => ['type' => 'beta_limit', 'message' => "Tageslimit für $was erreicht. Morgen geht es weiter."]], 429);

/* ===== Spracherkennung (OpenAI) ===== */
if ($t === 'stt') {
    if (empty($SEC['openai'])) jsonOut(['error' => ['type' => 'no_stt', 'message' => 'Spracherkennung ist in der Beta nicht freigeschaltet.']], 503);
    if ($u['stt'] >= $LIM_STT) $limitMsg('Spracherkennung');
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) jsonOut(['error' => ['type' => 'upload', 'message' => 'Aufnahme kam nicht an (zu gross?).']], 400);
    if ($f['size'] > 24 * 1024 * 1024) jsonOut(['error' => ['type' => 'upload', 'message' => 'Aufnahme zu gross.']], 413);
    $model = (string)($_POST['model'] ?? 'gpt-4o-mini-transcribe');
    if (!in_array($model, ['gpt-4o-mini-transcribe', 'gpt-4o-transcribe', 'whisper-1'], true)) $model = 'gpt-4o-mini-transcribe';
    $name = preg_replace('/[^a-z0-9.]/i', '', (string)$f['name']) ?: 'aufnahme.webm';
    $form = ['file' => new CURLFile($f['tmp_name'], (string)($f['type'] ?: 'audio/webm'), $name), 'model' => $model];
    foreach (['language', 'prompt'] as $k) if (isset($_POST[$k]) && $_POST[$k] !== '') $form[$k] = mb_substr((string)$_POST[$k], 0, 400);
    [$c, $out] = forward('https://api.openai.com/v1/audio/transcriptions', ['Authorization: Bearer ' . $SEC['openai']], $form, true);
    if ($c < 400) usage($uFile, $tester, ['stt' => 1, 'mb' => round($f['size'] / 1048576, 2)]);
    if ($c === 401 || $c === 403) relay(502, json_encode(['error' => ['type' => 'upstream_key', 'message' => 'Der Beta-Schlüssel für die Spracherkennung funktioniert nicht. Bitte Martin Bescheid geben.']]));
    relay($c, $out);
}

/* ===== Auswertung (Claude) ===== */
if (empty($SEC['anthropic'])) jsonOut(['error' => ['type' => 'no_ai', 'message' => 'Auswertung ist in der Beta nicht freigeschaltet.']], 503);
if ($u['ai'] >= $LIM_AI) $limitMsg('KI-Auswertungen');
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 30 * 1024 * 1024) jsonOut(['error' => ['type' => 'size', 'message' => 'Anfrage zu gross (zu viele Fotos?).']], 413);
$req = json_decode($raw, true);
if (!is_array($req) || empty($req['messages'])) jsonOut(['error' => ['type' => 'bad_request', 'message' => 'Ungültige Anfrage.']], 400);

/* nur erlaubte Modelle (Opus ist in der Beta zu teuer) und gedeckelte Antwortlänge */
$okModels = $SEC['models'] ?? ['claude-haiku-5-5', 'claude-sonnet-5-5'];
if (!in_array($req['model'] ?? '', $okModels, true)) $req['model'] = $okModels[0];
$req['max_tokens'] = min((int)($req['max_tokens'] ?? 2500), 4000);
$clean = ['model' => $req['model'], 'max_tokens' => $req['max_tokens'], 'messages' => $req['messages']];
if (isset($req['system'])) $clean['system'] = $req['system'];

[$c, $out] = forward('https://api.anthropic.com/v1/messages', [
    'content-type: application/json', 'x-api-key: ' . $SEC['anthropic'], 'anthropic-version: 2023-06-01',
], json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
if ($c < 400) {
    $j = json_decode($out, true); $us = $j['usage'] ?? [];
    usage($uFile, $tester, ['ai' => 1, 'in' => (int)($us['input_tokens'] ?? 0), 'out' => (int)($us['output_tokens'] ?? 0)]);
}
if ($c === 401 || $c === 403) relay(502, json_encode(['error' => ['type' => 'upstream_key', 'message' => 'Der Beta-Schlüssel für die KI funktioniert nicht. Bitte Martin Bescheid geben.']]));
relay($c, $out);
