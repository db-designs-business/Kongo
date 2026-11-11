<?php
// Upwork-Jobs aus den Alert-Mails: Liste, Status, Filter, manueller Abruf
require __DIR__ . '/upwork_lib.php';
$uid = kg_require_user();
$action = $_GET['action'] ?? 'list';
$in = kg_input();
$db = uw_db();

function uw_out(array $r): array {
  return ['id' => (int)$r['id'], 'title' => $r['title'], 'url' => $r['url'] ?: null, 'app_url' => ($r['app_url'] ?? '') ?: null, 'kind' => $r['kind'], 'budget' => $r['budget'],
    'snippet' => $r['snippet'], 'skills' => $r['skills'], 'verified' => $r['verified'] === null ? null : (bool)$r['verified'],
    'spent' => $r['spent'], 'country' => $r['country'], 'rating' => $r['rating'] ?? '', 'received' => $r['received'], 'status' => $r['status'], 'reason' => $r['reason'], 'manual' => str_starts_with((string)$r['msg_id'], 'manual:'),
    'draft' => $r['draft'] ?? '', 'draft_answers' => uw_answers_list((string)($r['draft_answers'] ?? '')), 'draft_notes' => $r['draft_notes'] ?? '', 'draft_ts' => $r['draft_ts'] ?? null,
    'boosted' => isset($r['boosted']) ? (bool)$r['boosted'] : null, 'connects' => isset($r['connects']) ? (int)$r['connects'] : null];
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
    $st = $db->prepare('SELECT * FROM upwork_jobs WHERE id=? AND user_id=?'); $st->execute([$id, $uid]); $job = $st->fetch(PDO::FETCH_ASSOC);
    if (!$job) kg_json(['error' => ['message' => 'Job nicht gefunden']], 404);
    $db->prepare('UPDATE upwork_jobs SET status=?, updated=? WHERE id=? AND user_id=?')->execute([$s, gmdate('c'), $id, $uid]);
    if ($s === 'beworben') {
      $job['boosted'] = array_key_exists('boosted', $in) && $in['boosted'] !== null ? (int)!empty($in['boosted']) : null;
      $job['connects'] = isset($in['connects']) && $in['connects'] !== null && $in['connects'] !== '' ? max(0, (int)$in['connects']) : null;
      $db->prepare('UPDATE upwork_jobs SET boosted=?, connects=? WHERE id=?')->execute([$job['boosted'], $job['connects'], $id]);
    } elseif ($job['status'] === 'beworben') $db->prepare('UPDATE upwork_jobs SET boosted=NULL, connects=NULL WHERE id=?')->execute([$id]);
    // Google Sheet: beworben → Zeile rein, „Doch nicht“ → Zeile raus
    $sheet = null;
    if (($s === 'beworben') !== ($job['status'] === 'beworben')) {
      try { $sheet = uw_sheet_apply($uid, $job, $s === 'beworben'); } catch (Throwable $e) { $sheet = ['ok' => false, 'msg' => 'Sheet-Fehler']; }
    }
    kg_json(['ok' => true, 'sheet' => $sheet]);

  case 'settings':
    $words = $in['exclude'] ?? [];
    if (is_string($words)) $words = preg_split('/[\n,;]+/', $words);
    $words = array_slice(array_values(array_unique(array_filter(array_map(fn($w) => mb_substr(trim((string)$w), 0, 60), (array)$words), 'strlen'))), 0, 200);
    kg_set($uid, 'upwork_filter', ['exclude' => $words, 'only_verified' => !empty($in['only_verified']), 'min_rate' => max(0, (float)($in['min_rate'] ?? 0)), 'archive' => !empty($in['archive'])]);
    uw_refilter($uid);
    kg_json(['ok' => true, 'settings' => uw_settings($uid)]);

  case 'portfolio':
    // Portfolio-Liste (Name + Link) für den Einfüge-Button im Proposal
    $list = kg_get($uid, 'upwork_portfolio');
    if (!is_array($list)) $list = array_map(fn($n) => ['name' => $n, 'url' => ''], UW_PORTFOLIO_DEFAULT);
    kg_json(['ok' => true, 'items' => array_values($list)]);

  case 'portfolio_save':
    $items = [];
    foreach ((array)($in['items'] ?? []) as $it) {
      $n = mb_substr(trim((string)($it['name'] ?? '')), 0, 120); $u = mb_substr(trim((string)($it['url'] ?? '')), 0, 500);
      if ($n === '' && $u === '') continue;
      if ($u !== '' && !preg_match('~^https?://~i', $u)) $u = 'https://' . $u;
      $items[] = ['name' => $n !== '' ? $n : $u, 'url' => $u];
      if (count($items) >= 60) break;
    }
    kg_set($uid, 'upwork_portfolio', $items);
    kg_json(['ok' => true, 'items' => $items]);

  case 'draft':
    // Entwurf in Kongrow bearbeiten
    $db->prepare('UPDATE upwork_jobs SET draft=?, draft_answers=?, draft_ts=?, updated=? WHERE id=? AND user_id=?')
      ->execute([mb_substr((string)($in['draft'] ?? ''), 0, 6000), uw_answers_json($in['answers'] ?? null), gmdate('c'), gmdate('c'), (int)($in['id'] ?? 0), $uid]);
    kg_json(['ok' => true]);

  case 'title':
    $t = mb_substr(trim((string)($in['title'] ?? '')), 0, 200);
    if ($t === '') kg_json(['error' => ['message' => 'Titel fehlt']], 400);
    $st = $db->prepare('SELECT job_key FROM upwork_jobs WHERE id=? AND user_id=?'); $st->execute([(int)($in['id'] ?? 0), $uid]); $jk = $st->fetchColumn();
    $db->prepare('UPDATE upwork_jobs SET title=?, updated=? WHERE id=? AND user_id=?')->execute([$t, gmdate('c'), (int)($in['id'] ?? 0), $uid]);
    if ($jk) { $db->prepare('UPDATE upwork_applied SET title=? WHERE user_id=? AND job_key=?')->execute([$t, $uid, $jk]); try { uw_sheet_title($uid, $jk, $t); } catch (Throwable $e) {} }
    kg_json(['ok' => true]);

  case 'mcp_key':
    // Adresse für den Kongrow-Connector in Claude (Schlüssel wird bei Bedarf erzeugt oder neu erzeugt)
    $k = kg_get($uid, 'mcp_key');
    if (!is_string($k) || !empty($in['renew'])) { $k = bin2hex(random_bytes(20)); kg_set($uid, 'mcp_key', $k); }
    kg_json(['url' => kg_base_url() . '/api/mcp.php?key=' . $k]);

  case 'delete':
    // merken, damit derselbe Job nicht aus einer Alert-Mail zurückkommt
    $st = $db->prepare('SELECT job_key, title FROM upwork_jobs WHERE id=? AND user_id=?'); $st->execute([(int)($in['id'] ?? 0), $uid]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      if ($row['job_key']) $db->prepare('INSERT OR REPLACE INTO upwork_deleted(user_id,job_key,ts) VALUES(?,?,?)')->execute([$uid, $row['job_key'], time()]);
      uw_log($uid, 'gelöscht: ' . mb_substr($row['title'], 0, 60));
    }
    $db->prepare('DELETE FROM upwork_jobs WHERE id=? AND user_id=?')->execute([(int)($in['id'] ?? 0), $uid]);
    kg_json(['ok' => true]);

  case 'add':
    // Job-Link manuell speichern (Kongrow ruft die Upwork-Seite dabei nicht ab)
    $url = trim((string)($in['url'] ?? ''));
    if (!preg_match('#^https?://([a-z0-9-]+\.)?upwork\.com/#i', $url) || !preg_match('/(~0[0-9a-z]{10,})/i', $url, $m)) kg_json(['error' => ['message' => 'Das sieht nicht nach einem Upwork-Job-Link aus. Er muss eine Job-ID wie ~02… enthalten.']], 400);
    $key = $m[1]; $clean = 'https://www.upwork.com/jobs/' . $key;
    $db->prepare('DELETE FROM upwork_deleted WHERE user_id=? AND job_key=?')->execute([$uid, $key]);
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

  case 'sheet_rows':
    // Bewerbungsliste aus dem verknüpften Sheet lesen (nur lesen), für den KPI-Abgleich in Kongrow
    if (empty(kg_get($uid, 'upwork_sheet')['id'])) kg_json(['error' => ['message' => 'Kein Google Sheet verknüpft']], 409);
    $c = uw_sheet_ctx($uid); if (!$c) kg_json(['error' => ['message' => 'Sheet nicht erreichbar. Ist Google noch verbunden?']], 409);
    $r = kg_http('GET', $c['base'] . '/values/' . rawurlencode($c['q'] . '!A1:Z5000') . '?valueRenderOption=UNFORMATTED_VALUE&dateTimeRenderOption=SERIAL_NUMBER', $c['h']);
    if (($r['code'] ?? 0) !== 200) kg_json(['error' => ['message' => 'Sheet konnte nicht gelesen werden']], 409);
    $vals = $r['json']['values'] ?? [];
    // Kopfzeile = erste Zeile (in den ersten 10), in der „Date“/„Datum“ steht
    $hi = 0; foreach (array_slice($vals, 0, 10) as $i => $row) { if (preg_grep('/^\s*(date|datum)\b/i', array_map('strval', $row))) { $hi = $i; break; } }
    kg_json(['ok' => true, 'tab' => trim($c['q'], "'"), 'header' => array_map('strval', $vals[$hi] ?? []), 'header_row' => $hi + 1, 'rows' => array_slice($vals, $hi + 1)]);

  case 'remove_kpi_tabs':
    kg_json(uw_sheet_remove_kpi_tabs($uid));

  case 'log':
    kg_json(['log' => kg_get($uid, 'upwork_log', [])]);

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
    $r = uw_fetch_new($uid, true);
    if ($r['error']) kg_json(['error' => ['message' => $r['error']]], 409);
    kg_json(['ok' => true, 'new' => count($r['new']), 'filtered' => $r['filtered']]);
}
kg_json(['error' => ['message' => 'Unbekannte Aktion']], 400);
