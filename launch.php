<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CES Launch</title>
<style>
  :root{--bg:#0b0f14;--card:#0e141d;--ink:#e6edf3;--mut:#9fb1c6;--acc:#3ea6ff;--err:#ff6b6b;--ok:#3bdb83}
  html,body{background:var(--bg);color:var(--ink);margin:0;font:16px system-ui,Segoe UI,Roboto,Arial}
  .wrap{max-width:720px;margin:0 auto;padding:18px}
  .card{background:#0e141d;border:1px solid #223345;border-radius:12px;padding:16px;margin:14px 0}
  .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  label{display:block;margin:8px 0}
  input[type=text]{width:100%;background:#0b111a;border:1px solid #2a3a50;border-radius:8px;color:var(--ink);padding:10px}
  button{border:1px solid #2a3a50;background:#0e141d;color:var(--ink);padding:10px 14px;border-radius:10px;cursor:pointer}
  button[disabled]{opacity:.6;cursor:not-allowed}
  .primary{background:var(--acc);color:#001322;border-color:transparent}
  .mut{color:var(--mut);font-size:12px}
  .warn{color:var(--err)}
  .ok{color:var(--ok)}
  .choices{display:flex;gap:10px}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;background:#132034;border:1px solid #25344a;color:#9fb1c6;font-size:12px;margin-left:6px}
  .diag pre{max-height:260px;overflow:auto;background:#0b111a;border:1px solid #263446;border-radius:10px;padding:10px}
  .grid2{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}
</style>

<div class="wrap">
  <h2>CES Inspection — Launch</h2>

  <div class="card">
    <div class="grid2">
      <div class="mut">Open resets any prior session to avoid device-sharing conflicts.</div>
      <div>
        <button id="resetBtn" type="button">Clear previous inspection</button>
        <span id="resetPill" class="pill" style="display:none">cleared</span>
      </div>
    </div>
  </div>

  <div class="card" id="empCard">
    <h3>1) Verify Employee</h3>
    <label>Employee ID
      <input id="empId" type="text" inputmode="numeric" placeholder="e.g. 134846">
    </label>
    <div class="row">
      <button class="primary" id="verifyBtn" type="button">Verify</button>
      <span id="empMsg" class="mut"></span>
      <button id="installBtn" type="button" style="display:none;margin-left:auto;">Install (Admin)</button>
    </div>
  </div>

  <div class="card" id="modeCard" style="display:none">
    <h3>2) Choose Mode</h3>
    <div class="choices">
      <button id="scanBtn" type="button">Scan QR Code</button>
      <button id="nocodeBtn" class="primary" type="button">No-Code</button>
    </div>
    <div class="mut" style="margin-top:6px">Scan a unit QR or proceed without a code.</div>
  </div>

  <div class="card" id="locCard" style="display:none">
    <h3>3) Capture Location</h3>
    <div class="row">
      <button id="locBtn" type="button">Use current GPS</button>
      <span id="locMsg" class="mut"></span>
    </div>
    <div class="mut" style="margin-top:6px">We’ll attach this location to the inspection start.</div>
  </div>

  <div class="card" id="goCard" style="display:none">
    <h3>4) Start & Go to SOP</h3>
    <div class="row">
      <button class="primary" id="startBtn" type="button">Start Inspection</button>
      <span id="startMsg" class="mut"></span>
    </div>
  </div>

  <div class="card diag" id="diagCard" style="display:none">
    <h3>Diagnostics</h3>
    <div class="mut" id="diagNote">Add <code>?diag=1</code> to the URL to keep this open.</div>
    <pre id="diagPre">loading…</pre>
  </div>
</div>

<script>
/* ---------- Config ---------- */
const API_INIT = '/inspect-init.php';    // server mint endpoint
const SOP_URL  = '/sop.php';             // next page (will pass ?inspectId= & next=/nocode-main.php)
const SCAN_URL = '/scanner.html';
const NEXT_AFTER_SOP = '/nocode-main.php';
const ADMIN_IDS = new Set(['134846']);   // <-- add more admin IDs here
const CES_STATE_KEY = 'CESState';

/* ---------- Helpers ---------- */
const $ = s => document.querySelector(s);
const qs = new URLSearchParams(location.search);
const DIAG_ON = qs.get('diag') === '1';

function show(el, on=true){ el.style.display = on ? '' : 'none'; }

function setMsg(el, txt, cls='mut'){
  el.textContent = txt || '';
  el.className = cls;
}

function readState(){ try { return JSON.parse(localStorage.getItem(CES_STATE_KEY) || '{}'); } catch { return {}; } }
function writeState(patch){
  const cur = readState();
  const next = { ...cur, ...patch };
  localStorage.setItem(CES_STATE_KEY, JSON.stringify(next));
  updateDiag();
  return next;
}

function hardReset(){
  try { sessionStorage.clear(); localStorage.removeItem(CES_STATE_KEY); } catch {}
  show($('#resetPill'), true);
  updateDiag();
}

function createMapsLink(lat,lng){ return `https://maps.google.com/?q=${lat},${lng}`; }

function getLocationOnce(){
  return new Promise((resolve,reject)=>{
    if (!navigator.geolocation) return reject(new Error('Geolocation not supported'));
    navigator.geolocation.getCurrentPosition(
      pos => resolve({
        lat: pos.coords.latitude,
        lng: pos.coords.longitude,
        acc: pos.coords.accuracy ?? null
      }),
      err => reject(err),
      { enableHighAccuracy:true, timeout:8000, maximumAge:0 }
    );
  });
}

async function tryAutoGPS(label){
  try {
    const p = await getLocationOnce();
    writeState({ startLocation: p, locationLink: createMapsLink(p.lat, p.lng) });
    setMsg($('#locMsg'), `${label}: Lat ${p.lat.toFixed(5)}, Lng ${p.lng.toFixed(5)} (±${p.acc||'?'}m)`, 'ok');
    return true;
  } catch (e) {
    setMsg($('#locMsg'), `${label} failed: ${e && e.message ? e.message : 'GPS error'}`, 'warn');
    return false;
  }
}

function mintLocalInspectId(){
  const t = new Date();
  const pad = n => String(n).padStart(2,'0');
  const ts = `${t.getFullYear()}${pad(t.getMonth()+1)}${pad(t.getDate())}-${pad(t.getHours())}${pad(t.getMinutes())}${pad(t.getSeconds())}`;
  const rand = Math.floor(Math.random()*0xFFFFFF).toString(16).toUpperCase().padStart(6,'0');
  return `INS-${ts}-${rand}`;
}

/* ---------- Diagnostics ---------- */
function updateDiag(data){
  const box = $('#diagCard');
  if (!DIAG_ON && !box.hasAttribute('data-open')) { show(box, false); return; }
  show(box, true);
  const st = readState();
  const dump = {
    now: new Date().toISOString(),
    chosenMode,
    state: st,
    lastInitResponse: lastInitResp,
    lastInitError: lastInitErr
  };
  $('#diagPre').textContent = JSON.stringify(dump, null, 2);
}

/* ---------- Wire up ---------- */
let chosenMode = null;      // 'scan' | 'nocode'
let lastInitResp = null;
let lastInitErr = null;

$('#resetBtn').addEventListener('click', hardReset);

$('#verifyBtn').addEventListener('click', async (ev)=>{
  ev.preventDefault();
  const empId = $('#empId').value.trim();
  if (!empId){ setMsg($('#empMsg'), 'Enter an Employee ID', 'warn'); return; }

  // TODO: replace with real verify endpoint call
  const ok = !!empId;
  if (!ok){ setMsg($('#empMsg'), 'Invalid employee ID', 'warn'); return; }

  writeState({ employeeId: empId, verifiedAt: Date.now() });
  setMsg($('#empMsg'), 'Verified', 'ok');

  // Show admin Install if whitelisted
  if (ADMIN_IDS.has(empId)){
    show($('#installBtn'), true);
  }

  // open the next step
  show($('#modeCard'), true);

  // Attempt auto GPS here (post-verify)
  show($('#locCard'), true);
  setMsg($('#locMsg'), 'Capturing GPS…');
  await tryAutoGPS('Auto GPS (post-verify)');

  // enable starting once mode is chosen
  maybeEnableStart();
  updateDiag();
});

$('#scanBtn').addEventListener('click', (ev)=>{
  ev.preventDefault();
  chosenMode = 'scan';
  setMsg($('#locMsg'), 'Scan path: GPS recommended (capturing if permitted).');
  show($('#locCard'), true);
  show($('#goCard'), true);
  maybeEnableStart();
  updateDiag();
});

$('#nocodeBtn').addEventListener('click', (ev)=>{
  ev.preventDefault();
  chosenMode = 'nocode';
  setMsg($('#locMsg'), 'No-code path: GPS required before main; attempting capture.');
  show($('#locCard'), true);
  show($('#goCard'), true);
  maybeEnableStart();
  updateDiag();
});

$('#locBtn').addEventListener('click', async (ev)=>{
  ev.preventDefault();
  setMsg($('#locMsg'), 'Requesting GPS…');
  const ok = await tryAutoGPS('Manual GPS');
  if (ok) show($('#goCard'), true);
  maybeEnableStart();
  updateDiag();
});

function maybeEnableStart(){
  const st = readState();
  const hasEmp = !!st.employeeId;
  const hasMode = !!chosenMode;
  // For nocode, we strongly prefer location but we won't hard-block if browser denies
  const btn = $('#startBtn');
  btn.disabled = !(hasEmp && hasMode);
}

$('#startBtn').addEventListener('click', async (ev)=>{
  ev.preventDefault();
  const st = readState();
  if (!st.employeeId){ setMsg($('#startMsg'), 'Employee not verified', 'warn'); return; }
  if (!chosenMode){ setMsg($('#startMsg'), 'Choose Scan or No-Code', 'warn'); return; }

  setMsg($('#startMsg'), 'Starting…');
  lastInitResp = null; lastInitErr = null;

  // Build payload expected by server
  const payload = {
    employee: st.employeeId,
    mode: chosenMode,
    lat: st.startLocation?.lat ?? null,
    lng: st.startLocation?.lng ?? null,
    acc: st.startLocation?.acc ?? null
  };

  try{
    const r = await fetch(API_INIT, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      body: JSON.stringify(payload)
    });
    const j = await r.json().catch(()=> ({}));
    lastInitResp = { status: r.status, body: j };

    if (!r.ok || !j.ok || !j.inspectId) throw new Error(j.error || `HTTP_${r.status}`);

    const inspectId = j.inspectId;
    writeState({ inspectId, mode: chosenMode, startedAt: Date.now() });

    setMsg($('#startMsg'), 'Started', 'ok');
    routeNext(inspectId);
  } catch (e){
    lastInitErr = String(e && e.message ? e.message : e);
    // Fail-safe: mint a local ID so field work can continue
    const fallbackId = mintLocalInspectId();
    writeState({ inspectId: fallbackId, mode: chosenMode, startedAt: Date.now(), initFallback:true });

    setMsg($('#startMsg'), 'Server init failed — using local ID, continuing…', 'warn');
    routeNext(fallbackId);
  } finally {
    updateDiag();
  }
});

function routeNext(inspectId){
  if (chosenMode === 'scan'){
    location.assign(`${SCAN_URL}?inspectId=${encodeURIComponent(inspectId)}`);
  } else {
    const st = readState();
    // ensure locationLink present if we have GPS
    if (!st.locationLink && st.startLocation) {
      writeState({ locationLink: createMapsLink(st.startLocation.lat, st.startLocation.lng) });
    }
    location.assign(`${SOP_URL}?inspectId=${encodeURIComponent(inspectId)}&next=${encodeURIComponent(NEXT_AFTER_SOP)}`);
  }
}

/* ---------- Install (Admin only) ---------- */
$('#installBtn').addEventListener('click', async ()=>{
  try {
    // If you have a custom “install” action, wire it here.
    // As a placeholder, we open the PWA manifest route if present.
    window.open('/manifest.webmanifest', '_blank');
  } catch {}
});

/* ---------- Page init ---------- */
(function init(){
  // Always clear prior session on open
  hardReset();

  // Diagnostics toggle
  if (DIAG_ON){ $('#diagCard').setAttribute('data-open','1'); show($('#diagCard'), true); updateDiag(); }

  // Try auto-GPS ASAP; browsers that already granted will resolve quickly
  setMsg($('#locMsg'), 'Auto GPS on load…');
  tryAutoGPS('Auto GPS (onload)').then(ok=>{
    if (ok) { show($('#locCard'), true); }
    updateDiag();
  });
})();
</script>
