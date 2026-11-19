# Browser face matching (works on InfinityFree / any PHP host)

**How it works.** The student's phone runs face-api.js, turns a camera frame into 128 numbers (a *descriptor*) and sends only those numbers. PHP (`FaceBrowserProvider`) stores the enrolment descriptors and compares later ones. No photos are sent or stored.

**Turn it on** — in `config/local.production.php` set `'ATTENDER_FACE_PROVIDER' => 'browser'`. Upload `assets/vendor/faceapi/` (library + 3 model files, ~8 MB). Models download to a phone once, then are cached.

**Enrolment.** New students: the registration page captures 3 frontal faces. Students registered earlier: `student/face.html` (index number + registered phone number). If a face is already on file the lecturer must delete it first (Students page).

**Matching.** Distance ≤ 0.60 (face-api.js standard) between the median probe and the nearest enrolled descriptor. Shown as similarity `1 − d/3`, so the default 80 % threshold = 0.60. The lecturer slider can only make it stricter (higher %), never looser than 0.60.

**Liveness (soft).** The page asks for a random action (blink / turn left / turn right / smile) between frontal captures; the server requires a "passed" report and rejects byte-identical frames.

**Limits — be honest with users.**
* Descriptors are computed on the student's device, so a technical cheater can post crafted data, e.g. from a photo of a classmate. This stops proxy attendance by hand, not a determined attacker. For stronger guarantees use a server-side engine (`FACE_INTEGRATION.md`).
* Different people can sit close together; 0.60 is a standard compromise. Twins/siblings may match.
* Lecturer-side retention (Settings → Privacy) deletes templates after 12 / 6 months, or when the student leaves the class (0). It runs when a lecturer opens/closes a session or saves settings (no cron needed).
