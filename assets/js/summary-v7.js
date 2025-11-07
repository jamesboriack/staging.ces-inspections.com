// summary-v8.js — externalized logic (CSP-friendly)
// Version: summary-2025-10-09-v8 (fix diagBlob->diagResolved, v-formnum->v-form, null-safeguards)

(function(){
  'use strict';
  const VERSION = 'summary-2025-10-09-v8';
  const $ = id => document.getElementById(id);
  const prefer = (...vals) => vals.find(v => v!=null && String(v).trim()!=='') ?? '';
  const booly  = v => (v===true || v===1 || v==='1' || v==='true' || v==='YES' || v==='yes');

  const KEY='CESState';
  const J=(x,d)=>{ try { return JSON.parse(x)||d; } catch(_) { return d; } };
  const R=()=>J(localStorage.getItem(KEY),{});
  const W=s=>{ s._touched=Date.now(); localStorage.setItem(KEY, JSON.stringify(s)); return s; };

  function getCookie(name){
    const m = document.cookie.match(new RegExp('(?:^|; )'+name.replace(/([.$?*|{}()\\[\\]\\\\/+^])/g,'\\\\$1')+'=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : '';
  }

  function setText(id, text){
    const el=$(id); if (!el) return;
    const v=String(text||'').trim();
    el.textContent = v ? v : '—';
  }
  function setMaybeLink(id, val){
    const el=$(id); if (!el) return;
    const v = (val||'').toString().trim();
    if (v && /^https?:\/\//i.test(v)) {
      el.innerHTML = '<a href="'+v.replace(/"/g,'&quot;')+'" target="_blank" rel="noopener">'+v+'</a>';
    } else {
      el.textContent = v || '—';
    }
  }
  function setIfMissing(id, val){
    const el=$(id); if (!el) return;
    if ((el.textContent||'').trim()==='—' && prefer(val)!=='') el.textContent = String(val);
  }
  function setIfMissingMaybeLink(id, val){
    const el=$(id); if (!el) return;
    const cur = (el.textContent||'').trim();
    if (cur && cur !== '—') return;
    setMaybeLink(id, val);
  }
  function firstUrlByKeyPart(partList){
    try {
      for (let i=0;i<localStorage.length;i++){
        const k = (localStorage.key(i)||'').toLowerCase();
        const v = localStorage.getItem(localStorage.key(i)) || '';
        if (!v) continue;
        if (partList.some(p => k.includes(p)) && /^https?:\/\//i.test(v.trim())) return v.trim();
      }
    } catch(_) {}
    return '';
  }

  // APIs
  async function fetchProfile({inspect, code, employee}){
    const u = new URL('/api/main-profile.php', location.origin);
    if (inspect) u.searchParams.set('inspect', inspect);
    if (code)    u.searchParams.set('code', code);
    if (employee)u.searchParams.set('employee', employee);
    const r = await fetch(u.toString(), { credentials:'include', cache:'no-store' });
    const t = await r.text();
    let j; try{ j=JSON.parse(t); } catch(_){ j = { raw:t }; }
    return { ok:r.ok, status:r.status, url:u.toString(), json:j, raw: r.ok?null:t, error: r.ok?null:('HTTP '+r.status) };
  }
  async function fetchInspection(inspect){
    if (!inspect) return { ok:false, status:0, url:null, json:null };
    const u = new URL('/api/inspections.php', location.origin);
    u.searchParams.set('action','get'); u.searchParams.set('inspect', inspect);
    const r = await fetch(u.toString(), { credentials:'include', cache:'no-store' });
    const t = await r.text();
    let j; try{ j=JSON.parse(t); } catch(_){ j = { raw:t }; }
    return { ok:r.ok, status:r.status, url:u.toString(), json:j, raw: r.ok?null:t, error: r.ok?null:('HTTP '+r.status) };
  }
  async function qrResolve(code){
    if (!code) return { ok:false, status:0, url:null, json:null };
    const u = new URL('/api/qr-resolve.php', location.origin);
    u.searchParams.set('code', code);
    const r = await fetch(u.toString(), { cache:'no-store' });
    const t = await r.text();
    let j; try{ j=JSON.parse(t); } catch(_){ j = { raw:t }; }
    return { ok:r.ok, status:r.status, url:u.toString(), json:j, raw: r.ok?null:t, error: r.ok?null:('HTTP '+r.status) };
  }

  function legacySnapshot(){
    const pick = k => localStorage.getItem(k);
    return {
      'ces.notes': pick('ces.notes'),
      'ces.photosWalkFolderUrl': pick('ces.photosWalkFolderUrl'),
      'ces.photosRepairFolderUrl': pick('ces.photosRepairFolderUrl'),
      'ces.close.needsRepair': pick('ces.close.needsRepair'),
      'ces.close.safeToOperate': pick('ces.close.safeToOperate'),
      'ces.close.repairDesc': pick('ces.close.repairDesc'),
      'ces.close.meterReading': pick('ces.close.meterReading'),
      'ces.inspect': pick('ces.inspect'),
      'ces.code': pick('ces.code')
    };
  }

  async function prefill(){
    const sp = new URLSearchParams(location.search);
    const qsInspect = (sp.get('inspect')||sp.get('inspection')||'').trim();
    const qsCode    = (sp.get('code')||'').trim().toUpperCase();

    const L = {
      inspect: localStorage.getItem('ces.inspect'),
      code:    localStorage.getItem('ces.code'),
      notes:   localStorage.getItem('ces.notes'),
      loc:     localStorage.getItem('ces.locationLink'),
      walk:    localStorage.getItem('ces.photosWalkFolderUrl'),
      repair:  localStorage.getItem('ces.photosRepairFolderUrl'),
      needs:   localStorage.getItem('ces.close.needsRepair'),
      safe:    localStorage.getItem('ces.close.safeToOperate'),
      mtr:     localStorage.getItem('ces.close.meterReading'),
      repdesc: localStorage.getItem('ces.close.repairDesc')
    };

    let S = R();
    if (!prefer(S.inspectId)) S.inspectId = prefer(qsInspect, L.inspect, S.inspectId);
    if (!prefer(S.code))      S.code      = prefer(qsCode,    L.code,    S.code);

    if (S.sopAgreed === undefined) {
      const sopLS = localStorage.getItem('ces.sopAgreed');
      if (sopLS!=null) S.sopAgreed = (sopLS==='true'||sopLS==='1');
    }
    S.notes                 = prefer(S.notes,                L.notes);
    S.locationLink          = prefer(S.locationLink,         L.loc);
    S.photosWalkFolderUrl   = prefer(S.photosWalkFolderUrl,  L.walk);
    S.photosRepairFolderUrl = prefer(S.photosRepairFolderUrl,L.repair);
    if (S.needsRepair === undefined && L.needs!=null) S.needsRepair = booly(L.needs);
    if (S.safeToOperate === undefined && L.safe!=null) S.safeToOperate = booly(L.safe);
    if (!prefer(S.meterReading) && L.mtr!=null) S.meterReading = L.mtr;
    if (!prefer(S.repairDesc) && L.repdesc!=null) S.repairDesc = L.repdesc;

    const cookieEmp = getCookie('employee_id');
    if (!prefer(S.employeeId) && cookieEmp) S.employeeId = cookieEmp;
    W(S);
    try {
      if (prefer(S.inspectId)) localStorage.setItem('ces.inspect', S.inspectId);
      if (prefer(S.code))      localStorage.setItem('ces.code',    S.code);
    } catch(_) {}

    // APIs
    const prof = await fetchProfile({inspect:S.inspectId||'', code:S.code||'', employee:S.employeeId||cookieEmp||''});
    const qr   = (!prof.json?.unit && S.code) ? await qrResolve(S.code) : { ok:false,status:0,url:null,json:null };
    const insp = await fetchInspection(S.inspectId||'');

    // Resolve
    const emp  = (prof.json && prof.json.employee) || {};
    const unit = (prof.json && prof.json.unit) || (qr.json && qr.json.unit) || {};
    const preferred = prefer(prof.json?.preferredName, emp.preferredName, emp.preferred_name, emp.per_name, S.preferredName);

    S.employeeId    = prefer(S.employeeId,   emp.employeeId, emp.employee_id, cookieEmp);
    S.employeeName  = prefer(S.employeeName, emp.name, emp.employeeName);
    S.preferredName = prefer(S.preferredName, preferred);
    S.email         = prefer(S.email,        prof.json?.email, emp.email);
    S.phone         = prefer(S.phone,        prof.json?.phone, emp.phone);

    S.unitId           = prefer(S.unitId,           unit.unitId, unit.id);
    S.displayedUnitId  = prefer(S.displayedUnitId,  unit.displayId, unit.displayedUnitId, unit.display_id);
    S.unitCategory     = prefer(S.unitCategory,     unit.unitCategory, unit.category);
    S.unitType         = prefer(S.unitType,         unit.unitType, unit.unit_type);
    S.sFormNum         = prefer(S.sFormNum,         unit.sFormNum, unit.s_form_num);
    S.jobNumber        = prefer(S.jobNumber,        prof.json?.jobNumber, S.jobNumber);

    if (insp.ok && insp.json) {
      const d = insp.json.data || insp.json;
      S.photosWalkFolderUrl   = prefer(S.photosWalkFolderUrl,   d.photosWalkFolderUrl,   d.walkFolderUrl);
      S.photosRepairFolderUrl = prefer(S.photosRepairFolderUrl, d.photosRepairFolderUrl, d.repairFolderUrl);
      S.meterReading          = prefer(S.meterReading,          d.meterReading, d.meter);
      if (S.needsRepair === undefined && d.needsRepair != null)     S.needsRepair = booly(d.needsRepair);
      if (S.safeToOperate === undefined && d.safeToOperate != null) S.safeToOperate = booly(d.safeToOperate);
      S.repairDesc            = prefer(S.repairDesc, d.repairDesc, d.repairDescription);
      S.locationLink          = prefer(S.locationLink, d.locationLink);
      S.notes                 = prefer(S.notes, d.notes);
    }
    W(S);

    // Paint
    setText('v-employee',  prefer(S.employeeName, S.employeeId, '—'));
    setText('v-preferred', prefer(S.preferredName, '—'));
    setText('v-email',     prefer(S.email, '—'));
    setText('v-phone',     prefer(S.phone, '—'));

    setText('v-unit',      prefer(S.unitId, '—'));
    setText('v-display',   prefer(S.displayedUnitId, '—'));
    setText('v-form',      prefer(S.sFormNum, '—'));            // <-- FIXED ID
    setText('v-job',       prefer(S.jobNumber, '—'));

    const cat = prefer(S.unitCategory, '');
    const typ = prefer(S.unitType, '');
    setText('v-catType', (cat||'—') + ' / ' + (typ||'—'));

    setText('v-location',  prefer(S.locationLink, '—'));
    setText('v-notes',     prefer(S.notes, '—'));
    setText('v-sop',       booly(S.sopAgreed) ? 'Yes' : 'No');

    setText('v-meter',     prefer(S.meterReading, '—'));
    setText('v-needs',     S.needsRepair==null ? '—' : (booly(S.needsRepair)?'Yes':'No'));
    setText('v-safe',      S.safeToOperate==null ? '—' : (booly(S.safeToOperate)?'Yes':'No'));
    setText('v-repair',    prefer(S.repairDesc, '—'));

    // Photos: prefer CESState; else scan legacy keys; then linkify
    let walk   = prefer(S.photosWalkFolderUrl, '');
    let repair = prefer(S.photosRepairFolderUrl, '');
    if (!walk)   walk   = firstUrlByKeyPart(['walk','360','round','panorama']);
    if (!repair) repair = firstUrlByKeyPart(['repair','fix','maintenance']);
    setIfMissingMaybeLink('v-walk',         walk);
    setIfMissingMaybeLink('v-repairPhotos', repair);

    // DIAG (write into existing IDs; all guarded)
    const diag = {
      urlParams: { inspect: qsInspect, code: qsCode, employee: S.employeeId || getCookie('employee_id') || '' },
      cookies: { PHPSESSID: getCookie('PHPSESSID') || '', employee_id: getCookie('employee_id') || '' },
      api: { main_profile: prof, qr_resolve: qr, inspection: insp },
      resolved: {
        inspectId: prefer(S.inspectId,''), code: prefer(S.code,''), employeeId: prefer(S.employeeId,''), employeeName: prefer(S.employeeName,''),
        preferredName: prefer(S.preferredName,''), email: prefer(S.email,''), phone: prefer(S.phone,''),
        unitId: prefer(S.unitId,''), displayedUnitId: prefer(S.displayedUnitId,''), unitCategory: prefer(S.unitCategory,''), unitType: prefer(S.unitType,''),
        sFormNum: prefer(S.sFormNum,''), jobNumber: prefer(S.jobNumber,''), notes: prefer(S.notes,''), locationLink: prefer(S.locationLink,''),
        photosWalkFolderUrl: prefer(walk,''), photosRepairFolderUrl: prefer(repair,''), meterReading: prefer(S.meterReading,''),
        needsRepair: S.needsRepair??'', safeToOperate: S.safeToOperate??'', repairDesc: prefer(S.repairDesc,'')
      },
      version: VERSION, ts: new Date().toISOString()
    };
    const dRes=$('diagResolved'); if (dRes) dRes.textContent = JSON.stringify(diag, null, 2);
    const dCES=$('diagCES');      if (dCES) dCES.textContent = JSON.stringify(R(), null, 2);
    const dLoc=$('diagLocal');    if (dLoc) dLoc.textContent = JSON.stringify(legacySnapshot(), null, 2);
  }

  function bindUI(){
    const printBtn   = $('btn-print');
    const emailBtn   = $('btn-email');
    const finBtn     = $('btn-finalize');
    const diagBtn    = $('btnDiag');
    const diagBox    = $('diag');
    const diagClose  = $('btnCloseDiag');
    const diagRefresh= $('btnRefresh');

    printBtn && printBtn.addEventListener('click', ()=> window.print());
    emailBtn && emailBtn.addEventListener('click', async ()=>{
      const msg=$('msg'); if (msg) msg.textContent='Generating & emailing PDF…';
      try{
        const s = R();
        const r = await fetch('/api/inspections.php?action=email_pdf', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ inspect: s.inspectId||'', code: s.code||'' })
        });
        if (msg) msg.textContent = r.ok ? 'Email sent (staging stub).' : ('Email failed: HTTP '+r.status);
      }catch(e){ if (msg) msg.textContent = 'Email failed: '+e; }
    });
    finBtn && finBtn.addEventListener('click', async ()=>{
      const msg=$('msg'); if (msg) msg.innerHTML='<span class="spin"></span> Finalizing…';
      try{
        const s = R();
        const r = await fetch('/api/inspections.php?action=finalize', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({
            inspect: s.inspectId||'', code: s.code||'', unitId: s.unitId||'', employeeId: s.employeeId||'',
            needsRepair: !!s.needsRepair, safeToOperate: !!s.safeToOperate,
            meterReading: s.meterReading||'', notes: s.notes||'',
            photosWalkFolderUrl: s.photosWalkFolderUrl||'', photosRepairFolderUrl: s.photosRepairFolderUrl||''
          })
        });
        if (msg) msg.textContent = r.ok ? 'Finalized (staging stub).' : ('Finalize failed: HTTP '+r.status);
      }catch(e){ if (msg) msg.textContent = 'Finalize failed: '+e; }
    });

    diagBtn    && diagBtn.addEventListener('click', ()=>{ if (diagBox) diagBox.style.display = (diagBox.style.display==='block'?'none':'block'); });
    diagClose  && diagClose.addEventListener('click', ()=>{ if (diagBox) diagBox.style.display='none'; });
    diagRefresh&& diagRefresh.addEventListener('click', prefill);
  }

  document.addEventListener('DOMContentLoaded', () => { bindUI(); prefill(); });
})();
