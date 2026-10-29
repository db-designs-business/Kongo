<?php
// Kongrow – eigenes Backend (SQLite). Wird von data.php / auth.php eingebunden.
declare(strict_types=1);

// Einmal-Code für das Anlegen des (einzigen) Kontos. Gespeichert ist nur der Hash.
const KG_SETUP_HASH = '$2y$10$vMBWpW1RwkktK.CS/fktA.WdR6rswihRc1usXTXtZ5MdJF8zTXW1a';
const KG_SESSION_DAYS = 180;
const KG_COOKIE = 'kg_sess';

function kg_data_dir(): string {
  // Bevorzugt außerhalb des Web-Verzeichnisses (httpdocs), sonst api/data (per .htaccess gesperrt)
  $outside = dirname(__DIR__, 2) . '/kongrow-private';
  if (is_dir($outside) || @mkdir($outside, 0700, true)) {
    if (is_writable($outside)) return $outside;
  }
  $inside = __DIR__ . '/data';
  if (!is_dir($inside)) @mkdir($inside, 0700, true);
  return $inside;
}

function kg_db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $pdo = new PDO('sqlite:' . kg_data_dir() . '/kongrow.sqlite');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
  $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, pass TEXT NOT NULL, created TEXT NOT NULL)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (token TEXT PRIMARY KEY, user_id INTEGER NOT NULL, expires INTEGER NOT NULL)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS login_fails (ip TEXT NOT NULL, ts INTEGER NOT NULL)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS records (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, base TEXT NOT NULL, tbl TEXT NOT NULL, fields TEXT NOT NULL, created TEXT NOT NULL)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS rec_tbl ON records(user_id, base, tbl)');
  return $pdo;
}

function kg_json($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function kg_input(): array {
  $in = json_decode(file_get_contents('php://input') ?: '{}', true);
  return is_array($in) ? $in : [];
}

function kg_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '?'; }

function kg_user_count(): int { return (int)kg_db()->query('SELECT COUNT(*) FROM users')->fetchColumn(); }

function kg_set_cookie(string $value, int $expires): void {
  setcookie(KG_COOKIE, $value, ['expires' => $expires, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
}

function kg_start_session(int $userId): void {
  $token = bin2hex(random_bytes(32));
  $exp = time() + KG_SESSION_DAYS * 86400;
  kg_db()->prepare('INSERT INTO sessions(token,user_id,expires) VALUES(?,?,?)')->execute([hash('sha256', $token), $userId, $exp]);
  kg_db()->prepare('DELETE FROM sessions WHERE expires < ?')->execute([time()]);
  kg_set_cookie($token, $exp);
}

// Liefert die User-ID der aktuellen Sitzung oder null
function kg_current_user(): ?int {
  $t = $_COOKIE[KG_COOKIE] ?? '';
  if (!is_string($t) || !preg_match('/^[a-f0-9]{64}$/', $t)) return null;
  $st = kg_db()->prepare('SELECT user_id FROM sessions WHERE token=? AND expires>?');
  $st->execute([hash('sha256', $t), time()]);
  $id = $st->fetchColumn();
  return $id === false ? null : (int)$id;
}

function kg_require_user(): int {
  $u = kg_current_user();
  if ($u === null) kg_json(['error' => ['type' => 'AUTH', 'message' => 'Nicht angemeldet']], 401);
  return $u;
}

// Zu viele Fehlversuche? (8 pro 15 Minuten und IP)
function kg_too_many_fails(): bool {
  $db = kg_db();
  $db->prepare('DELETE FROM login_fails WHERE ts < ?')->execute([time() - 900]);
  $st = $db->prepare('SELECT COUNT(*) FROM login_fails WHERE ip=?');
  $st->execute([kg_ip()]);
  return (int)$st->fetchColumn() >= 8;
}
function kg_note_fail(): void {
  kg_db()->prepare('INSERT INTO login_fails(ip,ts) VALUES(?,?)')->execute([kg_ip(), time()]);
}
