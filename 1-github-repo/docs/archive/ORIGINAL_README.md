# ATTENDER — Smart Attendance, Verified Presence

Complete lecturer + student attendance system: reusable frontend UI wired to a modular **PHP + MySQL** backend.

Students scan. Lecturers manage. ATTENDER verifies.

## Stack

| Layer | Choice |
|---|---|
| UI | Existing HTML5 / CSS3 / Bootstrap 5 / vanilla JS (kept from source zip) |
| API | Modular PHP 8.1+ (no heavy framework) |
| DB | MySQL 8 / MariaDB 10.11+ |
| Reports | Dompdf (PDF) + PhpSpreadsheet (Excel) via Composer |
| Face | Pluggable `FaceServiceInterface` + optional `face-service/` (FastAPI / InsightFace / ONNX) |

**Why this stack:** matches the shipped frontend, runs cleanly on XAMPP, stays simple to host later on shared PHP hosting or a VPS.

## What works

* Lecturer auth (login/register/logout) with `password_hash`, PHP sessions, CSRF
* Classes, courses, invitations, students, registration approve/reject
* Course enrolment (independent of class registration)
* Live attendance sessions: QR tokens + one-time codes
* Shared verification engine: session → registration/enrolment → GPS Haversine → face/liveness interface → duplicate unique constraint → PRESENT/late/pending/fail
* Offline student queue → server verifies later (never counts as present client-side)
* Records, audited corrections, dashboard charts, Excel/PDF export
* Lecturer isolation on every scoped query
* Seed: 2 lecturers, 3 classes, 6 courses, 30 students, 12 closed sessions + records

## Face recognition (honest status)

Client capture + liveness prompts are real. PHP stores enrolment/probe JPEGs under `storage/templates/`.

Default provider (`ATTENDER_FACE_PROVIDER=null`) **never marks PRESENT for face-required sessions** — it returns **pending review**.

### Enable the bundled InsightFace service

```bat
cd face-service
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
copy .env.example .env
uvicorn app.main:app --host 127.0.0.1 --port 8090
```

Then set (same `API_KEY` as in `face-service/.env`):

```
ATTENDER_FACE_PROVIDER=http
ATTENDER_FACE_API_URL=http://127.0.0.1:8090
ATTENDER_FACE_API_KEY=attender-face-dev-key
ATTENDER_FACE_THRESHOLD=0.80
```

Details: [`face-service/README.md`](face-service/README.md) · [`docs/FACE_INTEGRATION.md`](docs/FACE_INTEGRATION.md). Soft liveness is motion-based only (not certified anti-spoof).

## XAMPP install (Windows)

1. Install [XAMPP](https://www.apachefriends.org/) with Apache + MySQL + PHP 8.1+.
2. Copy this folder to `C:\xampp\htdocs\attender`.
3. Start **Apache** and **MySQL** from the XAMPP Control Panel.
4. Open phpMyAdmin → create database `attender` (collation `utf8mb4_unicode_ci`).
5. Confirm `config/database.php` (defaults work for XAMPP root with empty password):
   ```php
   'host' => '127.0.0.1',
   'port' => 3306,
   'database' => 'attender',
   'username' => 'root',
   'password' => '',
   ```
6. In a terminal:
   ```bat
   cd C:\xampp\htdocs\attender
   C:\xampp\mysql\bin\mysql -u root attender < database\schema.sql
   composer install
   php database\seed.php
   ```
7. Browse to `http://localhost/attender/`.
8. Sign in with a seed lecturer (below).

### Seed logins

| Role | Email | Password |
|---|---|---|
| Lecturer | `k.mensah@ucc.edu.gh` | `<dev-seed-password>` |
| Lecturer | `a.owusu@ucc.edu.gh` | `<dev-seed-password>` |

Invite tokens (student registration): `inv_L300CS_9fK2xQ`, `inv_L200IT_r7Wm3B`  
Join URL example: `http://localhost/attender/student/join.html?invite=inv_L300CS_9fK2xQ`

## LAN access (phones on the same Wi‑Fi)

1. Find the laptop IPv4 (`ipconfig` on Windows).
2. Open `http://192.168.x.x/attender/` on the phone.
3. Allow Apache through Windows Firewall (private networks).

### Camera + GPS need HTTPS on phones

Browsers block `getUserMedia` and precise geolocation on plain `http://192.168…` (except `localhost`). Options:

* Test camera/GPS on the laptop via `http://localhost/attender/`.
* Or enable LAN HTTPS (self-signed or mkcert) — see `docs/DEPLOYMENT.md`.
* Until HTTPS is ready, lecturers can use **one-time codes** (no camera required for the code path; GPS/face still need secure context when enabled).

## Local smoke test without XAMPP (PHP built-in server)

```bash
export ATTENDER_DB_HOST=127.0.0.1 ATTENDER_DB_PORT=3306
export ATTENDER_DB_USER=root ATTENDER_DB_PASS= ATTENDER_DB_NAME=attender
composer install
mysql -u root attender < database/schema.sql
php database/seed.php
php -S 0.0.0.0:8080 router.php
```

Open `http://127.0.0.1:8080/`.

## Project layout

```
index.html, auth/, lecturer/, student/, assets/   # existing UI (wired to API)
api/index.php                                      # JSON API front controller
config/                                            # app, database, security
src/Controllers|Models|Services|Middleware|Support
database/schema.sql, seed.php, seed.sql
storage/templates|logs|exports                     # not web-public when Apache is configured
docs/TESTING.md, docs/DEPLOYMENT.md
composer.json
```

## Security controls (server-enforced)

* RBAC: lecturer session required for manage APIs; students use invite/token flows
* Lecturer isolation (`lecturer_id` on owned tables + scoped queries)
* CSRF token on mutating requests (`X-CSRF-Token` / `_csrf`)
* Prepared statements (PDO)
* `password_hash` / `password_verify`
* QR/code secrets stored hashed; plaintext kept only while session is open for display
* `UNIQUE(session_id, student_id)` on attendance records

## Later live hosting

See `docs/DEPLOYMENT.md` for HTTPS, env vars, face microservice, backups, and hardening.
