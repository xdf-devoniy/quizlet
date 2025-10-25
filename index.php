<?php
require __DIR__ . '/config.php';

$db = get_db();

function fetch_user(int $id): array
{
    global $db;
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function current_user(): ?array
{
    return isset($_SESSION['user_id']) ? fetch_user($_SESSION['user_id']) : null;
}

function require_login(): void
{
    if (!current_user()) {
        header('Location: index.php');
        exit;
    }
}

function is_teacher(): bool
{
    $user = current_user();
    return $user && $user['role'] === 'teacher';
}

function handle_upload(array $file, string $directory, array $allowedTypes): ?string
{
    if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowedTypes, true)) {
        return null;
    }
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('upload_', true) . ($extension ? ".{$extension}" : '');
    $destination = rtrim($directory, '/') . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return null;
    }
    return $destination;
}

function random_code(int $length = 6): string
{
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $code;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $stmt = $db->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            header('Location: index.php');
            exit;
        } else {
            $message = 'Invalid credentials';
        }
    } elseif ($action === 'register') {
        $username = trim($_POST['username'] ?? '');
        $displayName = trim($_POST['display_name'] ?? $username);
        $password = $_POST['password'] ?? '';
        if ($username && $password) {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $db->prepare('INSERT INTO users (username, password_hash, role, display_name) VALUES (?, ?, ?, ?)')
                   ->execute([$username, $hash, 'student', $displayName]);
                $message = 'Account created. You can log in now.';
            } catch (PDOException $e) {
                $message = 'Unable to register user. Username may already exist.';
            }
        } else {
            $message = 'Username and password are required.';
        }
    } elseif ($action === 'logout') {
        session_destroy();
        header('Location: index.php');
        exit;
    } else {
        require_login();
        $user = current_user();
        switch ($action) {
            case 'create_set':
                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $subject = trim($_POST['subject'] ?? '');
                $visibility = $_POST['visibility'] ?? 'public';
                $imagePath = handle_upload($_FILES['set_image'] ?? [], IMAGE_UPLOAD_DIR, ['image/jpeg', 'image/png', 'image/gif']);
                $audioPath = handle_upload($_FILES['set_audio'] ?? [], AUDIO_UPLOAD_DIR, ['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/mp3']);
                if ($title) {
                    $db->prepare('INSERT INTO study_sets (title, description, subject, visibility, creator_id, image_path, audio_path) VALUES (?, ?, ?, ?, ?, ?, ?)')
                       ->execute([$title, $description, $subject, $visibility, $user['id'], $imagePath, $audioPath]);
                    $message = 'Study set created.';
                }
                break;
            case 'add_term':
                $setId = (int) ($_POST['set_id'] ?? 0);
                $term = trim($_POST['term'] ?? '');
                $definition = trim($_POST['definition'] ?? '');
                $termImage = handle_upload($_FILES['term_image'] ?? [], IMAGE_UPLOAD_DIR, ['image/jpeg', 'image/png', 'image/gif']);
                $termAudio = handle_upload($_FILES['term_audio'] ?? [], AUDIO_UPLOAD_DIR, ['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/mp3']);
                if ($setId && $term && $definition) {
                    $db->prepare('INSERT INTO study_terms (set_id, term, definition, image_path, audio_path) VALUES (?, ?, ?, ?, ?)')
                       ->execute([$setId, $term, $definition, $termImage, $termAudio]);
                    $message = 'Term added.';
                }
                break;
            case 'import_terms':
                $setId = (int) ($_POST['set_id'] ?? 0);
                $raw = trim($_POST['import_text'] ?? '');
                if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
                    $raw .= "\n" . file_get_contents($_FILES['import_file']['tmp_name']);
                    $db->prepare('INSERT INTO imports (user_id, original_filename) VALUES (?, ?)')
                       ->execute([$user['id'], $_FILES['import_file']['name'] ?? 'upload.csv']);
                }
                $lines = array_filter(array_map('trim', preg_split('/\r?\n/', $raw)));
                foreach ($lines as $line) {
                    [$term, $definition] = array_pad(preg_split('/[,;\t]|::/', $line, 2), 2, '');
                    $term = trim($term);
                    $definition = trim($definition);
                    if ($term && $definition && $setId) {
                        $db->prepare('INSERT INTO study_terms (set_id, term, definition) VALUES (?, ?, ?)')
                           ->execute([$setId, $term, $definition]);
                    }
                }
                $message = 'Import complete.';
                break;
            case 'copy_set':
                $setId = (int) ($_POST['set_id'] ?? 0);
                if ($setId) {
                    $stmt = $db->prepare('SELECT * FROM study_sets WHERE id = ?');
                    $stmt->execute([$setId]);
                    if ($original = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $db->prepare('INSERT INTO study_sets (title, description, subject, visibility, creator_id, image_path, audio_path) VALUES (?, ?, ?, ?, ?, ?, ?)')
                           ->execute([
                               $original['title'] . ' (Copy)',
                               $original['description'],
                               $original['subject'],
                               'private',
                               $user['id'],
                               $original['image_path'],
                               $original['audio_path'],
                           ]);
                        $newSetId = (int) $db->lastInsertId();
                        $terms = $db->prepare('SELECT * FROM study_terms WHERE set_id = ?');
                        $terms->execute([$setId]);
                        foreach ($terms as $term) {
                            $db->prepare('INSERT INTO study_terms (set_id, term, definition, image_path, audio_path, mastery_level, attempts, success_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                               ->execute([
                                   $newSetId,
                                   $term['term'],
                                   $term['definition'],
                                   $term['image_path'],
                                   $term['audio_path'],
                                   0,
                                   0,
                                   0,
                               ]);
                        }
                        $message = 'Set copied to your library.';
                    }
                }
                break;
            case 'create_class':
                if (is_teacher()) {
                    $name = trim($_POST['name'] ?? '');
                    $description = trim($_POST['description'] ?? '');
                    if ($name) {
                        $code = random_code();
                        $db->prepare('INSERT INTO classes (name, description, teacher_id, join_code) VALUES (?, ?, ?, ?)')
                           ->execute([$name, $description, $user['id'], $code]);
                        $classId = (int) $db->lastInsertId();
                        $db->prepare('INSERT INTO class_members (class_id, user_id, role) VALUES (?, ?, ?)')
                           ->execute([$classId, $user['id'], 'teacher']);
                        $message = 'Class created with join code ' . $code;
                    }
                }
                break;
            case 'join_class':
                $code = strtoupper(trim($_POST['code'] ?? ''));
                if ($code) {
                    $stmt = $db->prepare('SELECT * FROM classes WHERE join_code = ?');
                    $stmt->execute([$code]);
                    if ($class = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $db->prepare('INSERT OR IGNORE INTO class_members (class_id, user_id, role) VALUES (?, ?, ?)')
                           ->execute([$class['id'], $user['id'], 'student']);
                        $message = 'Joined class ' . $class['name'];
                    } else {
                        $message = 'Class code not found.';
                    }
                }
                break;
            case 'assign_set':
                if (is_teacher()) {
                    $classId = (int) ($_POST['class_id'] ?? 0);
                    $setId = (int) ($_POST['set_id'] ?? 0);
                    if ($classId && $setId) {
                        $db->prepare('INSERT INTO class_assignments (class_id, set_id) VALUES (?, ?)')
                           ->execute([$classId, $setId]);
                        $message = 'Set assigned to class.';
                    }
                }
                break;
            case 'record_session':
                $setId = (int) ($_POST['set_id'] ?? 0);
                $mode = $_POST['mode'] ?? 'flashcards';
                $accuracy = (float) ($_POST['accuracy'] ?? 0);
                $progress = $_POST['progress'] ?? '[]';
                if ($setId) {
                    $db->prepare('INSERT INTO study_sessions (user_id, set_id, mode, completed_at, accuracy, progress_json) VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, ?)')
                       ->execute([$user['id'], $setId, $mode, $accuracy, $progress]);
                    $message = 'Progress saved.';
                }
                break;
            case 'start_live':
                if (is_teacher()) {
                    $setId = (int) ($_POST['set_id'] ?? 0);
                    if ($setId) {
                        $code = random_code(5);
                        $db->prepare('INSERT INTO live_sessions (set_id, host_id, code, status) VALUES (?, ?, ?, ?)')
                           ->execute([$setId, $user['id'], $code, 'waiting']);
                        $message = 'Live session started. Code: ' . $code;
                    }
                }
                break;
            case 'join_live':
                $code = strtoupper(trim($_POST['code'] ?? ''));
                if ($code) {
                    $stmt = $db->prepare('SELECT * FROM live_sessions WHERE code = ?');
                    $stmt->execute([$code]);
                    if ($session = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $db->prepare('INSERT OR IGNORE INTO live_participants (live_session_id, user_id) VALUES (?, ?)')
                           ->execute([$session['id'], $user['id']]);
                        $message = 'Joined live session.';
                    } else {
                        $message = 'Session not found.';
                    }
                }
                break;
            case 'update_live_score':
                $sessionId = (int) ($_POST['session_id'] ?? 0);
                $score = (int) ($_POST['score'] ?? 0);
                if ($sessionId) {
                    $db->prepare('UPDATE live_participants SET score = ? WHERE live_session_id = ? AND user_id = ?')
                       ->execute([$score, $sessionId, $user['id']]);
                    $message = 'Score updated.';
                }
                break;
            case 'record_match_score':
                $setId = (int) ($_POST['set_id'] ?? 0);
                $time = (float) ($_POST['elapsed'] ?? 0);
                if ($setId && $time > 0) {
                    $db->prepare('INSERT INTO match_game_scores (user_id, set_id, elapsed_seconds) VALUES (?, ?, ?)')
                       ->execute([$user['id'], $setId, $time]);
                    $message = 'Match score saved!';
                }
                break;
        }
    }
}

$user = current_user();
$page = $_GET['page'] ?? 'dashboard';
$setId = isset($_GET['set_id']) ? (int) $_GET['set_id'] : 0;

