<?php
// Mitteilungs-Einstellungen: welche Quellen eine Push-Nachricht auslösen
require __DIR__ . '/notify_lib.php';
$uid = kg_require_user();
$action = $_GET['action'] ?? 'get';
$in = kg_input();
if ($action === 'set') {
  $key = (string)($in['key'] ?? '');
  if (!preg_match('/^(morning|evening|upwork|gmail|mail:m[0-9a-f]+|slack:[A-Z0-9]+)$/', $key)) kg_json(['error' => ['message' => 'Unbekannte Quelle']], 400);
  $p = n_prefs($uid); $p[$key] = !empty($in['on']); kg_set($uid, 'notify_prefs', $p);
  kg_json(['ok' => true]);
}
kg_json(['sources' => n_sources($uid)]);
