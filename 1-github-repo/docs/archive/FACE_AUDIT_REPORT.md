# ATTENDER face integration — audit & hardening report

**Brand:** ATTENDER — Smart Attendance, Verified Presence  
**Source zip:** `internal/attender_ab8c.zip` → synced to `docs/attender/`  
**Date:** 2026-11-17

## Problems fixed

| # | Issue | Fix |
|---|---|---|
| 1 | PHP `FaceHttpProvider::enroll` returned `ok:true` when Python enroll failed | Enroll succeeds **only** after face-service returns `ok` and PHP writes `embedding_ready=1` |
| 2 | `StudentController` ignored enroll result | Transaction; on required embedding failure → **422**, no registration row; response distinguishes `registration` / `face_enrolled` / `retry_face` |
| 3 | Attendance possible without embedding | `VerificationEngine` refuses face-required sessions unless `embedding_ready=1`; clear error to re-enrol |
| 4 | “Largest face” accepted multi-person frames | Python `embed_one` rejects **0 or >1** faces |
| 5 | Weak image/quality gates | Strict data-URL MIME; size/dimension caps; det confidence, face px, blur (Laplacian), lighting |
| 6 | Unavailable Python could confuse PRESENT | Pending/fail policies; **never PRESENT** when `pending`; optional `ATTENDER_FACE_UNAVAILABLE_POLICY=fail` |
| 7 | Insecure defaults / missing secrets | HTTP provider requires URL **and** API key; production `APP_ENV=production` forbids empty `API_KEY` |
| 8 | Health conflated availability & models | `/health` returns `service_available` + `engine_ready`; no silent download when `ALLOW_MODEL_DOWNLOAD=false` |
| 9 | Soft liveness oversold | Explicit notes in API/health/docs — motion only, **not** PAD / anti-spoof |
| 10 | Privacy gaps | Delete path (`DELETE /enroll/{id}` + PHP `students/face/delete`); no embedding vectors in logs; optional Fernet; Act 843 awareness (not a compliance claim) |
| 11 | Frontend claimed success too early | Join shows retry on face fail; scan success copy only after server decision; pending ≠ present |

## Files changed (high level)

### PHP
- `src/Services/FaceServiceInterface.php`, `FaceHttpProvider.php`, `FaceNullProvider.php`, `FaceService.php`
- `src/Controllers/StudentController.php` (+ `deleteFace`)
- `src/Services/VerificationEngine.php`
- `api/index.php` (route)
- `config/app.php`
- `database/schema.sql`, `database/seed.php`
- `database/migrations/20261117_face_embedding_ready.sql` **(new)**
- `student/join.html`, `student/scan.html`

### Python `face-service/`
- `app/config.py`, `face_engine.py`, `image_processing.py`, `main.py`, `schemas.py`, `.env.example`
- `tests/test_face_service.py` (expanded)
- `README.md` (update below / face-service README)

### Docs
- This report, `FACE_INTEGRATION.md`, `DEPLOYMENT.md` (face sections), main `README.md` pointers

## DB migrations

Run on existing databases:

```bat
C:\xampp\mysql\bin\mysql -u root attender < database\migrations\20261117_face_embedding_ready.sql
```

Adds:
- `facial_templates.embedding_ready` (default 0)
- `student_registrations.face_enrolled` (default 0)

Fresh installs already include columns in `schema.sql`.

## Test results

### Python (mock / fake engine — default CI)
```
15 passed, 1 deselected (integration)
```
Coverage includes: auth 401, enroll saved only on ok, zero/multi face reject, low confidence reject, unknown student, match+liveness, engine-down → pending never ok, delete, health fields, ownership mismatch.

### Python (real InsightFace)
`pytest -m integration` — **skipped** unless `buffalo_l` already installed under `~/.insightface/models/`. Not executed with live weights in this audit run.

### PHP
- `php -l` clean on all modified face PHP files
- Contract asserts for enroll success vs failure flags
- Full HTTP+DB attendance e2e against live face-service **not** re-run in this pass (MariaDB may be down); logic paths updated as above

## Mock vs real

| Mode | How | Claims |
|---|---|---|
| **Mock** | `_FakeEngine` in unit tests | Synthetic JPEGs; deterministic vectors; labels MOCK in test module docstring |
| **Real** | InsightFace `buffalo_l` + ONNX Runtime | Requires model download/install; integration mark |

## Remaining limits (honest)

* Soft liveness ≠ anti-spoof; replay/deepfake risk remains
* Threshold 0.80 needs classroom camera calibration
* Seed data has **no** real embeddings (`embedding_ready=0`) — re-enrol students after enabling face-service
* Free hosting must keep face-service private (localhost/LAN); do not expose embeddings publicly
* Ghana **Act 843** awareness: minimise retention, support deletion, secure storage — this report does **not** claim legal compliance
* Not production-ready until: strong `API_KEY`, TLS to phones (camera/GPS), Fernet key, `ALLOW_MODEL_DOWNLOAD=false` with pre-provisioned models, unavailable policy chosen, and real InsightFace verification on-device

## Exact Windows / XAMPP commands

```bat
:: 1) DB migration (existing install)
cd C:\xampp\htdocs\attender
C:\xampp\mysql\bin\mysql -u root attender < database\migrations\20261117_face_embedding_ready.sql

:: 2) Face service
cd C:\xampp\htdocs\attender\face-service
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
copy .env.example .env
:: edit .env → set API_KEY
uvicorn app.main:app --host 127.0.0.1 --port 8090

:: 3) PHP env (System or Apache) then restart Apache
setx ATTENDER_FACE_PROVIDER http
setx ATTENDER_FACE_API_URL http://127.0.0.1:8090
setx ATTENDER_FACE_API_KEY attender-face-dev-key
setx ATTENDER_FACE_THRESHOLD 0.80

:: 4) Tests
cd C:\xampp\htdocs\attender\face-service
.venv\Scripts\activate
pytest -q -m "not integration"
```

## Hosting readiness checklist

- [ ] `API_KEY` / `ATTENDER_FACE_API_KEY` strong & matched
- [ ] `APP_ENV=production`, `ALLOW_MODEL_DOWNLOAD=false`, models pre-installed
- [ ] `EMBEDDING_FERNET_KEY` set
- [ ] Face-service bound to localhost or private network only
- [ ] Migration applied; students re-enrolled (`embedding_ready=1`)
- [ ] HTTPS for student camera/GPS
- [ ] `ATTENDER_FACE_UNAVAILABLE_POLICY` chosen (`pending` or `fail`)
- [ ] Backup/retention/deletion process documented for biometrics
- [ ] Real InsightFace smoke: enroll + verify same person / reject other / reject multi-face
