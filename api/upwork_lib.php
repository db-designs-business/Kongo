<?php
// Upwork-Job-Alerts: liest die Alert-Mails aus dem verbundenen Gmail-Postfach, speichert die Jobs und filtert sie.
// Kongrow spricht dabei nie mit Upwork selbst – es verarbeitet nur Mails im eigenen Postfach.
// Bricht nie mit kg_json ab (wird auch vom Cronjob benutzt).
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/notify_lib.php';

const UW_LABEL = 'Upwork-Jobs';

function uw_db(): PDO {
  static $done = false;
  $db = kg_db();
  if (!$done) {
    $done = true;
    $db->exec('CREATE TABLE IF NOT EXISTS upwork_jobs (
      id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, msg_id TEXT NOT NULL, job_key TEXT, url TEXT, title TEXT NOT NULL,
      kind TEXT, budget TEXT, rate_min REAL, rate_max REAL, snippet TEXT, skills TEXT, verified INTEGER, spent TEXT, country TEXT,
      received TEXT NOT NULL, status TEXT NOT NULL, reason TEXT, updated TEXT, UNIQUE(user_id, msg_id))');
    $db->exec('CREATE INDEX IF NOT EXISTS uw_status ON upwork_jobs(user_id, status, received)');
    $db->exec('CREATE TABLE IF NOT EXISTS upwork_seen (user_id INTEGER NOT NULL, msg_id TEXT NOT NULL, ts INTEGER NOT NULL, PRIMARY KEY(user_id, msg_id))');
  }
  return $db;
}

function uw_settings(int $uid): array {
  $s = kg_get($uid, 'upwork_filter', []);
  return [
    'exclude' => array_values(array_filter(array_map('trim', (array)($s['exclude'] ?? [])), 'strlen')),
    'only_verified' => !empty($s['only_verified']),
    'min_rate' => max(0, (float)($s['min_rate'] ?? 0)),
    'archive' => array_key_exists('archive', $s) ? !empty($s['archive']) : true,
  ];
}

/* ---------- Mail auslesen ---------- */
function uw_lines(string $text): array {
  $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
  $out = [];
  foreach (explode("\n", $text) as $l) { $l = trim(preg_replace('/\s+/u', ' ', $l)); if ($l !== '') $out[] = $l; }
  return $out;
}
function uw_parts(string $line): array { return array_values(array_filter(array_map('trim', preg_split('/\s+[•·|]\s+/u', $line)), 'strlen')); }
function uw_money(string $s): ?float { $s = str_replace(',', '', $s); if (!preg_match('/([\d.]+)\s*([kKmM])?/', $s, $m)) return null; $v = (float)$m[1]; if (!empty($m[2])) $v *= strtolower($m[2]) === 'k' ? 1000 : 1000000; return $v; }

