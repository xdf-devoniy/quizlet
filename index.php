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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quizlet Classroom Suite</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-900 min-h-screen">
    <div class="max-w-7xl mx-auto px-4 py-6">
        <header class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-slate-800">Office of Teaching &amp; Learning — Quizlet Toolkit</h1>
                <p class="text-slate-600">Create, manage, and gamify study experiences for every learner.</p>
                <?php if ($message): ?>
                    <p class="mt-2 text-sm text-amber-600 bg-amber-100 px-3 py-2 rounded"><?= htmlspecialchars($message) ?></p>
                <?php endif; ?>
            </div>
            <div class="text-right">
                <?php if ($user): ?>
                    <p class="font-medium">Welcome, <?= htmlspecialchars($user['display_name'] ?: $user['username']) ?> (<?= htmlspecialchars($user['role']) ?>)</p>
                    <form method="post" class="inline">
                        <input type="hidden" name="action" value="logout">
                        <button class="mt-2 inline-flex items-center gap-2 px-4 py-2 bg-slate-800 text-white rounded shadow">Log out</button>
                    </form>
                <?php endif; ?>
            </div>
        </header>

        <?php if (!$user): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <section class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-semibold mb-4">Log in</h2>
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="action" value="login">
                        <div>
                            <label class="block text-sm font-medium">Username</label>
                            <input name="username" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Password</label>
                            <input type="password" name="password" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <button class="w-full bg-indigo-600 text-white rounded py-2">Log in</button>
                        <p class="text-xs text-slate-500">Default teacher credentials: <strong>teacher / teacher</strong>. Student: <strong>student / student</strong>.</p>
                    </form>
                </section>
                <section class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-semibold mb-4">Create a student account</h2>
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="action" value="register">
                        <div>
                            <label class="block text-sm font-medium">Display name</label>
                            <input name="display_name" class="w-full border rounded px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Username</label>
                            <input name="username" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Password</label>
                            <input type="password" name="password" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <button class="w-full bg-emerald-600 text-white rounded py-2">Sign up</button>
                    </form>
                </section>
            </div>
        <?php else: ?>
            <nav class="bg-white rounded-lg shadow mb-6">
                <ul class="flex flex-wrap items-center">
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
                        <li>
                            <a href="?page=<?= $key ?><?= $setId ? '&amp;set_id=' . $setId : '' ?>" class="block px-4 py-3 <?= $page === $key ? 'bg-indigo-100 text-indigo-700 font-semibold' : 'text-slate-600 hover:bg-slate-100' ?>"><?= $label ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <?php if ($page === 'dashboard'): ?>
                <?php
                    $classes = get_classes_for_user($user);
                    $userSets = get_sets_for_user($user);
                    $publicSets = get_public_sets();
                ?>
                <section class="grid lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 space-y-6">
                        <div class="bg-white rounded-lg shadow p-6">
                            <h2 class="text-xl font-semibold mb-3">Your recent study sets</h2>
                            <div class="grid md:grid-cols-2 gap-4">
                                <?php foreach ($userSets as $set): ?>
                                    <a href="?page=set_view&amp;set_id=<?= $set['id'] ?>" class="border rounded-lg p-4 hover:border-indigo-500">
                                        <h3 class="font-semibold text-lg"><?= htmlspecialchars($set['title']) ?></h3>
                                        <p class="text-sm text-slate-500 line-clamp-2"><?= htmlspecialchars($set['description']) ?></p>
                                        <p class="mt-2 text-xs uppercase tracking-wide text-slate-400">Mode ready: Flashcards, Learn, Test, Games</p>
                                    </a>
                                <?php endforeach; ?>
                                <?php if (!$userSets): ?>
                                    <p class="text-sm text-slate-500">Create your first study set to get started.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="bg-white rounded-lg shadow p-6">
                            <h2 class="text-xl font-semibold mb-3">Classroom live games</h2>
                            <p class="text-sm text-slate-500">Launch Blast, Categories, or Match competitions to energize your classroom—remote or in-person.</p>
                            <div class="mt-4 flex flex-wrap gap-3">
                                <a href="?page=live" class="px-4 py-2 bg-indigo-600 text-white rounded">Host a Live Session</a>
                                <a href="?page=match" class="px-4 py-2 bg-emerald-500 text-white rounded">Play Match</a>
                            </div>
                        </div>
                    </div>
                    <aside class="space-y-6">
                        <div class="bg-white rounded-lg shadow p-6">
                            <h3 class="text-lg font-semibold mb-2">Classes</h3>
                            <ul class="space-y-2">
                                <?php foreach ($classes as $class): ?>
                                    <li class="border rounded p-3">
                                        <h4 class="font-semibold"><?= htmlspecialchars($class['name']) ?></h4>
                                        <p class="text-sm text-slate-500">Join code: <?= htmlspecialchars($class['join_code']) ?></p>
                                    </li>
                                <?php endforeach; ?>
                                <?php if (!$classes): ?>
                                    <li class="text-sm text-slate-500">No classes yet.</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <div class="bg-white rounded-lg shadow p-6">
                            <h3 class="text-lg font-semibold mb-2">Discover public sets</h3>
                            <div class="space-y-2 max-h-64 overflow-y-auto">
                                <?php foreach ($publicSets as $set): ?>
                                    <form method="post" class="border rounded p-3">
                                        <h4 class="font-semibold"><?= htmlspecialchars($set['title']) ?></h4>
                                        <p class="text-xs text-slate-500">By <?= htmlspecialchars($set['display_name']) ?> · <?= htmlspecialchars($set['subject']) ?></p>
                                        <input type="hidden" name="action" value="copy_set">
                                        <input type="hidden" name="set_id" value="<?= $set['id'] ?>">
                                        <button class="mt-2 text-indigo-600 text-sm">Copy to my library</button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </aside>
                </section>
            <?php elseif ($page === 'sets'): ?>
                <?php $userSets = get_sets_for_user($user); ?>
                <div class="grid lg:grid-cols-3 gap-6">
                    <section class="lg:col-span-2 space-y-6">
                        <div class="bg-white rounded-lg shadow p-6">
                            <h2 class="text-xl font-semibold mb-4">Create study set</h2>
                            <form method="post" enctype="multipart/form-data" class="space-y-4">
                                <input type="hidden" name="action" value="create_set">
                                <div class="grid md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium">Title</label>
                                        <input name="title" class="w-full border rounded px-3 py-2" required>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium">Subject</label>
                                        <input name="subject" class="w-full border rounded px-3 py-2">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium">Description</label>
                                    <textarea name="description" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                                </div>
                                <div class="grid md:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium">Visibility</label>
                                        <select name="visibility" class="w-full border rounded px-3 py-2">
                                            <option value="public">Public</option>
                                            <option value="private">Private</option>
                                            <option value="class">Class only</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium">Cover image</label>
                                        <input type="file" name="set_image" accept="image/*" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium">Intro audio</label>
                                        <input type="file" name="set_audio" accept="audio/*" class="w-full border rounded px-3 py-2">
                                    </div>
                                </div>
                                <button class="bg-indigo-600 text-white px-4 py-2 rounded">Create set</button>
                            </form>
                        </div>

                        <div class="bg-white rounded-lg shadow p-6">
                            <h2 class="text-xl font-semibold mb-4">Add terms</h2>
                            <form method="post" enctype="multipart/form-data" class="grid md:grid-cols-2 gap-4">
                                <input type="hidden" name="action" value="add_term">
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium">Study set</label>
                                    <select name="set_id" class="w-full border rounded px-3 py-2">
                                        <?php foreach ($userSets as $set): ?>
                                            <option value="<?= $set['id'] ?>"><?= htmlspecialchars($set['title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium">Term / Question</label>
                                    <input name="term" class="w-full border rounded px-3 py-2" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium">Definition / Answer</label>
                                    <textarea name="definition" class="w-full border rounded px-3 py-2" rows="3" required></textarea>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium">Image</label>
                                    <input type="file" name="term_image" accept="image/*" class="w-full border rounded px-3 py-2">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium">Audio</label>
                                    <input type="file" name="term_audio" accept="audio/*" class="w-full border rounded px-3 py-2">
                                </div>
                                <div class="md:col-span-2">
                                    <button class="bg-emerald-600 text-white px-4 py-2 rounded">Add term</button>
                                </div>
                            </form>
                        </div>

                        <div class="bg-white rounded-lg shadow p-6">
                            <h2 class="text-xl font-semibold mb-4">Search library</h2>
                            <form method="get" class="flex gap-3">
                                <input type="hidden" name="page" value="sets">
                                <input name="q" placeholder="Search by title, subject, or description" class="flex-1 border rounded px-3 py-2" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                                <button class="px-4 py-2 bg-slate-800 text-white rounded">Search</button>
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
                                    <div class="border rounded p-4">
                                        <h3 class="font-semibold"><?= htmlspecialchars($set['title']) ?></h3>
                                        <p class="text-sm text-slate-500">By <?= htmlspecialchars($set['display_name']) ?> · <?= htmlspecialchars($set['subject']) ?></p>
                                        <div class="mt-2 flex gap-3 text-sm">
                                            <a class="text-indigo-600" href="?page=set_view&amp;set_id=<?= $set['id'] ?>">Open</a>
                                            <form method="post" class="inline">
                                                <input type="hidden" name="action" value="copy_set">
                                                <input type="hidden" name="set_id" value="<?= $set['id'] ?>">
                                                <button class="text-emerald-600">Copy</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($query && !$results): ?>
                                    <p class="text-sm text-slate-500">No sets matched your search.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>
                    <aside class="space-y-6">
                        <div class="bg-white rounded-lg shadow p-6">
                            <h3 class="text-lg font-semibold mb-3">Your sets</h3>
                            <ul class="space-y-2 max-h-[32rem] overflow-y-auto">
                                <?php foreach ($userSets as $set): ?>
                                    <li class="border rounded p-3">
                                        <a href="?page=set_view&amp;set_id=<?= $set['id'] ?>" class="font-semibold text-indigo-600"><?= htmlspecialchars($set['title']) ?></a>
                                        <p class="text-xs text-slate-500"><?= htmlspecialchars($set['subject']) ?> · <?= htmlspecialchars($set['visibility']) ?></p>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </aside>
                </div>
            <?php elseif ($page === 'set_view' && $setId): ?>
                <?php $set = get_set($setId); $terms = get_terms_for_set($setId); ?>
                <?php if ($set): ?>
                    <section class="bg-white rounded-lg shadow p-6 space-y-4">
                        <div class="flex flex-wrap justify-between gap-3">
                            <div>
                                <h2 class="text-2xl font-semibold"><?= htmlspecialchars($set['title']) ?></h2>
                                <p class="text-slate-500">Created by <?= htmlspecialchars($set['display_name']) ?> · <?= htmlspecialchars($set['subject']) ?></p>
                                <p class="text-sm text-slate-600 mt-2"><?= nl2br(htmlspecialchars($set['description'])) ?></p>
                            </div>
                            <div class="flex gap-2">
                                <a class="px-4 py-2 bg-indigo-600 text-white rounded" href="?page=flashcards&amp;set_id=<?= $set['id'] ?>">Flashcards</a>
                                <a class="px-4 py-2 bg-emerald-600 text-white rounded" href="?page=learn&amp;set_id=<?= $set['id'] ?>">Learn</a>
                                <a class="px-4 py-2 bg-slate-800 text-white rounded" href="?page=test&amp;set_id=<?= $set['id'] ?>">Test</a>
                            </div>
                        </div>
                        <?php if ($set['image_path']): ?>
                            <img src="<?= htmlspecialchars(str_replace(__DIR__, '', $set['image_path'])) ?>" alt="Cover" class="rounded max-w-sm">
                        <?php endif; ?>
                        <?php if ($set['audio_path']): ?>
                            <audio controls class="w-full">
                                <source src="<?= htmlspecialchars(str_replace(__DIR__, '', $set['audio_path'])) ?>">
                            </audio>
                        <?php endif; ?>
                        <div class="grid md:grid-cols-2 gap-4">
                            <?php foreach ($terms as $term): ?>
                                <div class="border rounded p-4 bg-slate-50">
                                    <h3 class="font-semibold text-lg"><?= htmlspecialchars($term['term']) ?></h3>
                                    <p class="text-slate-600 mt-2"><?= nl2br(htmlspecialchars($term['definition'])) ?></p>
                                    <?php if ($term['image_path']): ?>
                                        <img src="<?= htmlspecialchars(str_replace(__DIR__, '', $term['image_path'])) ?>" class="mt-2 rounded">
                                    <?php endif; ?>
                                    <?php if ($term['audio_path']): ?>
                                        <audio controls class="mt-2 w-full">
                                            <source src="<?= htmlspecialchars(str_replace(__DIR__, '', $term['audio_path'])) ?>">
                                        </audio>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$terms): ?>
                                <p class="text-sm text-slate-500">No terms yet.</p>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php else: ?>
                    <p class="text-sm text-slate-500">Set not found.</p>
                <?php endif; ?>
            <?php elseif (in_array($page, ['flashcards', 'learn', 'write', 'spell', 'test', 'match']) && $setId): ?>
                <?php $set = get_set($setId); $terms = get_terms_for_set($setId); ?>
                <?php if ($set && $terms): ?>
                    <section class="bg-white rounded-lg shadow p-6">
                        <div class="flex justify-between items-center mb-4">
                            <div>
                                <h2 class="text-2xl font-semibold"><?= htmlspecialchars($set['title']) ?></h2>
                                <p class="text-sm text-slate-500">Mode: <?= ucfirst($page) ?> · <?= count($terms) ?> cards</p>
                            </div>
                            <div class="flex gap-2">
                                <a href="?page=set_view&amp;set_id=<?= $set['id'] ?>" class="text-sm text-indigo-600">Back to set</a>
                            </div>
                        </div>
                        <?php if ($page === 'flashcards'): ?>
                            <div id="flashcard" class="relative bg-indigo-50 rounded-lg p-10 text-center cursor-pointer">
                                <p id="flashcard-content" class="text-2xl font-semibold"></p>
                                <p class="mt-3 text-sm text-indigo-700">Click to flip</p>
                            </div>
                            <div class="mt-4 flex justify-between">
                                <button id="prev" class="px-4 py-2 bg-slate-200 rounded">Previous</button>
                                <button id="next" class="px-4 py-2 bg-indigo-600 text-white rounded">Next</button>
                            </div>
                        <?php elseif ($page === 'learn'): ?>
                            <div id="learn-card" class="space-y-4">
                                <div class="bg-emerald-50 p-6 rounded">
                                    <h3 id="learn-prompt" class="text-xl font-semibold"></h3>
                                    <div class="space-y-3">
                                        <button data-choice="term" class="learn-choice w-full text-left border rounded px-3 py-2"></button>
                                        <button data-choice="definition" class="learn-choice w-full text-left border rounded px-3 py-2"></button>
                                        <button data-choice="mixed" class="learn-choice w-full text-left border rounded px-3 py-2"></button>
                                    </div>
                                    <p id="learn-feedback" class="text-sm"></p>
                                </div>
                                <button id="complete-learn" class="hidden px-4 py-2 bg-indigo-600 text-white rounded">Mark Learn Session Complete</button>
                            </div>
                        <?php elseif ($page === 'write'): ?>
                            <div class="space-y-4">
                                <div class="bg-slate-50 rounded p-6">
                                    <h3 id="write-prompt" class="text-xl font-semibold"></h3>
                                    <input id="write-answer" class="mt-3 w-full border rounded px-3 py-2" placeholder="Type your answer">
                                    <p id="write-feedback" class="mt-2 text-sm"></p>
                                    <button id="write-submit" class="mt-3 px-4 py-2 bg-indigo-600 text-white rounded">Check</button>
                                </div>
                                <button id="complete-write" class="hidden px-4 py-2 bg-emerald-600 text-white rounded">Save Write Progress</button>
                            </div>
                        <?php elseif ($page === 'spell'): ?>
                            <div class="space-y-4">
                                <div class="bg-sky-50 rounded p-6">
                                    <h3 class="text-xl font-semibold">Spell the word you hear</h3>
                                    <button id="spell-play" class="mt-2 px-3 py-2 bg-sky-600 text-white rounded">Play audio</button>
                                    <input id="spell-answer" class="mt-3 w-full border rounded px-3 py-2" placeholder="Type what you heard">
                                    <p id="spell-feedback" class="mt-2 text-sm"></p>
                                    <button id="spell-submit" class="mt-3 px-4 py-2 bg-indigo-600 text-white rounded">Check</button>
                                </div>
                                <button id="complete-spell" class="hidden px-4 py-2 bg-emerald-600 text-white rounded">Save Spell Progress</button>
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
                                    <div class="border rounded p-4">
                                        <h3 class="font-semibold">Question <?= $index + 1 ?></h3>
                                        <?php if ($index % 3 === 0): ?>
                                            <p class="mt-2 text-sm">Write the definition for <strong><?= htmlspecialchars($term['term']) ?></strong></p>
                                            <textarea name="q<?= $index ?>" class="w-full border rounded px-3 py-2 mt-2" rows="3"></textarea>
                                        <?php elseif ($index % 3 === 1): ?>
                                            <p class="mt-2 text-sm">Select the correct term for the definition:</p>
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
                                                <label class="block mt-2"><input type="radio" name="q<?= $index ?>" value="<?= htmlspecialchars($choice) ?>" class="mr-2"> <?= htmlspecialchars($choice) ?></label>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p class="mt-2 text-sm">True or False: "<?= htmlspecialchars($term['term']) ?>" means "<?= htmlspecialchars($term['definition']) ?>"</p>
                                            <label class="block mt-2"><input type="radio" name="q<?= $index ?>" value="true" class="mr-2"> True</label>
                                            <label class="block mt-1"><input type="radio" name="q<?= $index ?>" value="false" class="mr-2"> False</label>
                                        <?php endif; ?>
                                        <input type="hidden" name="answer<?= $index ?>" value="<?= htmlspecialchars($term['term'] . '||' . $term['definition']) ?>">
                                    </div>
                                <?php endforeach; ?>
                                <input type="hidden" name="action" value="record_session">
                                <input type="hidden" name="mode" value="test">
                                <input type="hidden" name="set_id" value="<?= $set['id'] ?>">
                                <input type="hidden" name="accuracy" id="test-accuracy" value="0">
                                <input type="hidden" name="progress" id="test-progress" value="">
                                <button class="px-4 py-2 bg-indigo-600 text-white rounded">Submit test</button>
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
                                <button id="start-match" class="px-4 py-2 bg-indigo-600 text-white rounded">Start game</button>
                                <div id="match-grid" class="grid md:grid-cols-4 gap-3"></div>
                                <p id="match-timer" class="text-lg font-semibold"></p>
                                <form method="post" class="hidden" id="match-form">
                                    <input type="hidden" name="action" value="record_match_score">
                                    <input type="hidden" name="set_id" value="<?= $set['id'] ?>">
                                    <input type="hidden" name="elapsed" id="match-elapsed">
                                </form>
                                <section class="mt-6">
                                    <h3 class="font-semibold mb-2">Leaderboard</h3>
                                    <div class="grid md:grid-cols-2 gap-3">
                                        <?php
                                            $scores = $db->prepare('SELECT match_game_scores.*, users.display_name FROM match_game_scores JOIN users ON users.id = match_game_scores.user_id WHERE set_id = ? ORDER BY elapsed_seconds ASC LIMIT 10');
                                            $scores->execute([$set['id']]);
                                            foreach ($scores as $score):
                                        ?>
                                            <div class="border rounded p-3 bg-slate-50">
                                                <p class="font-semibold"><?= htmlspecialchars($score['display_name']) ?></p>
                                                <p class="text-sm text-slate-500"><?= number_format($score['elapsed_seconds'], 1) ?> seconds</p>
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
                <section class="grid lg:grid-cols-2 gap-6">
                    <div class="bg-white rounded-lg shadow p-6 space-y-4">
                        <h2 class="text-xl font-semibold">Teacher live controls</h2>
                        <?php if (is_teacher()): ?>
                            <form method="post" class="space-y-3">
                                <input type="hidden" name="action" value="start_live">
                                <div>
                                    <label class="block text-sm font-medium">Study set</label>
                                    <select name="set_id" class="w-full border rounded px-3 py-2">
                                        <?php foreach (get_sets_for_user($user) as $set): ?>
                                            <option value="<?= $set['id'] ?>"><?= htmlspecialchars($set['title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button class="px-4 py-2 bg-indigo-600 text-white rounded">Launch Live Game</button>
                            </form>
                        <?php else: ?>
                            <p class="text-sm text-slate-500">Only teachers can host live games. Join with a code below.</p>
                        <?php endif; ?>
                        <div>
                            <h3 class="font-semibold mt-4">Active live sessions</h3>
                            <div class="space-y-2">
                                <?php
                                    $sessions = $db->query('SELECT live_sessions.*, study_sets.title, users.display_name FROM live_sessions JOIN study_sets ON study_sets.id = live_sessions.set_id JOIN users ON users.id = live_sessions.host_id WHERE status != "closed" ORDER BY created_at DESC LIMIT 10');
                                    foreach ($sessions as $session):
                                ?>
                                    <div class="border rounded p-3 bg-slate-50">
                                        <p class="font-semibold"><?= htmlspecialchars($session['title']) ?></p>
                                        <p class="text-sm text-slate-500">Host: <?= htmlspecialchars($session['display_name']) ?> · Code: <?= htmlspecialchars($session['code']) ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow p-6 space-y-4">
                        <h2 class="text-xl font-semibold">Join a live session</h2>
                        <form method="post" class="flex gap-3">
                            <input type="hidden" name="action" value="join_live">
                            <input name="code" placeholder="Enter code" class="flex-1 border rounded px-3 py-2" required>
                            <button class="px-4 py-2 bg-emerald-600 text-white rounded">Join</button>
                        </form>
                        <div>
                            <h3 class="font-semibold">Your live scores</h3>
                            <div class="space-y-2">
                                <?php
                                    if ($user) {
                                        $liveScores = $db->prepare('SELECT live_participants.score, live_sessions.code, study_sets.title FROM live_participants JOIN live_sessions ON live_sessions.id = live_participants.live_session_id JOIN study_sets ON study_sets.id = live_sessions.set_id WHERE live_participants.user_id = ? ORDER BY live_participants.id DESC LIMIT 10');
                                        $liveScores->execute([$user['id']]);
                                        foreach ($liveScores as $score):
                                ?>
                                    <div class="border rounded p-3">
                                        <p class="font-semibold"><?= htmlspecialchars($score['title']) ?></p>
                                        <p class="text-sm text-slate-500">Score: <?= (int) $score['score'] ?> · Code: <?= htmlspecialchars($score['code']) ?></p>
                                    </div>
                                <?php endforeach; } ?>
                            </div>
                        </div>
                        <form method="post" class="space-y-3">
                            <input type="hidden" name="action" value="update_live_score">
                            <div>
                                <label class="block text-sm font-medium">Session ID</label>
                                <input name="session_id" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium">Score</label>
                                <input name="score" class="w-full border rounded px-3 py-2">
                            </div>
                            <button class="px-4 py-2 bg-slate-800 text-white rounded">Update score</button>
                        </form>
                    </div>
                </section>
            <?php elseif ($page === 'classes'): ?>
                <section class="grid lg:grid-cols-2 gap-6">
                    <div class="bg-white rounded-lg shadow p-6 space-y-4">
                        <h2 class="text-xl font-semibold">Classes</h2>
                        <?php if (is_teacher()): ?>
                            <form method="post" class="space-y-3">
                                <input type="hidden" name="action" value="create_class">
                                <div>
                                    <label class="block text-sm font-medium">Class name</label>
                                    <input name="name" class="w-full border rounded px-3 py-2" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium">Description</label>
                                    <textarea name="description" class="w-full border rounded px-3 py-2"></textarea>
                                </div>
                                <button class="px-4 py-2 bg-indigo-600 text-white rounded">Create class</button>
                            </form>
                        <?php endif; ?>
                        <div>
                            <h3 class="font-semibold mt-4">Join a class</h3>
                            <form method="post" class="flex gap-3">
                                <input type="hidden" name="action" value="join_class">
                                <input name="code" class="flex-1 border rounded px-3 py-2" placeholder="Join code" required>
                                <button class="px-4 py-2 bg-emerald-600 text-white rounded">Join</button>
                            </form>
                        </div>
                        <div>
                            <h3 class="font-semibold mt-4">Your classes</h3>
                            <div class="space-y-3">
                                <?php foreach (get_classes_for_user($user) as $class): ?>
                                    <details class="border rounded p-4 bg-slate-50">
                                        <summary class="font-semibold"><?= htmlspecialchars($class['name']) ?> — Code: <?= htmlspecialchars($class['join_code']) ?></summary>
                                        <p class="mt-2 text-sm text-slate-500"><?= htmlspecialchars($class['description']) ?></p>
                                        <h4 class="mt-4 font-semibold">Members</h4>
                                        <ul class="text-sm text-slate-600 space-y-1">
                                            <?php foreach (get_class_members($class['id']) as $member): ?>
                                                <li><?= htmlspecialchars($member['display_name']) ?> (<?= htmlspecialchars($member['class_role']) ?>)</li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <h4 class="mt-4 font-semibold">Assigned sets</h4>
                                        <ul class="text-sm text-slate-600 space-y-1">
                                            <?php foreach (get_class_assignments($class['id']) as $assigned): ?>
                                                <li><a class="text-indigo-600" href="?page=set_view&amp;set_id=<?= $assigned['id'] ?>"><?= htmlspecialchars($assigned['title']) ?></a></li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <?php if (is_teacher()): ?>
                                            <form method="post" class="mt-4 flex gap-3">
                                                <input type="hidden" name="action" value="assign_set">
                                                <input type="hidden" name="class_id" value="<?= $class['id'] ?>">
                                                <select name="set_id" class="flex-1 border rounded px-3 py-2">
                                                    <?php foreach (get_sets_for_user($user) as $set): ?>
                                                        <option value="<?= $set['id'] ?>"><?= htmlspecialchars($set['title']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="px-4 py-2 bg-slate-800 text-white rounded">Assign set</button>
                                            </form>
                                        <?php endif; ?>
                                    </details>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow p-6 space-y-4">
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
                <section class="bg-white rounded-lg shadow p-6 space-y-6">
                    <div>
                        <h2 class="text-2xl font-semibold">Learning analytics</h2>
                        <p class="text-sm text-slate-600">Adaptive difficulty, study modes, and classroom assignments all roll up here. Monitor mastery and celebrate wins.</p>
                    </div>
                    <div class="grid md:grid-cols-2 gap-4">
                        <?php
                            $sessions = $db->prepare('SELECT study_sets.title, study_sessions.mode, study_sessions.accuracy, study_sessions.completed_at FROM study_sessions JOIN study_sets ON study_sets.id = study_sessions.set_id WHERE study_sessions.user_id = ? ORDER BY study_sessions.completed_at DESC LIMIT 12');
                            $sessions->execute([$user['id']]);
                            foreach ($sessions as $session):
                        ?>
                            <div class="border rounded p-4 bg-slate-50">
                                <h3 class="font-semibold"><?= htmlspecialchars($session['title']) ?></h3>
                                <p class="text-sm text-slate-500">Mode: <?= htmlspecialchars(ucfirst($session['mode'])) ?> · Accuracy: <?= $session['accuracy'] ? number_format($session['accuracy'], 1) : 'N/A' ?>%</p>
                                <p class="text-xs text-slate-400">Completed: <?= htmlspecialchars($session['completed_at']) ?></p>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$sessions->rowCount()): ?>
                            <p class="text-sm text-slate-500">No study sessions recorded yet.</p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3 class="text-xl font-semibold mb-2">Mastery spotlight</h3>
                        <p class="text-sm text-slate-600">Learn mode tracks attempts and successes for each term. Review the JSON snapshot below for coaching conversations.</p>
                        <pre class="bg-slate-900 text-lime-200 p-4 rounded overflow-x-auto text-xs"><?php
                            $snapshot = $db->prepare('SELECT study_sets.title, study_sessions.mode, study_sessions.progress_json FROM study_sessions JOIN study_sets ON study_sets.id = study_sessions.set_id WHERE study_sessions.user_id = ? ORDER BY study_sessions.completed_at DESC LIMIT 1');
                            $snapshot->execute([$user['id']]);
                            $data = $snapshot->fetch(PDO::FETCH_ASSOC);
                            echo htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT));
                        ?></pre>
                    </div>
                </section>
            <?php elseif ($page === 'import'): ?>
                <section class="bg-white rounded-lg shadow p-6 space-y-4">
                    <h2 class="text-2xl font-semibold">Bulk import tools</h2>
                    <p class="text-sm text-slate-600">Paste vocabulary lists, upload CSV files, and generate terms in seconds. Great for teachers prepping large classes.</p>
                    <form method="post" enctype="multipart/form-data" class="space-y-3">
                        <input type="hidden" name="action" value="import_terms">
                        <div>
                            <label class="block text-sm font-medium">Study set</label>
                            <select name="set_id" class="w-full border rounded px-3 py-2">
                                <?php foreach (get_sets_for_user($user) as $set): ?>
                                    <option value="<?= $set['id'] ?>"><?= htmlspecialchars($set['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Paste list (term, definition)</label>
                            <textarea name="import_text" rows="6" class="w-full border rounded px-3 py-2" placeholder="water, H2O"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Upload CSV / TSV</label>
                            <input type="file" name="import_file" accept=".csv,.tsv,text/csv,text/tab-separated-values" class="w-full border rounded px-3 py-2">
                        </div>
                        <button class="px-4 py-2 bg-indigo-600 text-white rounded">Import</button>
                    </form>
                    <div>
                        <h3 class="text-xl font-semibold mt-4">Import history</h3>
                        <ul class="space-y-2 text-sm text-slate-600">
                            <?php
                                $imports = $db->prepare('SELECT * FROM imports WHERE user_id = ? ORDER BY imported_at DESC LIMIT 10');
                                $imports->execute([$user['id']]);
                                foreach ($imports as $row):
                            ?>
                                <li class="border rounded p-3 bg-slate-50">Uploaded <?= htmlspecialchars($row['original_filename']) ?> — <?= htmlspecialchars($row['imported_at']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
