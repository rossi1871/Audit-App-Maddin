<?php
/* Audit & Kalkulationstool – Analytics (nur mit Anmeldung im Dashboard) */
declare(strict_types=1);
require dirname(__DIR__) . '/api/lib.php';
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; connect-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:");
session_name('auditadm');
session_set_cookie_params(['lifetime' => 0, 'path' => dirname($_SERVER['SCRIPT_NAME']) . '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }

$D = dataDir();
$days = (int)($_GET['d'] ?? 30); if (!in_array($days, [1, 7, 30, 90, 365], true)) $days = 30;

function rows(string $D, int $from, int $to): Generator {
    for ($i = $from; $i >= $to; $i--) {
        $day = date('Y-m-d', strtotime("-$i day"));
        $f = $D . '/stats/' . $day . '.jsonl';
        if (!is_file($f)) continue;
        $fh = fopen($f, 'r');
        while ($fh && ($line = fgets($fh)) !== false) { $r = json_decode($line, true); if ($r) { $r['day'] = $day; $r['k'] = $r['k'] ?? 's'; yield $r; } }
        if ($fh) fclose($fh);
    }
}

/* CSV-Export der Rohdaten */
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-analytics-' . date('Y-m-d') . '-' . $days . 'd.csv"');
    $cols = ['day', 't', 'k', 'c', 'd', 'os', 'br', 'm', 'l', 'v', 'sc', 'f', 'ms', 'du', 'r', 'cp', 'n', 'nv', 'md', 'ak', 'usd', 'msg', 'src'];
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, $cols, ';');
    foreach (rows($D, $days - 1, 0) as $r) { $line = []; foreach ($cols as $c) $line[] = $r[$c] ?? ''; fputcsv($o, $line, ';'); }
    exit;
}

