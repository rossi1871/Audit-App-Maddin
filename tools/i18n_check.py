# Prüft, ob alle Bedienungstexte in einer Sprache übersetzt sind.
# Aufruf: lokalen Server starten (python3 -m http.server 8765), dann: python3 tools/i18n_check.py it
# Ausgabe: Anzahl fehlender Texte (Ziel: 0) und Liste in ./i18n_missing_<sprache>.json
from playwright.sync_api import sync_playwright
import json,sys
LANG=sys.argv[1] if len(sys.argv)>1 else 'it'
COL=r"""
window.__miss=window.__miss||{};
window.__col=function(){const L=curLP();const root=document.getElementById('root');
 const w=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);let n;
 while(n=w.nextNode()){const par=n.parentElement;if(!par||par.closest('[data-nt],textarea,script,style'))continue;const k=n.nodeValue.replace(/\s+/g,' ').trim();if(!k||k.length<2)continue;
  if(!/[A-Za-zÄÖÜäöüß]{2}/.test(k))continue; if(trS(k,L)!==k)continue; window.__miss[k]=(window.__miss[k]||0)+1}
 root.querySelectorAll('[placeholder],[aria-label],[title]').forEach(e=>{if(e.closest('[data-nt]'))return;['placeholder','aria-label','title'].forEach(a=>{const v=e.getAttribute(a);if(v&&/[A-Za-zÄÖÜäöüß]{3}/.test(v)&&trS(v,L)===v)window.__miss['@'+v]=1})})};
"""
def snap(pg,js=None,wait=250):
    try:
        _snap(pg,js,wait)
    except Exception as e: print("skip",js[:40] if js else "",str(e)[:80])
def _snap(pg,js=None,wait=250):
    if js: pg.evaluate(js+";0")
    pg.wait_for_timeout(wait)
    pg.evaluate("__col()")
with sync_playwright() as p:
    b=p.chromium.launch(); ctx=b.new_context(viewport={'width':390,'height':844},geolocation={'latitude':52.56,'longitude':13.09,'accuracy':12},permissions=['geolocation'])
    pg=ctx.new_page(); errs=[]; pg.on('pageerror',lambda e:errs.append(str(e)))
    pg.add_init_script("if(!sessionStorage.getItem('i')){localStorage.clear();localStorage.setItem('aa_lang','%s');sessionStorage.setItem('i','1')}"%LANG)
    pg.goto('http://localhost:8765/index.html'); pg.wait_for_timeout(800); pg.evaluate(COL)
    snap(pg)  # virgin start
    for i in range(0,6): snap(pg,"S.tour=%d;R()"%i)
    snap(pg,"S.tour=null;R()")
    pg.locator('[data-a=hnew]').first.click(); pg.wait_for_timeout(400); snap(pg)
    snap(pg,"S.wz=null;R()")
    snap(pg,"S.wz=0;R()"); snap(pg,"S.wz=1;R()"); snap(pg,"S.wz=2;R()"); snap(pg,"S.wz=null;R()")
    snap(pg,"add({k:'t',t:'Gehweg prüfen'});add({k:'v',t:'Sprachaufnahme (Text noch nicht ergänzt)',tag:'Aufnahme lokal gesichert, Text folgt',src:'',dur:12});R()")
    snap(pg,"S.oe=0;R()"); snap(pg,"S.oe=null;R()")
    snap(pg,"adrOpen('a1');R()",600); snap(pg,"S.adrForm=false;R()")
    snap(pg,"S.sw=true;R()"); snap(pg,"S.sw=false;R()")
    snap(pg,"S.netInfo=true;R()"); snap(pg,"S.netInfo=false;R()")
    snap(pg,"S.costInfo=true;R()"); snap(pg,"S.costInfo=false;R()")
    for t in ['look','lang','calc','ai','bak']:
        snap(pg,"S.cfg=true;S.cfgTab='%s';S.acc={};R();document.querySelectorAll('details').forEach(d=>d.open=true)"%t)
        snap(pg,"S.acc=new Proxy({},{get:()=>true});R()")
    snap(pg,"S.cfg=false;S.acc={};R()")
    snap(pg,"S.sc='calc';R()"); snap(pg,"S.rep=true;S.repT='x';R()"); snap(pg,"S.rep=false;S.sc='fin';R()")
    snap(pg,"S.sc='pause';R()"); snap(pg,"S.sc='done';R()"); snap(pg,"S.sc='walk';R()")
    snap(pg,"bgOpen&&bgOpen();R()",500); snap(pg,"S.bg=null;R()")
    snap(pg,"S.geoMap=true;R()"); snap(pg,"S.geoMap=false;R()")
    snap(pg,"persist();startNew();R()")
    snap(pg,"S.delAsk=S.audits[0]&&S.audits[0].id;R()"); snap(pg,"S.delAsk=null;R()")
    snap(pg,"S.cf='adr';R()"); snap(pg,"S.cf=null;R()")
    m=pg.evaluate("Object.fromEntries([...TRQ.values()].map(v=>[v.k,v.ctx]))")
    json.dump(m,open('i18n_missing_%s.json'%LANG,'w'),ensure_ascii=False,indent=0)
    print(LANG,'missing',len(m)); print('errors',errs[:5]); b.close()
