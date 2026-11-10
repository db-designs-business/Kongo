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
    // jeder Job nur einmal pro Nutzer (egal ob aus Alert-Mail oder manuell)
    try { $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uw_jobkey ON upwork_jobs(user_id, job_key) WHERE job_key IS NOT NULL'); } catch (Throwable $e) {}
    $cols = array_column($db->query('PRAGMA table_info(upwork_jobs)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('rating', $cols, true)) $db->exec('ALTER TABLE upwork_jobs ADD COLUMN rating TEXT');
    foreach (['draft', 'draft_answers', 'draft_notes', 'draft_ts'] as $c) if (!in_array($c, $cols, true)) $db->exec("ALTER TABLE upwork_jobs ADD COLUMN $c TEXT");
    $db->exec('CREATE TABLE IF NOT EXISTS upwork_deleted (user_id INTEGER NOT NULL, job_key TEXT NOT NULL, ts INTEGER NOT NULL, PRIMARY KEY(user_id, job_key))');
    // Altlast aus dem ersten Parser: Links im Titel („Titel...: https://…“) entfernen
    foreach ($db->query("SELECT id, title FROM upwork_jobs WHERE title LIKE '%http%'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $t = trim((string)preg_replace(['#\s*:?\s*<?https?://\S*>?#i', '/\s*:\s*$/'], ['', ''], $r['title']));
      if ($t !== '' && $t !== $r['title']) $db->prepare('UPDATE upwork_jobs SET title=? WHERE id=?')->execute([$t, $r['id']]);
    }
    // Bewerbungs-Log fürs Google Sheet (bleibt dauerhaft, auch wenn der Job aus der Liste gelöscht wird)
    $db->exec('CREATE TABLE IF NOT EXISTS upwork_applied (user_id INTEGER NOT NULL, job_key TEXT NOT NULL, url TEXT, title TEXT, budget TEXT, country TEXT, source TEXT, applied_at TEXT NOT NULL, PRIMARY KEY(user_id, job_key))');
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

// Bereinigt eine Zeile: Links raus, Reste wie „Titel: “ oder „<>“ entfernen
function uw_clean_line(string $l): string {
  $l = preg_replace('#<?\(?\[?https?://\S+\]?\)?>?#i', ' ', $l);
  $l = preg_replace('/\s*[:\-–]\s*$/u', '', trim(preg_replace('/\s+/u', ' ', $l)));
  return trim($l);
}
function uw_noise(string $l): bool {
  return $l === '' || preg_match('/^[-=_*·•.\s]+$/u', $l) || preg_match('/do not apply via email|only upwork applications will be accepted/i', $l);
}
function uw_stop(string $l): bool { return (bool)preg_match('/^(view job|see (all|more) jobs|you received this|you are receiving|unsubscribe|manage your|this email was sent|©)/i', $l); }
const UW_HEAD = '/^(posted|new job alert|new jobs?|jobs? (you might like|matching)|hi |hello |this job was just posted|these jobs|view job|skills?$|recommended)/i';

// Liefert alle Jobs einer Alert-Mail (Upwork schickt teils mehrere in einer Mail)
function uw_parse_all(string $text, string $html = '', string $subject = ''): array {
  // Links aus Text und HTML sammeln, bevor sie entfernt werden
  $links = [];
  if (preg_match_all('#https?://[^\s<>"\'\)\]]+#i', $text, $m)) foreach ($m[0] as $u) $links[] = $u;
  if ($html !== '' && preg_match_all('#href=["\']([^"\']+)["\']#i', $html, $m)) foreach ($m[1] as $u) $links[] = html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $jobLinks = [];
  foreach ($links as $u) if (preg_match('/(~0[0-9a-z]{10,})/i', $u, $k) && preg_match('#upwork\.com/#i', $u)) {
    $key = $k[1]; $clean = 'https://www.upwork.com/jobs/' . $key;
    if (!isset($jobLinks[$key])) $jobLinks[$key] = $clean;
  }
  // Zeilen bereinigen
  $L = [];
  foreach (uw_lines($text) as $l) { $c = uw_clean_line($l); if (!uw_noise($c)) $L[] = $c; }
  $budgetIdx = [];
  foreach ($L as $i => $l) if (preg_match('/^(Hourly|Fixed[- ]?price|Fixed)\b/i', $l)) $budgetIdx[] = $i;
  if (!$budgetIdx) return [];
  // Titel-Position je Job
  $titleIdx = [];
  foreach ($budgetIdx as $n => $b) {
    $t = null; $min = $n ? $budgetIdx[$n - 1] + 1 : 0;
    for ($i = $b - 1; $i >= $min; $i--) { if (preg_match(UW_HEAD, $L[$i]) || preg_match('/payment (un)?verified|\bspent\b/i', $L[$i])) continue; $t = $i; break; }
    $titleIdx[$n] = $t;
  }
  $keys = array_keys($jobLinks); $out = [];
  foreach ($budgetIdx as $n => $b) {
    $title = $titleIdx[$n] !== null ? $L[$titleIdx[$n]] : '';
    if ($title === '' && count($budgetIdx) === 1) $title = trim(preg_replace('/^(new job( alert)?|job alert)\s*[:\-–]\s*/i', '', $subject));
    if ($title === '') continue;
    $end = isset($budgetIdx[$n + 1]) ? ($titleIdx[$n + 1] ?? $budgetIdx[$n + 1]) : count($L);
    for ($i = $b + 1; $i < $end; $i++) if (uw_stop($L[$i])) { $end = $i; break; }
    $seg = array_slice($L, $b + 1, max(0, $end - $b - 1));
    // Budget
    $line = $L[$b];
    if (count(uw_parts($line)) < 2 && isset($seg[0]) && preg_match('/^(\$|budget|est)/i', $seg[0])) $line .= ' • ' . array_shift($seg);
    $bp = uw_parts($line);
    $kind = stripos($bp[0], 'hour') !== false ? 'Stundensatz' : 'Festpreis';
    // „Hourly: $20.00 - $38.00“ – Betrag steht direkt hinter dem Doppelpunkt
    $first = preg_match('/^[^:]+:\s*(.+)$/', $bp[0], $fm) ? [trim($fm[1])] : [];
    $parts = array_values(array_filter(array_merge($first, array_slice($bp, 1)), fn($p) => !preg_match('/^posted\b/i', $p)));
    $parts = array_map(fn($p) => preg_replace(['/^est\.? ?time:?\s*/i', '/^est\.? ?budget:?\s*/i', '/^budget:?\s*/i'], ['Dauer ', '', ''], $p), $parts);
    $budget = trim(implode(' · ', $parts));
    $rmin = $rmax = null;
    if (preg_match_all('/\$\s?([\d,]+(?:\.\d+)?\s*[kK]?)/', $line, $mm)) { $vals = array_map('uw_money', $mm[1]); $rmin = $vals[0]; $rmax = $vals[count($vals) - 1]; }
    // Kundenzeile(n)
    $verified = null; $spent = ''; $country = ''; $rating = ''; $c = null;
    foreach ($seg as $i => $l) if (preg_match('/payment (un)?verified|\bspent\b/i', $l)) { $c = $i; break; }
    $body = $c === null ? $seg : array_slice($seg, 0, $c);
    if ($c !== null) {
      $cl = $seg[$c];
      for ($i = $c + 1; $i < min(count($seg), $c + 3); $i++) {
        if (preg_match('/spent|verified|^\$|^[A-Z][A-Za-z .\'-]{2,30}$/', $seg[$i]) && !preg_match('/\b(skills?|more)\b/i', $seg[$i])) $cl .= ' • ' . $seg[$i]; else break;
      }
      $verified = preg_match('/payment unverified/i', $cl) ? 0 : (preg_match('/payment verified/i', $cl) ? 1 : null);
      if (preg_match('/(\$\s?[\d.,]+\s*[kKmM]?\+?)\s*spent/i', $cl, $mm)) $spent = str_replace(' ', '', $mm[1]);
      if (preg_match('/([0-5](?:\.\d+)?)\s*stars?/i', $cl, $mm)) $rating = $mm[1];
      foreach (array_reverse(uw_parts($cl)) as $p) { if (!preg_match('/verified|spent|\$|\d/i', $p) && mb_strlen($p) <= 40) { $country = $p; break; } }
    }
    // Beschreibung und Skills
    $tnorm = mb_strtolower(rtrim(preg_replace('/(…|\.\.\.)$/u', '', $title)));
    $desc = []; $skills = []; $inSkills = false; $cut = false;
    foreach ($body as $l) {
      if (preg_match('/^skills?\s*:?\s*(.*)$/i', $l, $sm)) { $inSkills = true; if ($sm[1] !== '') $skills[] = $sm[1]; continue; }
      if ($inSkills || $cut) { $skills[] = $l; continue; }
      // wiederholten (vollständigen) Titel als Titel übernehmen statt in die Beschreibung
      if ($tnorm !== '' && str_starts_with(mb_strtolower($l), $tnorm) && mb_strlen($l) <= 160 && !preg_match('/(…|\.\.\.)\s*more$/u', $l)) { if (preg_match("/(…|\\.\\.\\.)$/u", $title)) $title = $l; continue; }
      $desc[] = $l;
      if (preg_match('/(…|\.\.\.)\s*more$/u', $l)) $cut = true;
    }
    if (!$cut && count($desc) > 1 && !$inSkills) { $skills = array_merge(array_slice($desc, 1), $skills); $desc = [$desc[0]]; }
    $snippet = trim(preg_replace('/\s*(…|\.\.\.)\s*more$/u', '…', implode(' ', $desc)));
    if (mb_strlen($snippet) > 320) $snippet = mb_substr($snippet, 0, 317) . '…';
    $sk = [];
    foreach ($skills as $s) foreach (uw_parts($s) as $p) { $p = trim($p, " ·•:"); if ($p !== '' && mb_strlen($p) <= 40 && !preg_match('/^\+?\d+ more$/i', $p)) $sk[] = $p; }
    $sk = array_slice(array_values(array_unique($sk)), 0, 8);
    $key = $keys[count($budgetIdx) === count($keys) ? $n : (count($budgetIdx) === 1 ? 0 : -1)] ?? null;
    $out[] = ['title' => $title, 'kind' => $kind, 'budget' => $budget, 'rate_min' => $rmin, 'rate_max' => $rmax, 'snippet' => $snippet,
      'skills' => implode(' · ', $sk), 'verified' => $verified, 'spent' => $spent, 'country' => $country, 'rating' => $rating, 'url' => $key ? $jobLinks[$key] : '', 'job_key' => $key];
  }
  return $out;
}
function uw_parse(string $text, string $html = '', string $subject = ''): ?array { return uw_parse_all($text, $html, $subject)[0] ?? null; }

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
function uw_log(int $uid, string $line): void {
  $l = kg_get($uid, 'upwork_log', []); $l[] = date('d.m. H:i:s') . ' ' . $line; kg_set($uid, 'upwork_log', array_slice($l, -40));
}
// $deep = true (manuell „Jetzt prüfen“): auch schon gesehene Alert-Mails erneut auslesen und fehlende Jobs nachtragen
function uw_fetch_new(int $uid, bool $deep = false): array {
  $res = ['new' => [], 'filtered' => 0, 'error' => null];
  $at = n_google_token($uid);
  if ($at === '') { $res['error'] = 'Google ist nicht verbunden.'; uw_note($uid, $res); return $res; }
  $db = uw_db(); $s = uw_settings($uid); $auth = ['Authorization: Bearer ' . $at];
  // Neuer Parser: offene Jobs einmalig neu einlesen
  if ((int)(kg_get($uid, 'upwork_pv', 0)) < 2) {
    $db->prepare("DELETE FROM upwork_jobs WHERE user_id=? AND status IN ('neu','gefiltert')")->execute([$uid]);
    $db->prepare('DELETE FROM upwork_seen WHERE user_id=?')->execute([$uid]);
    kg_set($uid, 'upwork_pv', 2);
    $quiet = true;   // neu eingelesene alte Jobs nicht noch einmal per Push melden
  }
  $r = kg_http('GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages?' . http_build_query(['maxResults' => 25, 'q' => 'from:upwork.com newer_than:2d -in:trash']), $auth);
  if (($r['code'] ?? 0) >= 400 || ($r['code'] ?? 0) === 0) { $res['error'] = 'Gmail: ' . (($r['json']['error']['message'] ?? '') ?: ('Fehler ' . $r['code'])); uw_note($uid, $res); return $res; }
  $ids = array_map(fn($m) => $m['id'], $r['json']['messages'] ?? []);
  if ($ids && !$deep) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT msg_id FROM upwork_seen WHERE user_id=? AND msg_id IN ($in)"); $st->execute(array_merge([$uid], $ids));
    $ids = array_values(array_diff($ids, $st->fetchAll(PDO::FETCH_COLUMN)));
  }
  if (!$ids) { uw_note($uid, $res); return $res; }
  $urls = []; foreach (array_slice($ids, 0, 15) as $id) $urls[$id] = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $id . '?format=full';
  $seen = $db->prepare('INSERT OR IGNORE INTO upwork_seen(user_id,msg_id,ts) VALUES(?,?,?)');
  $ins = $db->prepare('INSERT OR IGNORE INTO upwork_jobs(user_id,msg_id,job_key,url,title,kind,budget,rate_min,rate_max,snippet,skills,verified,spent,country,rating,received,status,reason,updated)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $label = null; $labelTried = false;
  foreach (kg_http_multi($urls, $auth) as $id => $m) {
    if (!$m || empty($m['id'])) continue;   // bei Fehler nächstes Mal erneut versuchen
    $seen->execute([$uid, $id, time()]);
    $h = []; foreach ($m['payload']['headers'] ?? [] as $x) $h[strtolower($x['name'])] = $x['value'];
    [$plain, $html] = uw_bodies($m['payload'] ?? []);
    $text = $plain !== '' ? $plain : kg_html_to_text(preg_replace(['#</(td|th)>#i', '#</(span|a|b|strong)>#i'], ['$0' . "\n", '$0 '], $html));
    if (!preg_match('/job alert|matches your alert|view job details/i', $text)) continue;   // andere Upwork-Mails bleiben unangetastet
    $jobs = uw_parse_all($text, $html, (string)($h['subject'] ?? ''));
    if (!$jobs) continue;
    $received = gmdate('c', (int)(($m['internalDate'] ?? 0) / 1000) ?: time());
    foreach ($jobs as $n => $j) {
      // gleicher Job schon aus einer anderen Mail da?
      if ($j['job_key']) {
        $d = $db->prepare('SELECT 1 FROM upwork_jobs WHERE user_id=? AND job_key=? UNION SELECT 1 FROM upwork_deleted WHERE user_id=? AND job_key=?'); $d->execute([$uid, $j['job_key'], $uid, $j['job_key']]);
        if ($d->fetchColumn()) continue;   // schon da oder von dir gelöscht
      } elseif ($deep) { $d = $db->prepare('SELECT 1 FROM upwork_jobs WHERE user_id=? AND msg_id=?'); $d->execute([$uid, $n ? $id . '#' . $n : $id]); if ($d->fetchColumn()) continue; }
      $reason = uw_filter_reason($j, $s);
      $ins->execute([$uid, $n ? $id . '#' . $n : $id, $j['job_key'], $j['url'], $j['title'], $j['kind'], $j['budget'], $j['rate_min'], $j['rate_max'], $j['snippet'], $j['skills'],
        $j['verified'], $j['spent'], $j['country'], $j['rating'] ?? '', $received, $reason ? 'gefiltert' : 'neu', $reason, gmdate('c')]);
      if ($ins->rowCount() < 1) continue;
      $j['id'] = (int)$db->lastInsertId();
      if ($reason) $res['filtered']++; else $res['new'][] = $j;
      uw_log($uid, ($deep ? 'nachgetragen: ' : 'neu: ') . mb_substr($j['title'], 0, 60) . ($reason ? ' (aussortiert: ' . $reason . ')' : ''));
    }
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
  if (!empty($quiet)) $res['new'] = [];
  uw_note($uid, $res);
  return $res;
}
function uw_note(int $uid, array $res): void {
  if ($res['error']) uw_log($uid, 'Fehler: ' . $res['error']);
  kg_set($uid, 'upwork_last', ['ts' => time(), 'new' => count($res['new']), 'filtered' => $res['filtered'], 'error' => $res['error']]);
}
// Datensparsam: nicht beworbene Jobs nach 14 Tagen löschen, beworbene nach 120 Tagen
function uw_cleanup(int $uid): void {
  $db = uw_db();
  $db->prepare("DELETE FROM upwork_jobs WHERE user_id=? AND status NOT IN ('beworben','gemerkt') AND received < ?")->execute([$uid, gmdate('c', time() - 14 * 86400)]);
  $db->prepare("DELETE FROM upwork_jobs WHERE user_id=? AND status='gemerkt' AND received < ?")->execute([$uid, gmdate('c', time() - 45 * 86400)]);
  $db->prepare("DELETE FROM upwork_jobs WHERE user_id=? AND received < ?")->execute([$uid, gmdate('c', time() - 120 * 86400)]);
  $db->prepare('DELETE FROM upwork_seen WHERE user_id=? AND ts < ?')->execute([$uid, time() - 7 * 86400]);
  $db->prepare('DELETE FROM upwork_deleted WHERE user_id=? AND ts < ?')->execute([$uid, time() - 30 * 86400]);
}

// Push-Nachrichten für neue Jobs
function uw_push_messages(array $jobs): array {
  $line = fn($j) => trim(implode(' · ', array_filter([($j['kind'] === 'Stundensatz' ? 'Std. ' : '') . $j['budget'], $j['country'], $j['spent'] ? $j['spent'] . ' ausgegeben' : ''])));
  if (count($jobs) <= 3) return array_map(fn($j) => ['title' => '💼 ' . $j['title'], 'body' => $line($j) ?: 'Neuer Upwork-Job', 'url' => './?open=upwork&job=' . $j['id'], 'tag' => 'upwork-' . $j['id']], $jobs);
  return [['title' => '💼 ' . count($jobs) . ' neue Upwork-Jobs', 'body' => implode("\n", array_map(fn($j) => $j['title'], array_slice($jobs, 0, 4))), 'url' => './?open=upwork', 'tag' => 'upwork']];
}

/* ---------- Screening-Antworten: als JSON-Liste [{question, answer}] gespeichert ---------- */
// Alte Freitext-Fassung („Frage“ / darunter Antwort, Leerzeile dazwischen) in Paare zerlegen
function uw_answers_list(string $stored): array {
  $stored = trim($stored); if ($stored === '') return [];
  $j = json_decode($stored, true);
  if (is_array($j)) return array_values(array_filter(array_map(fn($x) => ['question' => trim((string)($x['question'] ?? '')), 'answer' => (string)($x['answer'] ?? '')], $j), fn($x) => $x['question'] !== '' || trim($x['answer']) !== ''));
  $out = [];
  foreach (preg_split('/\n\s*\n/', str_replace("\r", '', $stored)) as $block) {
    $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), 'strlen')); if (!$lines) continue;
    $q = preg_replace('/^(q|f|frage|question)\s*[:.)]\s*/i', '', array_shift($lines));
    $a = preg_replace('/^(a|antwort|answer)\s*[:.)]\s*/i', '', implode("\n", $lines));
    $out[] = ['question' => $q, 'answer' => $a];
  }
  return $out;
}
// Eingabe (Liste oder Text) als JSON-Liste speichern
function uw_answers_json($in): string {
  if (is_string($in)) $list = uw_answers_list($in);
  elseif (is_array($in)) $list = uw_answers_list(json_encode($in, JSON_UNESCAPED_UNICODE));
  else $list = [];
  foreach ($list as &$x) { $x['question'] = mb_substr($x['question'], 0, 1000); $x['answer'] = mb_substr($x['answer'], 0, 5000); } unset($x);
  return $list ? json_encode(array_slice($list, 0, 20), JSON_UNESCAPED_UNICODE) : '';
}

/* ---------- Google Sheet: Blatt „Upwork Bewerbungen“ ---------- */
const UW_SHEET_TAB = 'Upwork Bewerbungen';
// Job als beworben vormerken bzw. wieder entfernen
function uw_applied_set(int $uid, array $job, bool $on): void {
  $db = uw_db(); $key = (string)($job['job_key'] ?: ('id' . $job['id']));
  if ($on) $db->prepare('INSERT OR IGNORE INTO upwork_applied(user_id,job_key,url,title,budget,country,source,applied_at) VALUES(?,?,?,?,?,?,?,?)')
    ->execute([$uid, $key, $job['url'], $job['title'], $job['budget'], $job['country'], str_starts_with((string)$job['msg_id'], 'manual:') ? 'Manuell' : 'Job-Alert', (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i')]);
  else $db->prepare('DELETE FROM upwork_applied WHERE user_id=? AND job_key=?')->execute([$uid, $key]);
}
// Blatt komplett aus dem Log neu schreiben. Liefert ['ok'=>bool,'msg'=>string]
function uw_sheet_sync(int $uid): array {
  $sh = kg_get($uid, 'upwork_sheet');
  if (empty($sh['id'])) return ['ok' => false, 'msg' => 'Kein Google Sheet verknüpft'];
  $at = n_google_token($uid); if ($at === '') return ['ok' => false, 'msg' => 'Google nicht verbunden'];
  $h = ['Authorization: Bearer ' . $at, 'Content-Type: application/json'];
  $base = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($sh['id']);
  $r = kg_http('GET', $base . '?fields=sheets.properties', $h);
  if (($r['code'] ?? 0) >= 400 || ($r['code'] ?? 0) === 0) return ['ok' => false, 'msg' => 'Sheet nicht erreichbar (' . ($r['json']['error']['message'] ?? $r['code']) . ')'];
  $sid = null; foreach (($r['json']['sheets'] ?? []) as $x) if (($x['properties']['title'] ?? '') === UW_SHEET_TAB) $sid = $x['properties']['sheetId'];
  if ($sid === null) {
    $r = kg_http('POST', $base . ':batchUpdate', $h, json_encode(['requests' => [['addSheet' => ['properties' => ['title' => UW_SHEET_TAB, 'gridProperties' => ['frozenRowCount' => 1]]]]]]));
    if (($r['code'] ?? 0) >= 400) return ['ok' => false, 'msg' => 'Blatt konnte nicht angelegt werden'];
    $sid = $r['json']['replies'][0]['addSheet']['properties']['sheetId'] ?? null;
  }
  $st = uw_db()->prepare('SELECT * FROM upwork_applied WHERE user_id=? ORDER BY applied_at'); $st->execute([$uid]);
  $rows = [['Datum', 'Job', 'URL', 'Budget', 'Land', 'Quelle']];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $rows[] = [$a['applied_at'], (string)$a['title'], (string)$a['url'], (string)$a['budget'], (string)$a['country'], (string)$a['source']];
  $range = rawurlencode("'" . UW_SHEET_TAB . "'!A:F");
  kg_http('POST', $base . '/values/' . $range . ':clear', $h, '{}');
  $r = kg_http('PUT', $base . '/values/' . rawurlencode("'" . UW_SHEET_TAB . "'!A1") . '?valueInputOption=RAW', $h, json_encode(['values' => $rows], JSON_UNESCAPED_UNICODE));
  if (($r['code'] ?? 0) >= 400) return ['ok' => false, 'msg' => 'Schreiben ins Sheet fehlgeschlagen'];
  if ($sid !== null) kg_http('POST', $base . ':batchUpdate', $h, json_encode(['requests' => [
    ['repeatCell' => ['range' => ['sheetId' => $sid, 'startRowIndex' => 0, 'endRowIndex' => 1], 'cell' => ['userEnteredFormat' => ['textFormat' => ['bold' => true], 'backgroundColor' => ['red' => 0.88, 'green' => 0.97, 'blue' => 0.96]]], 'fields' => 'userEnteredFormat(textFormat,backgroundColor)']],
    ['autoResizeDimensions' => ['dimensions' => ['sheetId' => $sid, 'dimension' => 'COLUMNS', 'startIndex' => 0, 'endIndex' => 6]]]]]));
  return ['ok' => true, 'msg' => 'Im Sheet eingetragen ✓', 'rows' => count($rows) - 1];
}
