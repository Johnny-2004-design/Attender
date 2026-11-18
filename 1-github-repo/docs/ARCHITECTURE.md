# ATTENDER architecture

## Brand
**ATTENDER** — *Smart Attendance, Verified Presence*

## Stack
HTML/CSS/JS frontend (Bootstrap 5) + modular PHP 8.1 API + MySQL/MariaDB. XAMPP-friendly. No heavy PHP framework.

## Principles
1. Students scan, lecturers manage, the server verifies. The browser only collects evidence.
2. One verification engine for QR and one-time code.
3. Identity, lecturer-class registration and course enrolment are three separate things.
4. Every page talks to `AT.api` (`assets/js/api.js` → `/api/`).

## Layout
```
config/          app.php, database.php, security.php
api/             index.php front controller + .htaccess
bootstrap/       app.php
src/Controllers  Auth, Dashboard, Class, Course, Student, Session, Attendance, Record, Settings
src/Services     VerificationEngine, QrTokenService, CodeService, GeoService, Face*, Report*, Audit*, Settings*
src/Middleware   Auth, Csrf, LecturerScope
database/        schema.sql, seed.php, seed.sql
storage/         templates, logs, exports (not public)
assets|auth|lecturer|student|index.html  existing UI
```

## Verification order (server)
session → registration/enrolment → GPS (Haversine) → face match + liveness → duplicate (UNIQUE) → decision.

## Face
`FaceServiceInterface` with `null` (safe pending) and `http`/`onnx` providers. Never fakes success.  
Bundled microservice: `face-service/` (FastAPI + InsightFace + ONNX Runtime) speaking `/enroll` + `/verify`.
