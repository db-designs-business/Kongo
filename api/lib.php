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
  kg_backup_lazy();
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

/* ---------- Einstellungen & verschlüsselte Zugangsdaten ---------- */
function kg_settings_init(): void {
  static $done = false; if ($done) return; $done = true;
  kg_db()->exec('CREATE TABLE IF NOT EXISTS settings (user_id INTEGER NOT NULL, k TEXT NOT NULL, v TEXT NOT NULL, PRIMARY KEY(user_id, k))');
}
function kg_secret_key(): string {
  $f = kg_data_dir() . '/secret.key';
  if (!file_exists($f)) { file_put_contents($f, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)); @chmod($f, 0600); }
  return file_get_contents($f);
}
function kg_secret_encrypt(string $plain): string {
  $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
  return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, kg_secret_key()));
}
function kg_secret_decrypt(string $enc): ?string {
  $raw = base64_decode($enc, true); if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
  $out = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), kg_secret_key());
  return $out === false ? null : $out;
}
// Werte werden immer verschlüsselt abgelegt (JSON)
function kg_get(int $uid, string $k, $default = null) {
  kg_settings_init();
  $st = kg_db()->prepare('SELECT v FROM settings WHERE user_id=? AND k=?'); $st->execute([$uid, $k]);
  $v = $st->fetchColumn(); if ($v === false) return $default;
  $d = kg_secret_decrypt($v); if ($d === null) return $default;
  $j = json_decode($d, true); return $j === null ? $default : $j;
}
function kg_set(int $uid, string $k, $value): void {
  kg_settings_init();
  if ($value === null) { kg_db()->prepare('DELETE FROM settings WHERE user_id=? AND k=?')->execute([$uid, $k]); return; }
  kg_db()->prepare('INSERT INTO settings(user_id,k,v) VALUES(?,?,?) ON CONFLICT(user_id,k) DO UPDATE SET v=excluded.v')
    ->execute([$uid, $k, kg_secret_encrypt(json_encode($value, JSON_UNESCAPED_UNICODE))]);
}

/* ---------- HTTP ---------- */
function kg_http(string $method, string $url, array $headers = [], $body = null, int $timeout = 25): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => $headers]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
  $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  $json = is_string($res) ? json_decode($res, true) : null;
  return ['code' => $code, 'body' => $res, 'json' => $json, 'error' => $err];
}
// Mehrere GET-Anfragen parallel
function kg_http_multi(array $urls, array $headers = [], int $timeout = 25): array {
  $mh = curl_multi_init(); $hs = [];
  foreach ($urls as $k => $u) {
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => $headers]);
    curl_multi_add_handle($mh, $ch); $hs[$k] = $ch;
  }
  do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st == CURLM_OK);
  $out = [];
  foreach ($hs as $k => $ch) { $out[$k] = json_decode((string)curl_multi_getcontent($ch), true); curl_multi_remove_handle($mh, $ch); curl_close($ch); }
  curl_multi_close($mh);
  return $out;
}
function kg_base_url(): string { return 'https://' . ($_SERVER['HTTP_HOST'] ?? 'kongrow.bdriven-digital.com'); }

function kg_html_to_text(string $html): string {
  $html = preg_replace('#<(script|style|head)[^>]*>.*?</\1>#is', '', $html);
  $html = preg_replace('#<br\s*/?>|</p>|</div>|</tr>|</h[1-6]>|</li>#i', "\n", $html);
  $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $t = preg_replace("/[ \t]+/", ' ', $t);
  return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $t));
}

// Rücksprung nach OAuth per Seite (nicht per HTTP-Redirect), damit das SameSite=Strict-Cookie wieder mitgeschickt wird
function kg_back_to_app(string $query): void {
  header('Content-Type: text/html; charset=utf-8');
  $u = '/?' . $query;
  echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><body style="background:#000;color:#fff;font-family:sans-serif;padding:40px;text-align:center">Einen Moment …<script>location.replace(' . json_encode($u) . ');</script>';
  exit;
}

/* ---------- Automatisches Backup (1x täglich, 30 Tage aufbewahren) ---------- */
function kg_backup(bool $force = false): ?string {
  $dir = kg_data_dir() . '/backups';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  $file = $dir . '/kongrow-' . date('Y-m-d') . '.sqlite';
  if (!$force && file_exists($file)) return null;
  try {
    @unlink($file);
    kg_db()->exec('VACUUM INTO ' . kg_db()->quote($file));
    @chmod($file, 0600);
  } catch (Throwable $e) {
    // Fallback: einfache Dateikopie
    @copy(kg_data_dir() . '/kongrow.sqlite', $file);
  }
  $all = glob($dir . '/kongrow-*.sqlite') ?: [];
  sort($all);
  while (count($all) > 30) @unlink(array_shift($all));
  return $file;
}
// Beim ersten angemeldeten Zugriff des Tages sichern (falls der Cronjob mal ausfällt)
function kg_backup_lazy(): void {
  static $done = false; if ($done) return; $done = true;
  $marker = kg_data_dir() . '/backups/kongrow-' . date('Y-m-d') . '.sqlite';
  if (!file_exists($marker)) { try { kg_backup(); } catch (Throwable $e) {} }
}
