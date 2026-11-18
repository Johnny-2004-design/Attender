# Hosting requirements — ATTENDER (presentation)

## Minimum (PHP UI + API + MySQL)

| Requirement | Notes |
|-------------|--------|
| PHP **≥ 8.1** (prefer **8.3**) | `ext-pdo`, `pdo_mysql`, `mbstring`, `json`; zip/xml/gd helpful for Excel/PDF |
| Apache (or LiteSpeed) with `mod_rewrite` | `api/.htaccess` rewrites; `AllowOverride` for `.htaccess` |
| MySQL / MariaDB | utf8mb4; InnoDB; remote host/user from panel |
| HTTPS / Free SSL | Required for phone camera + precise GPS |
| Writable `storage/` | logs, exports, face audit image dirs |
| Disk | ~15–25 MB app + vendor (without face-service) |

**Not required for presentation:** Node, Vercel, Redis, SSH, Composer on the server (vendor is shipped).

## Not supported on InfinityFree-class free PHP hosts

- Python / FastAPI / InsightFace / ONNX (`face-service/`)
- Long-running workers
- Reliable custom process environment UI (use `config/local.production.php` instead)

## Recommended free architecture

**Single PHP+MySQL host** (InfinityFree or similar) + FaceNullProvider + **require Face off** for the demo.

## If live face is mandatory

Separate Always Free / cheap VPS (e.g. Oracle A1) running PHP + MySQL + face-service — see `docs/ORACLE_PHASE1_READINESS.md` if present. Not part of the InfinityFree upload zip.

## Local XAMPP (unchanged)

Apache + MySQL on Windows/macOS/Linux. Defaults: host `127.0.0.1`, user `root`, empty password, database `attender`. Do **not** place `config/local.production.php` on XAMPP unless intentionally overriding.
