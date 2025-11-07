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
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;background:#132034;border:1px solid #25344a;color:#9fb1c6;font-size:12px;margin-left:6px}
  .modebar{display:flex;gap:10px}
  .modebtn{border:1px solid #2a3a50;background:#0e141d;color:var(--ink);padding:10px 14px;border-radius:10px;cursor:pointer}
  .modebtn.active{background:var(--acc);color:#001322;border-color:transparent}
  .grid2{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}
  .diag pre{max-height:240px;overflow:auto;background:#0b111a;border:1px solid #263446;border-radius:10px;padding:10px}
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
    <div class="modebar">
      <button id="scanBtn"   class="modebtn"    type="button" data-mode="scan">Scan QR Code</button>
      <button id="nocodeBtn" class="modebtn"    type="button" data-mode="nocode">No-Code</button>
    </div>
    <div class="mut" style="margin-top:6px">Pick exactly one mode. GPS is captured on selection.</div>
  </div>

  <div class="card" id="locCard" style="display:none">
    <h3>3) Capture Location</h3>
    <div class="row">
      <button id="locBtn" type="button">Use current GPS</button>
      <span id="locMsg" class="mut"></span>
    </div>
  </div>

  <div class="card" id="goCard" style="display:none">
    <h3>4) Start</h3>
    <div class="row">
      <button class="primary" id="startBtn" type="button" disabled>Start Inspection</button>
      <span id="startMsg" class="mut"></span>
    </div>
  </div>

  <div class="card diag" id="diagCard" style="display:none">
    <h3>Diagnostics</h3>
    <pre id="diagPre">loading…</pre>
  </div>
</div>

<script>
/* -------- Config -------- */
const API_VERIFY = '/api/employee-verify.php';
const API_INIT   = '/inspect-init.php';
const SOP_URL    = '/sop.php';
const SCAN_URL   = '/scanner.html';
const NEXT_AFTER_SOP = '/nocode-main.php';
const CES_STATE_KEY = 'CESState';

/* -------- Helpers -------- */
const $ = s => document.querySelector(s);
function show(el, on=true){ el.style.display = on ? '' : 'none'; }
function setMsg(el, txt, cls='mut'){ el.textContent = txt || ''; el.className = cls; }
function readState(){ try { return JSON.parse(localStorage.getItem(CES_STATE_KEY) || '{}'); } catch { return {}; } }
function writeState(patch){ const next = { ...readState(), ...patch }; localStorage.setItem(CES_STATE_KEY, JSON.stringify(next)); updateDiag(); return next; }
function hardReset(){ try{ sessionStorage.clear(); localStorage.removeItem(CES_STATE_KEY); }catch{} show($('#resetPill'), true); updateDiag(); }
function createMapsLink(lat,lng){ return `https://maps.google.com/?q=${lat},${lng}`; }

function getLocationOnce(){
  return new Promise((resolve,reject)=>{
    if (!navigator.geolocation) return reject(new Error('Geolocation not supported'));
    navigator.geolocation.getCurrentPosition(
      p=>resolve({lat:p.coords.latitude,lng:p.coords.longitude,acc:p.coords.accuracy??null}),
      e=>reject(e),
      { enableHighAccuracy:true, timeout:8000, maximumAge:0 }
    );
  });
}

async function captureGPS(label){
  try{
    const p = await getLocationOnce();
    writeState({ startLocation:p, locationLink:createMapsLink(p.lat,p.lng) });
    setMsg($('#locMsg'), `${label}: ${p.lat.toFixed(5)}, ${p.lng.toFixed(5)} (±${p.acc||'?'}m)`, 'ok');
    return true;
  }catch(e){
    setMsg($('#locMsg'), `${label} failed: ${e?.message||'GPS error'}`, 'warn');
    return false;
  }
}

function mintLocalInspectId(){
  const t=new Date(), pad=n=>String(n).padStart(2,'0');
  const ts=`${t.getFullYear()}${pad(t.getMonth()+1)}${pad(t.getDate())}-${pad(t.getHours())}${pad(t.getMinutes())}${pad(t.getSeconds())}`;
  const rand=Math.floor(Math.random()*0xFFFFFF).toString(16).toUpperCase().padStart(6,'0');
  return `INS-${ts}-${rand}`;
}

/* -------- Diagnostics -------- */
const DIAG_ON = new URLSearchParams(location.search).get('diag')==='1';
let lastInitResp=null, lastInitErr=null;
function updateDiag(){
  if (!DIAG_ON) { show($('#diagCard'), false); return; }
  show($('#diagCard'), true);
  $('#diagPre').textContent = JSON.stringify({
    state: readState(),
    chosenMode,
    lastInitResp,
    lastInitErr,
    now: new Date().toISOString()
  }, null, 2);
}

/* -------- UI State -------- */
let chosenMode = null; // 'scan' | 'nocode'

function setMode(mode){
  chosenMode = mode; // or null
  // visual toggle
  for (const b of [$('#scanBtn'), $('#nocodeBtn')]) {
    b.classList.toggle('active', b.dataset.mode === mode);
  }
  // Reveal GPS + Start sections
  show($('#locCard'), true);
  show($('#goCard'),  true);
  maybeEnableStart(); // may still be disabled pending GPS for nocode
}

/* -------- Wire up -------- */
$('#resetBtn').addEventListener('click', hardReset);

$('#verifyBtn').addEventListener('click', async ()=>{
  const id = $('#empId').value.trim();
  if (!id) { setMsg($('#empMsg'),'Enter an Employee ID','warn'); return; }

  setMsg($('#empMsg'), 'Verifying…');
  try{
    const r = await fetch(`${API_VERIFY}?employee_id=${encodeURIComponent(id)}`, {cache:'no-cache', credentials:'same-origin'});
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || `HTTP_${r.status}`);
    const emp = j.employee || {};
    writeState({
      employeeId: emp.id || id,
      employeeName: emp.name || '',
      preferredName: emp.preferred_name || '',
      email: emp.email || '',
      phone: emp.phone || '',
      isAdmin: Number(emp.admin) === 1
    });
    setMsg($('#empMsg'), 'Verified', 'ok');
    $('#installBtn').style.display = (Number(emp.admin)===1) ? '' : 'none';
    show($('#modeCard'), true);
  } catch(e){
    setMsg($('#empMsg'), `Verify failed: ${e.message||e}`, 'warn');
  }
});