// Liefert die Job-Daten aus dem Text einer Alert-Mail oder null, wenn es keine Job-Alert-Mail ist
function uw_parse(string $text, string $html = '', string $subject = ''): ?array {
  $L = uw_lines($text);
  $b = null;
  foreach ($L as $i => $l) if (preg_match('/^(Hourly|Fixed[- ]?price|Fixed)\b/i', $l)) { $b = $i; break; }
  if ($b === null) return null;
  // Titel: nächste Zeile davor, die kein Kopftext ist
  $title = '';
  for ($i = $b - 1; $i >= 0; $i--) {
    if (preg_match('/^(posted|new job alert|hi |this job was just posted|view job)/i', $L[$i])) continue;
    $title = $L[$i]; break;
  }
  if ($title === '') $title = trim(preg_replace('/^(new job( alert)?|job alert)\s*[:\-–]\s*/i', '', $subject));
  if ($title === '') return null;
  // Budget-Zeile (steht der Betrag in der nächsten Zeile, wird sie angehängt)
  if (count(uw_parts($L[$b])) < 2 && isset($L[$b + 1]) && preg_match('/^(\$|budget|est\.|est budget)/i', $L[$b + 1])) { $L[$b] .= ' • ' . $L[$b + 1]; array_splice($L, $b + 1, 1); }
  $bp = uw_parts($L[$b]);
  $kind = stripos($bp[0], 'hour') !== false ? 'Stundensatz' : 'Festpreis';
  $budget = trim(implode(' · ', array_slice($bp, 1)));
  $rmin = $rmax = null;
  if (preg_match_all('/\$\s?([\d,]+(?:\.\d+)?\s*[kK]?)/', $L[$b], $mm)) { $vals = array_map('uw_money', $mm[1]); $rmin = $vals[0]; $rmax = $vals[count($vals) - 1]; }
  // Kunden-Zeile
  $c = null;
  for ($i = $b + 1; $i < count($L); $i++) if (preg_match('/payment (un)?verified|\bspent\b/i', $L[$i])) { $c = $i; break; }
  $end = $c ?? count($L);
  for ($i = $b + 1; $i < $end; $i++) if (preg_match('/^view job/i', $L[$i])) { $end = $i; break; }
  $mid = array_slice($L, $b + 1, max(0, $end - $b - 1));
  // Beschreibung bis „… more“, danach Skills
  $desc = []; $skills = [];
  $cut = null;
  foreach ($mid as $i => $l) if (preg_match('/(…|\.\.\.)\s*more$/u', $l)) { $cut = $i; break; }
  if ($cut !== null) { $desc = array_slice($mid, 0, $cut + 1); $skills = array_slice($mid, $cut + 1); }
  elseif ($mid) { $desc = [$mid[0]]; $skills = array_slice($mid, 1); }
  $snippet = trim(preg_replace('/\s*(…|\.\.\.)\s*more$/u', '…', implode(' ', $desc)));
  $verified = null; $spent = ''; $country = '';
  if ($c !== null) {
    $cl = $L[$c];
    for ($i = $c + 1; $i < min(count($L), $c + 4); $i++) { if (preg_match('/^(view job|you received|this email|\(?more\)?$)/i', $L[$i])) break; $cl .= ' • ' . $L[$i]; }
    $verified = preg_match('/payment unverified/i', $cl) ? 0 : (preg_match('/payment verified/i', $cl) ? 1 : null);
    if (preg_match('/(\$\s?[\d.,]+\s*[kKmM]?\+?)\s*spent/i', $cl, $m)) $spent = str_replace(' ', '', $m[1]);
    $cp = uw_parts($cl); $last = end($cp);
    if ($last && !preg_match('/verified|spent|\$/i', $last)) $country = $last;
  }
  // Link zum Job aus dem HTML
  $url = '';
  if ($html !== '' && preg_match_all('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $am, PREG_SET_ORDER)) {
    $cands = [];
    foreach ($am as $a) {
      $h = html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'); $t = trim(strip_tags($a[2]));
      if (!preg_match('#^https://#i', $h)) continue;
      $score = 0;
      if (preg_match('#upwork\.com/(jobs|freelance-jobs|nx/jobs|ab/proposals/job)#i', $h)) $score += 3;
      if (stripos($t, 'view job') !== false) $score += 2;
      if (strcasecmp($t, $title) === 0) $score += 2;
      if (stripos($h, 'upwork') !== false) $score += 1;
      if (preg_match('/unsubscribe|preferences|settings|privacy|help/i', $h . ' ' . $t)) $score -= 5;
      $cands[] = [$score, $h];
    }
    usort($cands, fn($x, $y) => $y[0] <=> $x[0]);
    if ($cands && $cands[0][0] > 0) $url = $cands[0][1];
  }
  if ($url === '' && preg_match('#https://[^\s<>"\']*upwork\.com/(?:jobs|freelance-jobs)[^\s<>"\']*#i', $text, $m)) $url = $m[0];
  $key = preg_match('/(~0[0-9a-z]{10,})/i', $url, $m) ? $m[1] : null;
  return ['title' => $title, 'kind' => $kind, 'budget' => $budget, 'rate_min' => $rmin, 'rate_max' => $rmax, 'snippet' => $snippet,
    'skills' => trim(implode(' · ', $skills)), 'verified' => $verified, 'spent' => $spent, 'country' => $country, 'url' => $url, 'job_key' => $key];
}

/* ---------- Filter ---------- */
// Grund für das Aussortieren oder null
function uw_filter_reason(array $j, array $s): ?string {
  $hay = ' ' . $j['title'] . ' ' . ($j['snippet'] ?? '') . ' ' . ($j['skills'] ?? '') . ' ';
  foreach ($s['exclude'] as $w) {
    if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/iu', $hay)) return 'Ausschlusswort „' . $w . '“';
  }
  if ($s['only_verified'] && (int)($j['verified'] ?? -1) === 0) return 'Zahlung nicht verifiziert';
  if ($s['min_rate'] > 0 && ($j['kind'] ?? '') === 'Stundensatz' && $j['rate_max'] !== null && (float)$j['rate_max'] < $s['min_rate']) return 'Stundensatz unter ' . rtrim(rtrim(number_format($s['min_rate'], 2, '.', ''), '0'), '.') . ' $';
  return null;
}
// Filter neu auf offene Jobs anwenden (nach Änderung der Einstellungen)
function uw_refilter(int $uid): void {
  $s = uw_settings($uid); $db = uw_db();
  $st = $db->prepare("SELECT * FROM upwork_jobs WHERE user_id=? AND status IN ('neu','gefiltert')"); $st->execute([$uid]);
  $up = $db->prepare('UPDATE upwork_jobs SET status=?, reason=? WHERE id=?');
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $j) { $r = uw_filter_reason($j, $s); $up->execute([$r ? 'gefiltert' : 'neu', $r, $j['id']]); }
}

