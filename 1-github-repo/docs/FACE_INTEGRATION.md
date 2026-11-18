# Face integration — InsightFace microservice (hardened)

ATTENDER never fabricates a successful face match or face enrollment.

## Rules (post-audit)

1. **Enrollment success** = Python saved an embedding **and** PHP set `facial_templates.embedding_ready=1` / `face_enrolled=1`.
2. Local JPEG audit storage alone is **not** enrollment.
3. Face-required attendance is blocked until `embedding_ready=1`.
4. Exactly **one** face per image; quality gates apply.
5. Soft liveness is motion-only — **not** certified anti-spoof.
6. Face-service down → `pending` or hard `fail` (config) — **never PRESENT**.

## PHP environment

```bat
ATTENDER_FACE_PROVIDER=http
ATTENDER_FACE_API_URL=http://127.0.0.1:8090
ATTENDER_FACE_API_KEY=attender-face-dev-key
ATTENDER_FACE_THRESHOLD=0.80
ATTENDER_FACE_UNAVAILABLE_POLICY=pending
```

`FaceHttpProvider` requires both URL and API key. See `docs/FACE_AUDIT_REPORT.md` for migration + Windows commands.

## Contract

`POST /enroll`, `POST /verify`, `DELETE /enroll/{student_id}`, `GET /health`  
Details: [`../face-service/README.md`](../face-service/README.md)

## Privacy (Act 843 awareness)

Minimise retention; support deletion (`DELETE /enroll/{id}` / lecturer API); protect embedding files; never log biometric vectors. This is **not** a legal compliance certification.
