// summary-v9.js — compact, warning-free, CSP-friendly client logic
// Version: summary-2025-10-09-v9

(function () {
  'use strict';

  var VERSION = 'summary-2025-10-09-v9';

  // ---------- tiny utils ----------
  function $(id) { return document.getElementById(id); }
  function isNonEmpty(v) { return v !== null && v !== undefined && String(v).trim() !== ''; }
  function prefer() {
    for (var i = 0; i < arguments.length; i++) {
      if (isNonEmpty(arguments[i])) return arguments[i];
    }
    return '';
  }
  function booly(v) {
    var s = String(v).toLowerCase();
    return v === true || v === 1 || s === '1' || s === 'true' || s === 'yes';
  }
  function getCookie(name) {
    var all = document.cookie || '';
    if (!all) return '';
    var parts = all.split(/;\s*/);
    for (var i = 0; i < parts.length; i++) {
      var row = parts[i];
      var eq = row.indexOf('=');
      if (eq === -1) continue;
      var k = row.slice(0, eq);
      if (k === name) return decodeURIComponent(row.slice(eq + 1));
    }
    return '';
  }
  function safeJSON(text) {
    try { return JSON.parse(text); } catch (_) { return null; }
  }

  // ---------- local state ----------
  var LS_KEY = 'CESState';
  function getState() {
    var raw = localStorage.getItem(LS_KEY);
    return raw ? (safeJSON(raw) || {}) : {};
  }
  function saveState(s) {
    try {
      s._touched = Date.now();
      localStorage.setItem(LS_KEY, JSON.stringify(s));
    } catch (_) {}
    return s;
  }

  // ---------- DOM fill helpers ----------
  function setText(id, val) {
    var el = $(id); if (!el) return;
    var v = isNonEmpty(val) ? String(val).trim() : '';
    el.textContent = v || '—';
  }
  function setIfMissingText(id, val) {
    var el = $(id); if (!el) return;
    if ((el.textContent || '').trim() === '—' && isNonEmpty(val)) el.textContent = String(val);
  }
  function setMaybeLink(id, val) {
    var el = $(id); if (!el) return;
    var v = isNonEmpty(val) ? String(val).trim() : '';
    if (/^https?:\/\//i.test(v)) {
      // build anchor node (no innerHTML)
      while (el.firstChild) el.removeChild(el.firstChild);
      var a = document.createElement('a');
      a.href = v; a.target = '_blank'; a.rel = 'noopener';
      a.textContent = v;
      el.appendChild(a);
    } else {
      el.textContent = v || '—';
    }
  }
  function setIfMissingMaybeLink(id, val) {
    var el = $(id); if (!el) return;
    if ((el.textContent || '').trim() !== '—') return;
    setMaybeLink(id, val);
  }
  function firstUrlByKeyPart(parts) {
    try {
      for (var i = 0; i < localStorage.length; i++) {
        var k = localStorage.key(i) || '';
        var v = localStorage.getItem(k) || '';
        if (!v) continue;
        var kl = k.toLowerCase();
        var hit = false;
        for (var p = 0; p < parts.length; p++) { if (kl.indexOf(parts[p]) !== -1) { hit = true; break; } }
        if (hit && /^https?:\/\//i.test(v.trim())) return v.trim();
      }
    } catch (_) {}
    return '';
  }

  // ---------- API wrappers (quiet; no console noise) ----------
  function apiGet(url) {
    return fetch(url, { credentials: 'include', cache: 'no-store' })
      .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, status: r.status, text: t }; }); })
      .catch(function () { return { ok: false, status: 0, text: '' }; });
  }
  function fetchProfile(params) {
    var u = new URL('/api/main-profile.php', location.origin);
    if (isNonEmpty(params.inspect))  u.searchParams.set('inspect', params.inspect);
    if (isNonEmpty(params.code))     u.searchParams.set('code', params.code);
    if (isNonEmpty(params.employee)) u.searchParams.set('employee', params.employee);
    return apiGet(u.toString()).then(function (res) {
      return { ok: res.ok, status: res.status, url: u.toString(), json: safeJSON(res.text) || {}, raw: res.ok ? null : res.text };
    });
  }
  function fetchInspection(inspect) {
    if (!isNonEmpty(inspect)) return Promise.resolve({ ok:false, status:0, url:null, json:null });
    var u = new URL('/api/inspections.php', location.origin);
    u.searchParams.set('action', 'get'); u.searchParams.set('inspect', inspect);
    return apiGet(u.toString()).then(function (res) {
      return { ok: res.ok, status: res.status, url: u.toString(), json: safeJSON(res.text) || {}, raw: res.ok ? null : res.text };
    });
  }
  function qrResolve(code) {
    if (!isNonEmpty(code)) return Promise.resolve({ ok:false, status:0, url:null, json:null });
    var u = new URL('/api/qr-resolve.php', location.origin);
    u.searchParams.set('code', code);
    return apiGet(u.toString()).then(function (res) {
      return { ok: res.ok, status: res.status, url: u.toString(), json: safeJSON(res.text) || {}, raw: res.ok ? null : res.text };
    });
  }

  // ---------- DIAG payloads (no console output) ----------
  function legacySnapshot() {
    var o = {};
    var keys = [
      'ces.notes','ces.photosWalkFolderUrl','ces.photosRepairFolderUrl',
      'ces.close.needsRepair','ces.close.safeToOperate','ces.close.repairDesc','ces.close.meterReading',
      'ces.inspect','ces.code'
    ];
    for (var i = 0; i < keys.length; i++) o[keys[i]] = localStorage.getItem(keys[i]);
    return o;
  }

  // ---------- main prefill / paint ----------
  function prefill() {
    var sp = new URLSearchParams(location.search);
    var qsInspect = (sp.get('inspect') || sp.get('inspection') || '').trim();
    var qsCode    = (sp.get('code') || '').trim().toUpperCase();

    var L = {
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

    var S = getState();
    if (!isNonEmpty(S.inspectId)) S.inspectId = prefer(qsInspect, L.inspect, S.inspectId);
    if (!isNonEmpty(S.code))      S.code      = prefer(qsCode,    L.code,    S.code);

    if (S.sopAgreed === undefined) {
      var sopLS = localStorage.getItem('ces.sopAgreed');
      if (sopLS !== null) S.sopAgreed = (sopLS === 'true' || sopLS === '1');
    }
    S.notes                 = prefer(S.notes,                L.notes);
    S.locationLink          = prefer(S.locationLink,         L.loc);
    S.photosWalkFolderUrl   = prefer(S.photosWalkFolderUrl,  L.walk);
    S.photosRepairFolderUrl = prefer(S.photosRepairFolderUrl,L.repair);
    if (S.needsRepair === undefined && L.needs !== null) S.needsRepair = booly(L.needs);
    if (S.safeToOperate === undefined && L.safe !== null) S.safeToOperate = booly(L.safe);
    if (!isNonEmpty(S.meterReading) && L.mtr !== null) S.meterReading = L.mtr;
    if (!isNonEmpty(S.repairDesc) && L.repdesc !== null) S.repairDesc = L.repdesc;

    var cookieEmp = getCookie('employee_id');
    if (!isNonEmpty(S.employeeId) && isNonEmpty(cookieEmp)) S.employeeId = cookieEmp;

    saveState(S);
    try {
      if (isNonEmpty(S.inspectId)) localStorage.setItem('ces.inspect', S.inspectId);
      if (isNonEmpty(S.code))      localStorage.setItem('ces.code',    S.code);
    } catch (_) {}

    // sequence calls
    fetchProfile({ inspect: S.inspectId || '', code: S.code || '', employee: S.employeeId || cookieEmp || '' })
      .then(function (prof) {
        var needQR = (!prof.json || !prof.json.unit) && isNonEmpty(S.code);
        var qrP    = needQR ? qrResolve(S.code) : Promise.resolve({ ok:false, status:0, url:null, json:null });
        var inspP  = fetchInspection(S.inspectId || '');
        return Promise.all([Promise.resolve(prof), qrP, inspP]);
      })
      .then(function (arr) {
        var prof = arr[0], qr = arr[1], insp = arr[2];

        var emp  = (prof.json && prof.json.employee) ? prof.json.employee : {};
        var unit = (prof.json && prof.json.unit) ? prof.json.unit : ((qr.json && qr.json.unit) ? qr.json.unit : {});

        var preferred = prefer(
          prof.json && prof.json.preferredName,
          emp.preferredName, emp.preferred_name, emp.per_name, S.preferredName
        );

        S.employeeId    = prefer(S.employeeId,   emp.employeeId, emp.employee_id, cookieEmp);
        S.employeeName  = prefer(S.employeeName, emp.name, emp.employeeName);
        S.preferredName = prefer(S.preferredName, preferred);
        S.email         = prefer(S.email, (prof.json && prof.json.email), emp.email);
        S.phone         = prefer(S.phone, (prof.json && prof.json.phone), emp.phone);

        S.unitId           = prefer(S.unitId,           unit.unitId, unit.id);
        S.displayedUnitId  = prefer(S.displayedUnitId,  unit.displayId, unit.displayedUnitId, unit.display_id);
        S.unitCategory     = prefer(S.unitCategory,     unit.unitCategory, unit.category);
        S.unitType         = prefer(S.unitType,         unit.unitType, unit.unit_type);
        S.sFormNum         = prefer(S.sFormNum,         unit.sFormNum, unit.s_form_num);
        S.jobNumber        = prefer(S.jobNumber,        (prof.json && prof.json.jobNumber), S.jobNumber);

        if (insp && insp.ok && insp.json) {
          var d = insp.json.data || insp.json;
          S.photosWalkFolderUrl   = prefer(S.photosWalkFolderUrl,   d.photosWalkFolderUrl,   d.walkFolderUrl);
          S.photosRepairFolderUrl = prefer(S.photosRepairFolderUrl, d.photosRepairFolderUrl, d.repairFolderUrl);
          S.meterReading          = prefer(S.meterReading,          d.meterReading, d.meter);
          if (S.needsRepair === undefined && d.needsRepair !== undefined && d.needsRepair !== null) S.needsRepair = booly(d.needsRepair);
          if (S.safeToOperate === undefined && d.safeToOperate !== undefined && d.safeToOperate !== null) S.safeToOperate = booly(d.safeToOperate);
          S.repairDesc            = prefer(S.repairDesc, d.repairDesc, d.repairDescription);
          S.locationLink          = prefer(S.locationLink, d.locationLink);
          S.notes                 = prefer(S.notes, d.notes);
        }

        saveState(S);

        // Paint
        setText('v-employee',  prefer(S.employeeName, S.employeeId, '—'));
        setText('v-preferred', prefer(S.preferredName, '—'));
        setText('v-email',     prefer(S.email, '—'));
        setText('v-phone',     prefer(S.phone, '—'));

        setText('v-unit',      prefer(S.unitId, '—'));
        setText('v-display',   prefer(S.displayedUnitId, '—'));
        setText('v-form',      prefer(S.sFormNum, '—'));
        setText('v-job',       prefer(S.jobNumber, '—'));

        var cat = prefer(S.unitCategory, '');
        var typ = prefer(S.unitType, '');
        setText('v-catType', (cat || '—') + ' / ' + (typ || '—'));

        setText('v-location',  prefer(S.locationLink, '—'));
        setText('v-notes',     prefer(S.notes, '—'));
        setText('v-sop',       booly(S.sopAgreed) ? 'Yes' : 'No');

        setText('v-meter',     prefer(S.meterReading, '—'));
        setText('v-needs',     S.needsRepair === undefined || S.needsRepair === null ? '—' : (booly(S.needsRepair) ? 'Yes' : 'No'));
        setText('v-safe',      S.safeToOperate === undefined || S.safeToOperate === null ? '—' : (booly(S.safeToOperate) ? 'Yes' : 'No'));
        setText('v-repair',    prefer(S.repairDesc, '—'));

        var walk = prefer(S.photosWalkFolderUrl, '');
        var rep  = prefer(S.photosRepairFolderUrl, '');
        if (!isNonEmpty(walk)) walk = firstUrlByKeyPart(['walk', '360', 'round', 'panorama']);
        if (!isNonEmpty(rep))  rep  = firstUrlByKeyPart(['repair', 'fix', 'maintenance']);
        setIfMissingMaybeLink('v-walk',         walk);
        setIfMissingMaybeLink('v-repairPhotos', rep);

        // DIAG
        var diag = {
          urlParams: { inspect: qsInspect, code: qsCode, employee: S.employeeId || getCookie('employee_id') || '' },
          cookies: { PHPSESSID: getCookie('PHPSESSID') || '', employee_id: getCookie('employee_id') || '' },
          api: {}, // keep minimal to avoid huge blobs; still show core statuses:
          resolved: {
            inspectId: prefer(S.inspectId,''), code: prefer(S.code,''), employeeId: prefer(S.employeeId,''), employeeName: prefer(S.employeeName,''),
            preferredName: prefer(S.preferredName,''), email: prefer(S.email,''), phone: prefer(S.phone,''),
            unitId: prefer(S.unitId,''), displayedUnitId: prefer(S.displayedUnitId,''), unitCategory: prefer(S.unitCategory,''), unitType: prefer(S.unitType,''),
            sFormNum: prefer(S.sFormNum,''), jobNumber: prefer(S.jobNumber,''), notes: prefer(S.notes,''), locationLink: prefer(S.locationLink,''),
            photosWalkFolderUrl: prefer(walk,''), photosRepairFolderUrl: prefer(rep,''), meterReading: prefer(S.meterReading,''),
            needsRepair: S.needsRepair === undefined ? '' : !!S.needsRepair, safeToOperate: S.safeToOperate === undefined ? '' : !!S.safeToOperate,
            repairDesc: prefer(S.repairDesc,'')
          },
          version: VERSION,
          ts: new Date().toISOString()
        };
        var dRes = $('diagResolved'); if (dRes) dRes.textContent = JSON.stringify(diag, null, 2);
        var dCES = $('diagCES');      if (dCES) dCES.textContent = JSON.stringify(getState(), null, 2);
        var dLoc = $('diagLocal');    if (dLoc) dLoc.textContent = JSON.stringify(legacySnapshot(), null, 2);
      });
  }

  // ---------- actions ----------
  function wireActions() {
    var btnPrint = $('btn-print');
    var btnEmail = $('btn-email');
    var btnFin   = $('btn-finalize');
    var btnDiag  = $('btnDiag');
    var boxDiag  = $('diag');
    var btnClose = $('btnCloseDiag');
    var btnRef   = $('btnRefresh');
    var msg      = $('msg');

    if (btnPrint) btnPrint.addEventListener('click', function(){ window.print(); });

    if (btnEmail) btnEmail.addEventListener('click', function () {
      if (msg) msg.textContent = 'Generating & emailing PDF…';
      var s = getState();
      fetch('/api/inspections.php?action=email_pdf', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ inspect: s.inspectId || '', code: s.code || '' })
      }).then(function (r) {
        if (msg) msg.textContent = r.ok ? 'Email sent (staging stub).' : ('Email failed: HTTP ' + r.status);
      }).catch(function (e) {
        if (msg) msg.textContent = 'Email failed: ' + (e && e.message ? e.message : e);
      });
    });

    if (btnFin) btnFin.addEventListener('click', function () {
      if (msg) msg.innerHTML = '<span class="spin"></span> Finalizing…';
      var s = getState();
      fetch('/api/inspections.php?action=finalize', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          inspect: s.inspectId || '', code: s.code || '', unitId: s.unitId || '', employeeId: s.employeeId || '',
          needsRepair: !!s.needsRepair, safeToOperate: !!s.safeToOperate,
          meterReading: s.meterReading || '', notes: s.notes || '',
          photosWalkFolderUrl: s.photosWalkFolderUrl || '', photosRepairFolderUrl: s.photosRepairFolderUrl || ''
        })
      }).then(function (r) {
        if (msg) msg.textContent = r.ok ? 'Finalized (staging stub).' : ('Finalize failed: HTTP ' + r.status);
      }).catch(function (e) {
        if (msg) msg.textContent = 'Finalize failed: ' + (e && e.message ? e.message : e);
      });
    });

    if (btnDiag && boxDiag) btnDiag.addEventListener('click', function () {
      boxDiag.style.display = (boxDiag.style.display === 'block') ? 'none' : 'block';
    });
    if (btnClose && boxDiag) btnClose.addEventListener('click', function () { boxDiag.style.display = 'none'; });
    if (btnRef) btnRef.addEventListener('click', prefill);
  }

  // ---------- boot ----------
  document.addEventListener('DOMContentLoaded', function () {
    wireActions();
    prefill();
  });

})();
