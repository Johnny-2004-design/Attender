/* ATTENDER — in-browser face matching helper (face-api.js).
   The browser turns a camera frame into a 128-number descriptor; the SERVER compares it with the
   enrolled one. No photo is sent. face-api.js (1.3 MB) and its models (7 MB) are only downloaded
   when a face step is actually reached, then cached by the browser. */
(function (w, d) {
  'use strict';
  const AT = (w.AT = w.AT || {});
  const F = (AT.face = {});

  // ---- where the library lives (…/assets/vendor/faceapi/), derived from this script's own URL
  const BASE = (function () {
    const s = d.getElementsByTagName('script');
    for (let i = 0; i < s.length; i++) {
      if (/\/assets\/js\/face\.js(\?|$)/.test(s[i].src || '')) return s[i].src.replace(/\/assets\/js\/face\.js(\?.*)?$/, '/assets/vendor/faceapi/');
    }
    return new URL('../assets/vendor/faceapi/', location.href).href;
  })();

  const sleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
  const dist = function (a, b) { return Math.hypot(a.x - b.x, a.y - b.y); };
  const fail = function (m) { return new Error(m); };

  // ---- loading ---------------------------------------------------------------------------
  let loading = null;
  F.load = function (onStatus) {
    if (loading) return loading;
    const say = onStatus || function () {};
    loading = (async function () {
      if (!w.faceapi) {
        say('Loading face check (first time only, ~8 MB)…');
        await new Promise(function (res, rej) {
          const t = d.createElement('script'); t.src = BASE + 'face-api.js'; t.onload = res;
          t.onerror = function () { rej(fail('Could not download the face check files. Check your internet connection and try again.')); };
          d.head.appendChild(t);
        });
      }
      const fa = w.faceapi;
      try { await fa.tf.setBackend('webgl'); } catch (e) { /* falls back to cpu/wasm automatically */ }
      await fa.tf.ready();
      say('Preparing face models…');
      await Promise.all([
        fa.nets.tinyFaceDetector.loadFromUri(BASE + 'models'),
        fa.nets.faceLandmark68Net.loadFromUri(BASE + 'models'),
        fa.nets.faceRecognitionNet.loadFromUri(BASE + 'models')
      ]);
    })().catch(function (e) { loading = null; throw e; });
    return loading;
  };

  // ---- measurements from the 68 landmarks -------------------------------------------------
  const dims = function (m) { return { w: m.videoWidth || m.naturalWidth || m.width || 0, h: m.videoHeight || m.naturalHeight || m.height || 0 }; };
  const ear = function (p, o) { return (dist(p[o + 1], p[o + 5]) + dist(p[o + 2], p[o + 4])) / (2 * dist(p[o], p[o + 3])); };

  /** Pure function (unit-testable): metrics from landmark positions + detection box + media size. */
  F.metrics = function (pts, box, size) {
    const jawW = dist(pts[0], pts[16]);
    return {
      yaw: (pts[30].x - pts[0].x) / jawW,                      // ~0.5 facing the camera; >0.5 = user turned to THEIR left
      ear: (ear(pts, 36) + ear(pts, 42)) / 2,                  // eye aspect ratio, drops when eyes close
      mouth: dist(pts[48], pts[54]) / jawW,                    // mouth width relative to face width, grows when smiling
      size: Math.max(box.width / size.w, box.height / size.h), // how much of the frame the face fills
      cx: (box.x + box.width / 2) / size.w, cy: (box.y + box.height / 2) / size.h,
      inside: box.x >= -0.02 * size.w && box.y >= -0.02 * size.h && box.x + box.width <= 1.02 * size.w && box.y + box.height <= 1.02 * size.h
    };
  };

  const opts = function () { return new w.faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 }); };

  async function look(media) {
    const faces = await w.faceapi.detectAllFaces(media, opts()).withFaceLandmarks();
    if (!faces.length) return { n: 0 };
    if (faces.length > 1) return { n: faces.length };
    const f = faces[0], box = f.detection.box;
    return { n: 1, m: F.metrics(f.landmarks.positions, box, dims(media)) };
  }

  const FRONT = 0.09, MIN_SIZE = 0.22;
  F.isFrontal = function (m, tol) { return Math.abs(m.yaw - 0.5) <= (tol || FRONT); };

  /** One good, frontal descriptor (retries for a few seconds). Throws a human-readable Error. */
  F.describe = async function (media, ui) {
    ui = ui || {};
    let last = 'No face found. Put your face inside the oval, in good light.';
    for (let i = 0; i < 14; i++) {
      const r = await look(media);
      if (r.n > 1) last = 'More than one face is visible. Only you should be in front of the camera.';
      else if (r.n === 0) last = 'No face found. Put your face inside the oval, in good light.';
      else if (r.m.size < MIN_SIZE) last = 'Move a little closer to the camera.';
      else if (!r.m.inside) last = 'Move back a little so your whole face is inside the oval.';
      else if (Math.abs(r.m.cx - 0.5) > 0.22 || Math.abs(r.m.cy - 0.5) > 0.25) last = 'Center your face in the oval.';
      else if (!F.isFrontal(r.m, ui.maxYaw)) last = 'Look straight at the camera.';
      else {
        const one = await w.faceapi.detectSingleFace(media, opts()).withFaceLandmarks().withFaceDescriptor();
        if (one && one.descriptor) {
          if (ui.good) ui.good(true);
          return { descriptor: Array.prototype.map.call(one.descriptor, function (x) { return Math.round(x * 1e5) / 1e5; }), metrics: r.m };
        }
      }
      if (ui.good) ui.good(false);
      if (ui.prompt) ui.prompt(last);
      await sleep(250);
    }
    throw fail(last);
  };

  /** Distance between two descriptors (0 = identical; > 0.6 = different people). */
  F.distance = function (a, b) { let s = 0; for (let i = 0; i < a.length; i++) { const x = a[i] - b[i]; s += x * x; } return Math.sqrt(s); };
  /** Registration captures must all be the same person — checked on the phone as each one is taken. */
  F.assertSamePerson = function (prev, next) {
    for (let i = 0; i < prev.length; i++) {
      if (F.distance(prev[i], next) > 0.5) throw fail('This capture does not look like the same face as your earlier one. Only you should be in front of the camera, with the same lighting. Try this photo again.');
    }
  };

  // ---- the random "are you really there" challenge ------------------------------------------
  F.CHALLENGES = {
    blink: 'Blink slowly',
    left: 'Slowly turn your head to the left',
    right: 'Slowly turn your head to the right',
    smile: 'Give a clear smile'
  };

  /** Pure function (unit-testable): did this frame complete the challenge? `st` keeps state between frames. */
  F.judge = function (name, base, m, st) {
    if (name === 'blink') {
      if (m.ear < base.ear * 0.72) st.closed = true;
      else if (st.closed && m.ear > base.ear * 0.86) return true;
      return false;
    }
    if (name === 'smile') {
      st.n = (m.mouth >= base.mouth * 1.12) ? (st.n || 0) + 1 : 0;
      return st.n >= 2;
    }
    if (name === 'left') return m.yaw - base.yaw >= 0.1;
    if (name === 'right') return base.yaw - m.yaw >= 0.1;
    return false;
  };

  F.challenge = async function (media, name, ui) {
    ui = ui || {};
    // baseline: relaxed, frontal face for ~0.8 s
    const samples = [];
    for (let i = 0; i < 6; i++) { const r = await look(media); if (r.n === 1) samples.push(r.m); await sleep(100); }
    if (samples.length < 3) throw fail('We could not see your face clearly. Try again in better light.');
    const med = function (k) { const a = samples.map(function (s) { return s[k]; }).sort(function (x, y) { return x - y; }); return a[Math.floor(a.length / 2)]; };
    const base = { yaw: med('yaw'), mouth: med('mouth'), ear: Math.max.apply(null, samples.map(function (s) { return s.ear; })) };
    if (ui.prompt) ui.prompt(F.CHALLENGES[name]);
    const st = {}, t0 = Date.now();
    while (Date.now() - t0 < 8000) {
      const r = await look(media);
      if (r.n === 1 && F.judge(name, base, r.m, st)) return true;
      await sleep(60);
    }
    return false;
  };

  /** Full attendance capture: 2 frontal descriptors → random challenge → 1 frontal descriptor. */
  F.verifyCapture = async function (media, ui) {
    ui = ui || {};
    const say = function (t) { if (ui.prompt) ui.prompt(t); };
    say('Look straight at the camera');
    const a = await F.describe(media, ui); await sleep(500);
    const b = await F.describe(media, ui);
    const names = Object.keys(F.CHALLENGES), name = names[Math.floor(Math.random() * names.length)];
    if (ui.good) ui.good(false);
    const passed = await F.challenge(media, name, ui);
    say(passed ? 'Good. Look straight again' : 'Look straight at the camera');
    if (ui.good) ui.good(false);
    await sleep(600);
    const c = await F.describe(media, ui);
    return { descriptors: [a.descriptor, b.descriptor, c.descriptor], challenge: name, passed: passed };
  };
})(window, document);
