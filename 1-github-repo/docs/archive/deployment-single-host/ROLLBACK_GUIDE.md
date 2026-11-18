# Rollback guide

## Abort remote deploy (keep local XAMPP)

1. Stop using the public URL; disable the free site or remove files from `htdocs/`.
2. Drop the panel MySQL database (or truncate tables) from phpMyAdmin if desired.
3. Delete server-only `config/local.production.php`.
4. Continue on XAMPP with `<local path>` (or `attender.zip`) — production local file was never required locally.

## Bad config on host

1. Fix or remove `config/local.production.php`.
2. Re-upload a clean extract of `attender-presentation-upload.zip` if files were corrupted.
3. Re-import `remote_import.sql` only if schema is broken (will not wipe data with `IF NOT EXISTS`; for a clean slate drop tables manually first).

## Revert code prep in the tree

Prepared changes are intentional and XAMPP-safe when `local.production.php` is absent:

- `bootstrap/app.php` local production loader
- HTTPS / `X-Forwarded-Proto` session + `base_url()`
- Safer root `.htaccess` + `vendor/.htaccess`
- Relaxed Composer platform check (≥ 8.1)

To roll back those edits, restore from `<local path>` and re-apply only what you still need.

## Face / demo behaviour

Turning Face back on without a face-service returns fail-closed / pending behaviour — that is expected, not a rollback failure.
