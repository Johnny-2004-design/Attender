# ATTENDER — free hosting inspection (UCC presentation)

**Date:** 2026-11-17  
**Source inspected:** `<local path>` restored as `<local path>`  
**Goal:** Temporary HTTPS demo for a UCC presentation. Do **not** rebuild or redesign.

---

## 1. Actual stack (confirmed)

| Layer | What it is |
|-------|------------|
| Frontend | Static HTML/CSS/JS (Bootstrap 5), pages under `index.html`, `auth/`, `lecturer/`, `student/`, `assets/` |
| Backend | Modular **plain PHP 8.1+** API (`api/index.php` front controller + `src/`), **no** Laravel/Symfony |
| Database | **MySQL / MariaDB** via PDO (`config/database.php`, `src/Database.php`) |
| Reports | Composer: PhpSpreadsheet + Dompdf (vendor already bundled in the zip) |
| Face (optional) | Separate **Python FastAPI** service in `face-service/` (InsightFace + ONNX Runtime) |
| Local preferred | XAMPP (Apache + MySQL) — keep working; defaults remain `127.0.0.1` / `root` / empty password / db `attender` |

**Vercel:** **Not necessary.** The UI is static HTML served from the same origin as the PHP API. Splitting to Vercel adds CORS/cookie complexity with no benefit for this demo.

---

## 2. InfinityFree vs presentation needs

| Need | InfinityFree free PHP+MySQL | Notes |
|------|-----------------------------|-------|
| Serve HTML + PHP API | Yes | Set panel PHP ≥ 8.1 (prefer 8.3) |
| MySQL / phpMyAdmin | Yes | Remote host/user/db from control panel |
| Composer on server | No | Ship `vendor/` in upload zip |
| Persistent Python / InsightFace / ONNX | **No** | Face microservice cannot run here |
| SSH / `php database/seed.php` | No | Use SQL import + optional SQL seed |
| Custom process env vars | Unreliable | Prefer `config/local.production.php` (putenv) on host only |
| Free SSL | Yes (panel) | Required for phone camera/GPS |

**Verdict for presentation:** A **single PHP+MySQL host** (InfinityFree or similar shared PHP hosting) is the best free architecture **if** Alice accepts face recognition offline/fail-closed for the demo.

**Better free/low-cost only if she needs live face match:** Oracle Always Free A1 VM (PHP + MySQL + face-service on one box) — already audited under ATT-8; gated on her Console Active. Do not block the UCC demo on that unless she insists on live face.

Prior InfinityFree attempt (`your-site.example`) was prepared earlier but site was still **502** when last probed — Alice must finish panel upload/DB; this inspection does **not** claim that site is live.

---

## 3. Face recognition — honest options

Default settings: `ATTENDER_FACE_PROVIDER=null` (FaceNullProvider). Session setting **`requireFace` defaults to `true`**.

With null provider + face required:

- Registration never gets a real embedding (`face_enrolled` / `embedding_ready` stay false).
- Attendance with `require_face=1` **fail-closes** (incomplete enrollment or pending) — never fakes PRESENT.

| Option | Demo effect | Effort |
|--------|-------------|--------|
| **A. Fail-closed (default)** | Face paths stay pending/fail; GPS+code/QR still demoable if face off per session | Zero host change |
| **B. Temporary disable `require_face`** (recommended for UCC) | Lecturer turns off Face in Settings / when opening a session; mark PRESENT via code/QR + GPS | Config/demo script only |
| **C. Separate cheap/Always Free VPS for face-service** | Real enroll/verify over HTTPS from PHP | Extra host + secrets; not InfinityFree |

**Recommendation for UCC:** **B** on a single PHP host. Keep FaceNullProvider. Document that live face needs Option C / Oracle later.

---

## 4. Localhost / URL / env / security findings

### READY

