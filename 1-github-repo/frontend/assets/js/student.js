/* ATTENDER — student-side helpers: camera, QR scanning, geolocation, offline queue */
(function (w, d) {
  'use strict';
  const AT = (w.AT = w.AT || {});
  const S = (AT.student = {});

  S.secureOk = function () { return w.isSecureContext || /^(localhost|127\.0\.0\.1)$/.test(location.hostname); };
  S.secureMessage = 'Camera and location only work on a secure (HTTPS) connection. Ask your lecturer for the https:// link, or see the LAN HTTPS guide.';

  S.startCamera = function (video, facing) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return Promise.reject(new Error(S.secureOk() ? 'This browser has no camera support.' : S.secureMessage));
    return navigator.mediaDevices.getUserMedia({ video: { facingMode: facing || 'user', width: { ideal: 960 }, height: { ideal: 960 } }, audio: false }).then(function (stream) {
      video.srcObject = stream; video.setAttribute('playsinline', ''); video.muted = true; return video.play().then(function () { return stream; });
    });
  };
  S.stopCamera = function (stream) { if (stream) stream.getTracks().forEach(function (t) { t.stop(); }); };
  S.cameraError = function (e) {
    if (e && (e.name === 'NotAllowedError' || e.name === 'SecurityError')) return 'Camera permission was blocked. Tap the lock icon in the address bar, allow Camera, then try again.';
    if (e && e.name === 'NotFoundError') return 'No camera was found on this device.';
    return (e && e.message) || 'The camera could not be started.';
  };
  S.snapshot = function (video, max) {
    const c = d.createElement('canvas'), r = Math.min(1, (max || 480) / Math.max(video.videoWidth, video.videoHeight)); c.width = video.videoWidth * r; c.height = video.videoHeight * r;
    c.getContext('2d').drawImage(video, 0, 0, c.width, c.height); return c.toDataURL('image/jpeg', 0.8);
  };

  // QR scanning with jsQR on frames from the camera
  S.scanQR = function (video, onResult) {
    const c = d.createElement('canvas'), x = c.getContext('2d', { willReadFrequently: true }); let on = true;
    (function loop() {
      if (!on) return;
      if (video.readyState === video.HAVE_ENOUGH_DATA && video.videoWidth) {
        const s = 480 / Math.max(video.videoWidth, video.videoHeight); c.width = video.videoWidth * s; c.height = video.videoHeight * s; x.drawImage(video, 0, 0, c.width, c.height);
        const img = x.getImageData(0, 0, c.width, c.height), code = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
        if (code && code.data) { on = false; onResult(code.data); return; }
      }
      requestAnimationFrame(loop);
    })();
    return function stop() { on = false; };
  };

  // Pull the secret session token out of a scanned QR (a URL like .../scan.html?t=TOKEN) or accept a raw token
  S.extractToken = function (text) { try { const u = new URL(text); return u.searchParams.get('t') || text; } catch (e) { return text; } };

  S.getPosition = function () {
    return new Promise(function (res, rej) {
      if (!navigator.geolocation) return rej(new Error('This browser has no location support.'));
      navigator.geolocation.getCurrentPosition(function (p) { res({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }); },
        function (e) { rej(new Error(e.code === 1 ? 'Location permission was blocked. Allow Location for this site and try again.' : e.code === 3 ? 'Finding your location took too long. Move near a window and retry.' : 'Your location is unavailable.')); },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    });
  };

  // Offline queue: submissions wait as PENDING; they are never treated as verified until the server answers.
  const QK = 'attender.queue.v1';
  S.queue = function () { try { return JSON.parse(localStorage.getItem(QK) || '[]'); } catch (e) { return []; } };
  S.enqueue = function (item) { const q = S.queue(); q.push(Object.assign({ queuedAt: new Date().toISOString() }, item)); localStorage.setItem(QK, JSON.stringify(q)); return q.length; };
  S.clearQueue = function () { localStorage.removeItem(QK); };

  S.remember = function (k, v) { try { v == null ? localStorage.removeItem('attender.' + k) : localStorage.setItem('attender.' + k, v); } catch (e) { /* ignore */ } };
  S.recall = function (k) { try { return localStorage.getItem('attender.' + k); } catch (e) { return null; } };
})(window, document);

// Offline / online banner shared by student pages
document.addEventListener('DOMContentLoaded', function () {
  const b = document.getElementById('offline'); if (!b) return;
  const upd = function () { b.classList.toggle('show', !navigator.onLine); }; window.addEventListener('online', upd); window.addEventListener('offline', upd); upd();
});
