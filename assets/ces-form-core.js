/* ces-form-core.js - CES shared logic (ES5 only, strict null checks)
   Exposes: window.CESForm.{bootstrapMain}
   Dependencies: none (uses localStorage, XMLHttpRequest, geolocation)
*/
(function (global) {
  'use strict';

  var CESForm = {}; // namespace

  /* ---------------- Utilities ---------------- */

  function parseQuery() {
    var out = {};
    var qs = (location.search || '').replace(/^\?/, '');
    if (!qs) return out;
    var parts = qs.split('&');
    for (var i = 0; i < parts.length; i++) {
      if (!parts[i]) continue;
      var kv = parts[i].split('=');
      var k = decodeURIComponent(kv[0] || '').trim();
      var v = decodeURIComponent(kv.slice(1).join('=') || '').trim();
      if (k) out[k] = v;
    }
    return out;
  }
  var QS = parseQuery();

  // --- Strict CESState IO (no legacy mirrors) ---
  function readCES() {
    try { return JSON.parse(localStorage.getItem('CESState') || '{}'); }
    catch (_){ return {}; }
  }
  function writeCES(patch) {
    var cur = readCES();
    var next = {};
    var k;
    for (k in cur) if (Object.prototype.hasOwnProperty.call(cur,k)) next[k]=cur[k];
    for (k in (patch||{})) if (Object.prototype.hasOwnProperty.call(patch,k)) next[k]=patch[k];
    try {
      next._touched = Date.now();
      localStorage.setItem('CESState', JSON.stringify(next));
    } catch (_){}
    return next;
  }

  function ajaxGet(url, cb) {
    try {
      var xhr = new XMLHttpRequest();
      xhr.open('GET', url, true);
      xhr.withCredentials = true;
      xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
          var ok = (xhr.status >= 200 && xhr.status < 300);
          var json = null;
          try { json = JSON.parse(xhr.responseText || 'null'); } catch (e) {}
          cb(ok, json, xhr);
        }
      };
      xhr.send(null);
    } catch (e) { cb(false, null, null); }
  }

  function ajaxPostJSON(url, payload, cb) {
    try {
      var data = JSON.stringify(payload || {});
      var xhr = new XMLHttpRequest();
      xhr.open('POST', url, true);
      xhr.withCredentials = true;
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
          var ok = (xhr.status >= 200 && xhr.status < 300);
          var json = null;
          try { json = JSON.parse(xhr.responseText || 'null'); } catch (e) {}
          cb(ok, json, xhr);
        }
      };
      xhr.send(data);
    } catch (e) { cb(false, null, null); }
  }

  function $(sel, root) { return (root || document).querySelector(sel); }
  function on(el, ev, fn) { if (el && el.addEventListener) el.addEventListener(ev, fn); }
  function setText(el, txt) { if (el) el.textContent = txt; }
  function setHTML(el, html) { if (el) el.innerHTML = html; }

  /* ---------- Context (CESState-first, no legacy fallbacks) ---------- */
  function getCtx() {
    var st = readCES();

    // If verified=1 in URL, mark SOP agreed so we don't bounce
    if (QS.verified === '1' && !st.sopAgreed) {
      st = writeCES({ sopAgreed: true }) || st;
    }

    return {
      // IMPORTANT: inspectId is ONLY from CESState (main.php seeds CESState from URL if needed)
      inspectId: st.inspectId || '',
      code: String(st.code || '').toUpperCase(),
      flow: (localStorage.getItem('ces.noqr') === '1') ? 'rental' : 'qr',
      unitId: st.unitId || '',
      unitCategory: st.unitCategory || '',
      unitType: st.unitType || '',
      // Identity strictly from CESState
      employeeId: st.employeeId || '',
      employeeName: st.employeeName || '',
      sopAgreed: !!st.sopAgreed
    };
  }

  /* ---------------- Gating (QR) ----------------
     NOTE: can be disabled via opts.disableGate === true
  */
  function gateMainQR(routes, requireSOP) {
    routes = routes || {};
    var verifiedQS  = (QS.verified === '1');
    try { localStorage.setItem('ces.noqr', '0'); } catch (e) {}

    var ctx = getCtx();
    var codeParam = ctx.code;

    // No code (or explicit noqr) -> go to nocode
    if (!codeParam || codeParam === 'NOCODE' || QS.noqr === '1') {
      var noc = routes.nocode || '/nocode-main.php';
      var to = noc + (ctx.inspectId ? ('?inspect=' + encodeURIComponent(ctx.inspectId) + '&noqr=1') : '?noqr=1');
      location.replace(to);
      return false;
    }

    // Must have inspectId minted server-side
    if (!ctx.inspectId) {
      location.replace((routes.start || '/start.php') + '?code=' + encodeURIComponent(codeParam));
      return false;
    }

    // If verified in URL, trust it and do not bounce (server handoff)
    if (verifiedQS) return true;

    // Legacy enforcement path (kept): soft gate if no employee or SOP
    if (!ctx.employeeId || !ctx.employeeName) {
      location.replace((routes.verify || '/employee-verify.php') +
        '?inspect=' + encodeURIComponent(ctx.inspectId) +
        '&code=' + encodeURIComponent(codeParam));
      return false;
    }
    if (requireSOP && !ctx.sopAgreed) {
      location.replace((routes.sop || '/sop.php') +
        '?inspect=' + encodeURIComponent(ctx.inspectId) +
        '&code=' + encodeURIComponent(codeParam));
      return false;
    }
    return true;
  }

  /* ---------------- GPS ---------------- */

  function gpsOnce(timeoutMs, cb) {
    if (!navigator.geolocation) {
      cb({ ok: false, error: 'Geolocation not supported' });
      return;
    }
    navigator.geolocation.getCurrentPosition(
      function (pos) {
        var accVal = (typeof pos.coords.accuracy === 'number') ? +pos.coords.accuracy.toFixed(1) : null;
        cb({
          ok: true,
          lat: +pos.coords.latitude.toFixed(6),
          lon: +pos.coords.longitude.toFixed(6),
          acc: accVal
        });
      },
      function (err) {
        cb({ ok: false, error: (err && err.message) ? err.message : 'Location unavailable' });
      },
      { enableHighAccuracy: true, timeout: timeoutMs || 12000, maximumAge: 0 }
    );
  }

  /* ---------------- Prefill (API-only for identity fields) ---------------- */
  function prefillMain(endpoints, sel, ctx, done) {
    if (sel.pre) setHTML(sel.pre, '<span class="spin"></span> Loading profile from database...');

    var url = (endpoints.prefill || '/api/main-profile.php') +
      '?inspect=' + encodeURIComponent(ctx.inspectId || '') +
      '&employee=' + encodeURIComponent(ctx.employeeId || '') +
      '&unit=' + encodeURIComponent(ctx.unitId || '');

    ajaxGet(url, function (ok, json) {
      var e = (ok && json && json.employee) ? json.employee : {};
      var u = (ok && json && json.unit) ? json.unit : {};

      // Prefer flat fields when provided by API (we added them)
      var flat = json || {};

      // Context header
      if (sel.inspectId) sel.inspectId.value = ctx.inspectId || '';
      if (sel.code)      sel.code.value      = ctx.code || '';
      if (sel.unitId)    sel.unitId.value    = (flat.unitId || ctx.unitId || '');

      // Unit (flat first; then nested; then ctx)
      if (sel.unitCategory) sel.unitCategory.value = (flat.unitCategory || u.unitCategory || ctx.unitCategory || '');
      if (sel.unitType)     sel.unitType.value     = (flat.unitType     || u.unitType     || ctx.unitType     || '');

      // Employee display line: from CESState (ctx)
      if (sel.employee) {
        var disp = (ctx.employeeName ? (ctx.employeeName + ' \u2022 ') : '') + (ctx.employeeId || '');
        sel.employee.value = disp.trim();
      }

      // *** STRICT: these must come from API only (or remain blank) ***
      var apiPref  = flat.preferredName || e.preferredName || e.preferred_name || e.per_name || '';
      var apiEmail = flat.email || e.email || '';
      var apiPhone = flat.phone || e.phone || '';

      if (sel.preferredName) sel.preferredName.value = apiPref;
      if (sel.email)         sel.email.value         = apiEmail;
      if (sel.phone)         sel.phone.value         = apiPhone;

      // Displayed Unit ID & Job Number from API flat first
      if (sel.displayedUnitId) sel.displayedUnitId.value = (flat.displayedUnitId || u.displayedUnitId || '');
      if (sel.jobNumber)       sel.jobNumber.value       = (flat.jobNumber || '');

      if (sel.pre)  sel.pre.style.display  = 'none';
      if (sel.form) sel.form.style.display = '';
      if (done) done(true);
    });
  }

  /* ---------------- GPS UI ---------------- */

  function initGPSUI(opts, sel, done) {
    var REQUIRE_LOCATION = !!opts.requireLocation;

    function setBanner(text, cls) {
      if (!sel.gpsRow) return;
      sel.gpsRow.className = (cls || 'muted');
      setHTML(sel.gpsRow, text);
    }
    function showLink(link) {
      if (sel.mapLink) { sel.mapLink.href = link; sel.mapLink.style.display = ''; }
    }
    function warn(msg) {
      if (sel.gpsWarn) { setText(sel.gpsWarn, msg); sel.gpsWarn.style.display = ''; }
      if (sel.btnGPS)  sel.btnGPS.style.display = '';
    }

    function request() {
      setBanner('<span class="spin"></span> Getting current location...', 'muted');
      gpsOnce(opts.gpsTimeoutMs || 12000, function (r) {
        if (r.ok) {
          var accStr = ((r.acc !== null) && (typeof r.acc !== 'undefined')) ? ' (\u00B1' + r.acc + 'm)' : '';
          var link = 'https://maps.google.com/?q=' + r.lat + ',' + r.lon;
          writeCES({ locationLink: link, gpsLat: r.lat, gpsLon: r.lon, gpsAcc: r.acc, gpsTs: Date.now() });
          setBanner('Location: ' + r.lat + ', ' + r.lon + accStr, 'ok');
          showLink(link);
          if (sel.gpsWarn) sel.gpsWarn.style.display = 'none';
          if (sel.btnGPS)  sel.btnGPS.style.display  = 'none';
          if (done) done(true);
        } else {
          if (REQUIRE_LOCATION) {
            warn('Location is required. ' + (r.error || 'Enable location and tap Retry.'));
            setBanner('Waiting for location...', 'warn');
          } else {
            warn('Location not available: ' + (r.error || ''));
            setBanner('Proceeding without location.', 'warn');
          }
          if (done) done(false);
        }
      });
    }

    if (sel.btnGPS) on(sel.btnGPS, 'click', request);
    request();
  }

  /* ---------------- Save Main ---------------- */

  function sanitizePhone(s) { return String(s || '').replace(/[^\d+]/g, ''); }

  function saveMain(opts, sel, ctx) {
    function val(id) { return sel[id] && (sel[id].value || '').trim(); }
    function setErr(msg) { if (sel.err) { sel.err.style.display = ''; setText(sel.err, msg); } }
    function clearErr() { if (sel.err) { sel.err.style.display = 'none'; setText(sel.err, ''); } }
    function step(t) { if (sel.msg) setHTML(sel.msg, '<span class="spin"></span> ' + t); }
    function clearStep() { if (sel.msg) setText(sel.msg, ''); }

    var required = (opts.required || []);
    for (var i = 0; i < required.length; i++) {
      var v = val(required[i]);
      if (!v) { setErr('Fill all required fields.'); return; }
    }
    if (opts.requireLocation) {
      var st = readCES();
      if ((typeof st.gpsLat === 'undefined' || st.gpsLat === null) ||
          (typeof st.gpsLon === 'undefined' || st.gpsLon === null)) {
        setErr('Location is required. Please allow location and tap Retry.');
        return;
      }
    }

    var payload = {
      inspectId: ctx.inspectId,
      code: ctx.code || '',
      employeeId: ctx.employeeId,
      preferredName: val('preferredName'),
      email: val('email'),
      phone: sanitizePhone(val('phone')),
      unitId: ctx.unitId,
      displayedUnitId: val('displayedUnitId'),
      jobNumber: val('jobNumber'),
      locationLink: (readCES().locationLink || ''),
      notes: val('notes') || ''
    };

    step('Saving to database...');
    ajaxPostJSON((opts.endpoints && opts.endpoints.save) || '/api/main-profile.php', payload, function (ok, json, xhr) {
      if (!ok || !json || json.ok !== true) {
        setErr('Save error: ' + (json && json.error ? json.error : ('HTTP ' + (xhr ? xhr.status : '0'))));
        clearStep();
        return;
      }

      // Persist into CESState ONLY (no legacy mirrors)
      try {
        writeCES({
          preferredName: payload.preferredName,
          email: payload.email,
          phone: payload.phone,
          displayedUnitId: payload.displayedUnitId,
          locationLink: payload.locationLink,
          jobNumber: payload.jobNumber,
          notes: payload.notes
        });
      } catch (_){}

      // summary upsert (best-effort)
      ajaxPostJSON(((opts.endpoints && opts.endpoints.upsert) || '/api/inspections.php?action=upsert'), {
        source: 'main.php',
        stage: 'main_saved',
        inspectId: ctx.inspectId,
        code: ctx.code,
        employeeId: ctx.employeeId,
        unitId: ctx.unitId,
        locationLink: payload.locationLink,
        flow: 'qr'
      }, function(){});

      if (sel.msg) setHTML(sel.msg, '<span class="ok">Saved.</span> Opening 360 Walk Photos...');
      var next = (opts.routes && opts.routes.next) || '/360walkphotos.html';
      // It’s OK to include query params; 360 page will ignore them if CESState is already set
      var url = next + '?inspect=' + encodeURIComponent(ctx.inspectId) + (ctx.code ? ('&code=' + encodeURIComponent(ctx.code)) : '');
      setTimeout(function(){ location.replace(url); }, 200);
    });
  }

  /* ---------------- Bootstrap ---------------- */

  CESForm.bootstrapMain = function (config) {
    var opts = config || {};

    var sel = {};
    function bind(id) {
      return (opts.selectors && opts.selectors[id]) ? $(opts.selectors[id]) : document.getElementById(id);
    }
    sel.pre = bind('pre');
    sel.form = bind('form');
    sel.msg = bind('msg');
    sel.err = bind('err');
    sel.inspectId = bind('inspectId');
    sel.code = bind('code');
    sel.unitId = bind('unitId');
    sel.unitCategory = bind('unitCategory');
    sel.unitType = bind('unitType');
    sel.employee = bind('employee');
    sel.email = bind('email');
    sel.preferredName = bind('preferredName');
    sel.phone = bind('phone');
    sel.displayedUnitId = bind('displayedUnitId');
    sel.jobNumber = bind('jobNumber');
    sel.notes = bind('notes');
    sel.gpsRow = bind('gpsRow');
    sel.gpsWarn = bind('gpsWarn');
    sel.btnGPS = bind('btnGPS');
    sel.mapLink = bind('mapLink');

    // Optional hard gate
    if (!opts.disableGate) {
      var ok = gateMainQR(opts.routes, true);
      if (!ok) return;
    }

    var ctx = getCtx();

    prefillMain(opts.endpoints || {}, sel, ctx, function () {
      initGPSUI(opts, sel, function () {
        on(sel.form, 'submit', function (ev) {
          if (ev && ev.preventDefault) ev.preventDefault();
          saveMain(opts, sel, ctx);
        });
      });
    });
  };

  // export
  global.CESForm = CESForm;

})(window);
