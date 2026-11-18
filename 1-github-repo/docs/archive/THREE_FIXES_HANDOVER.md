# ATTENDER — Three review fixes handover

**Date:** 2026-11-17  
**Source:** `<local path>` → synced to `<local path>`  
**Scope:** ONLY similarity validation, biometric deletion confirmation, production credential tests.  
**Not done:** rebuild, redesign, new features, DB reset, deploy.

---

## Readiness

| Classification | Applies? |
| --- | --- |
| READY | **No** — mocked tests ≠ live face accuracy |
| **NEEDS REAL-WORLD TESTING** | **Yes** — contracts fixed; classroom cameras / InsightFace still required |
| NEEDS MORE DEVELOPMENT | No for these three review items |
| NOT SAFE FOR PRODUCTION | Still true until strong env secrets + HTTPS + real-face calibration |

---

## 1. Similarity validation

**Problem:** incomplete / non-finite / out-of-range similarity could still reach threshold logic.

**Fix:**
- `src/Support/FaceSimilarity.php` — require present finite float in `[-1.0, 1.0]`; reject null/empty/non-numeric/NaN/±Inf/OOR; `meetsThreshold()` only after parse.
- `FaceHttpProvider::normalizeVerifyResponse` uses it before liveness/PRESENT path.
- `VerificationEngine` uses `FaceSimilarity::meetsThreshold` — invalid similarity never PRESENT/LATE.
- Python `validate_similarity()` in `face_engine.py`; verify path fail-closes on invalid scores.

## 2. Biometric deletion confirmation

**Problem:** PHP cleared local state after a best-effort remote call (silent success risk).

**Fix:**
- Remote first: require HTTP 2xx, valid JSON, `ok===true`, and `absent===true` (`FaceHttpProvider::remoteDeleteConfirmed`).
- Python `DELETE /enroll/{id}` returns `{ok, absent}` only when gallery file is confirmed gone (idempotent if already absent).
- Then local DB soft-clear + `purgeAuditImages`; re-check no `embedding_ready` and no template files.
- Failure → `pending_deletion` + retry; `StudentController::deleteFace` audits success/failure (never silent `ok:true`).
- Lecturer ownership via `student_registrations` scope.

## 3. Production credential tests

**Fix:**
- PHP `FaceSecrets` — production rejects empty/missing/short/known-dev/weak keys; strong ≥16 accepted.
- `FaceHttpProvider::isConfigured` uses `FaceSecrets::assertUsable`.
- Python `Settings.is_forbidden_api_key` + `validate_security` in production.
- Tests load production config: accept strong; reject empty/dev/weak/missing; invalid auth 401; valid auth succeeds.
- Env templates: `.env.example` / `.env.production.example` (PHP + face-service). **No real secrets** in tests or frontend.

---

## Files changed

| Path | Why |
| --- | --- |
| `src/Support/FaceSimilarity.php` | NEW — similarity parse/threshold |
| `src/Support/FaceSecrets.php` | NEW — prod key policy |
| `src/Services/FaceHttpProvider.php` | normalize verify + confirmed delete |
| `src/Services/FaceNullProvider.php` | `purgeAuditImages` |
| `src/Services/VerificationEngine.php` | similarity fail-closed |
| `src/Controllers/StudentController.php` | delete audit / pending_deletion |
| `config/app.php` | `face_require_liveness` |
| `face-service/app/{main,schemas,config,face_engine}.py` | absent confirm + sim validate + prod keys |
| `face-service/tests/test_face_service.py` | MOCK cases for all three fixes |
| `tests/test_three_fixes.php` | PHP MOCK unit suite |
| `.env.example`, `.env.production.example` | local vs prod checklists |
| `face-service/.env.production.example` | prod checklist |
| `docs/THREE_FIXES_HANDOVER.md` | this file |

**Migrations:** none (data preserved).

---

## Test results

| Suite | Result |
| --- | --- |
| `php -l` (changed PHP) | Clean |
| `php tests/test_three_fixes.php` | **44 passed** |
| `pytest -m "not integration"` | **19 passed**, 1 integration deselected |
| Frontend secret scan | No API keys in JS/HTML |

---

## Windows / XAMPP commands

```bat
:: PHP unit (from attender root)
cd C:\xampp\htdocs\attender
php tests\test_three_fixes.php

:: Face-service MOCK tests
cd C:\xampp\htdocs\attender\face-service
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
pytest -q -m "not integration"

:: Run face-service (local)
copy .env.example .env
uvicorn app.main:app --host 127.0.0.1 --port 8090

:: PHP env (Apache / system)
set ATTENDER_FACE_PROVIDER=http
set ATTENDER_FACE_API_URL=http://127.0.0.1:8090
set ATTENDER_FACE_API_KEY=attender-face-dev-key
```

Production: use `.env.production.example` values with a **strong** matching key (dev key rejected when `ATTENDER_ENV=production` / `APP_ENV=production`).

---

## Remaining limits

- Soft liveness is still motion-based (not PAD).
- Mocked tests do not prove real-face accuracy.
- Free PHP hosts still cannot run InsightFace — need a separate Python process.
- Production operators must set strong keys (and ideally Fernet) themselves.

**Zip:** `<local path>`

---

## One-file database (Alice request)

**Path:** `<local path>`

- Full schema + `embedding_ready` + `face_enrolled`
- `CREATE DATABASE IF NOT EXISTS` / `utf8mb4` / `CREATE TABLE IF NOT EXISTS`
- No default destructive DROPs (optional lab wipe commented at bottom)
- `database/schema.sql` kept in sync with the same definitions

phpMyAdmin: Import → choose `attender_full.sql` → Go.  
Then optional: `php database/seed.php`
