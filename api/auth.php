<?php
// Anmeldung: status | setup | login | logout | password
require __DIR__ . '/lib.php';
$action = $_GET['action'] ?? 'status';
$in = kg_input();

if ($action === 'status') {
  $uid = kg_current_user();
  $name = null;
  if ($uid !== null) {
    $st = kg_db()->prepare('SELECT username FROM users WHERE id=?'); $st->execute([$uid]); $name = $st->fetchColumn() ?: null;
  }
  kg_json(['loggedIn' => $uid !== null, 'username' => $name, 'setupNeeded' => kg_user_count() === 0]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') kg_json(['error' => ['message' => 'POST erforderlich']], 405);

if ($action === 'setup') {
  if (kg_user_count() > 0) kg_json(['error' => ['message' => 'Es gibt bereits ein Konto.']], 403);
  if (kg_too_many_fails()) kg_json(['error' => ['message' => 'Zu viele Versuche. Bitte 15 Minuten warten.']], 429);
  $code = strtoupper(trim((string)($in['code'] ?? '')));
  $user = trim((string)($in['username'] ?? ''));
  $pass = (string)($in['password'] ?? '');
  if (!password_verify($code, KG_SETUP_HASH)) { kg_note_fail(); kg_json(['error' => ['message' => 'Einrichtungscode falsch.']], 403); }
  if (!preg_match('/^[\p{L}0-9_.-]{3,32}$/u', $user)) kg_json(['error' => ['message' => 'Benutzername: 3–32 Zeichen (Buchstaben, Zahlen, _ . -).']], 400);
  if (mb_strlen($pass) < 10) kg_json(['error' => ['message' => 'Passwort: mindestens 10 Zeichen.']], 400);
  $db = kg_db();
  $db->prepare('INSERT INTO users(username,pass,created) VALUES(?,?,?)')->execute([$user, password_hash($pass, PASSWORD_DEFAULT), date('c')]);
  kg_start_session((int)$db->lastInsertId());
  kg_json(['ok' => true, 'username' => $user]);
}

if ($action === 'login') {
  if (kg_too_many_fails()) kg_json(['error' => ['message' => 'Zu viele Fehlversuche. Bitte 15 Minuten warten.']], 429);
  $user = trim((string)($in['username'] ?? ''));
  $pass = (string)($in['password'] ?? '');
  $st = kg_db()->prepare('SELECT id, pass FROM users WHERE username = ? COLLATE NOCASE');
  $st->execute([$user]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row || !password_verify($pass, $row['pass'])) {
    kg_note_fail();
    usleep(400000);
    kg_json(['error' => ['message' => 'Benutzername oder Passwort falsch.']], 401);
  }
  kg_start_session((int)$row['id']);
  kg_json(['ok' => true]);
}

if ($action === 'logout') {
  $t = $_COOKIE[KG_COOKIE] ?? '';
  if (is_string($t) && $t !== '') kg_db()->prepare('DELETE FROM sessions WHERE token=?')->execute([hash('sha256', $t)]);
  kg_set_cookie('', time() - 3600);
  kg_json(['ok' => true]);
}

if ($action === 'password') {
  $uid = kg_require_user();
  $st = kg_db()->prepare('SELECT pass FROM users WHERE id=?'); $st->execute([$uid]);
  if (!password_verify((string)($in['old'] ?? ''), (string)$st->fetchColumn())) { kg_note_fail(); kg_json(['error' => ['message' => 'Altes Passwort falsch.']], 403); }
  $new = (string)($in['new'] ?? '');
  if (mb_strlen($new) < 10) kg_json(['error' => ['message' => 'Passwort: mindestens 10 Zeichen.']], 400);
  kg_db()->prepare('UPDATE users SET pass=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
  // alle anderen Sitzungen beenden
  $t = $_COOKIE[KG_COOKIE] ?? '';
  kg_db()->prepare('DELETE FROM sessions WHERE user_id=? AND token<>?')->execute([$uid, hash('sha256', (string)$t)]);
  kg_json(['ok' => true]);
}

kg_json(['error' => ['message' => 'Unbekannte Aktion']], 400);