function agg(string $D, int $from, int $to): array {
    $A = ['days' => [], 'dim' => [], 'heat' => array_fill(0, 7, array_fill(0, 24, 0)), 'ev' => [], 'lang' => [], 'ai' => [], 'aik' => [], 'err' => [],
          'starts' => 0, 'vis' => 0, 'new' => 0, 'du' => [], 'ms' => [], 'errs' => 0, 'aiN' => 0, 'aiUsd' => 0.0, 'evN' => 0];
    for ($i = $from; $i >= $to; $i--) $A['days'][date('Y-m-d', strtotime("-$i day"))] = ['s' => 0, 'u' => [], 'n' => 0, 'q' => 0, 'a' => 0, 'usd' => 0.0];
    foreach (rows($D, $from, $to) as $r) {
        $d = &$A['days'][$r['day']];
        $k = $r['k'];
        if ($k === 's') {
            $A['starts']++; $d['s']++; $d['u'][$r['u'] ?? ''] = 1;
            if (!empty($r['f'])) { $A['new']++; $d['n']++; }
            if (!empty($r['ms'])) $A['ms'][] = (int)$r['ms'];
            foreach (['c', 'd', 'os', 'br', 'm', 'l', 'v', 'sc', 'r', 'cp'] as $f) { $x = (string)($r[$f] ?? ''); if ($x === '' && in_array($f, ['r', 'cp'], true)) continue; $x = $x === '' ? '?' : $x; $A['dim'][$f][$x] = ($A['dim'][$f][$x] ?? 0) + 1; }
            $h = (int)substr((string)($r['t'] ?? '0'), 0, 2); $w = max(1, min(7, (int)($r['w'] ?? date('N', strtotime($r['day']))))) - 1;
            $A['heat'][$w][$h]++;
        } elseif ($k === 'e') { if (($r['du'] ?? 0) > 0) $A['du'][] = (int)$r['du']; }
        elseif ($k === 'x') { $n = (string)($r['n'] ?? ''); $A['ev'][$n] = ($A['ev'][$n] ?? 0) + 1; $A['evN']++; if ($n === 'lang' && !empty($r['nv'])) $A['lang'][$r['nv']] = ($A['lang'][$r['nv']] ?? 0) + 1; }
        elseif ($k === 'q') { $A['errs']++; $d['q']++; $key = ($r['msg'] ?? '') . ' · ' . ($r['src'] ?? '') . ' · V' . ($r['v'] ?? ''); $A['err'][$key] = ($A['err'][$key] ?? 0) + 1; }
        elseif ($k === 'a') { $A['aiN']++; $u = (float)($r['usd'] ?? 0); $A['aiUsd'] += $u; $d['a']++; $d['usd'] += $u; $m = (string)($r['md'] ?? '?'); $A['ai'][$m]['n'] = ($A['ai'][$m]['n'] ?? 0) + 1; $A['ai'][$m]['usd'] = ($A['ai'][$m]['usd'] ?? 0) + $u; $ak = (string)($r['ak'] ?? 'ai'); $A['aik'][$ak] = ($A['aik'][$ak] ?? 0) + 1; }
        unset($d);
    }
    foreach ($A['days'] as $day => $d) { $A['vis'] += count($d['u']); $A['days'][$day]['u'] = count($d['u']); }
    foreach ($A['dim'] as &$x) arsort($x); unset($x);
    arsort($A['ev']); arsort($A['err']); arsort($A['lang']);
    uasort($A['ai'], fn($a, $b) => $b['usd'] <=> $a['usd']);
    $med = function (array $a) { if (!$a) return 0; sort($a); return $a[intdiv(count($a), 2)]; };
    $A['duMed'] = $med($A['du']); $A['msMed'] = $med($A['ms']); $A['sessions'] = count($A['du']);
    unset($A['du'], $A['ms']);
    $A['err'] = array_slice($A['err'], 0, 25, true);
    return $A;
}
$cur = agg($D, $days - 1, 0);
$prev = agg($D, 2 * $days - 1, $days);
$prevK = ['starts' => $prev['starts'], 'vis' => $prev['vis'], 'new' => $prev['new'], 'duMed' => $prev['duMed'], 'msMed' => $prev['msMed'], 'errs' => $prev['errs'], 'aiN' => $prev['aiN'], 'aiUsd' => $prev['aiUsd']];
$geoOk = is_file($D . '/geo4.bin');
?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Audit · Analytics</title><link rel="icon" type="image/png" href="../icon-192.png?v=2">
<style>
:root{--bg:#0b1426;--card:#121f38;--line:#22324f;--tx:#e8eefb;--mu:#93a3c3;--ac:#5898f4;--ac2:#3ecf8e;--bad:#ff6b6b;--land:#24324d}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--tx);font:15px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
.wrap{max-width:1180px;margin:0 auto;padding:16px}a{color:var(--ac)}
header{display:flex;align-items:center;gap:12px;margin:4px 0 12px;flex-wrap:wrap}header img{width:40px;height:40px;border-radius:10px}header h1{font-size:20px;margin:0;flex:1}
.nav{display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap}.nav a,.seg a{padding:8px 14px;border-radius:9px;border:1px solid var(--line);text-decoration:none;color:var(--tx);background:#14223d;font-size:14px}
.nav a.on,.seg a.on{background:var(--ac);border-color:var(--ac);color:#fff}
.seg{display:inline-flex;gap:4px;background:#0f1a30;border:1px solid var(--line);border-radius:11px;padding:4px;flex-wrap:wrap}.seg a{border:0;background:transparent;padding:6px 12px}
.btn{display:inline-block;border:1px solid var(--line);background:#1a2a48;color:var(--tx);border-radius:9px;padding:7px 12px;font-size:14px;text-decoration:none}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;margin-bottom:16px;min-width:0}
.card h2{font-size:16px;margin:0 0 4px}.sub{color:var(--mu);font-size:13px;margin:0 0 12px}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px}
.kpi{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 14px}.kpi b{display:block;font-size:24px}.kpi span{color:var(--mu);font-size:13px}
.dl{font-size:12px;margin-left:6px}.up{color:var(--ac2)}.dn{color:var(--bad)}
.g2{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px}.g3{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}
.map{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr);gap:16px;align-items:start}@media (max-width:820px){.map{grid-template-columns:1fr}}
svg{display:block;width:100%;height:auto}
.tb{width:100%;border-collapse:collapse;font-size:14px}.tb td,.tb th{padding:8px 6px;border-bottom:1px solid var(--line);text-align:left}.tb th{color:var(--mu);font-weight:600;font-size:13px}.tb td.n,.tb th.n{text-align:right;white-space:nowrap}
.tb tr:nth-child(even) td{background:rgba(255,255,255,.02)}
.bar{height:6px;background:#1c2d4d;border-radius:3px;margin-top:4px;overflow:hidden}.bar i{display:block;height:100%;background:var(--ac);border-radius:3px}
.heat{display:grid;grid-template-columns:28px repeat(24,1fr);gap:2px;font-size:10px;color:var(--mu)}.heat div{aspect-ratio:1;border-radius:2px}.heat .lb{aspect-ratio:auto;display:flex;align-items:center}
.lg{display:flex;gap:14px;color:var(--mu);font-size:12px;margin-top:6px}.lg i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:-1px}
.tip{position:fixed;pointer-events:none;background:#0f1a30;border:1px solid var(--line);padding:6px 9px;border-radius:8px;font-size:13px;display:none;z-index:9}
.mu{color:var(--mu);font-size:13px}.empty{color:var(--mu);font-size:14px;padding:8px 0}
footer{color:var(--mu);font-size:12px;margin:8px 0 24px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
</style></head><body><div class="wrap">
<header><img src="../icon-192.png?v=2" alt=""><h1>Audit · Analytics</h1><a class="btn" href="./?logout=1">Abmelden</a></header>
<nav class="nav"><a href="./">Übersicht &amp; Updates</a><a class="on" href="analytics.php">Analytics</a></nav>
<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px">
 <div class="seg"><?php foreach ([1 => 'Heute', 7 => '7 Tage', 30 => '30 Tage', 90 => '90 Tage', 365 => '12 Monate'] as $n => $t): ?><a class="<?= $n === $days ? 'on' : '' ?>" href="?d=<?= $n ?>"><?= $t ?></a><?php endforeach; ?></div>
 <a class="btn" href="?d=<?= $days ?>&amp;csv=1">Rohdaten als CSV</a>
</div>
<div id="app"></div>
<footer><span>Ohne Cookies und ohne IP-Speicherung. Vergleich jeweils zum gleich langen Zeitraum davor.</span><span><?= $geoOk ? 'IP-Geolocation by <a href="https://db-ip.com" rel="noopener">DB-IP</a>' : 'Länder-Datenbank noch nicht geladen' ?></span></footer>
</div><div class="tip" id="tip"></div>
<script>
const A=<?= json_encode($cur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,P=<?= json_encode($prevK) ?>,DAYS=<?= $days ?>;
const N2A={4:"AF",8:"AL",10:"AQ",12:"DZ",16:"AS",20:"AD",24:"AO",28:"AG",31:"AZ",32:"AR",36:"AU",40:"AT",44:"BS",48:"BH",50:"BD",51:"AM",52:"BB",56:"BE",60:"BM",64:"BT",68:"BO",70:"BA",72:"BW",74:"BV",76:"BR",84:"BZ",86:"IO",90:"SB",92:"VG",96:"BN",100:"BG",104:"MM",108:"BI",112:"BY",116:"KH",120:"CM",124:"CA",132:"CV",136:"KY",140:"CF",144:"LK",148:"TD",152:"CL",156:"CN",158:"TW",162:"CX",166:"CC",170:"CO",174:"KM",175:"YT",178:"CG",180:"CD",184:"CK",188:"CR",191:"HR",192:"CU",196:"CY",203:"CZ",204:"BJ",208:"DK",212:"DM",214:"DO",218:"EC",222:"SV",226:"GQ",231:"ET",232:"ER",233:"EE",234:"FO",238:"FK",239:"GS",242:"FJ",246:"FI",248:"AX",250:"FR",254:"GF",258:"PF",260:"TF",262:"DJ",266:"GA",268:"GE",270:"GM",275:"PS",276:"DE",288:"GH",292:"GI",296:"KI",300:"GR",304:"GL",308:"GD",312:"GP",316:"GU",320:"GT",324:"GN",328:"GY",332:"HT",334:"HM",336:"VA",340:"HN",344:"HK",348:"HU",352:"IS",356:"IN",360:"ID",364:"IR",368:"IQ",372:"IE",376:"IL",380:"IT",384:"CI",388:"JM",392:"JP",398:"KZ",400:"JO",404:"KE",408:"KP",410:"KR",414:"KW",417:"KG",418:"LA",422:"LB",426:"LS",428:"LV",430:"LR",434:"LY",438:"LI",440:"LT",442:"LU",446:"MO",450:"MG",454:"MW",458:"MY",462:"MV",466:"ML",470:"MT",474:"MQ",478:"MR",480:"MU",484:"MX",492:"MC",496:"MN",498:"MD",499:"ME",500:"MS",504:"MA",508:"MZ",512:"OM",516:"NA",520:"NR",524:"NP",528:"NL",531:"CW",533:"AW",534:"SX",535:"BQ",540:"NC",548:"VU",554:"NZ",558:"NI",562:"NE",566:"NG",570:"NU",574:"NF",578:"NO",580:"MP",581:"UM",583:"FM",584:"MH",585:"PW",586:"PK",591:"PA",598:"PG",600:"PY",604:"PE",608:"PH",612:"PN",616:"PL",620:"PT",624:"GW",626:"TL",630:"PR",634:"QA",638:"RE",642:"RO",643:"RU",646:"RW",652:"BL",654:"SH",659:"KN",660:"AI",662:"LC",663:"MF",666:"PM",670:"VC",674:"SM",678:"ST",682:"SA",686:"SN",688:"RS",690:"SC",694:"SL",702:"SG",703:"SK",704:"VN",705:"SI",706:"SO",710:"ZA",716:"ZW",724:"ES",728:"SS",729:"SD",732:"EH",740:"SR",744:"SJ",748:"SZ",752:"SE",756:"CH",760:"SY",762:"TJ",764:"TH",768:"TG",772:"TK",776:"TO",780:"TT",784:"AE",788:"TN",792:"TR",795:"TM",796:"TC",798:"TV",800:"UG",804:"UA",807:"MK",818:"EG",826:"GB",831:"GG",832:"JE",833:"IM",834:"TZ",840:"US",850:"VI",854:"BF",858:"UY",860:"UZ",862:"VE",876:"WF",882:"WS",887:"YE",894:"ZM"};
const RN=(()=>{try{return new Intl.DisplayNames(['de'],{type:'region'})}catch(e){return null}})(),LN=(()=>{try{return new Intl.DisplayNames(['de'],{type:'language'})}catch(e){return null}})();
const cn=c=>c==='?'?'Unbekannt':(RN&&RN.of(c))||c,lnm=c=>c==='?'?'Unbekannt':(LN&&LN.of(c))||c;
const esc=s=>String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fmt=n=>Number(n).toLocaleString('de-CH');const usd=n=>'$ '+Number(n).toLocaleString('de-CH',{minimumFractionDigits:2,maximumFractionDigits:n<1?4:2});
const dur=s=>s<60?s+' s':Math.floor(s/60)+' min '+(s%60)+' s';
const EVL={start:'Audit gestartet',walk:'Begehung gestartet',voice:'Spracheingabe',photo:'Foto aufgenommen',text:'Texteingabe',calc:'Kalkulation geöffnet',finish:'Audit abgeschlossen',word:'Word-Export',excel:'Excel-Export',report:'Bericht versendet',mail:'Bericht per Mail',share:'Bericht geteilt',copy:'Bericht kopiert',backup:'Sicherung exportiert',lang:'Sprache gewechselt',help:'Hilfe geöffnet',gps:'Adresse per GPS',typo:'Tippfehler korrigiert',ai:'KI-Auswertung',delete:'Audit gelöscht',reopen:'Audit wieder geöffnet',settings:'Einstellungen geöffnet',map:'Karte geöffnet',undo:'Rückgängig',setup:'Einrichtung abgeschlossen',net:'Netzwerk eingerichtet'};
const DIM={d:'Geräte',os:'Betriebssysteme',br:'Browser',m:'Nutzung als',sc:'Bildschirm',l:'App-Sprache',v:'App-Versionen',r:'Herkunft (verweisende Websites)',cp:'Kampagnen'};
const LBL={m:{app:'Android-App',pwa:'Home-Bildschirm',web:'Browser','?':'Unbekannt'},sc:{phone:'Handy',tablet:'Tablet',desktop:'Computer','?':'Unbekannt'}};
const flag=c=>/^[A-Z]{2}$/.test(c)?String.fromCodePoint(...[...c].map(x=>127397+x.charCodeAt(0)))+' ':'';
const lab=(k,x)=>k==='c'?flag(x)+cn(x):k==='l'?lnm(x):k==='v'?(x==='?'?'Unbekannt':'V'+x):(LBL[k]&&LBL[k][x])||x;
function delta(c,p,inv){if(!p&&!c)return'';if(!p)return'<span class="dl up">neu</span>';const d=Math.round((c-p)/p*100);if(!d)return'<span class="dl mu">±0 %</span>';return'<span class="dl '+((d>0)!==!!inv?'up':'dn')+'">'+(d>0?'+':'')+d+' %</span>'}
function table(obj,k,head,max=10){const e=Object.entries(obj||{});if(!e.length)return'<div class="empty">Noch keine Daten in diesem Zeitraum.</div>';const tot=e.reduce((s,[,v])=>s+v,0),top=e[0][1];
 return'<table class="tb"><tr><th>'+head+'</th><th class="n">Anzahl</th><th class="n">Anteil</th></tr>'+e.slice(0,max).map(([x,v])=>'<tr><td>'+esc(lab(k,x))+'<div class="bar"><i style="width:'+(v/top*100).toFixed(1)+'%"></i></div></td><td class="n">'+fmt(v)+'</td><td class="n">'+Math.round(v/tot*100)+' %</td></tr>').join('')+'</table>'+(e.length>max?'<div class="mu" style="margin-top:6px">+ '+(e.length-max)+' weitere (siehe CSV)</div>':'')}
function line(){const D=Object.entries(A.days),W=900,H=220,pl=36,pb=24,pt=10;const mx=Math.max(1,...D.map(([,d])=>Math.max(d.s,d.u)));const st=Math.pow(10,Math.floor(Math.log10(mx)));const top=Math.ceil(mx/st)*st;
 const x=i=>pl+(D.length<2?(W-pl)/2:i*(W-pl-8)/(D.length-1)),y=v=>pt+(H-pt-pb)*(1-v/top);
 let g='';for(let i=0;i<=4;i++){const v=top*i/4;g+='<line x1="'+pl+'" x2="'+W+'" y1="'+y(v)+'" y2="'+y(v)+'" stroke="#22324f"/><text x="'+(pl-6)+'" y="'+(y(v)+4)+'" fill="#93a3c3" font-size="11" text-anchor="end">'+fmt(Math.round(v))+'</text>'}
 const path=k=>D.map(([,d],i)=>(i?'L':'M')+x(i).toFixed(1)+' '+y(d[k]).toFixed(1)).join(' ');
 const area='M'+x(0)+' '+y(0)+' '+D.map(([,d],i)=>'L'+x(i).toFixed(1)+' '+y(d.s).toFixed(1)).join(' ')+' L'+x(D.length-1)+' '+y(0)+'Z';
 const lbls=[0,Math.floor((D.length-1)/2),D.length-1].filter((v,i,a)=>a.indexOf(v)===i).map(i=>'<text x="'+x(i)+'" y="'+(H-6)+'" fill="#93a3c3" font-size="11" text-anchor="'+(i===0?'start':i===D.length-1?'end':'middle')+'">'+new Date(D[i][0]).toLocaleDateString('de-CH',{day:'2-digit',month:'2-digit'})+'</text>').join('');
 const hits=D.map(([day,d],i)=>'<rect x="'+(x(i)-(W-pl)/D.length/2)+'" y="0" width="'+((W-pl)/D.length)+'" height="'+(H-pb)+'" fill="transparent" data-t="'+esc(new Date(day).toLocaleDateString('de-CH',{weekday:'short',day:'2-digit',month:'2-digit'})+': '+d.s+' Starts · '+d.u+' Geräte · '+d.n+' neu'+(d.q?' · '+d.q+' Fehler':''))+'"/>').join('');
 return'<svg viewBox="0 0 '+W+' '+H+'">'+g+'<path d="'+area+'" fill="rgba(88,152,244,.18)"/><path d="'+path('s')+'" fill="none" stroke="#5898f4" stroke-width="2.5"/><path d="'+path('u')+'" fill="none" stroke="#3ecf8e" stroke-width="2" stroke-dasharray="5 4"/>'+(D.length<=31?D.map(([,d],i)=>'<circle cx="'+x(i)+'" cy="'+y(d.s)+'" r="2.5" fill="#5898f4"/>').join(''):'')+lbls+hits+'</svg><div class="lg"><span><i style="background:#5898f4"></i>App-Starts</span><span><i style="background:#3ecf8e"></i>Verschiedene Geräte</span></div>'}
function heat(){const mx=Math.max(1,...A.heat.flat());const wd=['Mo','Di','Mi','Do','Fr','Sa','So'];let h='<div class="heat"><div class="lb"></div>'+Array.from({length:24},(_,i)=>'<div class="lb" style="justify-content:center">'+(i%3?'':i)+'</div>').join('');
 A.heat.forEach((r,w)=>{h+='<div class="lb">'+wd[w]+'</div>'+r.map((v,hr)=>'<div style="background:'+(v?'rgba(88,152,244,'+(0.15+0.85*v/mx).toFixed(2)+')':'#16233f')+'" data-t="'+wd[w]+' '+hr+'–'+(hr+1)+' Uhr: '+v+' Starts"></div>').join('')});return h+'</div>'}
const c=A.dim.c||{};
const aiRows=Object.entries(A.ai);
document.getElementById('app').innerHTML=
 '<div class="kpis">'+
 '<div class="kpi"><span>App-Starts</span><b>'+fmt(A.starts)+delta(A.starts,P.starts)+'</b></div>'+
 '<div class="kpi"><span>Geräte (pro Tag gezählt)</span><b>'+fmt(A.vis)+delta(A.vis,P.vis)+'</b></div>'+
 '<div class="kpi"><span>Erststarts (neue Nutzer)</span><b>'+fmt(A.new)+delta(A.new,P.new)+'</b></div>'+
 '<div class="kpi"><span>Sitzungsdauer (Median)</span><b>'+dur(A.duMed)+'</b></div>'+
 '<div class="kpi"><span>Ladezeit (Median)</span><b>'+(A.msMed?(A.msMed/1000).toFixed(1)+' s':'–')+'</b></div>'+
 '<div class="kpi"><span>KI-Aufrufe · Kosten</span><b>'+fmt(A.aiN)+' · '+usd(A.aiUsd)+'</b></div>'+
 '<div class="kpi"><span>Fehler in der App</span><b>'+fmt(A.errs)+delta(A.errs,P.errs,1)+'</b></div>'+
 '</div>'+
 '<div class="card"><h2>Verlauf</h2><p class="sub">Pro Tag · Fahre über das Diagramm für Details</p>'+line()+'</div>'+
 '<div class="card"><h2>Länder</h2><p class="sub">Herkunft der App-Starts</p><div class="map"><div id="map"><div class="empty">Karte wird geladen …</div></div><div>'+table(c,'c','Land')+'</div></div></div>'+
 '<div class="g3">'+['d','os','br'].map(k=>'<div class="card"><h2>'+DIM[k]+'</h2>'+table(A.dim[k],k,DIM[k].replace(/e$/,''))+'</div>').join('')+'</div>'+
 '<div class="g3">'+['m','sc','l'].map(k=>'<div class="card"><h2>'+DIM[k]+'</h2>'+table(A.dim[k],k,'Wert')+'</div>').join('')+'</div>'+
 '<div class="g2"><div class="card"><h2>Tageszeit &amp; Wochentag</h2><p class="sub">Wann die App gestartet wird</p>'+heat()+'</div>'+
 '<div class="card"><h2>App-Versionen im Einsatz</h2>'+table(A.dim.v,'v','Version')+'</div></div>'+
 '<div class="g2"><div class="card"><h2>Benutzte Funktionen</h2><p class="sub">Wie oft welche Funktion verwendet wurde (ohne Inhalte)</p>'+(Object.keys(A.ev).length?'<table class="tb"><tr><th>Funktion</th><th class="n">Anzahl</th></tr>'+Object.entries(A.ev).map(([k,v])=>'<tr><td>'+esc(EVL[k]||k)+'</td><td class="n">'+fmt(v)+'</td></tr>').join('')+'</table>':'<div class="empty">Noch keine Daten in diesem Zeitraum.</div>')+
 (Object.keys(A.lang).length?'<p class="mu" style="margin:10px 0 0">Gewechselt zu: '+Object.entries(A.lang).map(([k,v])=>esc(lnm(k))+' '+v+'×').join(', ')+'</p>':'')+'</div>'+
 '<div class="card"><h2>KI-Nutzung &amp; Kosten</h2><p class="sub">Aus der App gemeldete Aufrufe; heute zahlen die Nutzer mit eigenem Schlüssel</p>'+(aiRows.length?'<table class="tb"><tr><th>Modell</th><th class="n">Aufrufe</th><th class="n">Kosten</th></tr>'+aiRows.map(([m,o])=>'<tr><td>'+esc(m)+'</td><td class="n">'+fmt(o.n)+'</td><td class="n">'+usd(o.usd)+'</td></tr>').join('')+'</table><p class="mu" style="margin:10px 0 0">Ø pro App-Start: '+usd(A.starts?A.aiUsd/A.starts:0)+' · Ø pro Aufruf: '+usd(A.aiN?A.aiUsd/A.aiN:0)+'</p>':'<div class="empty">Noch keine KI-Aufrufe in diesem Zeitraum.</div>')+'</div></div>'+
 '<div class="g2"><div class="card"><h2>Herkunft</h2><p class="sub">Von welchen Websites Besucher kamen</p>'+table(A.dim.r,'r','Website')+'</div>'+
 '<div class="card"><h2>Kampagnen</h2><p class="sub">Links mit ?utm_campaign=… oder ?ref=…</p>'+table(A.dim.cp,'cp','Kampagne')+'</div></div>'+
 '<div class="card"><h2>Fehler in der App</h2><p class="sub">Technische Fehlermeldungen, die bei Nutzern aufgetreten sind</p>'+(Object.keys(A.err).length?'<table class="tb"><tr><th>Meldung · Stelle · Version</th><th class="n">Anzahl</th></tr>'+Object.entries(A.err).map(([k,v])=>'<tr><td style="word-break:break-word">'+esc(k)+'</td><td class="n">'+v+'</td></tr>').join('')+'</table>':'<div class="empty">Keine Fehler – sehr gut.</div>')+'</div>';
/* Tooltip */
const tip=document.getElementById('tip');document.addEventListener('mousemove',e=>{const t=e.target.closest&&e.target.closest('[data-t]');if(!t){tip.style.display='none';return}tip.textContent=t.dataset.t;tip.style.display='block';tip.style.left=Math.min(innerWidth-tip.offsetWidth-8,e.clientX+12)+'px';tip.style.top=(e.clientY+14)+'px'});
/* Weltkarte */
(function(){const el=document.getElementById('map');const L=document.createElement('script');L.src='https://cdnjs.cloudflare.com/ajax/libs/d3/7.9.0/d3.min.js';
 L.onload=()=>{const T=document.createElement('script');T.src='https://cdnjs.cloudflare.com/ajax/libs/topojson/3.0.2/topojson.min.js';T.onload=draw;T.onerror=fail;document.head.appendChild(T)};L.onerror=fail;document.head.appendChild(L);
 function fail(){el.innerHTML='<div class="empty">Karte konnte nicht geladen werden.</div>'}
 async function draw(){try{const w=await (await fetch('https://cdn.jsdelivr.net/npm/world-atlas@2.0.2/countries-110m.json')).json();
  const f=topojson.feature(w,w.objects.countries).features.filter(x=>x.id!=='010');const mx=Math.max(1,...Object.values(c));
  const W=900,H=460,pr=d3.geoNaturalEarth1().fitSize([W,H],{type:'FeatureCollection',features:f}),pa=d3.geoPath(pr);
  const col=v=>v?d3.interpolateRgb('#2f4f86','#7fb3ff')(Math.sqrt(v/mx)):'#24324d';
  el.innerHTML='<svg viewBox="0 0 '+W+' '+H+'">'+f.map(x=>{const a=N2A[parseInt(x.id,10)]||'',v=c[a]||0;return'<path d="'+pa(x)+'" fill="'+col(v)+'" stroke="#0b1426" stroke-width=".5" data-t="'+esc((a?cn(a):(x.properties&&x.properties.name)||'')+': '+fmt(v)+' Starts')+'"/>'}).join('')+'</svg>';
 }catch(e){fail()}}
})();
</script></body></html>