/* ---------- Gmail ---------- */
function uw_b64(string $s): string { return base64_decode(strtr($s, '-_', '+/')); }
function uw_bodies(array $payload): array {
  $plain = ''; $html = '';
  $walk = function ($p) use (&$walk, &$plain, &$html) {
    $mt = $p['mimeType'] ?? '';
    if (!empty($p['body']['data']) && ($p['filename'] ?? '') === '') {
      if ($mt === 'text/plain' && $plain === '') $plain = uw_b64($p['body']['data']);
      if ($mt === 'text/html' && $html === '') $html = uw_b64($p['body']['data']);
    }
    foreach ($p['parts'] ?? [] as $c) $walk($c);
  };
  $walk($payload);
  return [$plain, $html];
}
function uw_label_id(int $uid, string $at): ?string {
  $c = kg_get($uid, 'upwork_label'); if (!empty($c['id'])) return $c['id'];
  $r = kg_http('GET', 'https://gmail.googleapis.com/gmail/v1/users/me/labels', ['Authorization: Bearer ' . $at]);
  foreach (($r['json']['labels'] ?? []) as $l) if (strcasecmp($l['name'] ?? '', UW_LABEL) === 0) { kg_set($uid, 'upwork_label', ['id' => $l['id']]); return $l['id']; }
  $r = kg_http('POST', 'https://gmail.googleapis.com/gmail/v1/users/me/labels', ['Authorization: Bearer ' . $at, 'Content-Type: application/json'],
    json_encode(['name' => UW_LABEL, 'labelListVisibility' => 'labelShow', 'messageListVisibility' => 'show']));
  if (!empty($r['json']['id'])) { kg_set($uid, 'upwork_label', ['id' => $r['json']['id']]); return $r['json']['id']; }
  return null;
}

