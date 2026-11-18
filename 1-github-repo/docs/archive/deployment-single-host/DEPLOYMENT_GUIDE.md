# Deployment guide — temporary free HTTPS (UCC presentation)

**Do not rebuild the app.** Upload the prepared package, configure DB + production local config, enable SSL.

## Package

Use `<local path>` (PHP app + vendor, **no** secrets, **no** face-service runtime).

Full local tree (XAMPP + face-service sources): `<local path>` and `<local path>`.

## Recommended host

InfinityFree (or similar free PHP+MySQL). **Vercel is not required.**

Alice must create/confirm the account and perform panel steps. Agents must **not** be given passwords in chat.

## Steps (InfinityFree-style)

1. **Panel:** Create site/subdomain + MySQL database. Note host, db name, user. Set PHP ≥ 8.1 (prefer 8.3).
2. **phpMyAdmin:** Select the DB → import `database/remote_import.sql` (after replacing `YOUR_PANEL_DB_NAME`) → optional `database/remote_seed_lecturers.sql`.
3. **File Manager / FTP:** Upload `attender-presentation-upload.zip` → extract so `index.html` sits in **`htdocs/`** (site root), not a nested extra folder.
4. **Config on server only:** Copy `config/local.production.php.example` → `config/local.production.php`. Set:
   - `ATTENDER_URL` to `https://your-subdomain…`
   - `ATTENDER_DB_*` to panel values
   - `ATTENDER_DB_PASS` to the panel password (**never** commit / never put in the zip)
   - Keep `ATTENDER_FACE_PROVIDER=null`
5. **SSL:** Enable Free SSL; wait until HTTPS loads without certificate warnings.
6. **Smoke (Alice on live HTTPS):** open site → login → dashboard → open session with **Face off** → student join/code path.  
   Camera/GPS checks need a real phone on HTTPS — label those as live-only.

## What will not work on this host

- Real face enroll/match (Python InsightFace). Use Face off / fail-closed.
- `php database/seed.php` over SSH (usually unavailable).

## After the presentation

See `ROLLBACK_GUIDE.md` and `SECURITY_CHECKLIST.md` (remove demo accounts, delete `local.production.php` from the host if retiring the site).

## Prior site note

An earlier attempt used `your-site.example` and was still **502** when last checked from an agent environment. Treat that as unfinished until Alice confirms HTTPS login works — this guide does **not** claim a live success.
