<?php
/* Umzugshilfe: nimmt die VERSCHLÜSSELTEN App-Daten der alten Adresse in Stücken entgegen
   und gibt sie an die neue Adresse wieder aus. Der Schlüssel bleibt im Browser (Teil hinter #),
   der Server sieht nur unlesbare Daten. Nach Abholung oder spätestens nach 48 Stunden gelöscht. */
require __DIR__ . '/lib.php';
cors();

const CHUNK_MAX = 4 * 1024 * 1024;   // 4 MB pro Stück
const PARTS_MAX = 400;               // bis 1.6 GB, praktisch nie erreicht
const TTL = 48 * 3600;

$dir = dataDir('move');
/* Aufräumen: alte Umzüge löschen */
foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
    if (filemtime($d) < time() - TTL) rmTree($d);
}

$a = $_GET['a'] ?? '';
if ($a === 'ping') jsonOut(['ok' => true, 'php' => PHP_VERSION, 'post_max' => ini_get('post_max_size')]);

$id = $_GET['id'] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $id)) jsonOut(['error' => 'id'], 400);
$md = $dir . '/' . $id;

if ($a === 'put') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonOut(['error' => 'method'], 405);
    $n = (int)($_GET['n'] ?? -1); $of = (int)($_GET['of'] ?? 0);
    if ($n < 0 || $of < 1 || $of > PARTS_MAX || $n >= $of) jsonOut(['error' => 'part'], 400);
    $body = file_get_contents('php://input', false, null, 0, CHUNK_MAX + 1);
    if ($body === false || strlen($body) === 0 || strlen($body) > CHUNK_MAX) jsonOut(['error' => 'size'], 413);
    if (!is_dir($md)) {
        if (count(glob($dir . '/*', GLOB_ONLYDIR) ?: []) > 50) jsonOut(['error' => 'busy'], 429);
        @mkdir($md, 0755, true);
    }
    $meta = readJson($md . '/meta.json', ['of' => $of, 't' => time()]);
    if ((int)$meta['of'] !== $of) jsonOut(['error' => 'of'], 409);
    if (@file_put_contents($md . '/' . $n . '.bin', $body, LOCK_EX) === false) jsonOut(['error' => 'write'], 500);
    writeJson($md . '/meta.json', $meta);
    jsonOut(['ok' => true, 'n' => $n]);
}

if ($a === 'info') {
    $meta = readJson($md . '/meta.json');
    if (!$meta) jsonOut(['error' => 'missing'], 404);
    $have = 0; for ($i = 0; $i < (int)$meta['of']; $i++) if (is_file($md . '/' . $i . '.bin')) $have++;
    jsonOut(['of' => (int)$meta['of'], 'have' => $have]);
}

if ($a === 'get') {
    $n = (int)($_GET['n'] ?? -1);
    $f = $md . '/' . $n . '.bin';
    if ($n < 0 || !is_file($f)) jsonOut(['error' => 'missing'], 404);
    header('Content-Type: application/octet-stream');
    header('Cache-Control: no-store');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}

if ($a === 'del') {
    rmTree($md);
    jsonOut(['ok' => true]);
}

jsonOut(['error' => 'action'], 400);
