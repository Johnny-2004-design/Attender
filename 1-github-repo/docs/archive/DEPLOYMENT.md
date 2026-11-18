# ATTENDER — production preparation

## Before going live

1. Set `ATTENDER_ENV=production` and `ATTENDER_DEBUG=0`.
2. Set a stable `ATTENDER_URL=https://attendance.example.edu`.
3. Use a dedicated MySQL user with rights only on `attender`.
4. Put strong DB credentials in environment variables (not committed files):
   ```
   ATTENDER_DB_HOST=127.0.0.1
   ATTENDER_DB_PORT=3306
   ATTENDER_DB_NAME=attender
   ATTENDER_DB_USER=attender_app
   ATTENDER_DB_PASS=...
   ```
5. Ensure `storage/` is writable by PHP and **not** served publicly (Apache deny / move outside docroot).
6. Force HTTPS and HSTS at the reverse proxy or Apache vhost.
7. Rotate seed passwords or wipe seed lecturers before real users enroll.
8. `composer install --no-dev --optimize-autoloader`.

## Apache vhost sketch

```apache
<VirtualHost *:443>
  ServerName attendance.example.edu
  DocumentRoot /var/www/attender

  SSLEngine on
  SSLCertificateFile /etc/letsencrypt/live/attendance.example.edu/fullchain.pem
  SSLCertificateKeyFile /etc/letsencrypt/live/attendance.example.edu/privkey.pem

  <Directory /var/www/attender>
    AllowOverride All
    Require all granted
  </Directory>

  AliasMatch "^/(config|src|storage|bootstrap|database|vendor)(/|$)" /var/www/attender/storage/denied
</VirtualHost>
```

Keep `api/.htaccess` rewrite rules enabled (`mod_rewrite`).

## LAN HTTPS for classroom phones

* **mkcert** (dev): generate a local CA, trust it on lecturer laptop + phones, serve Apache with that cert on the LAN IP.
* **Reverse proxy**: Caddy or nginx with an internal cert in front of Apache.
* Without HTTPS, prefer **one-time codes** for attendance; document that camera/GPS are blocked by the browser.

## Face recognition integration

Bundled service: `face-service/` (FastAPI + InsightFace + ONNX Runtime). See `face-service/README.md` and `docs/FACE_INTEGRATION.md`.

### Windows / XAMPP (CPU)

1. Install Python 3.10+; in `face-service` create a venv and `pip install -r requirements.txt`.
2. Copy `.env.example` → `.env`, set `API_KEY`.
3. Run `uvicorn app.main:app --host 127.0.0.1 --port 8090` (keep the window open, or install as a Windows service / NSSM).
4. First run downloads **buffalo_l** to `%USERPROFILE%\.insightface\models\` (one-time network).
5. Configure Apache/PHP env (restart Apache):

```
ATTENDER_FACE_PROVIDER=http
ATTENDER_FACE_API_URL=http://127.0.0.1:8090
ATTENDER_FACE_API_KEY=attender-face-dev-key
ATTENDER_FACE_THRESHOLD=0.80
```

Do **not** append `/v1` — PHP calls `{url}/enroll` and `{url}/verify` directly.

### Production notes

* Bind the face service to localhost or a private LAN; put a reverse proxy + TLS if phones must reach it (usually only PHP server calls it).
* Match `API_KEY` with `ATTENDER_FACE_API_KEY`.
* GPU optional: install `onnxruntime-gpu` and set `ONNX_PROVIDERS=CUDAExecutionProvider,CPUExecutionProvider`.
* Soft liveness is motion-based only — not certified anti-spoof. Document that honestly for your institution.
* `FaceNullProvider` remains the safe default when the API URL is unset.

## Backups

* Nightly `mysqldump attender` + copy of `storage/templates`.
* Retain audit_logs; do not truncate in production without policy.

## Scaling notes

* One PHP app server + MySQL is enough for departmental load.
* Put face matching on its own service so attendance API latency stays predictable.
* For multi-campus, run separate databases or add an institution_id migration (not in v1).

## Post-deploy smoke

1. HTTPS padlock valid on phone.
2. Lecturer login + open session.
3. Student join + scan over HTTPS.
4. Confirm pending vs present behaviour matches face provider configuration.
5. Excel/PDF export downloads.
