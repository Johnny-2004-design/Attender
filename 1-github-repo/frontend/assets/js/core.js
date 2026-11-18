/* ATTENDER — shared UI helpers and lecturer app shell */
(function (w, d) {
  'use strict';
  const AT = (w.AT = w.AT || {});

  AT.$ = function (s, r) { return (r || d).querySelector(s); };
  AT.$$ = function (s, r) { return Array.from((r || d).querySelectorAll(s)); };
  AT.esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); };

  AT.fmtDate = function (iso, withTime) {
    if (!iso) return '—'; const x = new Date(iso);
    const o = { day: '2-digit', month: 'short', year: 'numeric' }; if (withTime) { o.hour = '2-digit'; o.minute = '2-digit'; }
    return x.toLocaleString('en-GB', o);
  };
  AT.fmtTime = function (iso) { return iso ? new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) : '—'; };
  AT.initials = function (n) { return String(n || '?').replace(/^(Dr\.|Prof\.|Mr\.|Mrs\.|Ms\.)\s*/i, '').split(/\s+/).slice(0, 2).map(function (p) { return p[0]; }).join('').toUpperCase(); };
  const palette = ['#0A66C2', '#0e8a6a', '#7a4fd0', '#c4551d', '#b8327a', '#2d7ea6', '#6b7a1f', '#a83232'];
  AT.color = function (n) { let h = 0; String(n).split('').forEach(function (c) { h = (h * 31 + c.charCodeAt(0)) >>> 0; }); return palette[h % palette.length]; };
  AT.avatar = function (n, size) { return '<span class="avatar" style="background:' + AT.color(n) + (size ? ';width:' + size + 'px;height:' + size + 'px' : '') + '">' + AT.esc(AT.initials(n)) + '</span>'; };
  AT.badge = function (status) {
    const s = String(status).toLowerCase(); const label = ({ review: 'Pending review' })[s] || (s.charAt(0).toUpperCase() + s.slice(1));
    return '<span class="badge-soft b-' + s + '">' + label + '</span>';
  };
  AT.rateBar = function (p) { return p == null ? '<span class="text-muted">—</span>' : '<div class="d-flex align-items-center gap-2"><div class="progress slim flex-grow-1" style="min-width:70px"><div class="progress-bar" style="width:' + p + '%;' + (p < 60 ? 'background:var(--at-danger)' : p < 75 ? 'background:var(--at-warning)' : '') + '"></div></div><b style="font-size:.8rem">' + p + '%</b></div>'; };
  AT.empty = function (icon, title, text) { return '<div class="empty"><i class="bi bi-' + icon + '"></i><h6>' + AT.esc(title) + '</h6><div>' + AT.esc(text || '') + '</div></div>'; };
  AT.skeleton = function (rows) { let h = ''; for (let i = 0; i < (rows || 4); i++) h += '<div class="skeleton mb-2" style="height:44px"></div>'; return '<div class="p-3">' + h + '</div>'; };

  // ----- Toasts -----
  AT.toast = function (msg, type) {
    let wrap = AT.$('.toast-wrap'); if (!wrap) { wrap = d.createElement('div'); wrap.className = 'toast-wrap'; d.body.appendChild(wrap); }
    const icon = { success: 'check-circle-fill', error: 'x-octagon-fill', info: 'info-circle-fill' }[type || 'success'];
    const t = d.createElement('div'); t.className = 'at-toast ' + (type || 'success'); t.innerHTML = '<i class="bi bi-' + icon + '"></i><div>' + AT.esc(msg) + '</div>';
    wrap.appendChild(t); setTimeout(function () { t.style.opacity = 0; t.style.transition = 'opacity .3s'; setTimeout(function () { t.remove(); }, 300); }, 3200);
  };

  // ----- Confirm dialog (returns Promise<boolean>; optional reason field) -----
  AT.confirm = function (o) {
    return new Promise(function (resolve) {
      const id = 'cf' + Date.now(); const m = d.createElement('div'); m.className = 'modal fade'; m.id = id; m.tabIndex = -1;
      m.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-body p-4">' +
        '<div class="d-flex gap-3"><div class="stat-icon ' + (o.danger ? 'red' : 'blue') + '"><i class="bi bi-' + (o.icon || (o.danger ? 'exclamation-triangle' : 'question-circle')) + '"></i></div><div class="flex-grow-1"><h5 class="mb-1">' + AT.esc(o.title || 'Are you sure?') + '</h5><div class="text-muted">' + AT.esc(o.text || '') + '</div>' +
        (o.reason ? '<label class="form-label mt-3">Reason <span class="text-danger">*</span></label><textarea class="form-control" rows="2" placeholder="' + AT.esc(o.reasonHint || 'Required for the audit log') + '"></textarea><div class="invalid-feedback">A reason is required.</div>' : '') +
        '</div></div></div><div class="modal-footer border-0 pt-0 px-4 pb-4"><button class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button><button class="btn ' + (o.danger ? 'btn-danger' : 'btn-primary') + '" data-ok>' + AT.esc(o.ok || 'Confirm') + '</button></div></div></div>';
      d.body.appendChild(m); const modal = new bootstrap.Modal(m); let done = false;
      m.querySelector('[data-ok]').onclick = function () {
        const ta = m.querySelector('textarea'); if (ta && !ta.value.trim()) { ta.classList.add('is-invalid'); return; }
        done = true; resolve(ta ? { ok: true, reason: ta.value.trim() } : true); modal.hide();
      };
      m.addEventListener('hidden.bs.modal', function () { if (!done) resolve(false); m.remove(); });
      modal.show();
    });
  };

  AT.copy = function (text) { return (navigator.clipboard && navigator.clipboard.writeText ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () { AT.toast('Copied to clipboard'); }, function () { const t = d.createElement('textarea'); t.value = text; d.body.appendChild(t); t.select(); d.execCommand('copy'); t.remove(); AT.toast('Copied to clipboard'); }); };

  AT.download = function (name, content, type) { const b = new Blob([content], { type: type || 'text/csv' }); const a = d.createElement('a'); a.href = URL.createObjectURL(b); a.download = name; a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); }, 500); };
  AT.csv = function (rows) { return rows.map(function (r) { return r.map(function (c) { c = String(c == null ? '' : c); return /[",\n]/.test(c) ? '"' + c.replace(/"/g, '""') + '"' : c; }).join(','); }).join('\n'); };

  // Fixture-only CSRF placeholder: the PHP backend issues a real per-session token (meta tag) that api.js will send.
  AT.csrf = function () { const m = AT.$('meta[name="csrf-token"]'); return m ? m.content : ''; };

  // ----- Lecturer app shell -----
  const NAV = [
    { sec: 'Overview' },
    { href: 'dashboard.html', icon: 'grid-1x2-fill', label: 'Dashboard', key: 'dashboard' },
    { href: 'attendance-live.html', icon: 'broadcast', label: 'Take Attendance', key: 'live' },
    { sec: 'Academics' },
    { href: 'classes.html', icon: 'mortarboard-fill', label: 'My Classes', key: 'classes' },
    { href: 'courses.html', icon: 'journal-bookmark-fill', label: 'My Courses', key: 'courses' },
    { href: 'students.html', icon: 'people-fill', label: 'Students', key: 'students' },
    { href: 'requests.html', icon: 'person-check-fill', label: 'Registration Requests', key: 'requests', badge: true },
    { sec: 'Attendance' },
    { href: 'sessions.html', icon: 'calendar2-check-fill', label: 'Attendance Sessions', key: 'sessions' },
    { href: 'records.html', icon: 'list-check', label: 'Attendance Records', key: 'records' },
    { href: 'reports.html', icon: 'file-earmark-bar-graph-fill', label: 'Reports', key: 'reports' },
    { sec: 'Account' },
    { href: 'settings.html', icon: 'sliders', label: 'Settings', key: 'settings' },
    { href: 'profile.html', icon: 'person-circle', label: 'Profile', key: 'profile' }
  ];

  AT.shell = function (cfg) {
    const root = AT.$('#app-root'); const content = root.innerHTML;
    const links = NAV.map(function (n) {
      if (n.sec) return '<div class="nav-section">' + n.sec + '</div>';
      return '<a class="nav-link' + (cfg.active === n.key ? ' active' : '') + '" href="' + n.href + '"><i class="bi bi-' + n.icon + '"></i><span>' + n.label + '</span>' + (n.badge ? '<span class="count d-none" id="nav-pending"></span>' : '') + '</a>';
    }).join('');
    root.innerHTML =
      '<div class="app-shell"><aside class="sidebar" id="sidebar"><div class="brand"><img src="../assets/img/logo.svg" alt=""><div><b>ATTENDER</b><small>Verified Presence</small></div></div>' +
      '<nav>' + links + '</nav><div class="side-foot"><div class="side-user"><span id="side-avatar"></span><div class="flex-grow-1 text-truncate"><div class="fw-600 text-truncate" id="side-name">…</div><small id="side-role">Lecturer</small></div>' +
      '<a href="../auth/login.html" class="text-white-50" title="Logout" id="logout-btn"><i class="bi bi-box-arrow-right fs-5"></i></a></div></div></aside>' +
      '<div class="sidebar-backdrop" id="backdrop"></div><div class="main-area"><header class="topbar"><button class="btn btn-ghost btn-sm sidebar-toggle" id="menu-btn" aria-label="Menu"><i class="bi bi-list fs-5"></i></button>' +
      '<div class="flex-grow-1"><div class="crumb">ATTENDER / ' + AT.esc(cfg.crumb || cfg.title) + '</div><h1 class="page-title">' + AT.esc(cfg.title) + '</h1></div>' +
      (cfg.noQuick ? '' : '<a class="btn btn-primary btn-sm d-none d-sm-inline-flex align-items-center gap-2" href="attendance-live.html"><i class="bi bi-broadcast"></i>Open attendance</a>') +
      '</header><main class="page-content" id="page">' + content + '</main></div></div>';

    const sb = AT.$('#sidebar'), bd = AT.$('#backdrop');
    AT.$('#menu-btn').onclick = function () { sb.classList.toggle('open'); bd.classList.toggle('show'); };
    bd.onclick = function () { sb.classList.remove('open'); bd.classList.remove('show'); };
    AT.$('#logout-btn').onclick = function (e) {
      e.preventDefault();
      AT.confirm({ title: 'Log out of ATTENDER?', text: 'You will need to sign in again to manage attendance.', ok: 'Log out', icon: 'box-arrow-right' }).then(function (ok) {
        if (!ok) return;
        const go = function () { location.href = '../auth/login.html'; };
        if (AT.api && AT.api.logout) { AT.api.logout().then(go).catch(go); } else { go(); }
      });
    };
    AT.api.me().then(function (m) { AT.$('#side-name').textContent = m.name; AT.$('#side-avatar').innerHTML = AT.avatar(m.name); });
    AT.api.requests().then(function (r) { const b = AT.$('#nav-pending'); if (b && r.length) { b.textContent = r.length; b.classList.remove('d-none'); } });
  };

  // Generic QR render using bundled qrcode lib
  AT.drawQR = function (canvas, text, size) {
    return new Promise(function (res, rej) { QRCode.toCanvas(canvas, text, { width: size || 260, margin: 1, errorCorrectionLevel: 'M', color: { dark: '#0b1b2e', light: '#ffffff' } }, function (e) { e ? rej(e) : res(); }); });
  };
  AT.studentUrl = function (path) { return new URL('../student/' + path, location.href).href; };
})(window, document);
