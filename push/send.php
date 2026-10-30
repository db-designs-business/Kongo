<?php
// Versand der Erinnerungen. Aufruf per Plesk-Cronjob:
//   php push/send.php morning    (5:30)
//   php push/send.php evening    (20:30)
//   php push/send.php test       (Testnachricht)
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
  'morning' => ['title' => 'Guten Morgen 🌅', 'body' => 'Zeit für deine Morgenreflexion – starte bewusst in den Tag.', 'url' => './?open=morning'],
  'evening' => ['title' => 'Tagesabschluss 🌙', 'body' => 'Hak deine Dailys ab und mach deine Tagesreflexion.', 'url' => './?open=evening'],
  'test'    => ['title' => 'Kongrow', 'body' => 'Push funktioniert ✓', 'url' => './'],
];
if (!isset($messages[$type])) { echo "Unbekannter Typ. Erlaubt: morning, evening, test\n"; exit(1); }
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
