# ATTENDER — split deployment (GitHub + Netlify + InfinityFree)

```
Browser ──► Netlify  (static frontend, https://<site>.netlify.app)
   │
   └─ fetch(credentials: include) ─► InfinityFree  (PHP API + MySQL, https://<backend>/api/index.php)
```

Order matters: **backend first** (you need its URL), then frontend, then tell the backend the frontend's URL.

## 1. GitHub
```bash
cd 1-github-repo
git init -b main
git add .
git status            # confirm: no config/local.production.php, no vendor/, no .env
git commit -m "Initial commit: ATTENDER"
git remote add origin https://github.com/<you>/attender.git
git push -u origin main
```

## 2. Netlify (frontend)
1. Edit `assets/js/config.js` → `apiBase: 'https://<your-backend-domain>/api/index.php'`.
2. app.netlify.com → **Add new site → Deploy manually** → drag the **`2-netlify-frontend`** folder in.
   (Or connect the GitHub repo: Base/Publish directory `frontend`, no build command — `netlify.toml` does this.)
3. Optionally rename the site: **Site configuration → Change site name**.
4. Put the final site URL into the backend's `ATTENDER_ALLOWED_ORIGINS` (step 3.6).

## 3. InfinityFree (backend)
1. Create the hosting account + domain/subdomain. Enable **free SSL** (HTTPS is mandatory: cross-site cookies and phone camera/GPS need it).
2. Panel → **PHP version ≥ 8.1** (8.3 preferred).
3. Panel → **MySQL Databases** → create a database; note host (`sqlXXX.infinityfree.com`), database, user, password.
4. Panel → phpMyAdmin → select that database → **Import** `3-infinityfree-backend/database-import/01_schema.sql`.
   (`02_optional_migration…` is only for a database created from an older schema.)
5. Edit `3-infinityfree-backend/htdocs/config/local.production.php`: DB host/name/user/password, `ATTENDER_URL`.
6. In the same file set `ATTENDER_ALLOWED_ORIGINS` to the exact Netlify origin, e.g. `https://attender.netlify.app`
   (https, no trailing slash, no path; comma-separate several; wildcards are deliberately unsupported).
7. Upload the **contents** of `htdocs/` into the site's `htdocs/` with FTP (FileZilla; credentials in the panel).
   `vendor/` is ~1,000 small files — let the transfer finish. Do **not** upload `database-import/`.
8. Verify: open `https://<backend>/api/index.php?r=csrf&i=1` → `{"ok":true,"csrf":"…"}`.
9. Create the first lecturer through the site's **Register** page (no demo accounts are shipped).

## 4. Verify end to end
- Open the Netlify URL → Register / Sign in → dashboard loads.
- DevTools → Network: calls go to the backend with status 200, no CORS errors.
- Open an attendance session; scan/enter the code from a phone over HTTPS.

## Troubleshooting
| Symptom | Cause / fix |
|---|---|
| Console: *blocked by CORS policy* | Netlify origin missing/misspelled in `ATTENDER_ALLOWED_ORIGINS` (exact match). |
| Login works, next page logged out / "Invalid CSRF" | Browser blocks third-party cookies (Safari/iOS, Firefox strict, Brave). See the note below. |
| API returns an HTML page ("requires JavaScript") | InfinityFree anti-bot page. Keep `apiExtraQuery: 'i=1'` in `config.js`; open the backend URL once in the browser. |
| `Server error.` | Set `ATTENDER_DEBUG` to `'1'` briefly in `local.production.php` to see the message; check `storage/logs`. |
| Excel/PDF export fails | Confirm PHP ≥ 8.1 and that `vendor/` uploaded completely. |
| Database connection failed | Host is `sqlXXX.infinityfree.com`, not `localhost`; user/db have the `if0_` prefix. |

## Important: cookies across two sites
The session cookie is set by the backend domain while the page is on the Netlify domain, so browsers treat it as a
**third-party cookie**. Chrome/Edge (desktop + Android) currently allow it; **Safari/iOS, Firefox strict mode and Brave
block it**, which breaks login and student check-in on those browsers.
Fix for real use: put both parts under one registrable domain you own, e.g. `app.example.com` → Netlify and
`api.example.com` → InfinityFree. The cookie is then same-site and works everywhere; add `https://app.example.com`
to `ATTENDER_ALLOWED_ORIGINS` and update `apiBase`.
