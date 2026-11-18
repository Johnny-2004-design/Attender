# Deployment checklist

## Before upload

- [ ] Alice confirmed **single PHP host** (not Vercel split)
- [ ] Alice accepts **no live face** on free PHP host (Face off for demo)
- [ ] Upload zip built without `.env`, `local.production.php`, or secrets
- [ ] `remote_import.sql` ready; `YOUR_PANEL_DB_NAME` placeholder understood
- [ ] XAMPP copy still intact locally for rehearsal

## Panel

- [ ] Site / subdomain created
- [ ] PHP ≥ 8.1 (prefer 8.3)
- [ ] MySQL database created; credentials noted privately
- [ ] Free SSL enabled

## Database

- [ ] `remote_import.sql` imported into panel DB
- [ ] Optional `remote_seed_lecturers.sql` imported
- [ ] No reliance on `CREATE DATABASE` on shared host

## Files

- [ ] Zip extracted to `htdocs/` with `index.html` at web root
- [ ] `config/local.production.php` created **on server** with real DB password
- [ ] `storage/` writable
- [ ] `vendor/.htaccess` present (deny)

## Functional (live HTTPS — Alice)

- [ ] `https://…/` loads
- [ ] Lecturer login works
- [ ] CSRF login flow OK (cookie Secure on HTTPS)
- [ ] Open session with Face **disabled**
- [ ] Code or QR resolve works
- [ ] Excel/PDF export smoke (if Dompdf/Spreadsheet extensions OK)
- [ ] Face-required path either unused or correctly fails/pending (not fake PRESENT)

## Not claimed by agent automation

- [ ] Live HTTPS tests performed by Alice (agents must not invent pass results)
