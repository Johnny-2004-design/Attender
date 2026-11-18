# Security checklist (temporary public demo)

## Must do before sharing the URL

- [ ] `ATTENDER_ENV=production` and `ATTENDER_DEBUG=0`
- [ ] PHP `display_errors` off (bootstrap enforces when production / debug off)
- [ ] DB password only in server-local `local.production.php` or host env — **not** in git/zip/chat
- [ ] HTTPS enabled; session cookie `Secure` + `HttpOnly` + `SameSite=Lax`
- [ ] Sensitive dirs denied (`config`, `src`, `bootstrap`, `database`, `storage`, `vendor`)
- [ ] Face provider `null` unless a private face-service is intentionally wired
- [ ] Demo passwords treated as public — rotate or delete after UCC event

## Do not

- Commit `.env` or `config/local.production.php`
- Paste panel/FTP/DB passwords into chat
- Expose face-service port publicly
- Leave `ATTENDER_DEBUG=1` on the public host
- Rely on seed accounts for real student data

## After presentation

- [ ] Change or delete demo lecturer accounts
- [ ] Drop or restrict the free site if no longer needed
- [ ] Remove server `local.production.php` when retiring the deploy
- [ ] Keep XAMPP local as the development source of truth
