# Database setup

## XAMPP (local — keep working)

1. Create database `attender` (utf8mb4) in phpMyAdmin, **or** import `database/attender_full.sql` (includes `CREATE DATABASE`).
2. Optional full demo graph: `php database/seed.php` from the project root.
3. Defaults in `config/database.php` match XAMPP (`root` / empty password).

Files:

| File | Use |
|------|-----|
| `database/attender_full.sql` | One-file local schema (+ CREATE DATABASE) |
| `database/schema.sql` | Schema twin |
| `database/seed.php` | Rich demo data (needs PHP CLI) |
| `database/migrations/20261117_face_embedding_ready.sql` | Additive face columns for older DBs |

## Remote shared host (InfinityFree / similar)

1. Create an empty MySQL database in the control panel (note host, name, user, password).
2. Open phpMyAdmin → **select that database**.
3. Edit `database/remote_import.sql`: replace `YOUR_PANEL_DB_NAME` with the panel database name (or comment out the `USE` line if the DB is already selected).
4. Import `remote_import.sql`.
5. Optional demo logins: import `database/remote_seed_lecturers.sql`.
6. Put the same host/name/user/password into server-local `config/local.production.php` (password on server only).

**Do not** import `attender_full.sql` on InfinityFree if it tries `CREATE DATABASE` — panel users usually lack that privilege. Use `remote_import.sql`.

## Demo accounts (after remote seed)

| Email | Password |
|-------|----------|
| `k.mensah@ucc.edu.gh` | `<dev-seed-password>` |
| `a.owusu@ucc.edu.gh` | `<dev-seed-password>` |

Rich class/course/attendance history from `seed.php` is **not** included in the remote lecturer seed (no SSH). For a fuller story on remote, run a local seed, export selected tables, or create classes in the UI during rehearsal.

## Presentation face tip

After login: **Settings → turn off Require Face** (or uncheck Face when opening a session). Face-service cannot run on InfinityFree; null provider will not mark PRESENT for face-required sessions.