// Neue Alert-Mails verarbeiten. Liefert ['new' => [Jobs, die durch den Filter kamen], 'filtered' => n, 'error' => ?string]
function uw_fetch_new(int $uid): array {
  $res = ['new' => [], 'filtered' => 0, 'error' => null];
  $at = n_google_token($uid);
  if ($at === '') { $res['error'] = 'Google ist nicht verbunden.'; uw_note($uid, $res); return $res; }
  $db = uw_db(); $s = uw_settings($uid); $auth = ['Authorization: Bearer ' . $at];
  $r = kg_http('GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages?' . http_build_query(['maxResults' => 25, 'q' => 'from:upwork.com newer_than:2d -in:trash']), $auth);
  if (($r['code'] ?? 0) >= 400 || ($r['code'] ?? 0) === 0) { $res['error'] = 'Gmail: ' . (($r['json']['error']['message'] ?? '') ?: ('Fehler ' . $r['code'])); uw_note($uid, $res); return $res; }
  $ids = array_map(fn($m) => $m['id'], $r['json']['messages'] ?? []);
  if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT msg_id FROM upwork_seen WHERE user_id=? AND msg_id IN ($in)"); $st->execute(array_merge([$uid], $ids));
    $ids = array_values(array_diff($ids, $st->fetchAll(PDO::FETCH_COLUMN)));
  }
  if (!$ids) { uw_note($uid, $res); return $res; }
  $urls = []; foreach (array_slice($ids, 0, 15) as $id) $urls[$id] = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $id . '?format=full';
  $seen = $db->prepare('INSERT OR IGNORE INTO upwork_seen(user_id,msg_id,ts) VALUES(?,?,?)');
  $ins = $db->prepare('INSERT OR IGNORE INTO upwork_jobs(user_id,msg_id,job_key,url,title,kind,budget,rate_min,rate_max,snippet,skills,verified,spent,country,received,status,reason,updated)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $label = null; $labelTried = false;
  foreach (kg_http_multi($urls, $auth) as $id => $m) {
    if (!$m || empty($m['id'])) continue;   // bei Fehler nächstes Mal erneut versuchen
    $seen->execute([$uid, $id, time()]);
    $h = []; foreach ($m['payload']['headers'] ?? [] as $x) $h[strtolower($x['name'])] = $x['value'];
    [$plain, $html] = uw_bodies($m['payload'] ?? []);
    $text = $plain !== '' ? $plain : kg_html_to_text(preg_replace(['#</(td|th)>#i', '#</(span|a|b|strong)>#i'], ['$0' . "\n", '$0 '], $html));
    if (!preg_match('/job alert|matches your alert|view job details/i', $text)) continue;   // andere Upwork-Mails bleiben unangetastet
    $j = uw_parse($text, $html, (string)($h['subject'] ?? ''));
    if (!$j) continue;
    // gleicher Job schon aus einer anderen Mail da?
    if ($j['job_key']) { $d = $db->prepare('SELECT 1 FROM upwork_jobs WHERE user_id=? AND job_key=?'); $d->execute([$uid, $j['job_key']]); if ($d->fetchColumn()) continue; }
    $reason = uw_filter_reason($j, $s);
    $received = gmdate('c', (int)(($m['internalDate'] ?? 0) / 1000) ?: time());
    $ins->execute([$uid, $id, $j['job_key'], $j['url'], $j['title'], $j['kind'], $j['budget'], $j['rate_min'], $j['rate_max'], $j['snippet'], $j['skills'],
      $j['verified'], $j['spent'], $j['country'], $received, $reason ? 'gefiltert' : 'neu', $reason, gmdate('c')]);
    if ($ins->rowCount() < 1) continue;
    $j['id'] = (int)$db->lastInsertId();
    if ($reason) $res['filtered']++; else $res['new'][] = $j;
    // Mail als gelesen markieren und (falls eingestellt) aus dem Posteingang in das Label „Upwork-Jobs“ verschieben
    $mod = ['removeLabelIds' => ['UNREAD']];
    if ($s['archive']) {
      if (!$labelTried) { $labelTried = true; $label = uw_label_id($uid, $at); }
      $mod['removeLabelIds'][] = 'INBOX';
      if ($label) $mod['addLabelIds'] = [$label];
    }
    kg_http('POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $id . '/modify', array_merge($auth, ['Content-Type: application/json']), json_encode($mod));
  }
  uw_cleanup($uid);
  uw_note($uid, $res);
  return $res;
}
function uw_note(int $uid, array $res): void {
  kg_set($uid, 'upwork_last', ['ts' => time(), 'new' => count($res['new']), 'filtered' => $res['filtered'], 'error' => $res['error']]);
}
// Datensparsam: nicht beworbene Jobs nach 14 Tagen löschen, beworbene nach 120 Tagen
function uw_cleanup(int $uid): void {
  $db = uw_db();
  $db->prepare("DELETE FROM upwork_jobs WHERE user_id=? AND status<>'beworben' AND received < ?")->execute([$uid, gmdate('c', time() - 14 * 86400)]);
  $db->prepare("DELETE FROM upwork_jobs WHERE user_id=? AND received < ?")->execute([$uid, gmdate('c', time() - 120 * 86400)]);
  $db->prepare('DELETE FROM upwork_seen WHERE user_id=? AND ts < ?')->execute([$uid, time() - 7 * 86400]);
}

// Push-Nachrichten für neue Jobs
function uw_push_messages(array $jobs): array {
  $line = fn($j) => trim(implode(' · ', array_filter([($j['kind'] === 'Stundensatz' ? 'Std. ' : '') . $j['budget'], $j['country'], $j['spent'] ? $j['spent'] . ' ausgegeben' : ''])));
  if (count($jobs) <= 3) return array_map(fn($j) => ['title' => '💼 ' . $j['title'], 'body' => $line($j) ?: 'Neuer Upwork-Job', 'url' => './?open=upwork&job=' . $j['id'], 'tag' => 'upwork-' . $j['id']], $jobs);
  return [['title' => '💼 ' . count($jobs) . ' neue Upwork-Jobs', 'body' => implode("\n", array_map(fn($j) => $j['title'], array_slice($jobs, 0, 4))), 'url' => './?open=upwork', 'tag' => 'upwork']];
}
