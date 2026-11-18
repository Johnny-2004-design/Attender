<?php
declare(strict_types=1);

/**
 * ATTENDER database seeder
 * Usage (from project root): php database/seed.php
 * Requires schema.sql already imported.
 */

$root = dirname(__DIR__);
require $root . '/bootstrap/app.php';

use Attender\Database;
use Attender\Services\SettingsService;
use function Attender\Support\hash_token;
use function Attender\Support\now_sql;

echo "ATTENDER seeder\n";

$pdo = Database::pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ([
    'verification_attempts','attendance_records','attendance_codes','attendance_tokens',
    'attendance_sessions','facial_templates','course_enrollments','student_registrations',
    'class_invitations','classroom_locations','courses','lecturer_classes','notifications',
    'audit_logs','system_settings','students','lecturers',
] as $t) {
    $pdo->exec("TRUNCATE TABLE {$t}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$firstNames = ['Kwame','Ama','Kofi','Akosua','Yaw','Abena','Kwesi','Efua','Nana','Esi','Kojo','Adwoa','Samuel','Grace','Daniel','Mercy','Emmanuel','Linda','Isaac','Priscilla','Michael','Patience','Joseph','Comfort','Prince','Gifty','Richmond','Joyce','Eric','Mavis'];
$lastNames = ['Mensah','Owusu','Boateng','Asante','Appiah','Darko','Ofori','Agyeman','Amoah','Quaye','Tetteh','Addo','Osei','Sarpong','Baidoo','Acheampong','Antwi','Frimpong','Nkrumah','Badu','Yeboah','Ansah','Gyamfi','Kuffour','Danso','Larbi','Ampofo','Arhin','Bonsu','Duah'];

$hash1 = password_hash('Password123!', PASSWORD_DEFAULT);
$hash2 = password_hash('Lecturer@2026', PASSWORD_DEFAULT);

$l1 = Database::insert(
    'INSERT INTO lecturers (name, title, email, phone, department, institution, password_hash, created_at)
     VALUES (?,?,?,?,?,?,?,?)',
    ['Dr. Kojo Mensah', 'Senior Lecturer', 'k.mensah@ucc.edu.gh', '+233 24 555 0142', 'Computer Science', 'University of Cape Coast', $hash1, now_sql()]
);
$l2 = Database::insert(
    'INSERT INTO lecturers (name, title, email, phone, department, institution, password_hash, created_at)
     VALUES (?,?,?,?,?,?,?,?)',
    ['Dr. Ama Owusu', 'Lecturer', 'a.owusu@ucc.edu.gh', '+233 20 111 2233', 'Information Technology', 'University of Cape Coast', $hash2, now_sql()]
);

foreach ([$l1, $l2] as $lid) {
    foreach (SettingsService::defaults() as $k => $v) {
        Database::insert(
            'INSERT INTO system_settings (lecturer_id, setting_key, setting_value) VALUES (?,?,?)',
            [$lid, $k, json_encode($v)]
        );
    }
}

Database::insert(
    'INSERT INTO classroom_locations (lecturer_id, name, latitude, longitude, default_radius_m, is_default, created_at)
     VALUES (?,?,?,?,?,1,?)',
    [$l1, 'Lecture Hall A', 5.1153, -1.2786, 30, now_sql()]
);

$c1 = Database::insert(
    'INSERT INTO lecturer_classes (lecturer_id, name, programme, department, level, academic_year, description, created_at)
     VALUES (?,?,?,?,?,?,?,?)',
    [$l1, 'Level 300 Computer Science', 'BSc Computer Science', 'Computer Science', '300', '2026/2027', 'Third-year Computer Science students.', now_sql()]
);
$c2 = Database::insert(
    'INSERT INTO lecturer_classes (lecturer_id, name, programme, department, level, academic_year, description, created_at)
     VALUES (?,?,?,?,?,?,?,?)',
    [$l1, 'Level 200 Information Technology', 'BSc Information Technology', 'Computer Science', '200', '2026/2027', 'Second-year IT students.', now_sql()]
);
$c3 = Database::insert(
    'INSERT INTO lecturer_classes (lecturer_id, name, programme, department, level, academic_year, description, created_at)
     VALUES (?,?,?,?,?,?,?,?)',
    [$l2, 'Level 100 Foundation IT', 'BSc Information Technology', 'Information Technology', '100', '2026/2027', 'First-year foundation class.', now_sql()]
);

$courses = [
    [$l1, $c1, 'CSC 305', 'Data Structures', 'First', 'Lists, trees, graphs and algorithm analysis.'],
    [$l1, $c1, 'CSC 311', 'Database Systems', 'First', 'Relational design, SQL and transactions.'],
    [$l1, $c1, 'CSC 321', 'Software Engineering', 'First', 'Process models, requirements and testing.'],
    [$l1, $c2, 'ITC 205', 'Web Technologies', 'First', 'HTML, CSS, JavaScript and PHP basics.'],
    [$l1, $c2, 'ITC 211', 'Computer Networks', 'First', 'Protocols, addressing and LAN design.'],
    [$l2, $c3, 'ITC 101', 'Intro to Computing', 'First', 'Foundations of computing systems.'],
];
$courseIds = [];
foreach ($courses as $co) {
    $courseIds[] = Database::insert(
        'INSERT INTO courses (lecturer_id, class_id, code, title, semester, academic_year, description, created_at)
         VALUES (?,?,?,?,?,?,?,?)',
        [$co[0], $co[1], $co[2], $co[3], $co[4], '2026/2027', $co[5], now_sql()]
    );
}

Database::insert(
    'INSERT INTO class_invitations (lecturer_id, class_id, token, expires_at, max_uses, use_count, status, auto_approve, created_at)
     VALUES (?,?,?,?,?,?,?,?,?)',
    [$l1, $c1, 'inv_L300CS_9fK2xQ', date('Y-m-d H:i:s', strtotime('+14 days')), 120, 20, 'active', 0, now_sql()]
);
Database::insert(
    'INSERT INTO class_invitations (lecturer_id, class_id, token, expires_at, max_uses, use_count, status, auto_approve, created_at)
     VALUES (?,?,?,?,?,?,?,?,?)',
    [$l1, $c2, 'inv_L200IT_r7Wm3B', date('Y-m-d H:i:s', strtotime('+14 days')), 80, 10, 'active', 0, now_sql()]
);

$studentIds = [];
$regIds = [];
for ($i = 0; $i < 30; $i++) {
    $classId = $i < 20 ? $c1 : $c2;
    $status = $i < 24 ? 'approved' : ($i < 28 ? 'pending' : 'rejected');
    $name = $firstNames[$i] . ' ' . $lastNames[($i * 7 + 3) % 30];
    $index = ($classId === $c1 ? '5211' : '5221') . str_pad((string) (3400 + $i * 13), 6, '0', STR_PAD_LEFT);
    $index = substr($index, 0, 10);
    $email = strtolower($firstNames[$i] . '.' . $lastNames[($i * 7 + 3) % 30]) . '@stu.ucc.edu.gh';
    $sid = Database::insert(
        'INSERT INTO students (full_name, index_number, email, phone, programme, level, created_at)
         VALUES (?,?,?,?,?,?,?)',
        [
            $name, $index, $email,
            '+233 20 ' . str_pad((string) (1000000 + $i * 37151), 7, '0', STR_PAD_LEFT),
            $classId === $c1 ? 'BSc Computer Science' : 'BSc Information Technology',
            $classId === $c1 ? '300' : '200',
            date('Y-m-d H:i:s', strtotime('-' . (30 - $i) . ' days')),
        ]
    );
    $studentIds[$i] = $sid;
        $regId = Database::insert(
            'INSERT INTO student_registrations (lecturer_id, class_id, student_id, status, face_enrolled, consented_at, decided_at, created_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $l1, $classId, $sid, $status,
                0, // seed has audit placeholders only — not real embeddings
                date('Y-m-d H:i:s', strtotime('-' . (30 - $i) . ' days')),
                $status === 'pending' ? null : date('Y-m-d H:i:s', strtotime('-' . (29 - $i) . ' days')),
                date('Y-m-d H:i:s', strtotime('-' . (30 - $i) . ' days')),
            ]
        );
    $regIds[$i] = $regId;

    if ($status !== 'rejected') {
        // placeholder facial template marker (no binary; provider null)
        Database::insert(
            'INSERT INTO facial_templates (student_id, lecturer_id, provider, template_path, embedding_meta, is_active, embedding_ready, created_at)
             VALUES (?,?,?,?,?,1,0,?)',
            [$sid, $l1, 'null', 'templates/' . $sid . '/placeholder.txt', json_encode(['seed' => true, 'note' => 'placeholder — not a real embedding']), now_sql()]
        );
        @mkdir($root . '/storage/templates/' . $sid, 0750, true);
        file_put_contents($root . '/storage/templates/' . $sid . '/placeholder.txt', "Seed placeholder — replace via registration capture\n");
    }

    if ($status === 'approved') {
        $enrolled = $classId === $c1 ? [0, 1, 2] : [3, 4];
        if ($i === 3) $enrolled = [0, 1];
        if ($i === 8) $enrolled = [0];
        if ($i === 15) $enrolled = [1, 2];
        foreach ($enrolled as $ci) {
            Database::insert(
                'INSERT INTO course_enrollments (lecturer_id, course_id, registration_id, student_id, enrolled_at)
                 VALUES (?,?,?,?,?)',
                [$l1, $courseIds[$ci], $regId, $sid, now_sql()]
            );
        }
    }
}

// Closed sessions + records (mirrors frontend fixture plan)
$plan = [[0,21],[1,19],[2,17],[0,14],[1,12],[2,10],[0,7],[1,5],[3,20],[4,15],[3,6],[0,2]];
foreach ($plan as $idx => $p) {
    $courseId = $courseIds[$p[0]];
    $course = Database::fetch('SELECT * FROM courses WHERE id = ?', [$courseId]);
    $start = (new DateTimeImmutable('now'))->modify('-' . $p[1] . ' days')->setTime(9 + ($idx % 3) * 2, 0);
    $end = $start->modify('+25 minutes');
    $sid = Database::insert(
        'INSERT INTO attendance_sessions
         (lecturer_id, course_id, class_id, method, status, opened_at, closed_at, late_after_min, radius_m,
          require_gps, require_face, qr_mode, qr_rotate_sec, location_name, latitude, longitude, eligible_count, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $l1, $courseId, (int) $course['class_id'],
            $idx % 3 === 0 ? 'code' : 'qr', 'closed',
            $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'),
            10, $idx % 2 ? 30 : 50, 1, 1, 'rotate', 30,
            $idx % 2 ? 'Science Block, Room 12' : 'Lecture Hall A',
            5.1153, -1.2786, 0, now_sql(),
        ]
    );
    $enrolled = Database::fetchAll('SELECT student_id FROM course_enrollments WHERE course_id = ?', [$courseId]);
    Database::exec('UPDATE attendance_sessions SET eligible_count = ? WHERE id = ?', [count($enrolled), $sid]);
    foreach ($enrolled as $e) {
        $stId = (int) $e['student_id'];
        $roll = ($stId * 31 + $sid * 17) % 100;
        $status = $roll < 74 ? 'present' : ($roll < 84 ? 'late' : ($roll < 96 ? 'absent' : 'review'));
        $marked = $status === 'absent' ? null : $start->modify('+' . (($roll % 20) + 1) . ' minutes')->format('Y-m-d H:i:s');
        Database::insert(
            'INSERT INTO attendance_records
             (session_id, lecturer_id, student_id, status, method_used, marked_at, distance_m, gps_accuracy_m, face_similarity, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $sid, $l1, $stId, $status, $idx % 3 === 0 ? 'code' : 'qr', $marked,
                $status === 'absent' ? null : 4 + ($roll % 40),
                $status === 'absent' ? null : 6 + ($roll % 18),
                $status === 'absent' ? null : round(0.82 + ($roll % 15) / 100, 4),
                now_sql(),
            ]
        );
    }
}

Database::insert(
    'INSERT INTO audit_logs (lecturer_id, actor_type, actor_id, actor_name, action, detail, reason, created_at)
     VALUES (?,?,?,?,?,?,?,?)',
    [$l1, 'lecturer', $l1, 'Dr. Kojo Mensah', 'Attendance corrected', 'Ama Darko · CSC 305 · ABSENT → PRESENT', 'Phone battery died; confirmed in person.', date('Y-m-d H:i:s', strtotime('-9 days'))]
);
Database::insert(
    'INSERT INTO audit_logs (lecturer_id, actor_type, actor_id, actor_name, action, detail, reason, created_at)
     VALUES (?,?,?,?,?,?,?,?)',
    [$l1, 'lecturer', $l1, 'Dr. Kojo Mensah', 'Registration approved', 'Kofi Boateng · Level 300 Computer Science', '', date('Y-m-d H:i:s', strtotime('-4 days'))]
);

echo "Seeded lecturers:\n";
echo "  Demo lecturers created (DEV ONLY - see passwords above in this file).\n";
echo "Classes: 3 | Courses: " . count($courseIds) . " | Students: 30 | Closed sessions: " . count($plan) . "\n";
echo "Invite tokens: inv_L300CS_9fK2xQ , inv_L200IT_r7Wm3B\n";
echo "Done.\n";
