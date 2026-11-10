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
    'spent' => $r['spent'], 'country' => $r['country'], 'rating' => $r['rating'] ?? '', 'received' => $r['received'], 'status' => $r['status'], 'reason' => $r['reason'], 'manual' => str_starts_with((string)$r['msg_id'], 'manual:')];
}

switch ($action) {
  case 'list':
    $st = $db->prepare('SELECT * FROM upwork_jobs WHERE user_id=? ORDER BY received DESC LIMIT 300'); $st->execute([$uid]);
    $tok = kg_get($uid, 'google_tok');
    kg_json(['jobs' => array_map('uw_out', $st->fetchAll(PDO::FETCH_ASSOC)), 'settings' => uw_settings($uid), 'last' => kg_get($uid, 'upwork_last'),
      'google' => ['connected' => !empty($tok['refresh']), 'email' => $tok['email'] ?? null]]);

  case 'status':
    $id = (int)($in['id'] ?? 0); $s = (string)($in['status'] ?? '');
    if (!in_array($s, ['neu', 'gemerkt', 'beworben', 'uebersprungen'], true)) kg_json(['error' => ['message' => 'Unbekannter Status']], 400);
    $db->prepare('UPDATE upwork_jobs SET status=?, updated=? WHERE id=? AND user_id=?')->execute([$s, gmdate('c'), $id, $uid]);
    kg_json(['ok' => true]);

  case 'settings':
    $words = $in['exclude'] ?? [];
    if (is_string($words)) $words = preg_split('/[\n,;]+/', $words);
    $words = array_slice(array_values(array_unique(array_filter(array_map(fn($w) => mb_substr(trim((string)$w), 0, 60), (array)$words), 'strlen'))), 0, 200);
    kg_set($uid, 'upwork_filter', ['exclude' => $words, 'only_verified' => !empty($in['only_verified']), 'min_rate' => max(0, (float)($in['min_rate'] ?? 0)), 'archive' => !empty($in['archive'])]);
    uw_refilter($uid);
    kg_json(['ok' => true, 'settings' => uw_settings($uid)]);

  case 'add':
    // Job-Link manuell speichern (Kongrow ruft die Upwork-Seite dabei nicht ab)
    $url = trim((string)($in['url'] ?? ''));
    if (!preg_match('#^https?://([a-z0-9-]+\.)?upwork\.com/#i', $url) || !preg_match('/(~0[0-9a-z]{10,})/i', $url, $m)) kg_json(['error' => ['message' => 'Das sieht nicht nach einem Upwork-Job-Link aus. Er muss eine Job-ID wie ~02… enthalten.']], 400);
    $key = $m[1]; $clean = 'https://www.upwork.com/jobs/' . $key;
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 200); $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 1000);
    $st = $db->prepare('SELECT id, status FROM upwork_jobs WHERE user_id=? AND job_key=?'); $st->execute([$uid, $key]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      // schon vorhanden: nicht doppelt anlegen, aber auf die Merkliste holen (außer schon beworben)
      if ($row['status'] !== 'beworben') $db->prepare("UPDATE upwork_jobs SET status='gemerkt', reason=NULL, updated=? WHERE id=?")->execute([gmdate('c'), $row['id']]);
      if ($title !== '') $db->prepare('UPDATE upwork_jobs SET title=? WHERE id=?')->execute([$title, $row['id']]);
      if ($note !== '') $db->prepare('UPDATE upwork_jobs SET snippet=? WHERE id=?')->execute([$note, $row['id']]);
      kg_json(['ok' => true, 'id' => (int)$row['id'], 'existing' => true, 'status' => $row['status'] === 'beworben' ? 'beworben' : 'gemerkt']);
    }
    $db->prepare("INSERT INTO upwork_jobs(user_id,msg_id,job_key,url,title,kind,budget,snippet,received,status,reason,updated) VALUES(?,?,?,?,?,?,?,?,?,'gemerkt',NULL,?)")
      ->execute([$uid, 'manual:' . $key, $key, $clean, $title !== '' ? $title : 'Upwork-Job ' . $key, 'Manuell', '', $note, gmdate('c'), gmdate('c')]);
    kg_json(['ok' => true, 'id' => (int)$db->lastInsertId(), 'existing' => false, 'status' => 'gemerkt']);

  case 'test_push':
    // Push-Nachrichten für die aktuell offenen Jobs schicken (zum Testen)
    require_once __DIR__ . '/../push/lib.php';
    $st = $db->prepare("SELECT * FROM upwork_jobs WHERE user_id=? AND status IN ('neu','gemerkt') ORDER BY received DESC LIMIT 3"); $st->execute([$uid]);
    $jobs = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$jobs) kg_json(['error' => ['message' => 'Keine offenen Jobs zum Testen.']], 409);
    foreach ($jobs as &$j) $j['id'] = (int)$j['id']; unset($j);
    ob_start(); $devices = kg_push_all(uw_push_messages($jobs)); ob_end_clean();
    kg_json(['ok' => true, 'jobs' => count($jobs), 'devices' => $devices]);

  case 'check':
    $r = uw_fetch_new($uid);
    if ($r['error']) kg_json(['error' => ['message' => $r['error']]], 409);
    kg_json(['ok' => true, 'new' => count($r['new']), 'filtered' => $r['filtered']]);
}
kg_json(['error' => ['message' => 'Unbekannte Aktion']], 400);
