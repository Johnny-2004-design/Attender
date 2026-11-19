/* ==========================================================================
   ATTENDER — live API adapter (replaces fixture data.js)
   Every page talks to AT.api.*; this file maps those calls to /api/.
   ========================================================================== */
(function (w) {
  'use strict';
  const AT = (w.AT = w.AT || {});

  function apiBase() {
    // Resolve relative to site root: .../attender/assets/js/api.js → .../attender/api/
    try {
      const scripts = document.getElementsByTagName('script');
      for (let i = 0; i < scripts.length; i++) {
        const src = scripts[i].src || '';
        if (/\/assets\/js\/api\.js(\?|$)/.test(src)) {
          return src.replace(/\/assets\/js\/api\.js(\?.*)?$/, '/api/index.php');
        }
      }
    } catch (e) { /* ignore */ }
    return new URL('../api/index.php', location.href).href.replace(/\/(lecturer|student|auth)\/\.\.\//, '/');
  }

  // Split deployment: assets/js/config.js points at the backend (InfinityFree) API.
  const CFG = w.ATTENDER_CONFIG || {};
  const BASE = (CFG.apiBase || '').replace(/\s+/g, '') || apiBase();
  const EXTRA = CFG.apiExtraQuery ? '&' + String(CFG.apiExtraQuery).replace(/^[?&]/, '') : '';
  let CROSS_ORIGIN = false;
  try { CROSS_ORIGIN = new URL(BASE, location.href).origin !== location.origin; } catch (e) { /* ignore */ }
  if (/YOUR-BACKEND-DOMAIN/i.test(BASE)) {
    console.error('ATTENDER: set apiBase in assets/js/config.js to your backend API URL.');
  }

  function csrf() {
    const m = document.querySelector('meta[name="csrf-token"]');
    return (m && m.content) || AT._csrf || '';
  }

  function setCsrf(token) {
    if (!token) return;
    AT._csrf = token;
    let m = document.querySelector('meta[name="csrf-token"]');
    if (!m) {
      m = document.createElement('meta');
      m.name = 'csrf-token';
      document.head.appendChild(m);
    }
    m.content = token;
  }

  async function req(route, opts) {
    opts = opts || {};
    const method = (opts.method || 'GET').toUpperCase();
    const url = BASE + (BASE.indexOf('?') >= 0 ? '&' : '?') + 'r=' + encodeURIComponent(route)
      + (opts.query ? '&' + opts.query : '') + EXTRA;
    const headers = { 'Accept': 'application/json' };
    if (method !== 'GET' && method !== 'HEAD') {
      headers['Content-Type'] = 'application/json';
      headers['X-CSRF-Token'] = csrf();
    }
    const init = { method: method, headers: headers, credentials: CROSS_ORIGIN ? 'include' : 'same-origin' };
    if (opts.body !== undefined) {
      const body = Object.assign({}, opts.body);
      if (!body._csrf) body._csrf = csrf();
      init.body = JSON.stringify(body);
    }
    const res = await fetch(url, init);
    const ct = res.headers.get('content-type') || '';
    if (ct.indexOf('application/json') < 0) {
      if (!res.ok) throw new Error('Request failed (' + res.status + ')');
      return res;
    }
    const data = await res.json();
    if (data && data.csrf) setCsrf(data.csrf);
    if (!res.ok) {
      if (res.status === 401 && /\/lecturer\//.test(location.pathname)) {
        location.href = new URL('../auth/login.html', location.href).href;
        return new Promise(function () {});
      }
      const err = new Error((data && (data.error || data.reason)) || ('HTTP ' + res.status));
      err.status = res.status;
      err.data = data;
      throw err;
    }
    return data;
  }

  // Ensure CSRF before mutating calls when meta is empty
  AT.ensureCsrf = function () {
    if (csrf()) return Promise.resolve(csrf());
    return req('csrf').then(function (d) { setCsrf(d.csrf); return d.csrf; });
  };

  AT.api = {
    me: function () { return req('me'); },
    updateMe: function (p) { return AT.ensureCsrf().then(function () { return req('me', { method: 'POST', body: p }); }); },
    login: function (email, password) {
      return AT.ensureCsrf().then(function () {
        return req('auth/login', { method: 'POST', body: { email: email, password: password } });
      });
    },
    register: function (p) {
      return AT.ensureCsrf().then(function () {
        return req('auth/register', { method: 'POST', body: p });
      });
    },
    logout: function () {
      return AT.ensureCsrf().then(function () {
        return req('auth/logout', { method: 'POST', body: {} });
      });
    },
    reset: function () { return Promise.reject(new Error('Use database/seed.php on the server to reset sample data.')); },

    dashboard: function () { return req('dashboard'); },

    classes: function () { return req('classes'); },
    saveClass: function (c) { return AT.ensureCsrf().then(function () { return req('classes/save', { method: 'POST', body: c }); }); },
    deleteClass: function (id) { return AT.ensureCsrf().then(function () { return req('classes/delete', { method: 'POST', body: { id: id } }); }); },

    courses: function () { return req('courses'); },
    saveCourse: function (c) { return AT.ensureCsrf().then(function () { return req('courses/save', { method: 'POST', body: c }); }); },
    deleteCourse: function (id) { return AT.ensureCsrf().then(function () { return req('courses/delete', { method: 'POST', body: { id: id } }); }); },

    students: function () { return req('students'); },
    student: function (id) { return req('student', { query: 'id=' + encodeURIComponent(id) }); },
    setEnrollment: function (studentId, courseIds) {
      return AT.ensureCsrf().then(function () {
        return req('students/enrollment', { method: 'POST', body: { studentId: studentId, courseIds: courseIds } });
      });
    },

    requests: function () { return req('requests'); },
    decideRequest: function (id, decision, reason) {
      return AT.ensureCsrf().then(function () {
        return req('requests/decide', { method: 'POST', body: { id: id, decision: decision, reason: reason || '' } });
      });
    },

    invitations: function () { return req('invitations'); },
    createInvitation: function (p) {
      return AT.ensureCsrf().then(function () { return req('invitations/create', { method: 'POST', body: p }); });
    },
    revokeInvitation: function (id) {
      return AT.ensureCsrf().then(function () { return req('invitations/revoke', { method: 'POST', body: { id: id } }); });
    },

    sessions: function () { return req('sessions'); },
    session: function (id) { return req('session', { query: 'id=' + encodeURIComponent(id) }); },
    records: function () { return req('records'); },
    correctRecord: function (id, status, reason) {
      return AT.ensureCsrf().then(function () {
        return req('records/correct', { method: 'POST', body: { id: id, status: status, reason: reason } });
      });
    },
    audit: function () { return req('audit'); },

    settings: function () { return req('settings'); },
    saveSettings: function (p) {
      return AT.ensureCsrf().then(function () { return req('settings', { method: 'POST', body: p }); });
    },

    activeSession: function () { return req('activeSession'); },
    openSession: function (p) {
      return AT.ensureCsrf().then(function () { return req('sessions/open', { method: 'POST', body: p }); });
    },
    rotateToken: function () {
      return AT.ensureCsrf().then(function () { return req('sessions/rotateToken', { method: 'POST', body: {} }); });
    },
    newCode: function () {
      return AT.ensureCsrf().then(function () { return req('sessions/newCode', { method: 'POST', body: {} }); });
    },
    patchActive: function (p) {
      return AT.ensureCsrf().then(function () { return req('sessions/patch', { method: 'POST', body: p }); });
    },
    closeSession: function () {
      return AT.ensureCsrf().then(function () { return req('sessions/close', { method: 'POST', body: {} }); });
    },
    // Demo simulator removed server-side; keep no-op for UI buttons that still call it.
    simulateArrival: function () {
      return AT.api.activeSession().then(function (a) {
        AT.toast && AT.toast('Live arrivals come from real student devices.', 'info');
        return a;
      });
    },

    inviteInfo: function (token) {
      return req('invite', { query: 'token=' + encodeURIComponent(token || '') });
    },
    submitRegistration: function (p) {
      return AT.ensureCsrf().then(function () { return req('registration', { method: 'POST', body: p }); });
    },
    enrolFace: function (p) {
      return AT.ensureCsrf().then(function () { return req('registration/face', { method: 'POST', body: p }); });
    },
    resolveAttendanceInput: function (input) {
      return AT.ensureCsrf().then(function () {
        return req('attendance/resolve', { method: 'POST', body: { input: input } });
      });
    },
    submitAttendance: function (p) {
      return AT.ensureCsrf().then(function () {
        return req('attendance/submit', { method: 'POST', body: p });
      });
    },
    flushQueue: function (items) {
      return AT.ensureCsrf().then(function () {
        return req('attendance/queue', { method: 'POST', body: { items: items } });
      });
    },
    exportReport: function (format, params) {
      params = params || {};
      const q = ['format=' + encodeURIComponent(format || 'xlsx')];
      if (params.sessionId) q.push('sessionId=' + encodeURIComponent(params.sessionId));
      if (params.courseId) q.push('courseId=' + encodeURIComponent(params.courseId));
      const url = BASE + (BASE.indexOf('?') >= 0 ? '&' : '?') + 'r=reports/export&' + q.join('&') + EXTRA;
      window.location.href = url;
      return Promise.resolve(true);
    }
  };

  // Warm CSRF on load for forms
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { AT.ensureCsrf().catch(function () {}); });
  } else {
    AT.ensureCsrf().catch(function () {});
  }
})(window);
