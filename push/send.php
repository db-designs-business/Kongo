<?php
// Versand der Erinnerungen. Aufruf per Plesk-Cronjob:
//   php push/send.php morning    (5:30)
//   php push/send.php evening    (20:30)
//   php push/send.php test       (Testnachricht)
//   php push/send.php slack      (alle 5 Min: neue Slack-Nachrichten)
require __DIR__ . '/lib.php';
require_once __DIR__ . '/../api/lib.php';
// Tägliche Datensicherung läuft mit dem Erinnerungs-Cronjob mit
try { $bk = kg_backup(); if ($bk) echo 'Backup: ' . basename($bk) . "\n"; } catch (Throwable $e) { echo 'Backup-Fehler: ' . $e->getMessage() . "\n"; }
$type = $argv[1] ?? ($_GET['type'] ?? '');
if (PHP_SAPI !== 'cli') {               // Aufruf über den Browser nur mit Geheimwort (für Tests)
  kg_ensure_data_dir();
  $secretFile = KG_DATA_DIR . '/secret.txt';
  if (!file_exists($secretFile)) file_put_contents($secretFile, bin2hex(random_bytes(12)));
  if (($_GET['key'] ?? '') !== trim(file_get_contents($secretFile))) { http_response_code(403); exit('forbidden'); }
  header('Content-Type: text/plain; charset=utf-8');
}
$messages = [
  'morning' => ['title' => 'Guten Morgen 🌅', 'body' => 'Zeit für deine Morgenreflexion. Starte bewusst in den Tag.', 'url' => './?open=morning'],
  'evening' => ['title' => 'Abendreflexion 🌙', 'body' => 'Hak deine Dailys ab und mach deine Abendreflexion.', 'url' => './?open=evening'],
  'test'    => ['title' => 'Kongrow', 'body' => 'Push funktioniert ✓', 'url' => './'],
];
// Neue Slack-Nachrichten und E-Mails melden (Cronjob alle 5 Minuten: php push/send.php slack)
$prefsAll = [];
if (in_array($type, ['slack', 'morning', 'evening'], true)) {
  require_once __DIR__ . '/../api/notify_lib.php';
  kg_settings_init();
  foreach (kg_db()->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN) as $u) $prefsAll[(int)$u] = n_prefs((int)$u);
}
if (($type === 'morning' || $type === 'evening') && $prefsAll && !array_filter($prefsAll, fn($p) => n_on($p, $type))) { echo date('c') . " $type: in den Einstellungen ausgeschaltet\n"; exit(0); }
if ($type === 'slack') {
  require_once __DIR__ . '/../api/slack_lib.php';
  $new = [];
  foreach ($prefsAll as $u => $p) {
    try { foreach (s_new_messages($u) as $n) if (n_on($p, 'slack:' . $n['team'])) $new[] = $n + ['kind' => 'slack']; } catch (Throwable $e) { echo 'Slack-Fehler: ' . $e->getMessage() . "\n"; }
    try { $new = array_merge($new, n_mail_new($u)); } catch (Throwable $e) { echo 'Mail-Fehler: ' . $e->getMessage() . "\n"; }
  }
  if (!$new) { echo date('c') . " nachrichten: nichts Neues\n"; exit(0); }
  $line = fn($n) => $n['kind'] === 'slack' ? $n['from'] . ': ' . $n['text'] : '✉️ ' . $n['from'] . ': ' . $n['subject'];
  if (count($new) === 1) {
    $n = $new[0];
    if ($n['kind'] === 'slack') $messages['slack'] = ['title' => $n['from'] . ' · ' . $n['conv'], 'body' => $n['text'] ?: 'Neue Nachricht', 'url' => './?open=slack&team=' . rawurlencode($n['team']) . '&ch=' . rawurlencode($n['channel']), 'tag' => 'slack-' . $n['channel']];
    else $messages['slack'] = ['title' => '✉️ ' . $n['from'] . ' · ' . $n['account'], 'body' => $n['subject'], 'url' => './?open=mail&src=' . rawurlencode($n['src']) . '&acc=' . rawurlencode($n['accountId']) . '&id=' . rawurlencode($n['id']), 'tag' => 'mail-' . $n['id']];
  } else {
    $ns = count(array_filter($new, fn($n) => $n['kind'] === 'slack')); $nm = count($new) - $ns;
    $title = trim(($ns ? $ns . ' Slack' : '') . ($ns && $nm ? ' · ' : '') . ($nm ? $nm . ' E-Mail' . ($nm > 1 ? 's' : '') : '')) . ' neu';
    $messages['slack'] = ['title' => $title, 'body' => implode("\n", array_map($line, array_slice($new, -4))), 'url' => './?open=nachrichten', 'tag' => 'nachrichten'];
  }
}
if (!isset($messages[$type])) { echo "Unbekannter Typ. Erlaubt: morning, evening, test, slack\n"; exit(1); }
$vapid = kg_vapid();
$subs = kg_subs_load();
$keep = [];
foreach ($subs as $s) {
  try { $code = kg_send($s, $messages[$type], $vapid); } catch (Throwable $e) { $code = 0; }
  echo date('c') . " $type -> " . parse_url($s['endpoint'], PHP_URL_HOST) . " : $code\n";
  if ($code !== 404 && $code !== 410) $keep[] = $s;   // abgelaufene Abos entfernen
}
kg_subs_save($keep);
echo count($keep) . " Gerät(e) aktiv\n";
