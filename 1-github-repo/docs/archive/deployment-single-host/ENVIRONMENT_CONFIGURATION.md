# Environment configuration

ATTENDER reads settings via `getenv('ATTENDER_*')` in `config/app.php` and `config/database.php`.

There is **no PHP dotenv loader**. A file named `.env` is **not** auto-loaded by the app.

## Ways to configure

### 1. Host process environment (preferred on VPS)

Set variables in Apache `SetEnv`, systemd, or the panel env UI:

```
ATTENDER_ENV=production
ATTENDER_DEBUG=0
ATTENDER_URL=https://your-host.example
ATTENDER_DB_HOST=...
ATTENDER_DB_PORT=3306
ATTENDER_DB_NAME=...
ATTENDER_DB_USER=...
ATTENDER_DB_PASS=...
ATTENDER_FACE_PROVIDER=null
```

### 2. `config/local.production.php` (shared hosts)

1. Copy `config/local.production.php.example` → `config/local.production.php` **on the server only**.
2. Fill MySQL password and panel DB values.
3. `bootstrap/app.php` `putenv`s each entry **before** config loads.
4. File is **gitignored**. Never put it in the upload zip with a real password.

### 3. XAMPP local

Leave env unset. Defaults:

| Key | Default |
|-----|---------|
| DB host | `127.0.0.1` |
| DB user | `root` |
| DB pass | empty |
| DB name | `attender` |
| Face provider | `null` |
| Debug | on |

## Important variables

| Variable | Production presentation |
|----------|-------------------------|
| `ATTENDER_ENV` | `production` |
| `ATTENDER_DEBUG` | `0` |
| `ATTENDER_URL` | Public HTTPS origin (no trailing slash) |
| `ATTENDER_DB_*` | Panel MySQL values |
| `ATTENDER_FACE_PROVIDER` | `null` on InfinityFree |
| `ATTENDER_FACE_API_URL` / `_KEY` | Only if a remote face-service exists |

## Frontend base URL

`assets/js/api.js` resolves `/api/index.php` relative to the script URL. No frontend rebuild needed when the public host changes. Still set `ATTENDER_URL` for absolute links generated server-side (`base_url()`).

## Secrets policy

- Never commit `.env`, `local.production.php`, or real DB/face API keys.
- Demo lecturer passwords in seed SQL are for presentation only — rotate after the event.