function get_sets_for_user(?array $user): array
{
    global $db;
    if (!$user) {
        return [];
    }
    if ($user['role'] === 'teacher') {
        $stmt = $db->prepare('SELECT * FROM study_sets WHERE creator_id = ? ORDER BY created_at DESC');
        $stmt->execute([$user['id']]);
    } else {
        $stmt = $db->prepare('SELECT * FROM study_sets WHERE visibility = "public" OR creator_id = ? ORDER BY created_at DESC');
        $stmt->execute([$user['id']]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_public_sets(): array
{
    global $db;
    $stmt = $db->query('SELECT study_sets.*, users.display_name FROM study_sets JOIN users ON users.id = study_sets.creator_id WHERE visibility != "private" ORDER BY created_at DESC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_set(int $id): ?array
{
    global $db;
    $stmt = $db->prepare('SELECT study_sets.*, users.display_name FROM study_sets JOIN users ON users.id = study_sets.creator_id WHERE study_sets.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function get_terms_for_set(int $setId): array
{
    global $db;
    $stmt = $db->prepare('SELECT * FROM study_terms WHERE set_id = ? ORDER BY id');
    $stmt->execute([$setId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_classes_for_user(array $user): array
{
    global $db;
    if ($user['role'] === 'teacher') {
        $stmt = $db->prepare('SELECT * FROM classes WHERE teacher_id = ? ORDER BY created_at DESC');
        $stmt->execute([$user['id']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $stmt = $db->prepare('SELECT classes.* FROM classes JOIN class_members ON class_members.class_id = classes.id WHERE class_members.user_id = ? ORDER BY classes.created_at DESC');
    $stmt->execute([$user['id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_class_members(int $classId): array
{
    global $db;
    $stmt = $db->prepare('SELECT users.display_name, users.role, class_members.role AS class_role FROM class_members JOIN users ON users.id = class_members.user_id WHERE class_id = ?');
    $stmt->execute([$classId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_class_assignments(int $classId): array
{
    global $db;
    $stmt = $db->prepare('SELECT study_sets.* FROM class_assignments JOIN study_sets ON study_sets.id = class_assignments.set_id WHERE class_assignments.class_id = ?');
    $stmt->execute([$classId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_recent_sessions(int $setId): array
{
    global $db;
    $stmt = $db->prepare('SELECT study_sessions.*, users.display_name FROM study_sessions JOIN users ON users.id = study_sessions.user_id WHERE set_id = ? ORDER BY started_at DESC LIMIT 20');
    $stmt->execute([$setId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_user_activity_summary(array $user): array
{
    global $db;
    $summary = [
        'set_count' => 0,
        'term_count' => 0,
        'class_count' => 0,
        'sessions_this_week' => 0,
        'avg_accuracy' => null,
        'live_sessions' => 0,
        'imports' => 0,
        'best_match_time' => null,
        'unique_modes' => 0,
        'mastered_terms' => 0,
        'total_attempts' => 0,
    ];

    $stmt = $db->prepare('SELECT COUNT(*) FROM study_sets WHERE creator_id = ?');
    $stmt->execute([$user['id']]);
    $summary['set_count'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT COUNT(*) FROM study_terms WHERE set_id IN (SELECT id FROM study_sets WHERE creator_id = ?)');
    $stmt->execute([$user['id']]);
    $summary['term_count'] = (int) $stmt->fetchColumn();

    if ($user['role'] === 'teacher') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM classes WHERE teacher_id = ?');
    } else {
        $stmt = $db->prepare('SELECT COUNT(*) FROM class_members WHERE user_id = ?');
    }
    $stmt->execute([$user['id']]);
    $summary['class_count'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT COUNT(*) FROM study_sessions WHERE user_id = ? AND started_at >= datetime("now", "-7 days")');
    $stmt->execute([$user['id']]);
    $summary['sessions_this_week'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT AVG(accuracy) FROM study_sessions WHERE user_id = ? AND accuracy IS NOT NULL');
    $stmt->execute([$user['id']]);
    $avg = $stmt->fetchColumn();
    $summary['avg_accuracy'] = $avg !== null ? (float) $avg : null;

    if ($user['role'] === 'teacher') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM live_sessions WHERE host_id = ?');
    } else {
        $stmt = $db->prepare('SELECT COUNT(*) FROM live_participants WHERE user_id = ?');
    }
    $stmt->execute([$user['id']]);
    $summary['live_sessions'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT COUNT(*) FROM imports WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $summary['imports'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT MIN(elapsed_seconds) FROM match_game_scores WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $best = $stmt->fetchColumn();
    $summary['best_match_time'] = $best !== null ? (float) $best : null;

    $stmt = $db->prepare('SELECT COUNT(DISTINCT mode) FROM study_sessions WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $summary['unique_modes'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT COUNT(*) FROM study_terms WHERE mastery_level >= 3 AND set_id IN (SELECT id FROM study_sets WHERE creator_id = ?)');
    $stmt->execute([$user['id']]);
    $summary['mastered_terms'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT SUM(attempts) FROM study_terms WHERE set_id IN (SELECT id FROM study_sets WHERE creator_id = ?)');
    $stmt->execute([$user['id']]);
    $attempts = $stmt->fetchColumn();
    $summary['total_attempts'] = $attempts !== null ? (int) $attempts : 0;

    return $summary;
}

function get_mastery_distribution(int $setId): array
{
    global $db;
    $stmt = $db->prepare('SELECT mastery_level, COUNT(*) AS total FROM study_terms WHERE set_id = ? GROUP BY mastery_level ORDER BY mastery_level');
    $stmt->execute([$setId]);
    $distribution = [];
    foreach ($stmt as $row) {
        $distribution[(int) $row['mastery_level']] = (int) $row['total'];
    }
    return $distribution;
}

function get_mode_breakdown(int $setId): array
{
    global $db;
    $stmt = $db->prepare('SELECT mode, COUNT(*) AS total, AVG(accuracy) AS avg_accuracy FROM study_sessions WHERE set_id = ? GROUP BY mode ORDER BY total DESC');
    $stmt->execute([$setId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_live_sessions_for_user(array $user): array
{
    global $db;
    if ($user['role'] === 'teacher') {
        $stmt = $db->prepare('SELECT live_sessions.*, study_sets.title FROM live_sessions JOIN study_sets ON study_sets.id = live_sessions.set_id WHERE host_id = ? ORDER BY created_at DESC LIMIT 6');
        $stmt->execute([$user['id']]);
    } else {
        $stmt = $db->prepare('SELECT live_sessions.*, study_sets.title FROM live_participants JOIN live_sessions ON live_sessions.id = live_participants.live_session_id JOIN study_sets ON study_sets.id = live_sessions.set_id WHERE live_participants.user_id = ? ORDER BY live_sessions.created_at DESC LIMIT 6');
        $stmt->execute([$user['id']]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_recent_assignments(array $user, int $limit = 6): array
{
    global $db;
    $limit = max(1, $limit);
    if ($user['role'] === 'teacher') {
        $stmt = $db->prepare('SELECT class_assignments.assigned_at, classes.name AS class_name, study_sets.title FROM class_assignments JOIN classes ON classes.id = class_assignments.class_id JOIN study_sets ON study_sets.id = class_assignments.set_id WHERE classes.teacher_id = ? ORDER BY class_assignments.assigned_at DESC LIMIT ' . $limit);
        $stmt->execute([$user['id']]);
    } else {
        $stmt = $db->prepare('SELECT class_assignments.assigned_at, classes.name AS class_name, study_sets.title FROM class_assignments JOIN classes ON classes.id = class_assignments.class_id JOIN study_sets ON study_sets.id = class_assignments.set_id JOIN class_members ON class_members.class_id = classes.id WHERE class_members.user_id = ? ORDER BY class_assignments.assigned_at DESC LIMIT ' . $limit);
        $stmt->execute([$user['id']]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$inputClass = 'w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200';
$labelClass = 'block text-xs font-semibold uppercase tracking-[0.3em] text-slate-500';
$cardClass = 'rounded-3xl border border-slate-200 bg-white shadow-soft backdrop-blur';
$primaryButton = 'inline-flex items-center gap-2 rounded-full bg-brand-500 px-5 py-3 text-sm font-semibold text-white shadow-soft transition hover:bg-brand-600';
$secondaryButton = 'inline-flex items-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-5 py-3 text-sm font-semibold text-brand-700 transition hover:bg-brand-100';
$successButton = 'inline-flex items-center gap-2 rounded-full bg-emerald-500 px-5 py-3 text-sm font-semibold text-white shadow-soft transition hover:bg-emerald-600';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quizlet Classroom Suite</title>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            400: '#78a6ff',
                            500: '#4c7dff',
                            600: '#355fd8'
                        }
                    },
                    fontFamily: {
                        display: ['"Plus Jakarta Sans"', 'Inter', 'ui-sans-serif', 'system-ui'],
                        body: ['"Inter"', 'system-ui', 'sans-serif']
                    },
                    boxShadow: {
                        soft: '0 35px 65px -30px rgba(15, 23, 42, 0.18)',
                        lifted: '0 25px 55px -25px rgba(148, 163, 184, 0.3)'
                    },
                    backdropBlur: {
                        xs: '2px'
                    }
                }
            }
        };
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-100 text-slate-700 font-body">
    <div class="relative min-h-screen overflow-hidden">
        <div class="pointer-events-none absolute inset-0 -z-10">
            <div class="absolute -top-32 right-8 h-80 w-80 rounded-full bg-brand-500/30 blur-3xl"></div>
            <div class="absolute bottom-0 left-1/2 h-96 w-[36rem] -translate-x-1/2 rounded-full bg-emerald-500/20 blur-3xl"></div>
            <div class="absolute top-1/3 -left-24 h-72 w-72 rounded-full bg-indigo-500/20 blur-3xl"></div>
        </div>
        <div class="relative mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <header class="mb-10 flex flex-col gap-6 rounded-3xl border border-slate-200 bg-white p-6 shadow-soft backdrop-blur">
                <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                    <div class="max-w-2xl space-y-4">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-500/20 text-lg font-semibold text-brand-400">OTL</span>
                            <div>
                                <p class="text-xs uppercase tracking-[0.3em] text-slate-500">Office of Teaching &amp; Learning</p>
                                <h1 class="font-display text-3xl font-bold text-slate-900 sm:text-4xl">Quizlet Classroom Experience</h1>
                            </div>
                        </div>
                        <p class="text-base text-slate-600">
                            Deliver a polished, mobile-first study platform with adaptive practice, classroom controls, and live engagement built for professional educators.
                        </p>
                    </div>
                    <div class="flex items-start gap-4 self-stretch rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm text-slate-600">
                        <?php if ($user): ?>
                            <div class="flex-1">
                                <p class="text-xs uppercase tracking-widest text-slate-500">You are signed in as</p>
                                <p class="font-semibold text-slate-900">
                                    <?= htmlspecialchars($user['display_name'] ?: $user['username']) ?>
                                    <span class="ml-2 rounded-full bg-brand-500/20 px-2 py-0.5 text-xs font-medium text-brand-600">
                                        <?= htmlspecialchars($user['role']) ?>
                                    </span>
                                </p>
                                <p class="mt-2 text-xs leading-relaxed text-slate-500">Access your library, launch live games, and monitor class mastery with real-time analytics.</p>
                            </div>
                            <form method="post" class="self-end">
                                <input type="hidden" name="action" value="logout">
                                <button class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-900 transition hover:bg-slate-200">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m18 12-3 3m3-3-3-3m3 3h-9" />
                                    </svg>
                                    Log out
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="space-y-2 text-xs text-slate-600">
                                <p class="font-semibold text-slate-900">Sign in to access premium classroom controls.</p>
                                <p>Default teacher credentials: <span class="font-medium text-brand-600">teacher / teacher</span></p>
                                <p>Default student credentials: <span class="font-medium text-brand-600">student / student</span></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </header>

            <?php if ($message): ?>
                <div class="mb-8 rounded-3xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-700 shadow-soft">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

        <?php if (!$user): ?>
            <div class="grid gap-6 lg:grid-cols-2">
                <section class="<?= $cardClass ?> p-8">
                    <div class="mb-6 flex items-center justify-between">
                        <div>
                            <p class="text-xs uppercase tracking-[0.3em] text-slate-500">Returning users</p>
                            <h2 class="font-display text-2xl font-semibold text-slate-900">Secure portal sign-in</h2>
                        </div>
                        <span class="rounded-full bg-brand-500/20 px-3 py-1 text-xs font-medium text-brand-600">SSO ready</span>
                    </div>
                    <form method="post" class="space-y-5">
                        <input type="hidden" name="action" value="login">
                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-slate-600">Username</label>
                            <input name="username" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 placeholder:text-slate-500 focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/60" placeholder="your.name" required>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-slate-600">Password</label>
                            <input type="password" name="password" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 placeholder:text-slate-500 focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/60" placeholder="********" required>
                        </div>
                        <button class="w-full rounded-full bg-brand-500 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-brand-500/40 transition hover:bg-brand-600">Log in</button>
                        <p class="text-xs text-slate-500">Need faculty access? Use the default teacher credentials: <span class="font-semibold text-brand-600">teacher / teacher</span>. Students can begin with <span class="font-semibold text-brand-600">student / student</span>.</p>
                    </form>
                </section>
                <section class="<?= $cardClass ?> p-8">
                    <div class="mb-6">
                        <p class="text-xs uppercase tracking-[0.3em] text-slate-500">New learners</p>
                        <h2 class="font-display text-2xl font-semibold text-slate-900">Create a student account</h2>
                        <p class="mt-1 text-sm text-slate-500">Gain instant access to flashcards, adaptive learn mode, and class assignments.</p>
                    </div>
                    <form method="post" class="space-y-5">
                        <input type="hidden" name="action" value="register">
                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-slate-600">Display name</label>
                            <input name="display_name" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 placeholder:text-slate-500 focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/60" placeholder="Classroom name">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-slate-600">Username</label>
                            <input name="username" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 placeholder:text-slate-500 focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/60" placeholder="username" required>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-slate-600">Password</label>
                            <input type="password" name="password" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 placeholder:text-slate-500 focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/60" placeholder="Create a secure password" required>
                        </div>
                        <button class="w-full rounded-full bg-emerald-500 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-500/40 transition hover:bg-emerald-600">Sign up</button>
                    </form>
                </section>
            </div>
        <?php else: ?>
            <nav class="mb-10 rounded-3xl border border-slate-200 bg-white p-4 shadow-soft backdrop-blur">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div class="space-y-1">
                        <p class="text-xs uppercase tracking-[0.35em] text-slate-500">Navigate modes</p>
                        <p class="text-sm text-slate-600">Seamlessly move between authoring, adaptive practice, and live engagement tools.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="?page=sets" class="inline-flex items-center gap-2 rounded-full bg-brand-500 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white shadow-brand-500/50 transition hover:bg-brand-600">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            New set
                        </a>
                        <a href="?page=live" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-100 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-slate-900 transition hover:bg-slate-200">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m15.75 10.5-5.25 3V7.5l5.25 3z" />
                            </svg>
                            Launch live
                        </a>
                    </div>
                </div>
                <ul class="mt-5 flex w-full snap-x snap-mandatory items-center gap-2 overflow-x-auto pb-2 text-sm font-semibold text-slate-600">
                    <?php
                        $tabs = [
                            'dashboard' => 'Dashboard',
                            'sets' => 'Study Sets',
                            'flashcards' => 'Flashcards',
                            'learn' => 'Learn',
                            'write' => 'Write',
                            'spell' => 'Spell',
                            'test' => 'Test',
                            'match' => 'Match Game',
                            'live' => 'Live',
                            'classes' => 'Classes',
                            'analytics' => 'Analytics',
                            'import' => 'Import',
                        ];
                        foreach ($tabs as $key => $label):
                    ?>
                        <li class="snap-center">
                            <a href="?page=<?= $key ?><?= $setId ? '&amp;set_id=' . $setId : '' ?>" class="inline-flex items-center gap-2 rounded-full px-4 py-2 transition <?= $page === $key ? 'bg-brand-500 text-slate-900 shadow-lg shadow-brand-500/40' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">
                                <span><?= $label ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <?php if ($page === 'dashboard'): ?>
                <?php
                    $classes = get_classes_for_user($user);
                    $userSets = get_sets_for_user($user);
                    $publicSets = get_public_sets();
                    $summary = get_user_activity_summary($user);
                    $recentAssignments = get_recent_assignments($user);
                    $liveHistory = get_live_sessions_for_user($user);
                    $recommendations = [];

                    if ($summary['sessions_this_week'] < 3) {
                        $recommendations[] = 'Plan three quick study touchpoints this week to build a streak in Learn or Flashcards.';
                    } else {
                        $recommendations[] = 'Keep your streak going—add a Test or Match session to vary the challenge.';
                    }

                    if ($summary['avg_accuracy'] !== null && $summary['avg_accuracy'] < 85) {
                        $recommendations[] = 'Target cards under 85% accuracy inside Learn mode for adaptive follow-up questions.';
                    } else {
                        $recommendations[] = 'Accuracy looks strong. Schedule a Test mode assessment to verify mastery.';
                    }

                    if (is_teacher()) {
                        if ($summary['class_count'] === 0) {
                            $recommendations[] = 'Create your first class to assign sets, monitor analytics, and unlock classroom leaderboards.';
                        } else {
                            $recommendations[] = 'Share analytics snapshots with your classes so students can track growth.';
                        }
                    } else {
                        if ($summary['class_count'] === 0) {
                            $recommendations[] = 'Join a class with a teacher code to receive assignments and guided study paths.';
                        } else {
                            $recommendations[] = 'Check the Classes tab for new assignments and live session invites from your teacher.';
                        }
                    }

                    if ($summary['imports'] === 0) {
                        $recommendations[] = 'Use the Import tab to paste or upload a CSV and generate a full set in seconds.';
                    } else {
                        $recommendations[] = 'Refresh your library by importing an updated word list or adding audio for pronunciation.';
                    }
                ?>
                <section class="space-y-6">
                    <div class="<?= $cardClass ?> p-6 space-y-6">
                        <div>
                            <p class="text-xs uppercase tracking-[0.35em] text-slate-500">Performance snapshot</p>
                            <h2 class="font-display text-3xl font-semibold text-slate-900">Instructional performance dashboard</h2>
                            <p class="mt-2 text-sm text-slate-600">Track how your study sets, classes, and live experiences are progressing this week.</p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <p class="text-xs uppercase tracking-widest text-slate-500">Sets in library</p>
                                <p class="mt-2 text-3xl font-semibold text-slate-900"><?= $summary['set_count'] ?></p>
                                <p class="text-xs text-slate-600"><?= $summary['term_count'] ?> curated terms</p>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <p class="text-xs uppercase tracking-widest text-slate-500">Classes connected</p>
                                <p class="mt-2 text-3xl font-semibold text-slate-900"><?= $summary['class_count'] ?></p>
                                <p class="text-xs text-slate-600">Live sessions hosted/joined: <?= $summary['live_sessions'] ?></p>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <p class="text-xs uppercase tracking-widest text-slate-500">Study streak</p>
                                <p class="mt-2 text-3xl font-semibold text-slate-900"><?= $summary['sessions_this_week'] ?></p>
                                <p class="text-xs text-slate-600">Unique modes this week: <?= $summary['unique_modes'] ?></p>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <p class="text-xs uppercase tracking-widest text-slate-500">Average accuracy</p>
                                <p class="mt-2 text-3xl font-semibold text-slate-900"><?= $summary['avg_accuracy'] !== null ? number_format($summary['avg_accuracy'], 1) . '%' : '—' ?></p>
                                <p class="text-xs text-slate-600">Mastered terms: <?= $summary['mastered_terms'] ?></p>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs text-slate-600">
                            <span class="rounded-full bg-slate-100 px-3 py-1">Best Match time: <?= $summary['best_match_time'] !== null ? number_format($summary['best_match_time'], 1) . 's' : 'Not played yet' ?></span>
                            <span class="rounded-full bg-slate-100 px-3 py-1">Bulk imports processed: <?= $summary['imports'] ?></span>
                            <span class="rounded-full bg-slate-100 px-3 py-1">Total attempts logged: <?= number_format($summary['total_attempts']) ?></span>
                        </div>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-3">
                        <div class="space-y-6 lg:col-span-2">
                            <div class="<?= $cardClass ?> p-6">
                                <div class="mb-4 flex items-center justify-between">
                                    <div>
                                        <h2 class="font-display text-2xl font-semibold text-slate-900">Your recent study sets</h2>
                                        <p class="text-sm text-slate-600">Continue building mastery with quick access to your latest content.</p>
                                    </div>
                                    <span class="rounded-full bg-slate-50 px-3 py-1 text-xs text-slate-500">Adaptive ready</span>
                                </div>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <?php foreach ($userSets as $set): ?>
                                        <a href="?page=set_view&amp;set_id=<?= $set['id'] ?>" class="group rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:border-brand-400 hover:bg-slate-100">
                                            <h3 class="text-lg font-semibold text-slate-900 group-hover:text-brand-600"><?= htmlspecialchars($set['title']) ?></h3>
                                            <p class="mt-2 line-clamp-3 text-sm text-slate-600"><?= htmlspecialchars($set['description']) ?></p>
                                            <p class="mt-3 flex items-center gap-2 text-xs uppercase tracking-widest text-slate-500">
                                                <span class="h-1.5 w-1.5 rounded-full bg-brand-400"></span>
                                                Flashcards • Learn • Test • Games
                                            </p>
                                        </a>
                                    <?php endforeach; ?>
                                    <?php if (!$userSets): ?>
                                        <p class="rounded-2xl border border-dashed border-slate-300 p-6 text-sm text-slate-600">Create your first study set to activate personalized learning paths for your class.</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="<?= $cardClass ?> p-6">
                                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                    <div>
                                        <h2 class="font-display text-2xl font-semibold text-slate-900">Classroom live games</h2>
                                        <p class="text-sm text-slate-600">Launch Blast, Categories, or Match competitions to energize your classroom—remote or in-person.</p>
                                    </div>
                                    <div class="flex gap-2">
                                        <a href="?page=live" class="inline-flex items-center gap-2 rounded-full bg-brand-500 px-4 py-2 text-sm font-semibold text-white shadow-brand-500/40 transition hover:bg-brand-600">Host Live</a>
                                        <a href="?page=match" class="inline-flex items-center gap-2 rounded-full bg-emerald-500 px-4 py-2 text-sm font-semibold text-white shadow-emerald-500/40 transition hover:bg-emerald-600">Play Match</a>
                                    </div>
                                </div>
                                <dl class="mt-6 grid gap-4 sm:grid-cols-3">
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <dt class="text-xs uppercase tracking-widest text-slate-500">Avg. mastery</dt>
                                        <dd class="mt-2 text-2xl font-semibold text-slate-900">82%</dd>
                                    </div>
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <dt class="text-xs uppercase tracking-widest text-slate-500">Sessions this week</dt>
                                        <dd class="mt-2 text-2xl font-semibold text-slate-900"><?= $summary['sessions_this_week'] ?></dd>
                                    </div>
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <dt class="text-xs uppercase tracking-widest text-slate-500">Engagement boost</dt>
                                        <dd class="mt-2 text-2xl font-semibold text-slate-900">+36%</dd>
                                    </div>
                                </dl>
                            </div>

                            <div class="<?= $cardClass ?> p-6">
                                <h2 class="font-display text-2xl font-semibold text-slate-900">Personalized study coach</h2>
                                <p class="mt-2 text-sm text-slate-600">Actionable suggestions generated from your recent activity.</p>
                                <ul class="mt-4 space-y-3 text-sm text-slate-600">
                                    <?php foreach ($recommendations as $tip): ?>
                                        <li class="flex items-start gap-3">
                                            <span class="mt-1 inline-flex h-2 w-2 flex-none rounded-full bg-brand-500"></span>
                                            <span><?= htmlspecialchars($tip) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <aside class="space-y-6">
                            <div class="<?= $cardClass ?> p-6">
                                <h3 class="text-lg font-semibold text-slate-900">Classes</h3>
                                <ul class="mt-4 space-y-3">
                                    <?php foreach ($classes as $class): ?>
                                        <li class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                            <h4 class="text-base font-semibold text-slate-900"><?= htmlspecialchars($class['name']) ?></h4>
                                            <p class="mt-1 text-xs uppercase tracking-widest text-slate-500">Join code</p>
                                            <p class="text-sm font-mono text-brand-600"><?= htmlspecialchars($class['join_code']) ?></p>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if (!$classes): ?>
                                        <li class="rounded-2xl border border-dashed border-slate-300 p-5 text-sm text-slate-600">No classes yet. Create one in the Classes tab to assign study sets and track insights.</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            <div class="<?= $cardClass ?> p-6">
                                <h3 class="text-lg font-semibold text-slate-900">Discover public sets</h3>
                                <div class="mt-4 max-h-64 space-y-2 overflow-y-auto pr-1">
                                    <?php foreach ($publicSets as $set): ?>
                                        <form method="post" class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                            <h4 class="text-base font-semibold text-slate-900"><?= htmlspecialchars($set['title']) ?></h4>
                                            <p class="mt-1 text-xs uppercase tracking-widest text-slate-500">By <?= htmlspecialchars($set['display_name']) ?> · <?= htmlspecialchars($set['subject']) ?></p>
                                            <input type="hidden" name="action" value="copy_set">
                                            <input type="hidden" name="set_id" value="<?= $set['id'] ?>">
                                            <button class="mt-3 text-sm font-semibold text-brand-600 transition hover:text-brand-500">Copy to my library</button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="<?= $cardClass ?> p-6">
                                <h3 class="text-lg font-semibold text-slate-900">Recent assignments</h3>
                                <ul class="mt-4 space-y-3 text-sm text-slate-600">
                                    <?php foreach ($recentAssignments as $assignment): ?>
                                        <li class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                            <p class="font-semibold text-slate-900"><?= htmlspecialchars($assignment['title']) ?></p>
                                            <p class="text-xs uppercase tracking-widest text-slate-500">Class: <?= htmlspecialchars($assignment['class_name']) ?></p>
                                            <p class="text-xs text-slate-500">Assigned <?= htmlspecialchars($assignment['assigned_at']) ?></p>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if (!$recentAssignments): ?>
                                        <li class="rounded-2xl border border-dashed border-slate-300 p-4 text-sm text-slate-600">No assignments yet. Assign a set to a class to populate this feed.</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            <div class="<?= $cardClass ?> p-6">
                                <h3 class="text-lg font-semibold text-slate-900">Live session history</h3>
                                <ul class="mt-4 space-y-3 text-sm text-slate-600">
                                    <?php foreach ($liveHistory as $session): ?>
                                        <li class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                            <p class="font-semibold text-slate-900"><?= htmlspecialchars($session['title']) ?></p>
                                            <p class="text-xs uppercase tracking-widest text-slate-500">Code <?= htmlspecialchars($session['code'] ?? '') ?> · Status <?= htmlspecialchars($session['status'] ?? 'completed') ?></p>
                                            <p class="text-xs text-slate-500">Started <?= htmlspecialchars($session['created_at']) ?></p>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if (!$liveHistory): ?>
                                        <li class="rounded-2xl border border-dashed border-slate-300 p-4 text-sm text-slate-600">No live sessions yet. Use Launch Live to host or join classroom games.</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </aside>
                    </div>
                </section>
            <?php elseif ($page === 'sets'): ?>
                <?php $userSets = get_sets_for_user($user); ?>
                <div class="grid lg:grid-cols-3 gap-6">
                    <section class="lg:col-span-2 space-y-6">
                        <div class="<?= $cardClass ?> p-6">
                            <div class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.35em] text-slate-500">Authoring suite</p>
                                    <h2 class="font-display text-2xl font-semibold text-slate-900">Create study set</h2>
                                </div>
                                <span class="rounded-full bg-slate-50 px-3 py-1 text-xs text-slate-500">Rich media supported</span>
                            </div>
                            <form method="post" enctype="multipart/form-data" class="space-y-5">
                                <input type="hidden" name="action" value="create_set">
                                <div class="grid md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="<?= $labelClass ?>">Title</label>
                                        <input name="title" class="<?= $inputClass ?>" placeholder="AP Biology: Cell Processes" required>
                                    </div>
                                    <div>
                                        <label class="<?= $labelClass ?>">Subject</label>
                                        <input name="subject" class="<?= $inputClass ?>" placeholder="Biology">
                                    </div>
                                </div>
                                <div>
                                    <label class="<?= $labelClass ?>">Description</label>
                                    <textarea name="description" class="<?= $inputClass ?>" rows="3" placeholder="Learning targets, standards, or lesson framing."></textarea>
                                </div>
                                <div class="grid md:grid-cols-3 gap-4">
                                    <div>
                                        <label class="<?= $labelClass ?>">Visibility</label>
                                        <select name="visibility" class="<?= $inputClass ?>">
                                            <option value="public">Public</option>
                                            <option value="private">Private</option>
                                            <option value="class">Class only</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="<?= $labelClass ?>">Cover image</label>
                                        <input type="file" name="set_image" accept="image/*" class="<?= $inputClass ?>">
                                    </div>
                                    <div>
                                        <label class="<?= $labelClass ?>">Intro audio</label>
                                        <input type="file" name="set_audio" accept="audio/*" class="<?= $inputClass ?>">
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <button class="inline-flex items-center gap-2 rounded-full bg-brand-500 px-5 py-3 text-sm font-semibold text-white shadow-brand-500/40 transition hover:bg-brand-600">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        Create set
                                    </button>
                                    <span class="text-xs text-slate-500">Attach CSV lists or audio prompts after saving.</span>
                                </div>
                            </form>
                        </div>

                        <div class="<?= $cardClass ?> p-6">
                            <div class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.35em] text-slate-500">Content builder</p>
                                    <h2 class="font-display text-2xl font-semibold text-slate-900">Add terms</h2>
                                </div>
                                <span class="rounded-full bg-slate-50 px-3 py-1 text-xs text-slate-500">Supports images &amp; audio</span>
                            </div>
                            <form method="post" enctype="multipart/form-data" class="grid gap-4 md:grid-cols-2">
                                <input type="hidden" name="action" value="add_term">
                                <div class="md:col-span-2">
                                    <label class="<?= $labelClass ?>">Study set</label>
                                    <select name="set_id" class="<?= $inputClass ?>">
                                        <?php foreach ($userSets as $set): ?>
                                            <option value="<?= $set['id'] ?>"><?= htmlspecialchars($set['title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="<?= $labelClass ?>">Term / Question</label>
                                    <input name="term" class="<?= $inputClass ?>" required>
                                </div>
                                <div>
                                    <label class="<?= $labelClass ?>">Definition / Answer</label>
                                    <textarea name="definition" class="<?= $inputClass ?>" rows="3" required></textarea>
                                </div>
                                <div>
                                    <label class="<?= $labelClass ?>">Image</label>
                                    <input type="file" name="term_image" accept="image/*" class="<?= $inputClass ?>">
                                </div>
                                <div>
                                    <label class="<?= $labelClass ?>">Audio</label>
                                    <input type="file" name="term_audio" accept="audio/*" class="<?= $inputClass ?>">
                                </div>
                                <div class="md:col-span-2">
                                    <button class="inline-flex w-full items-center justify-center gap-2 rounded-full bg-emerald-500 px-5 py-3 text-sm font-semibold text-white shadow-emerald-500/40 transition hover:bg-emerald-600 md:w-auto">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        Add term
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div class="<?= $cardClass ?> p-6">
                            <div class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.35em] text-slate-500">Global discover</p>
                                    <h2 class="font-display text-2xl font-semibold text-slate-900">Search library</h2>
                                </div>
                                <span class="rounded-full bg-slate-50 px-3 py-1 text-xs text-slate-500">Copy and customize instantly</span>
                            </div>
                            <form method="get" class="flex flex-col gap-3 sm:flex-row">
                                <input type="hidden" name="page" value="sets">
                                <input name="q" placeholder="Search by title, subject, or description" class="flex-1 <?= $inputClass ?>" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                                <button class="inline-flex items-center justify-center gap-2 rounded-full bg-slate-100 px-5 py-3 text-sm font-semibold text-slate-900 transition hover:bg-slate-200">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m21 21-4.35-4.35M18 10.5a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z" />
                                    </svg>
                                    Search
                                </button>
                            </form>
                            <div class="mt-4 space-y-3">
                                <?php
                                    $query = trim($_GET['q'] ?? '');
                                    $results = [];
                                    if ($query) {
                                        $stmt = $db->prepare('SELECT study_sets.*, users.display_name FROM study_sets JOIN users ON users.id = study_sets.creator_id WHERE (title LIKE :q OR description LIKE :q OR subject LIKE :q) AND (visibility != "private" OR creator_id = :uid) ORDER BY created_at DESC');
                                        $stmt->execute([':q' => "%{$query}%", ':uid' => $user['id']]);
                                        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    }
                                ?>
                                <?php foreach ($results as $set): ?>
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <h3 class="text-base font-semibold text-slate-900"><?= htmlspecialchars($set['title']) ?></h3>
                                        <p class="mt-1 text-xs uppercase tracking-widest text-slate-500">By <?= htmlspecialchars($set['display_name']) ?> · <?= htmlspecialchars($set['subject']) ?></p>
                                        <div class="mt-3 flex flex-wrap gap-3 text-sm">
                                            <a class="inline-flex items-center gap-2 text-brand-600 transition hover:text-brand-500" href="?page=set_view&amp;set_id=<?= $set['id'] ?>">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.5 4.5 21 12l-7.5 7.5M21 12H3" />
                                                </svg>
                                                Open
                                            </a>
                                            <form method="post" class="inline">
                                                <input type="hidden" name="action" value="copy_set">
                                                <input type="hidden" name="set_id" value="<?= $set['id'] ?>">
                                                <button class="inline-flex items-center gap-2 text-emerald-600 transition hover:text-emerald-500">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.25 7.5V6A2.25 2.25 0 0 1 10.5 3.75h8.25A2.25 2.25 0 0 1 21 6v8.25A2.25 2.25 0 0 1 18.75 16.5H17.25M15.75 7.5H5.25A2.25 2.25 0 0 0 3 9.75v8.25A2.25 2.25 0 0 0 5.25 20.25h8.25A2.25 2.25 0 0 0 15.75 18V7.5Z" />
                                                    </svg>
                                                    Copy
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($query && !$results): ?>
                                    <p class="rounded-2xl border border-dashed border-slate-300 p-4 text-sm text-slate-500">No sets matched your search. Try different keywords or browse trending collections.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>
                    <aside class="space-y-6">
                        <div class="<?= $cardClass ?> p-6">
                            <h3 class="font-display text-xl font-semibold text-slate-900">Your sets</h3>
                            <ul class="mt-4 max-h-[32rem] space-y-3 overflow-y-auto pr-1">
                                <?php foreach ($userSets as $set): ?>
                                    <li class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <a href="?page=set_view&amp;set_id=<?= $set['id'] ?>" class="text-sm font-semibold text-brand-600 transition hover:text-brand-500"><?= htmlspecialchars($set['title']) ?></a>
                                        <p class="mt-1 text-xs uppercase tracking-widest text-slate-500"><?= htmlspecialchars($set['subject']) ?> · <?= htmlspecialchars($set['visibility']) ?></p>
                                    </li>
                                <?php endforeach; ?>
                                <?php if (!$userSets): ?>
                                    <li class="rounded-2xl border border-dashed border-slate-300 p-4 text-sm text-slate-500">No sets yet. Craft your first deck to unlock analytics and live games.</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </aside>
                </div>
            <?php elseif ($page === 'set_view' && $setId): ?>
                <?php
                    $set = get_set($setId);
                    $terms = get_terms_for_set($setId);
                    $mastery = get_mastery_distribution($setId);
                    $modeBreakdown = get_mode_breakdown($setId);
                    $recentStudySessions = get_recent_sessions($setId);
                    $totalTerms = count($terms);
                    $masteryLabels = [
                        0 => 'New',
                        1 => 'Emerging',
                        2 => 'Developing',
                        3 => 'Proficient',
                        4 => 'Advanced',
                        5 => 'Mastered',
                    ];
                ?>
                <?php if ($set): ?>
                    <section class="<?= $cardClass ?> p-6 space-y-6">
                        <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-3">
                                <div class="flex flex-wrap items-center gap-3 text-xs uppercase tracking-widest text-slate-500">
                                    <span class="rounded-full bg-slate-50 px-3 py-1">Set overview</span>
                                    <span class="rounded-full bg-brand-500/20 px-3 py-1 text-brand-600"><?= htmlspecialchars($set['subject'] ?: 'General') ?></span>
                                </div>
                                <h2 class="font-display text-3xl font-semibold text-slate-900"><?= htmlspecialchars($set['title']) ?></h2>
                                <p class="text-sm text-slate-500">Created by <span class="font-medium text-slate-900/90"><?= htmlspecialchars($set['display_name']) ?></span> · <?= htmlspecialchars($set['visibility']) ?> access</p>
                                <p class="text-sm leading-relaxed text-slate-600"><?= nl2br(htmlspecialchars($set['description'])) ?></p>
                                <div class="flex flex-wrap gap-3">
                                    <a class="inline-flex items-center gap-2 rounded-full bg-brand-500 px-5 py-2 text-sm font-semibold text-white shadow-brand-500/40 transition hover:bg-brand-600" href="?page=flashcards&amp;set_id=<?= $set['id'] ?>">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 7.5A2.25 2.25 0 0 1 6 5.25h12A2.25 2.25 0 0 1 20.25 7.5v9A2.25 2.25 0 0 1 18 18.75H6A2.25 2.25 0 0 1 3.75 16.5v-9Z" />
                                        </svg>
                                        Flashcards
                                    </a>
                                    <a class="inline-flex items-center gap-2 rounded-full bg-emerald-500 px-5 py-2 text-sm font-semibold text-white shadow-emerald-500/40 transition hover:bg-emerald-600" href="?page=learn&amp;set_id=<?= $set['id'] ?>">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v12m6-6H6" />
                                        </svg>
                                        Learn
                                    </a>
                                    <a class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-5 py-2 text-sm font-semibold text-slate-900 transition hover:bg-slate-200" href="?page=test&amp;set_id=<?= $set['id'] ?>">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7.5 4.5h9a3 3 0 0 1 3 3V19.5l-7.5-3-7.5 3V7.5a3 3 0 0 1 3-3Z" />
                                        </svg>
                                        Test
                                    </a>
                                </div>
                            </div>
                            <div class="flex flex-col items-start gap-4">
                                <?php if ($set['image_path']): ?>
                                    <img src="<?= htmlspecialchars(str_replace(__DIR__, '', $set['image_path'])) ?>" alt="Cover" class="max-h-48 w-full max-w-sm rounded-2xl border border-slate-200 object-cover">
                                <?php endif; ?>
                                <?php if ($set['audio_path']): ?>
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <p class="text-xs uppercase tracking-widest text-slate-500">Intro audio</p>
                                        <audio controls class="mt-2 w-full">
                                            <source src="<?= htmlspecialchars(str_replace(__DIR__, '', $set['audio_path'])) ?>">
                                        </audio>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="grid gap-4 md:grid-cols-3">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                                <h3 class="text-base font-semibold text-slate-900">Mastery distribution</h3>
                                <p class="mt-1 text-xs text-slate-500">Track how many terms students have progressed through each mastery level.</p>
                                <div class="mt-4 space-y-3">
                                    <?php for ($level = 0; $level <= 5; $level++):
                                        $count = $mastery[$level] ?? 0;
                                        $percent = $totalTerms ? round(($count / $totalTerms) * 100) : 0;
                                    ?>
                                        <div class="space-y-1">
                                            <div class="flex items-center justify-between text-xs text-slate-500">
                                                <span><?= htmlspecialchars($masteryLabels[$level] ?? ('Level ' . $level)) ?></span>
                                                <span><?= $count ?> terms</span>
                                            </div>
                                            <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
                                                <div class="h-full rounded-full bg-brand-500" style="width: <?= $percent ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                    <?php if (!$totalTerms): ?>
                                        <p class="text-xs text-slate-500">Add terms to generate a mastery breakdown.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                                <h3 class="text-base font-semibold text-slate-900">Mode engagement</h3>
                                <p class="mt-1 text-xs text-slate-500">See which study modes students are choosing for this set.</p>
                                <ul class="mt-4 space-y-3 text-sm text-slate-600">
                                    <?php foreach ($modeBreakdown as $modeRow): ?>
                                        <li class="flex items-center justify-between">
                                            <span class="font-medium text-slate-900"><?= htmlspecialchars(ucfirst($modeRow['mode'])) ?></span>
                                            <span><?= (int) $modeRow['total'] ?> sessions · <?= $modeRow['avg_accuracy'] !== null ? number_format($modeRow['avg_accuracy'], 1) . '%' : '—' ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if (!$modeBreakdown): ?>
                                        <li class="rounded-2xl border border-dashed border-slate-300 p-4 text-xs text-slate-500">No study sessions yet. Launch a mode to populate this panel.</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                                <h3 class="text-base font-semibold text-slate-900">Recent study sessions</h3>
                                <p class="mt-1 text-xs text-slate-500">Monitor who practiced last and how they performed.</p>
                                <ul class="mt-4 space-y-3 text-sm text-slate-600">
                                    <?php foreach (array_slice($recentStudySessions, 0, 4) as $session): ?>
                                        <li>
                                            <p class="font-semibold text-slate-900"><?= htmlspecialchars($session['display_name']) ?> · <?= htmlspecialchars(ucfirst($session['mode'])) ?></p>
                                            <p class="text-xs text-slate-500">Accuracy <?= $session['accuracy'] !== null ? number_format($session['accuracy'], 1) . '%' : '—' ?> · Completed <?= htmlspecialchars($session['completed_at'] ?: $session['started_at']) ?></p>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if (!$recentStudySessions): ?>
                                        <li class="rounded-2xl border border-dashed border-slate-300 p-4 text-xs text-slate-500">Encourage your class to study—progress will appear here automatically.</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <?php foreach ($terms as $term): ?>
                                <article class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                                    <div class="flex items-start justify-between gap-3">
                                        <h3 class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($term['term']) ?></h3>
                                        <span class="text-xs uppercase tracking-widest text-slate-500">Card</span>
                                    </div>
                                    <p class="mt-3 text-sm leading-relaxed text-slate-600"><?= nl2br(htmlspecialchars($term['definition'])) ?></p>
                                    <?php if ($term['image_path']): ?>
                                        <img src="<?= htmlspecialchars(str_replace(__DIR__, '', $term['image_path'])) ?>" class="mt-3 max-h-40 w-full rounded-xl border border-slate-200 object-cover">
                                    <?php endif; ?>
                                    <?php if ($term['audio_path']): ?>
                                        <audio controls class="mt-3 w-full">
                                            <source src="<?= htmlspecialchars(str_replace(__DIR__, '', $term['audio_path'])) ?>">
                                        </audio>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                            <?php if (!$terms): ?>
                                <p class="rounded-2xl border border-dashed border-slate-300 p-6 text-sm text-slate-500">No terms yet. Add content from the Study Sets tab to activate practice modes.</p>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php else: ?>
                    <p class="text-sm text-slate-500">Set not found.</p>
                <?php endif; ?>
            <?php elseif (in_array($page, ['flashcards', 'learn', 'write', 'spell', 'test', 'match']) && $setId): ?>
                <?php $set = get_set($setId); $terms = get_terms_for_set($setId); ?>
                <?php if ($set && $terms): ?>
                    <section class="<?= $cardClass ?> p-6 space-y-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-xs uppercase tracking-[0.35em] text-slate-500">Mode workspace</p>
                                <h2 class="font-display text-3xl font-semibold text-slate-900"><?= htmlspecialchars($set['title']) ?></h2>
                                <p class="text-sm text-slate-500">Mode: <?= ucfirst($page) ?> · <?= count($terms) ?> cards</p>
                            </div>
                            <a href="?page=set_view&amp;set_id=<?= $set['id'] ?>" class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-900 transition hover:bg-slate-200">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 19.5 8.25 12l7.5-7.5" />
                                </svg>
                                Back to set
                            </a>
                        </div>
                        <?php if ($page === 'flashcards'): ?>
                            <div id="flashcard" class="relative cursor-pointer rounded-2xl border border-slate-200 bg-gradient-to-br from-brand-100 via-white to-slate-200 p-10 text-center shadow-soft">
                                <p id="flashcard-content" class="text-3xl font-semibold text-slate-900"></p>
                                <p class="mt-3 text-sm text-slate-600">Tap or click to flip</p>
                            </div>
                            <div class="mt-6 flex items-center justify-between">
                                <button id="prev" class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-5 py-2 text-sm font-semibold text-slate-900 transition hover:bg-slate-200">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 19.5 8.25 12l7.5-7.5" />
                                    </svg>
                                    Previous
                                </button>
                                <div class="flex items-center gap-3">
                                    <span class="text-xs uppercase tracking-widest text-slate-500">Mastery review</span>
                                </div>
                                <button id="next" class="inline-flex items-center gap-2 rounded-full bg-brand-500 px-5 py-2 text-sm font-semibold text-white shadow-brand-500/40 transition hover:bg-brand-600">
                                    Next
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                    </svg>
                                </button>
                            </div>
                        <?php elseif ($page === 'learn'): ?>
                            <div id="learn-card" class="space-y-4">
                                <div class="rounded-2xl border border-slate-200 bg-emerald-500/10 p-6">
                                    <h3 id="learn-prompt" class="text-xl font-semibold text-slate-900"></h3>
                                    <div class="mt-4 space-y-3">
                                        <button data-choice="term" class="learn-choice w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-left text-sm font-medium text-slate-900 transition hover:border-brand-400"></button>
                                        <button data-choice="definition" class="learn-choice w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-left text-sm font-medium text-slate-900 transition hover:border-brand-400"></button>
                                        <button data-choice="mixed" class="learn-choice w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-left text-sm font-medium text-slate-900 transition hover:border-brand-400"></button>
                                    </div>
                                    <p id="learn-feedback" class="mt-3 text-sm text-slate-600"></p>
                                </div>
                                <button id="complete-learn" class="hidden w-full rounded-full bg-brand-500 px-5 py-3 text-sm font-semibold text-white shadow-brand-500/40 transition hover:bg-brand-600">Mark learn session complete</button>
                            </div>
                        <?php elseif ($page === 'write'): ?>
                            <div class="space-y-4">
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-6">
                                    <h3 id="write-prompt" class="text-xl font-semibold text-slate-900"></h3>
                                    <input id="write-answer" class="mt-3 <?= $inputClass ?>" placeholder="Type your answer">
                                    <p id="write-feedback" class="mt-2 text-sm text-slate-600"></p>
                                    <button id="write-submit" class="mt-4 inline-flex items-center gap-2 rounded-full bg-brand-500 px-5 py-2 text-sm font-semibold text-white shadow-brand-500/40 transition hover:bg-brand-600">Check</button>
                                </div>
                                <button id="complete-write" class="hidden w-full rounded-full bg-emerald-500 px-5 py-3 text-sm font-semibold text-white shadow-emerald-500/40 transition hover:bg-emerald-600">Save write progress</button>
                            </div>
                        <?php elseif ($page === 'spell'): ?>
                            <div class="space-y-4">
                                <div class="rounded-2xl border border-slate-200 bg-sky-500/10 p-6">
                                    <h3 class="text-xl font-semibold text-slate-900">Spell the word you hear</h3>
                                    <button id="spell-play" class="mt-3 inline-flex items-center gap-2 rounded-full bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-900 transition hover:bg-slate-200">Play audio</button>
                                    <input id="spell-answer" class="mt-3 <?= $inputClass ?>" placeholder="Type what you heard">
                                    <p id="spell-feedback" class="mt-2 text-sm text-slate-600"></p>
                                    <button id="spell-submit" class="mt-4 inline-flex items-center gap-2 rounded-full bg-brand-500 px-5 py-2 text-sm font-semibold text-white shadow-brand-500/40 transition hover:bg-brand-600">Check</button>
                                </div>
                                <button id="complete-spell" class="hidden w-full rounded-full bg-emerald-500 px-5 py-3 text-sm font-semibold text-white shadow-emerald-500/40 transition hover:bg-emerald-600">Save spell progress</button>
                            </div>
                            <audio id="spell-audio" hidden></audio>
                        <?php elseif ($page === 'test'): ?>
                            <?php
                                $questionPool = $terms;
                                shuffle($questionPool);
                                $questions = array_slice($questionPool, 0, min(8, count($questionPool)));
                            ?>
                            <form method="post" class="space-y-4" id="test-form">
                                <?php foreach ($questions as $index => $term): ?>
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                                        <h3 class="text-lg font-semibold text-slate-900">Question <?= $index + 1 ?></h3>
                                        <?php if ($index % 3 === 0): ?>
                                            <p class="mt-2 text-sm text-slate-600">Write the definition for <strong><?= htmlspecialchars($term['term']) ?></strong></p>
                                            <textarea name="q<?= $index ?>" class="<?= $inputClass ?> mt-3" rows="3"></textarea>
                                        <?php elseif ($index % 3 === 1): ?>
                                            <p class="mt-2 text-sm text-slate-600">Select the correct term for the definition:</p>
                                            <p class="mt-2 italic text-slate-600">"<?= htmlspecialchars($term['definition']) ?>"</p>
                                            <?php
                                                $choices = [$term['term']];
                                                $distractors = array_filter($terms, fn($t) => $t['id'] !== $term['id']);
                                                shuffle($distractors);
                                                foreach (array_slice($distractors, 0, 3) as $d) {
                                                    $choices[] = $d['term'];
                                                }
                                                shuffle($choices);
                                            ?>
                                            <?php foreach ($choices as $choice): ?>
                                                <label class="mt-2 flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 transition hover:border-brand-400">
                                                    <input type="radio" name="q<?= $index ?>" value="<?= htmlspecialchars($choice) ?>" class="h-4 w-4 rounded border-white/30 bg-transparent text-brand-500 focus:ring-brand-500">
                                                    <span><?= htmlspecialchars($choice) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p class="mt-2 text-sm text-slate-600">True or False: "<?= htmlspecialchars($term['term']) ?>" means "<?= htmlspecialchars($term['definition']) ?>"</p>
                                            <label class="mt-3 flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 text-sm text-slate-900 transition hover:border-brand-400">
                                                <input type="radio" name="q<?= $index ?>" value="true" class="h-4 w-4 rounded border-white/30 bg-transparent text-brand-500 focus:ring-brand-500">
                                                <span>True</span>
                                            </label>
                                            <label class="mt-2 flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 text-sm text-slate-900 transition hover:border-brand-400">
                                                <input type="radio" name="q<?= $index ?>" value="false" class="h-4 w-4 rounded border-white/30 bg-transparent text-brand-500 focus:ring-brand-500">
                                                <span>False</span>
                                            </label>
                                        <?php endif; ?>
                                        <input type="hidden" name="answer<?= $index ?>" value="<?= htmlspecialchars($term['term'] . '||' . $term['definition']) ?>">
                                    </div>
                                <?php endforeach; ?>
                                <input type="hidden" name="action" value="record_session">
                                <input type="hidden" name="mode" value="test">
                                <input type="hidden" name="set_id" value="<?= $set['id'] ?>">
                                <input type="hidden" name="accuracy" id="test-accuracy" value="0">
                                <input type="hidden" name="progress" id="test-progress" value="">
                                <button class="inline-flex items-center gap-2 rounded-full bg-brand-500 px-6 py-3 text-sm font-semibold text-white shadow-brand-500/40 transition hover:bg-brand-600">Submit test</button>
                            </form>
                            <script>
                                document.getElementById('test-form').addEventListener('submit', function(event) {
                                    const questions = <?= json_encode($questions) ?>;
                                    let correct = 0;
                                    questions.forEach((q, index) => {
                                        const answerField = document.querySelector(`[name="answer${index}"]`);
                                        const [term, definition] = answerField.value.split('||');
                                        const value = this[`q${index}`];
                                        if (!value) return;
                                        if (index % 3 === 0) {
                                            if (value.value.trim().toLowerCase() === definition.toLowerCase()) correct++;
                                        } else if (index % 3 === 1) {
                                            if (value.value === term) correct++;
                                        } else {
                                            const truth = value.value === 'true';
                                            const actual = true;
                                            if (truth === actual) correct++;
                                        }
                                    });
                                    const accuracy = questions.length ? (correct / questions.length) * 100 : 0;
                                    document.getElementById('test-accuracy').value = accuracy.toFixed(2);
                                    document.getElementById('test-progress').value = JSON.stringify({correct, total: questions.length});
                                });
                            </script>
                        <?php elseif ($page === 'match'): ?>
                            <div class="space-y-4">
                                <p class="text-sm text-slate-600">Match each term to the correct definition as quickly as you can.</p>
                                <button id="start-match" class="inline-flex items-center gap-2 rounded-full bg-brand-500 px-5 py-2 text-sm font-semibold text-white shadow-brand-500/40 transition hover:bg-brand-600">Start game</button>
                                <div id="match-grid" class="grid gap-3 md:grid-cols-4"></div>
                                <p id="match-timer" class="text-lg font-semibold text-slate-900"></p>
                                <form method="post" class="hidden" id="match-form">
                                    <input type="hidden" name="action" value="record_match_score">
                                    <input type="hidden" name="set_id" value="<?= $set['id'] ?>">
                                    <input type="hidden" name="elapsed" id="match-elapsed">
                                </form>
                                <section class="mt-6">
                                    <h3 class="text-lg font-semibold text-slate-900">Leaderboard</h3>
                                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                                        <?php
                                            $scores = $db->prepare('SELECT match_game_scores.*, users.display_name FROM match_game_scores JOIN users ON users.id = match_game_scores.user_id WHERE set_id = ? ORDER BY elapsed_seconds ASC LIMIT 10');
                                            $scores->execute([$set['id']]);
                                            foreach ($scores as $score):
                                        ?>
                                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                                <p class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($score['display_name']) ?></p>
                                                <p class="text-xs uppercase tracking-widest text-slate-500"><?= number_format($score['elapsed_seconds'], 1) ?> seconds</p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php else: ?>
                    <p class="text-sm text-slate-500">This set has no terms yet.</p>
                <?php endif; ?>
                <script>
                    const mode = '<?= $page ?>';
                    const terms = <?= json_encode(array_values($terms)) ?>;
                    if (mode === 'flashcards') {
                        let index = 0;
                        let flipped = false;
                        const content = document.getElementById('flashcard-content');
                        const card = document.getElementById('flashcard');
                        const updateCard = () => {
                            flipped = false;
                            content.textContent = terms[index].term;
                        };
                        card.addEventListener('click', () => {
                            flipped = !flipped;
                            content.textContent = flipped ? terms[index].definition : terms[index].term;
                        });
                        document.getElementById('prev').addEventListener('click', () => {
                            index = (index - 1 + terms.length) % terms.length;
                            updateCard();
                        });
                        document.getElementById('next').addEventListener('click', () => {
                            index = (index + 1) % terms.length;
                            updateCard();
                        });
                        updateCard();
                    } else if (mode === 'learn') {
                        let mastery = {};
                        let queue = [...terms];
                        const prompt = document.getElementById('learn-prompt');
                        const feedback = document.getElementById('learn-feedback');
                        const buttons = document.querySelectorAll('.learn-choice');
                        const completeButton = document.getElementById('complete-learn');
                        let current;
                        const nextCard = () => {
                            if (!queue.length) {
                                feedback.textContent = 'Session complete! Save your progress.';
                                completeButton.classList.remove('hidden');
                                return;
                            }
                            current = queue.shift();
                            prompt.textContent = `What matches ${current.term}?`;
                            buttons.forEach(btn => {
                                if (btn.dataset.choice === 'term') {
                                    btn.textContent = current.term;
                                } else if (btn.dataset.choice === 'definition') {
                                    btn.textContent = current.definition;
                                } else {
                                    const other = terms.filter(t => t.id !== current.id);
                                    const alt = other[Math.floor(Math.random() * other.length)] || current;
                                    btn.textContent = Math.random() > 0.5 ? alt.term : alt.definition;
                                }
                            });
                            feedback.textContent = '';
                        };
                        buttons.forEach(btn => {
                            btn.addEventListener('click', () => {
                                const correct = btn.textContent === current.definition || btn.textContent === current.term;
                                feedback.textContent = correct ? 'Great job! Card leveling up.' : 'Keep practicing, try again soon.';
                                feedback.className = correct ? 'text-sm text-emerald-600' : 'text-sm text-rose-600';
                                mastery[current.id] = mastery[current.id] || {success: 0, attempts: 0};
                                mastery[current.id].attempts++;
                                if (correct) {
                                    mastery[current.id].success++;
                                } else {
                                    queue.push(current);
                                }
                                setTimeout(nextCard, 800);
                            });
                        });
                        completeButton.addEventListener('click', () => {
                            const form = document.createElement('form');
                            form.method = 'post';
                            form.innerHTML = `
                                <input type="hidden" name="action" value="record_session">
                                <input type="hidden" name="set_id" value="<?= $set['id'] ?>">
                                <input type="hidden" name="mode" value="learn">
                                <input type="hidden" name="accuracy" value="${computeAccuracy(mastery)}">
                                <input type="hidden" name="progress" value='${JSON.stringify(mastery)}'>
                            `;
                            document.body.appendChild(form);
                            form.submit();
                        });
                        const computeAccuracy = (data) => {
                            const values = Object.values(data);
                            if (!values.length) return 0;
                            const total = values.reduce((acc, item) => acc + item.attempts, 0);
                            const success = values.reduce((acc, item) => acc + item.success, 0);
                            return total ? ((success / total) * 100).toFixed(2) : 0;
                        };
                        nextCard();
                    } else if (mode === 'write') {
                        let index = 0;
                        const prompt = document.getElementById('write-prompt');
                        const answer = document.getElementById('write-answer');
                        const feedback = document.getElementById('write-feedback');
                        const submit = document.getElementById('write-submit');
                        const complete = document.getElementById('complete-write');
                        let correct = 0;
                        const updatePrompt = () => {
                            const card = terms[index];
                            prompt.textContent = `Define: ${card.term}`;
                            answer.value = '';
                            feedback.textContent = '';
                        };
                        submit.addEventListener('click', () => {
                            const card = terms[index];
                            if (answer.value.trim().toLowerCase() === card.definition.toLowerCase()) {
                                feedback.textContent = 'Correct!';
                                feedback.className = 'mt-2 text-sm text-emerald-600';
                                correct++;
                            } else {
                                feedback.textContent = `Keep practicing. Answer: ${card.definition}`;
                                feedback.className = 'mt-2 text-sm text-rose-600';
                            }
                            index++;
                            if (index >= terms.length) {
                                complete.classList.remove('hidden');
                                submit.disabled = true;
                            } else {
                                setTimeout(updatePrompt, 600);
                            }
                        });
                        complete.addEventListener('click', () => {
                            const accuracy = (correct / terms.length) * 100;
                            const form = document.createElement('form');
                            form.method = 'post';
                            form.innerHTML = `
                                <input type="hidden" name="action" value="record_session">
                                <input type="hidden" name="set_id" value="<?= $set['id'] ?>">
                                <input type="hidden" name="mode" value="write">
                                <input type="hidden" name="accuracy" value="${accuracy.toFixed(2)}">
                                <input type="hidden" name="progress" value='${JSON.stringify({correct, total: terms.length})}'>
                            `;
                            document.body.appendChild(form);
                            form.submit();
                        });
                        updatePrompt();
                    } else if (mode === 'spell') {
                        const promptIndex = { value: 0 };
                        const audio = document.getElementById('spell-audio');
                        const button = document.getElementById('spell-play');
                        const input = document.getElementById('spell-answer');
                        const feedback = document.getElementById('spell-feedback');
                        const submit = document.getElementById('spell-submit');
                        const complete = document.getElementById('complete-spell');
                        let correct = 0;
                        const speak = () => {
                            const card = terms[promptIndex.value];
                            if (card.audio_path) {
                                audio.src = card.audio_path.replace('<?= addslashes(__DIR__) ?>', '');
                                audio.play();
                            } else {
                                const utterance = new SpeechSynthesisUtterance(card.term);
                                speechSynthesis.speak(utterance);
                            }
                        };
                        button.addEventListener('click', speak);
                        submit.addEventListener('click', () => {
                            const card = terms[promptIndex.value];
                            if (input.value.trim().toLowerCase() === card.term.toLowerCase()) {
                                feedback.textContent = 'Correct!';
                                feedback.className = 'mt-2 text-sm text-emerald-600';
                                correct++;
                            } else {
                                feedback.textContent = `Try again. Word was ${card.term}`;
                                feedback.className = 'mt-2 text-sm text-rose-600';
                            }
                            promptIndex.value++;
                            if (promptIndex.value >= terms.length) {
                                complete.classList.remove('hidden');
                                submit.disabled = true;
                            } else {
                                input.value = '';
                                speak();
                            }
                        });
                        complete.addEventListener('click', () => {
                            const accuracy = (correct / terms.length) * 100;
                            const form = document.createElement('form');
                            form.method = 'post';
                            form.innerHTML = `
                                <input type="hidden" name="action" value="record_session">
                                <input type="hidden" name="set_id" value="<?= $set['id'] ?>">
                                <input type="hidden" name="mode" value="spell">
                                <input type="hidden" name="accuracy" value="${accuracy.toFixed(2)}">
                                <input type="hidden" name="progress" value='${JSON.stringify({correct, total: terms.length})}'>
                            `;
                            document.body.appendChild(form);
                            form.submit();
                        });
                        speak();
                    } else if (mode === 'match') {
                        let matches = [];
                        let selected = [];
                        let playing = false;
                        let startTime;
                        const grid = document.getElementById('match-grid');
                        const timer = document.getElementById('match-timer');
                        const startButton = document.getElementById('start-match');
                        const form = document.getElementById('match-form');
                        const elapsedField = document.getElementById('match-elapsed');
                        startButton.addEventListener('click', () => {
                            matches = [];
                            selected = [];
                            playing = true;
                            startTime = Date.now();
                            timer.textContent = 'Time: 0.0s';
                            const cards = [];
                            terms.slice(0, 8).forEach(term => {
                                cards.push({ id: term.id + '-t', type: 'term', text: term.term, pair: term.id });
                                cards.push({ id: term.id + '-d', type: 'definition', text: term.definition, pair: term.id });
                            });
                            cards.sort(() => Math.random() - 0.5);
                            grid.innerHTML = '';
                            cards.forEach(card => {
                                const div = document.createElement('button');
                                div.type = 'button';
                                div.textContent = card.text;
                                div.dataset.pair = card.pair;
                                div.className = 'border rounded p-3 bg-white hover:bg-indigo-50';
                                div.addEventListener('click', () => {
                                    if (!playing || matches.includes(card.pair) || selected.includes(div)) return;
                                    selected.push(div);
                                    div.classList.add('bg-indigo-100');
                                    if (selected.length === 2) {
                                        const [first, second] = selected;
                                        if (first.dataset.pair === second.dataset.pair && first !== second) {
                                            matches.push(first.dataset.pair);
                                            first.classList.add('bg-emerald-100');
                                            second.classList.add('bg-emerald-100');
                                            if (matches.length === Math.min(terms.length, 8)) {
                                                playing = false;
                                                const elapsed = (Date.now() - startTime) / 1000;
                                                timer.textContent = `Finished in ${elapsed.toFixed(1)} seconds`;
                                                elapsedField.value = elapsed.toFixed(2);
                                                form.submit();
                                            }
                                        } else {
                                            setTimeout(() => {
                                                first.classList.remove('bg-indigo-100');
                                                second.classList.remove('bg-indigo-100');
                                            }, 500);
                                        }
                                        selected = [];
                                    }
                                });
                                grid.appendChild(div);
                            });
                            const interval = setInterval(() => {
                                if (!playing) return clearInterval(interval);
                                const elapsed = (Date.now() - startTime) / 1000;
                                timer.textContent = `Time: ${elapsed.toFixed(1)} seconds`;
                            }, 100);
                        });
                    }
                </script>
            <?php elseif ($page === 'live'): ?>
                <section class="grid gap-6 lg:grid-cols-2">
                    <div class="<?= $cardClass ?> p-6 space-y-5">
                        <div>
                            <p class="text-xs uppercase tracking-[0.35em] text-slate-500">Live orchestration</p>
                            <h2 class="font-display text-2xl font-semibold text-slate-900">Teacher live controls</h2>
                        </div>
                        <?php if (is_teacher()): ?>
                            <form method="post" class="space-y-4">
                                <input type="hidden" name="action" value="start_live">
                                <div>
                                    <label class="<?= $labelClass ?>">Study set</label>
                                    <select name="set_id" class="<?= $inputClass ?>">
                                        <?php foreach (get_sets_for_user($user) as $set): ?>
                                            <option value="<?= $set['id'] ?>"><?= htmlspecialchars($set['title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button class="<?= $primaryButton ?>">Launch live game</button>
                            </form>
                        <?php else: ?>
                            <p class="text-sm text-slate-600">Only teachers can host live games. Join with a code below.</p>
                        <?php endif; ?>
                        <div>
                            <h3 class="mt-6 text-lg font-semibold text-slate-900">Active live sessions</h3>
                            <div class="mt-3 space-y-3">
                                <?php
                                    $sessions = $db->query('SELECT live_sessions.*, study_sets.title, users.display_name FROM live_sessions JOIN study_sets ON study_sets.id = live_sessions.set_id JOIN users ON users.id = live_sessions.host_id WHERE status != "closed" ORDER BY created_at DESC LIMIT 10');
                                    foreach ($sessions as $session):
                                ?>
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <p class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($session['title']) ?></p>
                                        <p class="text-xs uppercase tracking-widest text-slate-500">Host: <?= htmlspecialchars($session['display_name']) ?> · Code: <?= htmlspecialchars($session['code']) ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="<?= $cardClass ?> p-6 space-y-5">
                        <div>
                            <p class="text-xs uppercase tracking-[0.35em] text-slate-500">Participant access</p>
                            <h2 class="font-display text-2xl font-semibold text-slate-900">Join a live session</h2>
                        </div>
                        <form method="post" class="flex flex-col gap-3 sm:flex-row">
                            <input type="hidden" name="action" value="join_live">
                            <input name="code" placeholder="Enter code" class="flex-1 <?= $inputClass ?>" required>
                            <button class="<?= $successButton ?>">Join</button>
                        </form>
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">Your live scores</h3>
                            <div class="mt-3 space-y-3">
                                <?php
                                    if ($user) {
                                        $liveScores = $db->prepare('SELECT live_participants.score, live_sessions.code, study_sets.title FROM live_participants JOIN live_sessions ON live_sessions.id = live_participants.live_session_id JOIN study_sets ON study_sets.id = live_sessions.set_id WHERE live_participants.user_id = ? ORDER BY live_participants.id DESC LIMIT 10');
                                        $liveScores->execute([$user['id']]);
                                        foreach ($liveScores as $score):
                                ?>
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <p class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($score['title']) ?></p>
                                        <p class="text-xs uppercase tracking-widest text-slate-500">Score: <?= (int) $score['score'] ?> · Code: <?= htmlspecialchars($score['code']) ?></p>
                                    </div>
                                <?php endforeach; } ?>
                            </div>
                        </div>
                        <form method="post" class="space-y-3">
                            <input type="hidden" name="action" value="update_live_score">
                            <div>
                                <label class="<?= $labelClass ?>">Session ID</label>
                                <input name="session_id" class="<?= $inputClass ?>">
                            </div>
                            <div>
                                <label class="<?= $labelClass ?>">Score</label>
                                <input name="score" class="<?= $inputClass ?>">
                            </div>
                            <button class="<?= $secondaryButton ?>">Update score</button>
                        </form>
                    </div>
                </section>
            <?php elseif ($page === 'classes'): ?>
                <section class="grid gap-6 lg:grid-cols-2">
                    <div class="<?= $cardClass ?> p-6 space-y-5">
                        <div>
                            <p class="text-xs uppercase tracking-[0.35em] text-slate-500">Class management</p>
                            <h2 class="font-display text-2xl font-semibold text-slate-900">Classes</h2>
                        </div>
                        <?php if (is_teacher()): ?>
                            <form method="post" class="space-y-4">
                                <input type="hidden" name="action" value="create_class">
                                <div>
                                    <label class="<?= $labelClass ?>">Class name</label>
                                    <input name="name" class="<?= $inputClass ?>" required>
                                </div>
                                <div>
                                    <label class="<?= $labelClass ?>">Description</label>
                                    <textarea name="description" class="<?= $inputClass ?>"></textarea>
                                </div>
                                <button class="<?= $primaryButton ?>">Create class</button>
                            </form>
                        <?php endif; ?>
                        <div>
                            <h3 class="mt-6 text-lg font-semibold text-slate-900">Join a class</h3>
                            <form method="post" class="mt-3 flex flex-col gap-3 sm:flex-row">
                                <input type="hidden" name="action" value="join_class">
                                <input name="code" class="flex-1 <?= $inputClass ?>" placeholder="Join code" required>
                                <button class="<?= $successButton ?>">Join</button>
                            </form>
                        </div>
                        <div>
                            <h3 class="mt-6 text-lg font-semibold text-slate-900">Your classes</h3>
                            <div class="mt-3 space-y-3">
                                <?php foreach (get_classes_for_user($user) as $class): ?>
                                    <details class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <summary class="cursor-pointer text-sm font-semibold text-slate-900"><?= htmlspecialchars($class['name']) ?> — Code: <?= htmlspecialchars($class['join_code']) ?></summary>
                                        <p class="mt-3 text-sm text-slate-600"><?= htmlspecialchars($class['description']) ?></p>
                                        <h4 class="mt-4 text-sm font-semibold text-slate-900">Members</h4>
                                        <ul class="mt-2 space-y-1 text-sm text-slate-600">
                                            <?php foreach (get_class_members($class['id']) as $member): ?>
                                                <li><?= htmlspecialchars($member['display_name']) ?> (<?= htmlspecialchars($member['class_role']) ?>)</li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <h4 class="mt-4 text-sm font-semibold text-slate-900">Assigned sets</h4>
                                        <ul class="mt-2 space-y-1 text-sm">
                                            <?php foreach (get_class_assignments($class['id']) as $assigned): ?>
                                                <li><a class="text-brand-600 transition hover:text-brand-500" href="?page=set_view&amp;set_id=<?= $assigned['id'] ?>"><?= htmlspecialchars($assigned['title']) ?></a></li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <?php if (is_teacher()): ?>
                                            <form method="post" class="mt-4 flex flex-col gap-3 sm:flex-row">
                                                <input type="hidden" name="action" value="assign_set">
                                                <input type="hidden" name="class_id" value="<?= $class['id'] ?>">
                                                <select name="set_id" class="flex-1 <?= $inputClass ?>">
                                                    <?php foreach (get_sets_for_user($user) as $set): ?>
                                                        <option value="<?= $set['id'] ?>"><?= htmlspecialchars($set['title']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="<?= $secondaryButton ?>">Assign set</button>
                                            </form>
                                        <?php endif; ?>
                                    </details>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="<?= $cardClass ?> p-6 space-y-4">
                        <h2 class="text-xl font-semibold">Teacher analytics</h2>
                        <p class="text-sm text-slate-600">Monitor progress and engagement across your classes. Adaptive difficulty and game-based learning results flow into one dashboard.</p>
                        <div class="space-y-3">
                            <?php
                                if (is_teacher()) {
                                    $analytics = $db->prepare('SELECT classes.name, study_sets.title, AVG(study_sessions.accuracy) AS avg_accuracy, COUNT(study_sessions.id) AS sessions
                                        FROM class_assignments
                                        JOIN classes ON classes.id = class_assignments.class_id
                                        JOIN study_sets ON study_sets.id = class_assignments.set_id
                                        LEFT JOIN study_sessions ON study_sessions.set_id = study_sets.id
                                        WHERE classes.teacher_id = ?
                                        GROUP BY classes.id, study_sets.id
                                        ORDER BY sessions DESC');
                                    $analytics->execute([$user['id']]);
                                    foreach ($analytics as $row):
                            ?>
                                <div class="border rounded p-4 bg-amber-50">
                                    <p class="font-semibold"><?= htmlspecialchars($row['name']) ?> — <?= htmlspecialchars($row['title']) ?></p>
                                    <p class="text-sm text-slate-600">Avg. accuracy: <?= $row['avg_accuracy'] ? number_format($row['avg_accuracy'], 1) : 'N/A' ?> · Sessions: <?= (int) $row['sessions'] ?></p>
                                </div>
                            <?php endforeach; } ?>
                        </div>
                        <div>
                            <h3 class="font-semibold mt-4">Live engagement tips</h3>
                            <ul class="list-disc pl-5 text-sm text-slate-600 space-y-1">
                                <li>Rotate through Match, Blast, and Categories to keep competition fresh.</li>
                                <li>Assign sets to classes so progress tracking updates automatically.</li>
                                <li>Use private visibility for class-only vocab drills.</li>
                            </ul>
                        </div>
                    </div>
                </section>
            <?php elseif ($page === 'analytics'): ?>
                <?php
                    $summary = get_user_activity_summary($user);
                    $sessions = $db->prepare('SELECT study_sets.title, study_sessions.mode, study_sessions.accuracy, study_sessions.completed_at FROM study_sessions JOIN study_sets ON study_sets.id = study_sessions.set_id WHERE study_sessions.user_id = ? ORDER BY study_sessions.completed_at DESC LIMIT 12');
                    $sessions->execute([$user['id']]);
                    $modeSummaryStmt = $db->prepare('SELECT mode, COUNT(*) AS total, AVG(accuracy) AS avg_accuracy FROM study_sessions WHERE user_id = ? GROUP BY mode ORDER BY total DESC');
                    $modeSummaryStmt->execute([$user['id']]);
                    $modeRows = $modeSummaryStmt->fetchAll(PDO::FETCH_ASSOC);
                    $matchLeaderboardStmt = $db->prepare('SELECT match_game_scores.elapsed_seconds, match_game_scores.played_at, study_sets.title FROM match_game_scores JOIN study_sets ON study_sets.id = match_game_scores.set_id WHERE match_game_scores.user_id = ? ORDER BY match_game_scores.elapsed_seconds ASC LIMIT 5');
                    $matchLeaderboardStmt->execute([$user['id']]);
                    $matchRows = $matchLeaderboardStmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <section class="<?= $cardClass ?> p-6 space-y-6">
                    <div>
                        <p class="text-xs uppercase tracking-[0.35em] text-slate-500">Insight dashboards</p>
                        <h2 class="font-display text-3xl font-semibold text-slate-900">Learning analytics</h2>
                        <p class="mt-2 text-sm text-slate-600">Adaptive difficulty, study modes, and classroom assignments all roll up here. Monitor mastery and celebrate wins.</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs uppercase tracking-widest text-slate-500">Sessions logged</p>
                            <p class="mt-2 text-2xl font-semibold text-slate-900"><?= $summary['sessions_this_week'] ?></p>
                            <p class="text-xs text-slate-600">This week&apos;s activity</p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs uppercase tracking-widest text-slate-500">Average accuracy</p>
                            <p class="mt-2 text-2xl font-semibold text-slate-900"><?= $summary['avg_accuracy'] !== null ? number_format($summary['avg_accuracy'], 1) . '%' : '—' ?></p>
                            <p class="text-xs text-slate-600">Across all recorded sessions</p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs uppercase tracking-widest text-slate-500">Match best time</p>
                            <p class="mt-2 text-2xl font-semibold text-slate-900"><?= $summary['best_match_time'] !== null ? number_format($summary['best_match_time'], 1) . 's' : 'Not set' ?></p>
                            <p class="text-xs text-slate-600">Fastest Match completion</p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs uppercase tracking-widest text-slate-500">Mastered terms</p>
                            <p class="mt-2 text-2xl font-semibold text-slate-900"><?= $summary['mastered_terms'] ?></p>
                            <p class="text-xs text-slate-600">Tracked via learn mode mastery</p>
                        </div>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <h3 class="text-base font-semibold text-slate-900">Recent study sessions</h3>
                            <p class="mt-2 text-xs text-slate-600">Latest activity across all modes.</p>
                            <div class="mt-4 space-y-3">
                                <?php foreach ($sessions as $session): ?>
                                    <div>
                                        <p class="font-semibold text-slate-900"><?= htmlspecialchars($session['title']) ?></p>
                                        <p class="text-xs text-slate-500">Mode <?= htmlspecialchars(ucfirst($session['mode'])) ?> · Accuracy <?= $session['accuracy'] ? number_format($session['accuracy'], 1) : 'N/A' ?>% · <?= htmlspecialchars($session['completed_at']) ?></p>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!$sessions->rowCount()): ?>
                                    <p class="rounded-2xl border border-dashed border-slate-300 p-4 text-sm text-slate-500">No study sessions recorded yet. Complete a study mode to populate analytics.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <h3 class="text-base font-semibold text-slate-900">Mode analytics</h3>
                            <p class="mt-2 text-xs text-slate-600">Engagement and accuracy by study mode.</p>
                            <ul class="mt-4 space-y-3 text-sm text-slate-600">
                                <?php foreach ($modeRows as $row): ?>
                                    <li class="flex items-center justify-between">
                                        <span class="font-medium text-slate-900"><?= htmlspecialchars(ucfirst($row['mode'])) ?></span>
                                        <span><?= (int) $row['total'] ?> sessions · <?= $row['avg_accuracy'] !== null ? number_format($row['avg_accuracy'], 1) . '%' : '—' ?></span>
                                    </li>
                                <?php endforeach; ?>
                                <?php if (!$modeRows): ?>
                                    <li class="rounded-2xl border border-dashed border-slate-300 p-4 text-xs text-slate-500">Run a study session to unlock mode analytics.</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <h3 class="text-base font-semibold text-slate-900">Mastery spotlight</h3>
                            <p class="mt-2 text-xs text-slate-600">Learn mode tracks attempts and successes for each term. Review the JSON snapshot below for coaching conversations.</p>
                            <pre class="mt-4 max-h-64 overflow-x-auto rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-slate-100/80 p-4 text-xs text-emerald-700"><?php
                                $snapshot = $db->prepare('SELECT study_sets.title, study_sessions.mode, study_sessions.progress_json FROM study_sessions JOIN study_sets ON study_sets.id = study_sessions.set_id WHERE study_sessions.user_id = ? ORDER BY study_sessions.completed_at DESC LIMIT 1');
                                $snapshot->execute([$user['id']]);
                                $data = $snapshot->fetch(PDO::FETCH_ASSOC);
                                echo htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT));
                            ?></pre>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <h3 class="text-base font-semibold text-slate-900">Match leaderboard</h3>
                            <p class="mt-2 text-xs text-slate-600">Celebrate top Match times across your sets.</p>
                            <ul class="mt-4 space-y-3 text-sm text-slate-600">
                                <?php foreach ($matchRows as $row): ?>
                                    <li class="flex items-center justify-between">
                                        <span class="font-medium text-slate-900"><?= htmlspecialchars($row['title']) ?></span>
                                        <span><?= number_format($row['elapsed_seconds'], 2) ?>s · <?= htmlspecialchars($row['played_at']) ?></span>
                                    </li>
                                <?php endforeach; ?>
                                <?php if (!$matchRows): ?>
                                    <li class="rounded-2xl border border-dashed border-slate-300 p-4 text-xs text-slate-500">Play Match mode to start building your leaderboard.</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </section>
            <?php elseif ($page === 'import'): ?>
                <section class="<?= $cardClass ?> p-6 space-y-6">
                    <div>
                        <p class="text-xs uppercase tracking-[0.35em] text-slate-500">Rapid authoring</p>
                        <h2 class="font-display text-3xl font-semibold text-slate-900">Bulk import tools</h2>
                        <p class="mt-2 text-sm text-slate-600">Paste vocabulary lists, upload CSV files, and generate terms in seconds. Perfect for prepping large classes.</p>
                    </div>
                    <form method="post" enctype="multipart/form-data" class="space-y-4">
                        <input type="hidden" name="action" value="import_terms">
                        <div>
                            <label class="<?= $labelClass ?>">Study set</label>
                            <select name="set_id" class="<?= $inputClass ?>">
                                <?php foreach (get_sets_for_user($user) as $set): ?>
                                    <option value="<?= $set['id'] ?>"><?= htmlspecialchars($set['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="<?= $labelClass ?>">Paste list (term, definition)</label>
                            <textarea name="import_text" rows="6" class="<?= $inputClass ?>" placeholder="water, H2O"></textarea>
                        </div>
                        <div>
                            <label class="<?= $labelClass ?>">Upload CSV / TSV</label>
                            <input type="file" name="import_file" accept=".csv,.tsv,text/csv,text/tab-separated-values" class="<?= $inputClass ?>">
                        </div>
                        <button class="<?= $primaryButton ?>">Import</button>
                    </form>
                    <div>
                        <h3 class="mt-6 text-xl font-semibold text-slate-900">Import history</h3>
                        <ul class="mt-3 space-y-2 text-sm text-slate-600">
                            <?php
                                $imports = $db->prepare('SELECT * FROM imports WHERE user_id = ? ORDER BY imported_at DESC LIMIT 10');
                                $imports->execute([$user['id']]);
                                foreach ($imports as $row):
                            ?>
                                <li class="rounded-2xl border border-slate-200 bg-slate-50 p-4">Uploaded <?= htmlspecialchars($row['original_filename']) ?> — <?= htmlspecialchars($row['imported_at']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
