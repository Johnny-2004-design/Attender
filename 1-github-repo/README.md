# ATTENDER — Smart Attendance, Verified Presence

Lecturer + student attendance system: QR / one-time-code check-in, GPS (Haversine) verification,
optional face verification, audited corrections, Excel/PDF reports.

Plain HTML/CSS/JS (Bootstrap 5) frontend · modular PHP 8.1+ JSON API · MySQL/MariaDB.

## Repository layout

```
frontend/   Static site (deploy to Netlify)
  index.html, auth/, lecturer/, student/, assets/
  assets/js/config.js   <- the one file that holds the backend API URL
  _headers              <- Netlify security / camera+GPS permission headers
backend/    PHP API + database (deploy to InfinityFree or any PHP host)
  api/ bootstrap/ config/ src/ storage/ database/ composer.json
docs/       Architecture, testing, deployment, face-service notes
  docs/archive/   historical hand-over notes (superseded by docs/DEPLOYMENT.md)
netlify.toml        Netlify build settings when deploying from GitHub
```

## Deployment targets

| Part | Goes to | Guide |
|---|---|---|
| `frontend/` | Netlify | [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md#2-netlify-frontend) |
| `backend/` (+ `composer install`) | InfinityFree | [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md#3-infinityfree-backend) |

The frontend calls the backend cross-origin. `frontend/assets/js/config.js` (`apiBase`) says where the
API is; `ATTENDER_ALLOWED_ORIGINS` in the backend settings says which frontend may call it.

## Local development (XAMPP)

1. Merge the *contents* of `frontend/` and `backend/` into one folder, e.g. `C:\xampp\htdocs\attender\`
   (so `index.html` and `api/` sit side by side — the original single-folder layout). Leave
   `apiBase: ''` in `assets/js/config.js`: the API URL is then derived from the site's own origin, as before.
2. Create DB `attender` (utf8mb4_unicode_ci) and import `backend/database/schema.sql`.
3. `cd backend && composer install`
4. Optional dev data: `php database/seed.php` (**dev only** — creates demo accounts with known passwords).
5. Defaults: host `127.0.0.1`, user `root`, empty password, db `attender` (override with `ATTENDER_DB_*` env vars).

Smoke test without Apache: `cd backend && php -S 127.0.0.1:8080 router.php`.

## Secrets

No credentials are committed. Production DB settings live in `backend/config/local.production.php`
(git-ignored; template: `local.production.php.example`). The frontend contains no secrets.
`database/seed.php` / `seed.sql` / `remote_seed_lecturers.sql` are **development-only** demo data.
