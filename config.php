<?php
session_start();

const DB_FILE = __DIR__ . '/database.sqlite';
const IMAGE_UPLOAD_DIR = __DIR__ . '/uploads/images';
const AUDIO_UPLOAD_DIR = __DIR__ . '/uploads/audio';

if (!file_exists(__DIR__ . '/uploads')) {
    mkdir(__DIR__ . '/uploads');
}
if (!file_exists(IMAGE_UPLOAD_DIR)) {
    mkdir(IMAGE_UPLOAD_DIR, 0777, true);
}
if (!file_exists(AUDIO_UPLOAD_DIR)) {
    mkdir(AUDIO_UPLOAD_DIR, 0777, true);
}

function get_db(): PDO
{
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . DB_FILE);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        initialize_schema($db);
    }
    return $db;
}

function initialize_schema(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT "student",
        display_name TEXT NOT NULL DEFAULT ""
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS study_sets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        description TEXT,
        subject TEXT,
        visibility TEXT NOT NULL DEFAULT "public",
        creator_id INTEGER NOT NULL,
        image_path TEXT,
        audio_path TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (creator_id) REFERENCES users(id)
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS study_terms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        set_id INTEGER NOT NULL,
        term TEXT NOT NULL,
        definition TEXT NOT NULL,
        image_path TEXT,
        audio_path TEXT,
        mastery_level INTEGER NOT NULL DEFAULT 0,
        attempts INTEGER NOT NULL DEFAULT 0,
        success_count INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (set_id) REFERENCES study_sets(id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS classes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT,
        teacher_id INTEGER NOT NULL,
        join_code TEXT UNIQUE,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (teacher_id) REFERENCES users(id)
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS class_members (
        class_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        role TEXT NOT NULL DEFAULT "student",
        PRIMARY KEY (class_id, user_id),
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS class_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        class_id INTEGER NOT NULL,
        set_id INTEGER NOT NULL,
        assigned_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
        FOREIGN KEY (set_id) REFERENCES study_sets(id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS study_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        set_id INTEGER NOT NULL,
        mode TEXT NOT NULL,
        started_at TEXT DEFAULT CURRENT_TIMESTAMP,
        completed_at TEXT,
        accuracy REAL,
        progress_json TEXT,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (set_id) REFERENCES study_sets(id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS live_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        set_id INTEGER NOT NULL,
        host_id INTEGER NOT NULL,
        code TEXT UNIQUE NOT NULL,
        status TEXT NOT NULL DEFAULT "waiting",
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (set_id) REFERENCES study_sets(id) ON DELETE CASCADE,
        FOREIGN KEY (host_id) REFERENCES users(id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS live_participants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        live_session_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        score INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (live_session_id) REFERENCES live_sessions(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS match_game_scores (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        set_id INTEGER NOT NULL,
        elapsed_seconds REAL NOT NULL,
        played_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (set_id) REFERENCES study_sets(id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS imports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        original_filename TEXT,
        imported_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )');

    ensure_default_users($db);
}

function ensure_default_users(PDO $db): void
{
    $stmt = $db->query('SELECT COUNT(*) FROM users');
    $count = (int) $stmt->fetchColumn();
    if ($count === 0) {
        $password = password_hash('teacher', PASSWORD_DEFAULT);
        $db->prepare('INSERT INTO users (username, password_hash, role, display_name) VALUES (?, ?, ?, ?)')
           ->execute(['teacher', $password, 'teacher', 'Lead Educator']);
        $studentPassword = password_hash('student', PASSWORD_DEFAULT);
        $db->prepare('INSERT INTO users (username, password_hash, role, display_name) VALUES (?, ?, ?, ?)')
           ->execute(['student', $studentPassword, 'student', 'Sample Student']);
    }
}