- Frontend API base is **relative** (`assets/js/api.js` → `…/api/index.php`) — no hardcoded production domain.
- DB and app settings already read `ATTENDER_*` via `getenv()` (`config/app.php`, `config/database.php`).
- HTTPS session cookies: `Session.php` sets `secure` when `HTTPS` is on; `HttpOnly` + `SameSite=Lax`.
- API hides exception details when `ATTENDER_DEBUG` is not `1`.
- Per-folder deny `.htaccess` exists for `config/`, `src/`, `bootstrap/`, `database/`, `storage/`.
- Face path is fail-closed; no fake match.

### NEEDS CONFIG

- PHP has **no dotenv loader** — `.env` files are documentation only unless the host injects env, or a **server-local** `config/local.production.php` calls `putenv`.
- Remote MySQL host/user/password/db name must be set on the host (never committed).
- Set `ATTENDER_ENV=production`, `ATTENDER_DEBUG=0`, `ATTENDER_URL=https://…`.
- Composer `platform_check.php` currently requires **PHP ≥ 8.3** (generated on 8.3). InfinityFree may offer 8.1/8.2 — relax check to ≥ 8.1 for shared hosting **or** force panel PHP 8.3.
- `storage/` must be writable; seed passwords must be rotated or demo-only.
- Camera/GPS need **HTTPS** (or localhost) — Free SSL required before phone demos.

### NEEDS CORRECTION (prep scope — addressed in Phase B)

- Root `.htaccess` used `<DirectoryMatch>` (invalid/risky in `.htaccess` on many shared hosts). **Fixed:** removed; per-folder deny + added `vendor/.htaccess`.
- Production should explicitly `ini_set('display_errors', '0')`. **Fixed** in `bootstrap/app.php`.
- Session `secure` / `base_url()` now honour `X-Forwarded-Proto: https`.
- Added `config/local.production.php.example` + bootstrap loader (gitignored real file).
- **Bug found:** `config/app.php` used `getenv('ATTENDER_DEBUG') ?: '1'`, so `"0"` was treated as falsy and debug stayed on. **Fixed** with explicit empty/false handling.
- Docs clarified: PHP does not auto-load `.env`; use host env or `local.production.php`.

### BLOCKING (for live face on InfinityFree)

- **Cannot run `face-service/` on InfinityFree.** Not fixable by PHP prep.
- Live HTTPS deploy **blocked on Alice’s panel credentials/actions** (upload, phpMyAdmin, SSL, DB password). Agent must not invent a successful live test.

### Not blocking for XAMPP

- Keep `127.0.0.1` / empty password defaults when env unset so local XAMPP continues to work.

---

## 5. Architecture recommendation (confirm before accounts/upload)

**Recommended for UCC presentation:**

1. **Single PHP+MySQL free host** (InfinityFree or equivalent).
2. Upload PHP app + `vendor/` **without** `face-service/` heavy runtime/models.
3. Import schema SQL into panel DB; optional demo seed.
4. On-server only: `config/local.production.php` with DB password + production flags.
5. Demo script: **turn off require Face** (and use code/QR ± GPS on HTTPS).
6. Keep full tree (including face-service) for XAMPP locally.

**Defer:** Vercel split; deploying face-service to InfinityFree; claiming live face match.

**Optional upgrade path:** Oracle Always Free A1 full stack when Console is Active (ATT-8).

---

## 6. Summary table

| Area | Status |
|------|--------|
| Stack identity | READY (confirmed) |
| Static UI + PHP co-host | READY |
| Relative API URLs | READY |
| Fail-closed face null provider | READY |
| XAMPP defaults | READY |
| Remote DB / production env injection | NEEDS CONFIG |
| `display_errors` / HTTPS session proxy | NEEDS CORRECTION |
| Root `DirectoryMatch` / missing `vendor/.htaccess` | NEEDS CORRECTION |
| Composer platform PHP 8.3 check on 8.1 hosts | NEEDS CORRECTION / NEEDS CONFIG |
| Face on InfinityFree | BLOCKING (use Option B or separate VPS) |
| Live deploy without Alice credentials | BLOCKING (by design) |

**Overall for presentation prep:** **NEEDS CONFIG + NEEDS CORRECTION** — proceed to Phase B minimal prep; then Alice confirms host choice and uploads.
