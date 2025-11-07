<?php
// sop.php — SOP agree → inspect-init (mints inspectId) → main/nocode
// - Writes only sopAgreed=true to CESState (and legacy mirror), never touches inspectId/code.
// - Passthrough: code (QR) or noqr (rental). Always adds verified=1.
// - Includes a small DIAG panel behind a toggle button; can be forced with ?diag=1

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache'); header('Expires: 0');

require __DIR__ . '/inc/bootstrap.php';

$code   = isset($_GET['code']) ? strtoupper(trim((string)$_GET['code'])) : '';
$noqr   = (isset($_GET['noqr']) && ($_GET['noqr'] === '' || $_GET['noqr'] === '1'));
$forceDiag = (isset($_GET['diag']) && $_GET['diag'] === '1'); // force open via URL if needed
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Company Policy Acknowledgement</title>
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0b0f14">
<style>
  :root{color-scheme:dark}
  body{margin:0;background:#0b0f14;color:#e6edf3;font:14px/1.45 system-ui,Segoe UI,Roboto,Arial}
  .wrap{max-width:820px;margin:8vh auto;padding:16px}
  .card{background:#121821;border-radius:16px;padding:20px;box-shadow:0 8px 24px rgba(0,0,0,.35)}
  .btn{cursor:pointer;background:#1a2939;border:1px solid #29445f;border-radius:10px;padding:10px 12px;color:#e6edf3}
  .btn:hover{border-color:#6ab0ff}
  .muted{color:#9fb0c2}
  /* --- DIAG styles --- */
  .diag-btn{position:fixed;right:14px;bottom:14px;z-index:9999;opacity:0.9}
  .diag{position:fixed;right:12px;bottom:60px;width:360px;max-width:90vw;max-height:70vh;overflow:auto;
        background:#0b131d;border:1px solid #29445f;border-radius:12px;box-shadow:0 10px 28px rgba(0,0,0,.45);
        padding:10px;z-index:9998;display:none}
  .diag h4{margin:6px 0 10px;font-size:14px;color:#9fb0c2}
  .diag pre{margin:8px 0;background:#0b0f14;border:1px solid #1e2a3a;border-radius:8px;padding:8px;font-size:12px;overflow:auto}
  .row{display:flex;gap:8px;flex-wrap:wrap}
</style>
</head>
<body>
<div class="wrap"><div class="card">
  <h2>Driver / Operator Company Policy</h2>
  <ul class="muted" style="margin-left:18px">
    <li>Perform manufacturer daily maintenance and lubrication.</li>
    <li>Check all fluid levels before starting the unit.</li>
    <li>Keep unit fueled and DEF (if equipped) filled.</li>
    <li>Keep cab/exterior/tools clean and organized.</li>
    <li>Complete a 360 Safety Walk Around before operating/moving.</li>
    <li>Complete an inspection on each unit you operate or drive.</li>
  </ul>
  <p style="margin-top:14px">I have read and understand the Clear Energy Services policy.</p>
  <button id="agree" class="btn" type="button">Yes — I Agree</button>
  <div id="msg" class="muted" style="margin-top:8px"></div>
</div></div>

<!-- DIAG BUTTON + PANEL -->
<button id="diagBtn" class="btn diag-btn" type="button" title="Diagnostics">DIAG</button>
<div id="diag" class="diag" role="dialog" aria-label="Diagnostics">
  <h4>Diagnostics</h4>
  <div class="row">
    <button id="refreshDiag" class="btn" type="button">Refresh</button>
    <button id="closeDiag" class="btn" type="button">Close</button>
  </div>
  <pre id="diagUrl"></pre>
  <pre id="diagCES"></pre>
  <pre id="diagLegacy"></pre>
</div>

<script>
(function(){
  'use strict';

  // ====== CONFIG ======
  // Toggle this to show/hide the DIAG button on this page (in addition to ?diag=1)
  var DIAG_ENABLE = true;

  var code  = <?php echo json_encode($code); ?>;
  var noqr  = <?php echo json_encode($noqr ? '1' : '0'); ?>;
  var forceOpen = <?php echo json_encode($forceDiag ? '1' : '0'); ?>;

  function nextURL(){
    // After SOP we go to inspect-init, which mints inspectId and redirects to main/nocode with verified=1
    var u = new URL('/inspect-init.php', location.origin);
    if (noqr === '1') u.searchParams.set('noqr','1'); // rental flow marker
    if (code)        u.searchParams.set('code', code);
    u.searchParams.set('verified','1');               // SOP agreed right now
    return u.toString();
  }

  function setSOPAgreed(){
    try{
      // Update CESState without clobbering inspectId/code/etc.
      var S = {};
      try { S = JSON.parse(localStorage.getItem('CESState')||'{}')||{}; } catch(_){}
      S.sopAgreed = true;
      localStorage.setItem('CESState', JSON.stringify(S));

      // Legacy mirror blob (kept only for pages that still read it)
      var L = {};
      try { L = JSON.parse(localStorage.getItem('ces.inspect.state')||'{}')||{}; } catch(_){}
      L.sopAgreed = true;
      localStorage.setItem('ces.inspect.state', JSON.stringify(L));

      // Small single-flag mirrors (optional)
      localStorage.setItem('ces.sopAgreed','1');
    }catch(_){}
  }

  document.getElementById('agree').addEventListener('click', function(){
    setSOPAgreed();
    document.getElementById('msg').textContent = 'Continuing…';
    location.replace(nextURL());
  });

  // ====== DIAGNOSTICS ======
  var $btn = document.getElementById('diagBtn');
  var $dlg = document.getElementById('diag');
  var $url = document.getElementById('diagUrl');
  var $ces = document.getElementById('diagCES');
  var $leg = document.getElementById('diagLegacy');

  function renderDiag(){
    var S={}, L={};
    try{ S = JSON.parse(localStorage.getItem('CESState')||'{}')||{}; }catch(_){}
    try{ L = JSON.parse(localStorage.getItem('ces.inspect.state')||'{}')||{}; }catch(_){}
    $url.textContent = 'URL\n' + location.href;
    $ces.textContent = 'CESState\n' + JSON.stringify(S, null, 2);
    $leg.textContent = 'ces.inspect.state\n' + JSON.stringify(L, null, 2);
  }
  function openDiag(){ renderDiag(); $dlg.style.display='block'; }
  function closeDiag(){ $dlg.style.display='none'; }

  document.getElementById('refreshDiag').addEventListener('click', renderDiag);
  document.getElementById('closeDiag').addEventListener('click', closeDiag);
  $btn.addEventListener('click', function(){
    if ($dlg.style.display === 'block') closeDiag(); else openDiag();
  });

  // Respect page-level and query-string controls
  if (!DIAG_ENABLE && forceOpen !== '1') {
    $btn.style.display = 'none';
  }
  if (forceOpen === '1') {
    if (!DIAG_ENABLE) $btn.style.display = ''; // ensure visible if forced
    openDiag();
  }
})();
</script>
</body>
</html>
