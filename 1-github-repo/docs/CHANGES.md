# Face step validates before continuing; location fix (Nov 2026)

* `VerificationEngine::checkFace` + route `POST attendance/face-check`: the scan page's Face step first checks the student HAS a registered face (stops before the camera opens with "Face enrollment incomplete…"), then, after capture, must MATCH it before moving to the next step. Records nothing; `submit()` still re-verifies. Returns yes/no only and throttles repeated failures.
* Registration / enrol pages compare each capture with the earlier ones on the phone (`AT.face.assertSamePerson`), and require the whole face to be centred inside the frame.
* Lecturer "Use my current location": keeps the best fix over 15 s, accepts up to ±250 m with an honest warning (was refused above ±100 m).

# Browser face matching + enforcement (Nov 2026)

* **New** `backend/src/Services/FaceBrowserProvider.php`, provider `browser` (see `docs/FACE_BROWSER.md`); `FaceService`/interface accept it (`enroll(..., array $extra = [])`).
* **New** `frontend/assets/js/face.js`, `frontend/assets/vendor/faceapi/` (face-api.js 1.7.15 + models), `frontend/student/face.html` (enrol / re-enrol), route `POST registration/face`.
* `join.html` and `scan.html` use the browser engine when the server reports `faceMode: browser`; legacy photo flow kept for `http`/`null` providers.
* **Max code attempts** now enforced (strictest value among the student's lecturers; locks wrong-code guessing for the code's lifetime) and stored on each code.
* **Retention** now enforced by `Services/FaceRetention.php` (12 / 6 months, or when the student leaves the class).

# Settings / GPS fixes (Nov 2026)

| File | Change |
|---|---|
| `backend/src/Services/SettingsService.php` | `allowDisableFace` and `retention` are now real settings (they were silently dropped on save). Every value is validated and clamped (radius 10–500 m, accuracy 10–500 m, threshold 0.60–0.95, ...), both on save and on read. |
| `backend/src/Services/VerificationEngine.php` | Uses the lecturer's face-threshold slider (was ignored; env value was always used). Min GPS accuracy read through `SettingsService`. `resolve` also returns `minAccuracy`. |
| `backend/src/Services/GeoService.php` | Rejection messages now include the numbers (distance / accuracy) so a failure can be diagnosed. |
| `backend/src/Controllers/SessionController.php` | Radius clamped to 10–500 m. Face check can only be turned off for a session when `allowDisableFace` is ticked (enforced server-side). |
| `backend/src/Controllers/AttendanceController.php` | Passes `minAccuracy` to the student page. |
| `frontend/lecturer/settings.html` | Range checks, error toast if saving fails, paste-coordinates box, refuses to save a laptop-grade (>100 m) location, accurate label for the accuracy limit. |
| `frontend/lecturer/attendance-live.html` | Radius slider allows up to 500 m (it stopped at 200, so a saved 5000 became 200); same location guard; error toast if opening fails. |
| `frontend/student/scan.html` | Stops early with a plain explanation when the device's GPS reading is too imprecise, before face capture. |

Known, not changed: `maxCodeAttempts` and `retention` are stored but not enforced anywhere yet; face matching needs a real face engine (see `docs/FACE_INTEGRATION.md`).

# What changed vs. the uploaded project

## Added / modified (minimal, behaviour-preserving)
| File | Change |
|---|---|
| `frontend/assets/js/config.js` (new) | Holds `apiBase` (backend URL) and `apiExtraQuery`. Empty `apiBase` = original same-origin behaviour. |
| `frontend/assets/js/api.js` | Reads config; sends cookies with `credentials: 'include'` only when the API is on another origin; appends `apiExtraQuery`. |
| 16 HTML pages | Added `<script src=".../config.js">` before `api.js`. No markup/design change. |
| `frontend/_headers` (new) | Security headers; `Permissions-Policy` keeps camera + GPS working for the scan pages. |
| `backend/api/index.php` | CORS: exact-origin allowlist (`ATTENDER_ALLOWED_ORIGINS`) with credentials, proper preflight. Previously sent credentials header with no allowed origin (non-functional cross-origin). |
| `backend/config/app.php` | New `allowed_origins` setting. |
| `backend/src/Support/Session.php` | Cookie `SameSite=None; Secure` automatically when HTTPS **and** allowed origins are set; otherwise `Lax` as before. |
| `backend/config/local.production.php.example` | Added `ATTENDER_ALLOWED_ORIGINS`. |
| `database/smoke_api.sh` | Credentials read from `SMOKE_EMAIL` / `SMOKE_PASSWORD` env vars instead of hard-coded. |
| `database/remote_import.sql` → `01_schema.sql` | `USE <placeholder>;` commented out (it made phpMyAdmin imports fail); real hosting DB name replaced with a placeholder. |

## Removed
| Item | Reason |
|---|---|
| Demo-login box on `auth/login.html` | Printed seed email + passwords on the public page. |
| `assets/js/data.js` | Old fixture layer, not referenced by any page. |
| `.env.example`, `.env.production.example` | Nothing reads `.env`; settings live in `local.production.php` / env vars. Misleading (one listed a dev face-API key). |
| `router.php`, `seed.php`, `seed.sql`, `remote_seed_lecturers.sql`, `smoke_api.sh`, `schema.sql`, `attender_full.sql` | Not removed from GitHub, but **excluded from the InfinityFree upload** (dev/CLI-only, or duplicate of `01_schema.sql`). |
| Vendor `tests/`, `docs/`, `examples/`, `guides/`, `.github/`, `*.md` | Not needed at runtime (PDF + XLSX generation re-tested after pruning). |
| Real hosting identifiers (DB name, site URL) and literal demo passwords in docs | Sensitive/identifying. Remaining dev-seed passwords exist only in `database/seed.php`. |
| Old `deployment/` and `docs/` hand-over notes | Moved to `docs/archive/` in the repo (describe the superseded single-host setup). |

## Not changed
All pages, styling, features, DB schema/relationships, auth, CSRF, verification engine, face provider logic.
