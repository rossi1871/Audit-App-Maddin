<?php
/* Audit & Kalkulationstool – Dashboard: Update-Historie, Versionen, Zurückspringen, Zugriffe */
declare(strict_types=1);
require dirname(__DIR__) . '/api/lib.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:");

session_name('auditadm');
session_set_cookie_params(['lifetime' => 0, 'path' => dirname($_SERVER['SCRIPT_NAME']) . '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$ROOT = dirname(__DIR__);              // Ordner der App (…/audit)
$D = dataDir();
$hashF = $D . '/admin_hash';
$hash = is_file($hashF) ? trim((string)file_get_contents($hashF)) : '';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrfOk(): bool { return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']); }
function fdt(int $t): string { return date('d.m.Y H:i', $t); }

/* ---------- Anmeldung ---------- */
$msg = ''; $err = '';
$lockF = $D . '/login_fail.json';
$lock = readJson($lockF, ['n' => 0, 't' => 0]);
if (time() - (int)$lock['t'] > 900) $lock = ['n' => 0, 't' => time()];
$locked = (int)$lock['n'] >= 8;

if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: ./'); exit; }

if (empty($_SESSION['ok']) && ($_POST['pw'] ?? null) !== null && $hash !== '') {
    if ($locked) { $err = 'Zu viele Fehlversuche. Bitte 15 Minuten warten.'; }
    elseif (password_verify((string)$_POST['pw'], $hash)) {
        session_regenerate_id(true); $_SESSION['ok'] = 1; @unlink($lockF);
        header('Location: ./'); exit;
    } else {
        sleep(1); $lock['n'] = (int)$lock['n'] + 1; $lock['t'] = $lock['t'] ?: time(); writeJson($lockF, $lock);
        $err = 'Passwort falsch.';
    }
}

/* ---------- Daten ---------- */
function deploys(string $D): array {
    $L = [];
    foreach (glob($D . '/deploys/*.json') ?: [] as $f) { $j = readJson($f); if ($j) $L[] = $j; }
    usort($L, fn($a, $b) => ($b['t'] ?? 0) <=> ($a['t'] ?? 0));
    return $L;
}
function versions(string $ROOT): array {
    $V = [];
    foreach (glob($ROOT . '/v/*', GLOB_ONLYDIR) ?: [] as $d) { $n = basename($d); if (ctype_digit($n) && is_file($d . '/index.html')) $V[(int)$n] = $d; }
    krsort($V);
    return $V;
}

/* ---------- Zurückspringen ---------- */
if (!empty($_SESSION['ok']) && ($_POST['act'] ?? '') === 'rollback') {
    if (!csrfOk()) { $err = 'Sitzung abgelaufen, bitte neu laden.'; }
    else {
        $n = (int)($_POST['ver'] ?? 0); $V = versions($ROOT);
        if (!isset($V[$n])) { $err = 'Version ' . $n . ' ist nicht vorhanden.'; }
        else {
            $ok = true;
            foreach (scandir($V[$n]) ?: [] as $f) {
                if ($f === '.' || $f === '..' || is_dir($V[$n] . '/' . $f)) continue;
                if (!preg_match('/^[\w.-]+$/', $f) || $f === '.htaccess') continue;
                $ok = @copy($V[$n] . '/' . $f, $ROOT . '/' . $f) && $ok;
            }
            if ($ok) {
                $t = time();
                writeJson($D . '/current.json', ['ver' => $n, 't' => $t, 'kind' => 'rollback']);
                dataDir('deploys');
                writeJson($D . '/deploys/' . $t . '-' . $n . '-r.json', ['ver' => $n, 't' => $t, 'kind' => 'rollback', 'title' => 'Zurückgesprungen auf Version ' . $n, 'actor' => 'Dashboard']);
                $msg = 'Version ' . $n . ' ist jetzt aktiv. Geräte übernehmen sie beim nächsten Öffnen der App.';
            } else { $err = 'Nicht alle Dateien konnten kopiert werden. Bitte erneut versuchen.'; }
        }
    }
}

/* ---------- Statistik ---------- */
function stats(string $D, int $days = 30): array {
    $out = ['days' => [], 'dev' => [], 'lang' => [], 'ver' => [], 'mode' => [], 'tot' => 0, 'vis' => 0];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i day"));
        $hits = 0; $u = [];
        $f = $D . '/stats/' . $day . '.jsonl';
        if (is_file($f)) {
            $fh = fopen($f, 'r');
            while ($fh && ($line = fgets($fh)) !== false) {
                $r = json_decode($line, true); if (!$r || (($r['k'] ?? 's') !== 's')) continue;
                $hits++; $u[$r['u'] ?? ''] = 1;
                foreach (['dev' => 'd', 'lang' => 'l', 'ver' => 'v', 'mode' => 'm'] as $k => $s) { $x = ($r[$s] ?? '') ?: '–'; $out[$k][$x] = ($out[$k][$x] ?? 0) + 1; }
            }
            if ($fh) fclose($fh);
        }
        $out['days'][] = ['d' => $day, 'h' => $hits, 'u' => count($u)];
        $out['tot'] += $hits; $out['vis'] += count($u);
    }
    foreach (['dev', 'lang', 'ver', 'mode'] as $k) arsort($out[$k]);
    return $out;
}

$LANGN = ['de' => 'Deutsch', 'en' => 'Englisch', 'fr' => 'Französisch', 'it' => 'Italienisch', 'es' => 'Spanisch', 'ru' => 'Russisch'];
$MODEN = ['app' => 'Android-App', 'pwa' => 'Home-Bildschirm', 'web' => 'Browser'];
?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Audit · Dashboard</title><link rel="icon" href="../icon-192.png?v=2">
<style>
:root{--bg:#0b1426;--card:#121f38;--line:#22324f;--tx:#e8eefb;--mu:#93a3c3;--ac:#5898f4;--ok:#3ecf8e;--bad:#ff6b6b}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--tx);font:15px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
.wrap{max-width:1000px;margin:0 auto;padding:16px}
header{display:flex;align-items:center;gap:12px;margin:4px 0 18px}header img{width:40px;height:40px;border-radius:10px}
header h1{font-size:20px;margin:0;flex:1}a{color:var(--ac)}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;margin-bottom:16px}
.card h2{font-size:16px;margin:0 0 12px}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.kpi{background:#0f1a30;border:1px solid var(--line);border-radius:12px;padding:12px}.kpi b{display:block;font-size:24px}.kpi span{color:var(--mu);font-size:13px}
table{width:100%;border-collapse:collapse}td,th{padding:9px 6px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}th{color:var(--mu);font-weight:600;font-size:13px}
.tag{display:inline-block;font-size:12px;padding:2px 8px;border-radius:99px;background:#1c2d4d;color:var(--mu)}.tag.live{background:rgba(62,207,142,.15);color:var(--ok)}
.btn{display:inline-block;border:1px solid var(--line);background:#1a2a48;color:var(--tx);border-radius:9px;padding:7px 12px;font-size:14px;text-decoration:none;cursor:pointer;min-height:36px}
.btn.pri{background:var(--ac);border-color:var(--ac);color:#fff}.row{display:flex;gap:8px;flex-wrap:wrap}
.msg{padding:10px 12px;border-radius:10px;margin-bottom:14px}.msg.ok{background:rgba(62,207,142,.12);color:var(--ok)}.msg.err{background:rgba(255,107,107,.12);color:var(--bad)}
.bars{display:flex;align-items:flex-end;gap:3px;height:120px;border-bottom:1px solid var(--line);padding-top:6px}
.bars div{flex:1;background:var(--ac);border-radius:3px 3px 0 0;min-height:2px;position:relative}.bars div:hover{opacity:.8}
.ax{display:flex;justify-content:space-between;color:var(--mu);font-size:12px;margin-top:4px}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px}
.li{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--line);font-size:14px}.li span:last-child{color:var(--mu)}
.mu{color:var(--mu);font-size:13px}input[type=password]{width:100%;padding:12px;border-radius:10px;border:1px solid var(--line);background:#0f1a30;color:var(--tx);font-size:16px;margin:8px 0 12px}
@media (max-width:640px){.hide-s{display:none}td,th{padding:8px 4px}}
</style></head><body><div class="wrap">
<header><img src="../icon-192.png?v=2" alt=""><h1>Audit · Dashboard</h1><?php if (!empty($_SESSION['ok'])): ?><a class="btn" href="?logout=1">Abmelden</a><?php endif; ?></header>
<?php if ($hash === ''): ?>
  <div class="card"><h2>Noch nicht eingerichtet</h2>
  <p>Lege in GitHub im Repository unter <b>Settings › Secrets and variables › Actions</b> ein Secret <b>ADMIN_PASS</b> mit deinem Wunsch-Passwort an.
  Starte danach unter <b>Actions › «Auf Strato veröffentlichen» › Run workflow</b> den Upload neu. Danach kannst du dich hier anmelden.</p></div>
<?php elseif (empty($_SESSION['ok'])): ?>
  <div class="card" style="max-width:420px;margin:40px auto"><h2>Anmelden</h2>
  <?php if ($err): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>
  <form method="post"><label class="mu" for="pw">Passwort</label><input id="pw" type="password" name="pw" autocomplete="current-password" autofocus required>
  <button class="btn pri" type="submit" style="width:100%">Anmelden</button></form></div>
<?php else: ?>
  <nav style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap"><a class="btn pri" href="./">Übersicht &amp; Updates</a><a class="btn" href="analytics.php">Analytics</a></nav>
<?php
  $DEP = deploys($D); $V = versions($ROOT); $cur = readJson($D . '/current.json', []); $curV = (int)($cur['ver'] ?? 0);
  $S = stats($D, 30); $today = end($S['days']); $wk = array_slice($S['days'], -7);
  $wkH = array_sum(array_column($wk, 'h')); $mx = max(1, max(array_column($S['days'], 'h')));
  $moves = count(glob($D . '/move/*', GLOB_ONLYDIR) ?: []);
?>
  <?php if ($msg): ?><div class="msg ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

  <div class="card"><h2>Überblick</h2><div class="kpis">
    <div class="kpi"><b>V<?= $curV ?: '–' ?></b><span>aktiv seit <?= isset($cur['t']) ? fdt((int)$cur['t']) : '–' ?><?= ($cur['kind'] ?? '') === 'rollback' ? ' (zurückgesprungen)' : '' ?></span></div>
    <div class="kpi"><b><?= (int)$today['h'] ?></b><span>App-Starts heute · <?= (int)$today['u'] ?> Geräte</span></div>
    <div class="kpi"><b><?= $wkH ?></b><span>App-Starts 7 Tage</span></div>
    <div class="kpi"><b><?= $S['tot'] ?></b><span>App-Starts 30 Tage</span></div>
  </div><?php if ($moves): ?><p class="mu" style="margin:12px 0 0">Offene Daten-Umzüge: <?= $moves ?> (werden nach Abholung oder 48 Stunden automatisch gelöscht)</p><?php endif; ?></div>

  <div class="card"><h2>Zugriffe der letzten 30 Tage</h2>
    <div class="bars"><?php foreach ($S['days'] as $d): ?><div style="height:<?= round($d['h'] / $mx * 100) ?>%" title="<?= h(date('d.m.', strtotime($d['d']))) ?>: <?= $d['h'] ?> Starts, <?= $d['u'] ?> Geräte"></div><?php endforeach; ?></div>
    <div class="ax"><span><?= h(date('d.m.', strtotime($S['days'][0]['d']))) ?></span><span>heute</span></div>
    <div class="grid2" style="margin-top:16px">
      <?php foreach (['dev' => 'Geräte', 'mode' => 'Nutzung als', 'lang' => 'Sprachen', 'ver' => 'App-Versionen'] as $k => $t): ?>
      <div><div class="mu" style="margin-bottom:4px"><?= $t ?></div>
        <?php if (!$S[$k]): ?><div class="mu">noch keine Daten</div><?php endif; ?>
        <?php foreach (array_slice($S[$k], 0, 8, true) as $n => $c): $lbl = $k === 'lang' ? ($LANGN[$n] ?? $n) : ($k === 'mode' ? ($MODEN[$n] ?? $n) : ($k === 'ver' && $n !== '–' ? 'V' . $n : $n)); ?>
          <div class="li"><span><?= h($lbl) ?></span><span><?= $c ?></span></div><?php endforeach; ?>
      </div><?php endforeach; ?>
    </div>
    <p class="mu" style="margin:12px 0 0">Gezählt wird jeder App-Start. Ohne Cookies, ohne IP-Adressen; «Geräte» zählt verschiedene Geräte pro Tag.</p>
  </div>

  <div class="card"><h2>Update-Historie</h2>
    <table><tr><th>Version</th><th>Änderung</th><th></th></tr>
    <?php $seen = []; foreach (array_slice($DEP, 0, 60) as $d): $n = (int)($d['ver'] ?? 0); $live = $n === $curV && !isset($seen['live']); if ($live) $seen['live'] = 1; ?>
      <tr><td><b>V<?= $n ?></b><?= $live ? ' <span class="tag live">aktiv</span>' : '' ?></td>
      <td><?= h($d['title'] ?? '') ?><div class="mu"><?= fdt((int)($d['t'] ?? 0)) ?> · <?= ($d['kind'] ?? '') === 'rollback' ? 'Dashboard' : 'GitHub' ?><?= !empty($d['sha']) ? ' · ' . h(substr($d['sha'], 0, 7)) : '' ?></div></td>
      <td><div class="row"><?php if (isset($V[$n])): ?><a class="btn" href="../v/<?= $n ?>/" target="_blank" rel="noopener">Ansehen</a>
        <?php if (!$live && $n !== $curV): ?><form method="post" onsubmit="return confirm('Version <?= $n ?> wieder aktiv machen? Alle Geräte bekommen sie beim nächsten Öffnen.')"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="act" value="rollback"><input type="hidden" name="ver" value="<?= $n ?>"><button class="btn" type="submit">Zurückspringen</button></form><?php endif; ?>
      <?php endif; ?></div></td></tr>
    <?php endforeach; if (!$DEP): ?><tr><td colspan="3" class="mu">Noch keine Einträge. Sie entstehen mit dem nächsten Upload.</td></tr><?php endif; ?>
    </table>
    <p class="mu" style="margin:12px 0 0">«Ansehen» öffnet die Version in einem neuen Fenster. Sie arbeitet mit denselben gespeicherten Audits wie die aktive App – also nur anschauen, nicht damit weiterarbeiten. Der nächste Upload aus GitHub ersetzt eine zurückgesprungene Version wieder.</p>
  </div>
<?php endif; ?>
</div></body></html>
