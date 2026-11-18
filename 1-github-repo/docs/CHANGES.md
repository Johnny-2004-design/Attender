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
