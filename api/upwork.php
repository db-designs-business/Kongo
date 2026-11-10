<?php
// Upwork-Jobs aus den Alert-Mails: Liste, Status, Filter, manueller Abruf
require __DIR__ . '/upwork_lib.php';
$uid = kg_require_user();
$action = $_GET['action'] ?? 'list';
$in = kg_input();
$db = uw_db();

function uw_out(array $r): array {
  return ['id' => (int)$r['id'], 'title' => $r['title'], 'url' => $r['url'] ?: null, 'kind' => $r['kind'], 'budget' => $r['budget'],
    'snippet' => $r['snippet'], 'skills' => $r['skills'], 'verified' => $r['verified'] === null ? null : (bool)$r['verified'],
    'spent' => $r['spent'], 'country' => $r['country'], 'rating' => $r['rating'] ?? '', 'received' => $r['received'], 'status' => $r['status'], 'reason' => $r['reason']];
}

switch ($action) {
  case 'list':
    $st = $db->prepare('SELECT * FROM upwork_jobs WHERE user_id=? ORDER BY received DESC LIMIT 300'); $st->execute([$uid]);
    $tok = kg_get($uid, 'google_tok');
    kg_json(['jobs' => array_map('uw_out', $st->fetchAll(PDO::FETCH_ASSOC)), 'settings' => uw_settings($uid), 'last' => kg_get($uid, 'upwork_last'),
      'google' => ['connected' => !empty($tok['refresh']), 'email' => $tok['email'] ?? null]]);

  case 'status':
    $id = (int)($in['id'] ?? 0); $s = (string)($in['status'] ?? '');
    if (!in_array($s, ['neu', 'interessant', 'beworben', 'uebersprungen'], true)) kg_json(['error' => ['message' => 'Unbekannter Status']], 400);
    $db->prepare('UPDATE upwork_jobs SET status=?, updated=? WHERE id=? AND user_id=?')->execute([$s, gmdate('c'), $id, $uid]);
    kg_json(['ok' => true]);

  case 'settings':
    $words = $in['exclude'] ?? [];
    if (is_string($words)) $words = preg_split('/[\n,;]+/', $words);
    $words = array_slice(array_values(array_unique(array_filter(array_map(fn($w) => mb_substr(trim((string)$w), 0, 60), (array)$words), 'strlen'))), 0, 200);
    kg_set($uid, 'upwork_filter', ['exclude' => $words, 'only_verified' => !empty($in['only_verified']), 'min_rate' => max(0, (float)($in['min_rate'] ?? 0)), 'archive' => !empty($in['archive'])]);
    uw_refilter($uid);
    kg_json(['ok' => true, 'settings' => uw_settings($uid)]);

  case 'check':
    $r = uw_fetch_new($uid);
    if ($r['error']) kg_json(['error' => ['message' => $r['error']]], 409);
    kg_json(['ok' => true, 'new' => count($r['new']), 'filtered' => $r['filtered']]);
}
kg_json(['error' => ['message' => 'Unbekannte Aktion']], 400);
