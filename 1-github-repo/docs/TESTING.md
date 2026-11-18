# ATTENDER — testing notes

## Environment used during build

* PHP 8.3 CLI + MariaDB (custom socket) were available in the cloud agent VM.
* Composer packages: `dompdf/dompdf`, `phpoffice/phpspreadsheet`.
* Automated API smoke tests were run against the seeded database (login, dashboard, open session, resolve/submit attendance without face, close session).
* Browser camera/GPS/liveness were **not** end-to-end tested on a physical phone in this environment.
* Face match success paths were **not** claimed working — default provider returns pending.

## Manual checklist (XAMPP)

### Auth
1. Open `/attender/auth/login.html` — seed login succeeds; wrong password fails.
2. Register a new lecturer — lands on dashboard; cannot reuse an existing email.
3. Logout clears session; dashboard APIs return 401.

### Lecturer isolation
1. Log in as Mensah — note class/course IDs.
2. Log in as Owusu — Mensah’s classes/students must not appear.
3. Crafted API calls with another lecturer’s IDs should 404.

### Academics
1. Create/edit class and course.
2. Create invitation → copy link → open join page → submit with 3 face captures.
3. Approve/reject registration requests; enrolment updates on approve.
4. Adjust per-student course enrolment on Students / Enrollment page.

### Live attendance
1. Open Take Attendance → start session (try QR and code methods).
2. QR canvas renders; rotate token changes payload; new code regenerates.
3. Corner / fullscreen / print modes still work from existing UI.
4. On another device/browser: `student/scan.html` with code or `?t=TOKEN`.
5. With face required + null provider: result is **pending**, not PRESENT.
6. With face disabled in session options: PRESENT/late after GPS (if required).
7. Duplicate submit → “Attendance already recorded.”
8. Outside radius (spoof coords in API) → fail reason stored; verification_attempts row written.
9. Close session → absent rows for non-attendees; tokens/codes deactivated.

### Offline queue
1. In DevTools, set offline → submit attendance → PENDING queue message.
2. Go online → queue flushes via `/api/attendance/queue` → server decides.

### Reports
1. Reports page builds tables from live records.
2. Export Excel downloads `.xlsx`; Export PDF downloads `.pdf`.

### Security smoke
1. POST without CSRF → 419.
2. SQL injection strings in login/index fields do not error-leak schema.
3. Direct browse to `/config/` or `/src/` denied (Apache).

## API smoke (curl)

```bash
# CSRF + login
curl -c cookies.txt -b cookies.txt 'http://localhost/attender/api/index.php?r=csrf'
# copy csrf from JSON into header below
curl -c cookies.txt -b cookies.txt -H 'Content-Type: application/json' -H 'X-CSRF-Token: TOKEN' \
  -d '{"email":"k.mensah@ucc.edu.gh","password":"<dev-seed-password>"}' \
  'http://localhost/attender/api/index.php?r=auth/login'
curl -b cookies.txt 'http://localhost/attender/api/index.php?r=dashboard'
```

## What still needs a host / device

| Feature | Needs |
|---|---|
| Real face match + liveness pass | External face API or ONNX microservice |
| Phone camera + GPS | HTTPS on LAN or production TLS |
| Printable QR in classroom | Physical printer / projector |
