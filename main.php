<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover" />
<title>CES Inspection — Main</title>
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0b0f14">
<style>
  :root{color-scheme:dark light; --bg:#0b0f14; --card:#121821; --ink:#e6edf3; --muted:#9fb0c2; --accent:#6ab0ff; --ok:#29c07a; --bad:#ff6b6b; --warn:#ffcc66}
  body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.45 system-ui,Segoe UI,Roboto,Arial}
  .wrap{max-width:900px;margin:18px auto;padding:16px}
  .card{background:var(--card);border-radius:16px;padding:18px;box-shadow:0 10px 28px rgba(0,0,0,.35);margin-bottom:16px}
  h1{margin:0 0 12px;font-size:1.6rem}
  h2{margin:12px 0 8px;font-size:1.1rem;color:var(--muted)}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
  .row-1{display:grid;grid-template-columns:1fr;gap:12px}
  label{display:block;font-size:.9rem;margin:6px 0 4px;color:var(--muted)}
  input, select, textarea{width:100%;border-radius:10px;border:1px solid #213045;background:#0e141d;color:var(--ink);padding:10px}
  textarea{min-height:100px}
  .hint{font-size:.8rem;color:var(--muted)}
  .btns{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
  .btn{appearance:none;border:1px solid #2a3a50;background:#0e141d;color:var(--ink);padding:10px 14px;border-radius:12px;cursor:pointer}
  .btn.primary{background:var(--accent);border-color:transparent;color:#001428}
  .btn.good{background:var(--ok);border-color:transparent;color:#001e12}
  .btn.warn{background:var(--warn);border-color:transparent;color:#1d1300}
  .pill{display:inline-block;padding:4px 8px;border-radius:999px;background:#0e141d;border:1px solid #2a3a50;font-size:.8rem}
  .grid2{display:grid;grid-template-columns:1fr auto;align-items:center;gap:8px}
  .sync{font-size:.85rem;color:var(--muted)}
  .error{color:var(--bad);font-weight:600}
  .ok{color:var(--ok);font-weight:600}
</style>
<script>
// ===== Tiny utilities =====
const $ = (q, el=document)=>el.querySelector(q);
const $$= (q, el=document)=>Array.from(el.querySelectorAll(q));
const qs = new URLSearchParams(location.search);
const CES_KEY = 'CESState';
const Q_KEY  = 'CESQueue';

// ===== Canonical ID =====
function mintInspect() {
  const t = Date.now();
  const rand = Math.random().toString(36).slice(2,8).toUpperCase();
  return `INS-${t}-${rand}`;
}

// ===== State =====
const CES = {
  load(){
    try{ return JSON.parse(localStorage.getItem(CES_KEY)) || {}; }catch(_){ return {}; }
  },
  save(obj){
    localStorage.setItem(CES_KEY, JSON.stringify(obj || state));
  }
};

let state = CES.load();

// Lock/seed inspect id
if (!state.inspect) state.inspect = qs.get('inspect') || mintInspect();

// Prefills from URL
if (qs.get('code'))     state.code = qs.get('code');
if (qs.get('employee')) state.employeeId = qs.get('employee');

// ===== Queue (JSON + base64 file payloads) =====
const Queue = {
  list(){ try{ return JSON.parse(localStorage.getItem(Q_KEY))||[] }catch(_){return[]} },
  save(list){ localStorage.setItem(Q_KEY, JSON.stringify(list)); },
  enqueue(job){
    const list = Queue.list();
    list.push(Object.assign({id:crypto.randomUUID(),ts:Date.now(),tries:0}, job));
    Queue.save(list);
    Queue.renderBadge();
  },
  async flushOne(job){
    try{
      // if file upload job: body is {fields..., file:{name, type, dataUrl}}
      if (job.kind === 'upload') {
        const fd = new FormData();
        for (const [k,v] of Object.entries(job.body.fields||{})) fd.append(k, v);
        if (job.body.file && job.body.file.dataUrl){
          const blob = await (await fetch(job.body.file.dataUrl)).blob();
          fd.append('file', blob, job.body.file.name || 'photo.jpg');
        }
        const r = await fetch(job.url, { method:'POST', body:fd, credentials:'include' });
        if (!r.ok) throw new Error('HTTP '+r.status);
        const json = await r.json().catch(()=>({}));
        return {ok:true, json};
      } else {
        const r = await fetch(job.url, {
          method: job.method||'POST',
          headers: Object.assign({'Content-Type':'application/json'}, job.headers||{}),
          body: job.body ? JSON.stringify(job.body) : null,
          credentials:'include'
        });
        if (!r.ok) throw new Error('HTTP '+r.status);
        const json = await r.json().catch(()=>({}));
        return {ok:true, json};
      }
    }catch(err){ return {ok:false, error:String(err)} }
  },
  async flushAll(){
    const out = [];
    let list = Queue.list();
    for (let i=0; i<list.length; i++){
      const job = list[i];
      const res = await Queue.flushOne(job);
      if (res.ok){
        // side-effect: if photo upload returns folder_url, we upsert hint
        if (job.kind==='upload' && res.json && res.json.folder_url && job.body?.fields?.inspect && job.body?.fields?.kind){
          const inspect = job.body.fields.inspect;
          const kind = job.body.fields.kind;
          if (kind==='walk' && !state.photosWalkFolderUrl) state.photosWalkFolderUrl = res.json.folder_url;
          if (kind==='repair' && !state.photosRepairFolderUrl) state.photosRepairFolderUrl = res.json.folder_url;
          CES.save();
          // push an upsert to persist folder hints
          Queue.enqueue({
            method:'POST',
            url:'/api/inspections.php?action=upsert',
            kind:'json',
            body:Object.assign({}, state, {
              photosWalkFolderUrl: state.photosWalkFolderUrl || undefined,
              photosRepairFolderUrl: state.photosRepairFolderUrl || undefined
            })
          });
        }
      } else {
        job.tries++;
        if (job.tries < 5) out.push(job); // keep for retry
      }
    }
    Queue.save(out);
    Queue.renderBadge();
    return {ok:true, remaining:out.length};
  },
  renderBadge(){
    const n = Queue.list().length;
    const el = $('#queueCount');
    if (el) el.textContent = n.toString();
  }
};

// Flush on regain connectivity
window.addEventListener('online', ()=>Queue.flushAll());

async function syncNow(){
  setStatus('Syncing…', 'muted');
  const r = await Queue.flushAll();
  setStatus(r.remaining===0 ? 'All synced' : `Queued: ${r.remaining}`, r.remaining===0?'ok':'warn');
}

function setStatus(msg, type){
  const el = $('#status');
  el.textContent = msg;
  el.className = type==='ok' ? 'ok' : type==='warn' ? 'warn' : type==='bad' ? 'error' : 'sync';
}

// ===== PWA install and SW messaging =====
if ('serviceWorker' in navigator){
  navigator.serviceWorker.register('/service-worker.js');
}

// ===== Form wiring =====
document.addEventListener('DOMContentLoaded', () => {
  Queue.renderBadge();
  $('#inspectId').textContent = state.inspect;
  if (state.code)         $('#code').textContent = state.code;
  if (state.employeeId)   $('#employeeId').value = state.employeeId;
  if (state.employeeName) $('#employeeName').value = state.employeeName || '';
  if (state.preferredName)$('#preferredName').value = state.preferredName||'';
  if (state.email)        $('#email').value = state.email||'';
  if (state.phone)        $('#phone').value = state.phone||'';

  if (state.unitId)             $('#unitId').value = state.unitId;
  if (state.displayedUnitId)    $('#displayedUnitId').value = state.displayedUnitId;
  if (state.unitCategory)       $('#unitCategory').value = state.unitCategory;
  if (state.unitType)           $('#unitType').value = state.unitType;
  if (state.sFormNum)           $('#sFormNum').value = state.sFormNum;
  if (state.jobNumber)          $('#jobNumber').value = state.jobNumber;

  if (state.locationLink)       $('#locationLink').value = state.locationLink;
  if (state.notes)              $('#notes').value = state.notes;
  if (state.sopAgreed!==undefined) setRadio('sopAgreed', state.sopAgreed);
  if (state.meterReading)       $('#meterReading').value = state.meterReading;
  if (state.safeToOperate!==undefined) setRadio('safeToOperate', state.safeToOperate);
  if (state.needsRepair!==undefined) setRadio('needsRepair', state.needsRepair);
  if (state.repairDesc)         $('#repairDesc').value = state.repairDesc;

  toggleRepair();
  $$('input[name="needsRepair"]').forEach(r=>r.addEventListener('change', toggleRepair));
  $('#saveDraft').addEventListener('click', saveDraft);
  $('#goWalk').addEventListener('click', gotoWalk);
  $('#syncNow').addEventListener('click', syncNow);
});

function setRadio(name, boolVal){
  const val = boolVal ? 'Yes' : 'No';
  const el = $(`input[name="${name}"][value="${val}"]`);
  if (el) el.checked = true;
}

function getRadioBool(name){
  const v = $(`input[name="${name}"]:checked`)?.value;
  if (v==='Yes') return true;
  if (v==='No')  return false;
  return undefined;
}

function toggleRepair(){
  const needs = getRadioBool('needsRepair');
  $('#repairBlock').style.display = needs ? 'block' : 'none';
}

function collect(){
  // Basic string trims
  const t = s=> (s||'').toString().trim();
  const meterStr = t($('#meterReading').value);
  const meter = meterStr==='' ? '' : Number(meterStr);

  const obj = Object.assign(state, {
    // identity
    inspect: state.inspect,
    code: state.code || '',
    // employee
    employeeId: t($('#employeeId').value),
    employeeName: t($('#employeeName').value),
    preferredName: t($('#preferredName').value),
    email: t($('#email').value),
    phone: t($('#phone').value),
    // unit
    unitId: t($('#unitId').value),
    displayedUnitId: t($('#displayedUnitId').value),
    unitCategory: t($('#unitCategory').value),
    unitType: t($('#unitType').value),
    sFormNum: t($('#sFormNum').value),
    jobNumber: t($('#jobNumber').value),
    // location/SOP
    locationLink: t($('#locationLink').value),
    notes: t($('#notes').value),
    sopAgreed: getRadioBool('sopAgreed'),
    // meter/condition
    meterReading: meterStr==='' ? '' : meter,
    safeToOperate: getRadioBool('safeToOperate'),
    needsRepair: getRadioBool('needsRepair'),
    repairDesc: t($('#repairDesc').value),
  });
  CES.save(obj);
  return obj;
}

function validateDraft(obj){
  // Minimal: allow saving even if not all required for finalize
  if (!obj.employeeId || !obj.unitId){
    return {ok:false, msg:'employeeId and unitId are required to save.'};
  }
  if (obj.meterReading!=='' && (isNaN(obj.meterReading) || obj.meterReading<0 || obj.meterReading>2000000)){
    return {ok:false, msg:'meterReading must be 0–2,000,000.'};
  }
  if (obj.locationLink && !/^https?:\/\//i.test(obj.locationLink)){
    return {ok:false, msg:'locationLink must start with http(s) or be left blank.'};
  }
  return {ok:true};
}

function saveDraft(){
  const obj = collect();
  const v = validateDraft(obj);
  if (!v.ok){ setStatus(v.msg,'bad'); return; }

  // enqueue upsert
  Queue.enqueue({
    method:'POST',
    url:'/api/inspections.php?action=upsert',
    kind:'json',
    body: obj
  });
  setStatus('Draft saved (queued).', 'ok');
  Queue.renderBadge();
}

function gotoWalk(){
  const obj = collect();

  // Validate primary required before proceeding
  const missing = [];
  if (!obj.employeeId) missing.push('employeeId');
  if (!obj.unitId) missing.push('unitId');
  if (obj.sopAgreed===undefined) missing.push('sopAgreed');
  if (obj.safeToOperate===undefined) missing.push('safeToOperate');
  if (obj.needsRepair===undefined) missing.push('needsRepair');
  if (obj.needsRepair===true && !obj.repairDesc) missing.push('repairDesc');
  if (obj.meterReading!=='' && (isNaN(obj.meterReading) || obj.meterReading<0 || obj.meterReading>2000000)) missing.push('meterReading out of range');
  if (obj.locationLink && !/^https?:\/\//i.test(obj.locationLink)) missing.push('locationLink protocol');

  if (missing.length){
    setStatus('Fix: '+missing.join(', '), 'bad');
    return;
  }

  // Save a draft before photos
  Queue.enqueue({
    method:'POST',
    url:'/api/inspections.php?action=upsert',
    kind:'json',
    body: obj
  });

  location.href = `/360walkphotos.html?inspect=${encodeURIComponent(state.inspect)}&code=${encodeURIComponent(state.code||'')}`;
}
</script>
</head>
<body>
<div class="wrap">
  <div class="grid2">
    <div><h1>CES Inspection — Main</h1><span class="pill">Inspect: <span id="inspectId"></span></span> <span class="pill">Code: <span id="code">—</span></span></div>
    <div class="sync">Queued: <span id="queueCount">0</span> &nbsp; <button id="syncNow" class="btn">Sync Now</button><div id="status" class="sync"></div></div>
  </div>

  <!-- Employee -->
  <div class="card">
    <h2>Employee</h2>
    <div class="row">
      <div><label>Employee (readonly display)</label><input id="employeeName" placeholder="James H Boriack" /></div>
      <div><label>Preferred Name</label><input id="preferredName" placeholder="Jimmy" /></div>
      <div><label>Email</label><input id="email" type="email" placeholder="name@example.com" /></div>
      <div><label>Phone</label><input id="phone" inputmode="tel" placeholder="9795426841" /></div>
      <div><label>Hidden: employeeId</label><input id="employeeId" placeholder="013846" /></div>
    </div>
  </div>

  <!-- Unit -->
  <div class="card">
    <h2>Unit</h2>
    <div class="row">
      <div><label>Unit (Canonical) — unitId</label><input id="unitId" placeholder="C24-002" /></div>
      <div><label>Unit (Displayed) — displayedUnitId</label><input id="displayedUnitId" placeholder="Truck 10001" /></div>
      <div>
        <label>Category — unitCategory</label>
        <select id="unitCategory">
          <option value="">Select…</option>
          <option>Vehicle</option><option>Trailer</option><option>Equipment</option>
          <option>Tool</option><option>Other</option>
        </select>
      </div>
      <div><label>Type — unitType</label><input id="unitType" placeholder="Light Truck / Goose-neck / etc." /></div>
      <div><label>S-Form Number — sFormNum</label><input id="sFormNum" inputmode="numeric" /></div>
      <div><label>Job Number — jobNumber</label><input id="jobNumber" /></div>
    </div>
  </div>

  <!-- Location & SOP -->
  <div class="card">
    <h2>Location & SOP</h2>
    <div class="row">
      <div><label>Location Link — locationLink</label><input id="locationLink" placeholder="https://maps.google.com/?q=lat,lon" /></div>
      <div><label>Notes — notes</label><input id="notes" placeholder="Optional notes…" /></div>
    </div>
    <div class="row">
      <div>
        <label>SOP Agreed — sopAgreed <span class="hint">(required)</span></label>
        <div>
          <label><input type="radio" name="sopAgreed" value="Yes"> Yes</label>
          <label style="margin-left:16px"><input type="radio" name="sopAgreed" value="No"> No</label>
        </div>
      </div>
    </div>
  </div>

  <!-- Meter & Condition -->
  <div class="card">
    <h2>Meter & Condition</h2>
    <div class="row">
      <div><label>Meter / Odometer — meterReading</label><input id="meterReading" inputmode="numeric" placeholder="0–2,000,000" /></div>
      <div>
        <label>Safe to Operate — safeToOperate <span class="hint">(required)</span></label>
        <div>
          <label><input type="radio" name="safeToOperate" value="Yes"> Yes</label>
          <label style="margin-left:16px"><input type="radio" name="safeToOperate" value="No"> No</label>
        </div>
      </div>
      <div>
        <label>Needs Repair — needsRepair <span class="hint">(required)</span></label>
        <div>
          <label><input type="radio" name="needsRepair" value="Yes"> Yes</label>
          <label style="margin-left:16px"><input type="radio" name="needsRepair" value="No"> No</label>
        </div>
      </div>
    </div>
    <div id="repairBlock" class="row-1" style="display:none">
      <div><label>Repair Description — repairDesc <span class="hint">(required when Needs Repair = Yes)</span></label><textarea id="repairDesc" placeholder="Describe the issue(s)…"></textarea></div>
    </div>
  </div>

  <div class="btns">
    <button id="saveDraft" class="btn">Save Draft</button>
    <button id="goWalk" class="btn primary">Start 360 Walk Photos</button>
    <a class="btn" href="/summary.php">Open Summary</a>
  </div>
</div>
</body>
</html>