function onModeClick(ev){
  ev.preventDefault();
  const mode = ev.currentTarget.dataset.mode; // 'scan' | 'nocode'
  setMode(mode);
  // Capture GPS immediately on selection (your requirement)
  setMsg($('#locMsg'), 'Capturing GPS…');
  captureGPS('GPS').then(()=> maybeEnableStart());
}
$('#scanBtn').addEventListener('click', onModeClick);
$('#nocodeBtn').addEventListener('click', onModeClick);

$('#locBtn').addEventListener('click', async ()=>{
  setMsg($('#locMsg'), 'Requesting GPS…');
  await captureGPS('Manual GPS');
  maybeEnableStart();
});

function maybeEnableStart(){
  const st = readState();
  const hasEmp  = !!st.employeeId;
  const hasMode = !!chosenMode;
  const needGPS = (chosenMode === 'nocode');
  const hasGPS  = !!st.startLocation;
  $('#startBtn').disabled = !(hasEmp && hasMode && (!needGPS || hasGPS));
}

$('#startBtn').addEventListener('click', async ()=>{
  const st = readState();
  if (!st.employeeId){ setMsg($('#startMsg'),'Employee not verified','warn'); return; }
  if (!chosenMode){ setMsg($('#startMsg'),'Choose Scan or No-Code','warn'); return; }
  if (chosenMode==='nocode' && !st.startLocation){ setMsg($('#startMsg'),'GPS required for No-Code','warn'); return; }

  setMsg($('#startMsg'),'Starting…');
  lastInitResp=null; lastInitErr=null;

  const payload = {
    employee: st.employeeId,
    mode: chosenMode,
    lat: st.startLocation?.lat ?? null,
    lng: st.startLocation?.lng ?? null,
    acc: st.startLocation?.acc ?? null
  };

  try{
    const r = await fetch(API_INIT, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
    const j = await r.json().catch(()=> ({}));
    lastInitResp = { status:r.status, body:j };
    if (!r.ok || !j.ok || !j.inspectId) throw new Error(j.error || `HTTP_${r.status}`);

    const inspectId = j.inspectId;
    writeState({ inspectId, mode: chosenMode, startedAt: Date.now() });
    setMsg($('#startMsg'),'Started','ok');
    routeNext(inspectId);
  } catch(e){
    lastInitErr = String(e.message || e);
    const fallbackId = mintLocalInspectId();
    writeState({ inspectId:fallbackId, mode:chosenMode, startedAt:Date.now(), initFallback:true });
    setMsg($('#startMsg'),'Server init failed — using local ID','warn');
    routeNext(fallbackId);
  } finally {
    updateDiag();
  }
});

function routeNext(inspectId){
  if (chosenMode === 'scan') {
    // Scan path FIRST — exactly as requested
    location.assign(`${SCAN_URL}?inspectId=${encodeURIComponent(inspectId)}`);
  } else {
    // No-code path → SOP → then nocode-main
    location.assign(`${SOP_URL}?inspectId=${encodeURIComponent(inspectId)}&next=${encodeURIComponent(NEXT_AFTER_SOP)}`);
  }
}

/* -------- Install (Admin) -------- */
$('#installBtn').addEventListener('click', ()=>{
  // Put your real installer here; placeholder opens manifest
  window.open('/manifest.webmanifest','_blank');
});

/* -------- Init -------- */
(function init(){
  hardReset();                  // always clear on open
  updateDiag();                 // render diag if ?diag=1
  show($('#goCard'), true);     // visible, button stays disabled until ready
})();
</script>
