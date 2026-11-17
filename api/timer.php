<?php
declare(strict_types=1);
// Pomodoro-Timer: Push-Nachricht genau zum Phasenende, auch wenn die App zu ist.
// Die Anfrage antwortet sofort, wartet danach im Hintergrund bis zum Ende und schickt dann die Push.
// Fallback: push/send.php (Cron alle 5 Min.) verschickt verpasste Timer.
require __DIR__ . '/lib.php';
$uid = kg_require_user();
$action = $_GET['action'] ?? '';
$in = kg_input();

function timer_fire(int $uid): bool {
  $t = kg_get($uid, 'pomo_timer');
  if (!is_array($t) || !empty($t['sent']) || (int)($t['at'] ?? 0) > time()) return false;
  $t['sent'] = time(); kg_set($uid, 'pomo_timer', $t);
  require_once __DIR__ . '/../push/lib.php';
  ob_start(); try { kg_push_all([['title' => $t['title'], 'body' => $t['body'], 'url' => './?open=pomo', 'tag' => 'pomo']]); } catch (Throwable $e) {} ob_end_clean();
  return true;
}

if ($action === 'set') {
  $at = (int)floor(((float)($in['at'] ?? 0)) / 1000);
  if ($at <= time() || $at > time() + 2 * 3600) kg_json(['error' => ['message' => 'Ungültige Zeit']], 400);
  $id = bin2hex(random_bytes(6));
  kg_set($uid, 'pomo_timer', ['id' => $id, 'at' => $at, 'title' => mb_substr((string)($in['title'] ?? 'Kongrow'), 0, 80), 'body' => mb_substr((string)($in['body'] ?? ''), 0, 160), 'sent' => 0]);
  // Antwort sofort schicken, dann im Hintergrund bis zum Ende warten
  ignore_user_abort(true); @set_time_limit(0);
  $out = json_encode(['ok' => true, 'id' => $id]);
  header('Content-Type: application/json; charset=utf-8'); header('Content-Length: ' . strlen($out)); header('Connection: close');
  echo $out;
  if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { @ob_end_flush(); flush(); }
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  while (time() < $at) {
    sleep(min(20, max(1, $at - time())));
    $t = kg_get($uid, 'pomo_timer');
    if (!is_array($t) || ($t['id'] ?? '') !== $id) exit;   // abgebrochen oder ersetzt
  }
  timer_fire($uid);
  exit;
}
if ($action === 'cancel') { kg_set($uid, 'pomo_timer', null); kg_json(['ok' => true]); }
kg_json(['error' => ['message' => 'Unbekannte Aktion']], 400);
