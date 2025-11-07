<?php
// inspect-init.php — seed client state from QR + go to main
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$codeQS = isset($_GET['code']) ? strtoupper(trim((string)$_GET['code'])) : '';
$verifiedQS = isset($_GET['verified']) ? trim((string)$_GET['verified']) : '';
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"/>
<title>Initialize Inspection</title>
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0b0f14">
<style>
  :root{color-scheme:light dark}
  body{margin:0;background:#0b0f14;color:#e6edf3;font:14px/1.5 system-ui,Segoe UI,Roboto,Arial}
  .wrap{max-width:680px;margin:12vh auto;padding:16px}
  .card{background:#121821;border-radius:16px;padding:22px;box-shadow:0 10px 28px rgba(0,0,0,.35)}
  .muted{color:#9fb0c2}
  .spin{display:inline-block;width:1em;height:1em;border:.18em solid #39506b;border-top-color:#6ab0ff;border-radius:50%;animation:spin 1s linear infinite;vertical-align:-.2em}
  @keyframes spin{to{transform:rotate(360deg)}}
</style>
<script>
if ('serviceWorker' in navigator) {
  addEventListener('load', ()=> navigator.serviceWorker.register('/service-worker.js'));
}
</script>
</head><body>
<div class="wrap">
  <div class="card">
    <h2>Preparing inspection…</h2>
    <div id="msg" class="muted"><span class="spin"></span> Loading unit & seeding state…</div>
  </div>
</div>

<script>
// ---- helpers + CESState ----
(function(){
  const KEY='CESState';
  function J(x){ try{ return JSON.parse(x)||{} }catch(_){ return {} } }
  function R(){ return J(localStorage.getItem(KEY)); }
  function W(p){ const out=Object.assign({},R(),p||{}); out._touched=Date.now(); localStorage.setItem(KEY,JSON.stringify(out)); return out; }
  function newInspect(){ return 'INS-' + Date.now() + '-' + Math.random().toString(16).slice(2,10).toUpperCase(); }
  window.CES = { read:R, write:W, ensure:function(){ const s=R(); if(!s.inspectId){ s.inspectId=newInspect(); W(s); } return s; } };
})();

const CODE = <?php echo json_encode($codeQS); ?>;
const VERIFIED = <?php echo json_encode($verifiedQS); ?>;

(async function run(){
  try{
    const S = CES.ensure();
    if (CODE) CES.write({ code: CODE });
    // Fetch unit by QR (idempotent if offline; SW may serve cached)
    let unit = null;
    try{
      const r = await fetch('/api/qr-resolve.php?code=' + encodeURIComponent(CODE), {cache:'no-store', credentials:'same-origin'});
      const t = await r.text();
      const j = JSON.parse(t);
      if (j && j.ok && j.unit){
        unit = j.unit;
        CES.write({
          unitId:       unit.unitId || '',
          displayedUnitId: unit.displayId || unit.displayedUnitId || '',
          unitCategory: unit.unitCategory || '',
          unitType:     unit.unitType || '',
          sFormNum:     unit.sFormNum || ''
        });
      }
    }catch(e){ /* stay quiet; offline is okay */ }

    // ensure inspectId
    const cur = CES.ensure();
    const inspect = cur.inspectId;

    // bounce to main
    const next = new URL('/main.php', location.origin);
    if (inspect) next.searchParams.set('inspect', inspect);
    if (CODE)    next.searchParams.set('code', CODE);
    if (VERIFIED) next.searchParams.set('verified', VERIFIED);
    location.replace(next.toString());
  }catch(e){
    document.getElementById('msg').textContent = 'Init failed: ' + String(e);
    console.error(e);
  }
})();
</script>
</body></html>
