<?php
// Sprachsteuerung auf dem Server (für Siri / Kurzbefehle / Aktionstaste).
//   In der App (Sitzung):   GET ?action=cfg | POST ?action=set_key {key} | POST ?action=del_key | POST ?action=siri_new | POST ?action=siri_revoke
//   Siri (Kurzbefehl):      POST ?action=run  Header "Authorization: Bearer kgs…"  Body {"text":"…"}  -> Text zum Vorlesen
require __DIR__ . '/lib.php';
date_default_timezone_set('Europe/Berlin');
$action = (string)($_GET['action'] ?? 'cfg');
$in = kg_input();
if (!$in && isset($_POST['text'])) $in = $_POST;

const A_BASE = 'appFqTB9ogHBBIFU9';     // Routinen-Base (Aufgaben)
const A_TBL  = 'tblibWJMIvlTBELVO';     // Aufgaben
const L_BASE = 'appb0On2W6yCzWj8w';     // Lesen
const L_BOOKS = 'tblINhCe4txqYtTcq';
const L_ENTRIES = 'tbla604W3lFpBoPFJ';

function a_text_out(string $s, int $code = 200): void {
  http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); echo $s; exit;
}
// Nutzer über Siri-Schlüssel finden (Format kgs<uid>_<zufall>, gespeichert wird nur der Hash)
function a_user_by_key(string $key): ?int {
  if (!preg_match('/^kgs(\d+)_([A-Za-z0-9]{32})$/', $key, $m)) return null;
  $uid = (int)$m[1];
  $h = kg_get($uid, 'siri_key');
  if (!$h || empty($h['hash'])) return null;
  return hash_equals($h['hash'], hash('sha256', $key)) ? $uid : null;
}

/* ---------- App-Einstellungen (nur mit Sitzung) ---------- */
if ($action !== 'run') {
  $uid = kg_require_user();
  switch ($action) {
    case 'cfg':
      $s = kg_get($uid, 'siri_key');
      kg_json(['hasKey' => (bool)kg_get($uid, 'anthropic_key'), 'siri' => (bool)$s, 'siriSince' => $s['since'] ?? null, 'url' => kg_base_url() . '/api/assistant.php?action=run']);
    case 'set_key':
      $k = trim((string)($in['key'] ?? ''));
      if (!preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,}$/', $k)) kg_json(['error' => ['message' => 'Das sieht nicht nach einem Anthropic-Schlüssel aus.']], 400);
      kg_set($uid, 'anthropic_key', ['v' => $k]);
      kg_json(['ok' => true]);
    case 'del_key':
      kg_set($uid, 'anthropic_key', null); kg_json(['ok' => true]);
    case 'siri_new':
      $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'; $r = '';
      for ($i = 0; $i < 32; $i++) $r .= $a[random_int(0, 61)];
      $key = 'kgs' . $uid . '_' . $r;
      kg_set($uid, 'siri_key', ['hash' => hash('sha256', $key), 'since' => date('Y-m-d')]);
      kg_json(['ok' => true, 'key' => $key]);   // wird nur dieses eine Mal angezeigt
    case 'siri_revoke':
      kg_set($uid, 'siri_key', null); kg_json(['ok' => true]);
  }
  kg_json(['error' => ['message' => 'Unbekannte Aktion']], 400);
}

/* ---------- Ausführen (Siri) ---------- */
$hdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$key = preg_match('/Bearer\s+(\S+)/i', $hdr, $mm) ? $mm[1] : (string)($_GET['key'] ?? ($in['key'] ?? ''));
$uid = $key !== '' ? a_user_by_key($key) : kg_current_user();
if (!$uid) {
  if (kg_too_many_fails()) a_text_out('Zu viele Versuche. Bitte später nochmal.', 429);
  kg_note_fail();
  a_text_out('Kongrow: Schlüssel ungültig. Bitte in der App unter Einstellungen → Siri einen neuen Schlüssel erstellen.', 401);
}
$text = trim((string)($in['text'] ?? $_GET['text'] ?? ''));
if ($text === '') a_text_out('Ich habe nichts verstanden. Sag es bitte nochmal.');
$ak = kg_get($uid, 'anthropic_key');
if (!$ak || empty($ak['v'])) a_text_out('In Kongrow fehlt noch der Anthropic-Schlüssel auf dem Server. Bitte in den Einstellungen unter Siri hinterlegen.');
$db = kg_db();

function a_records(PDO $db, int $uid, string $base, string $tbl): array {
  $st = $db->prepare('SELECT id, fields, created FROM records WHERE user_id=? AND base=? AND tbl=? ORDER BY created, rowid');
  $st->execute([$uid, $base, $tbl]);
  return array_map(fn($r) => ['id' => $r['id'], 'created' => $r['created'], 'f' => json_decode($r['fields'], true) ?: []], $st->fetchAll(PDO::FETCH_ASSOC));
}
function a_new_id(): string { $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'; $s = 'rec'; for ($i = 0; $i < 14; $i++) $s .= $a[random_int(0, 61)]; return $s; }
function a_insert(PDO $db, int $uid, string $base, string $tbl, array $fields): string {
  $fields = array_filter($fields, fn($v) => !($v === null || $v === '' || $v === false || $v === []));
  $id = a_new_id();
  $db->prepare('INSERT INTO records(id,user_id,base,tbl,fields,created) VALUES(?,?,?,?,?,?)')
     ->execute([$id, $uid, $base, $tbl, json_encode($fields, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d\TH:i:s.000\Z')]);
  return $id;
}
function a_patch(PDO $db, int $uid, string $id, array $fields): void {
  $st = $db->prepare('SELECT fields FROM records WHERE id=? AND user_id=?'); $st->execute([$id, $uid]);
  $cur = json_decode((string)$st->fetchColumn(), true) ?: [];
  foreach ($fields as $k => $v) { if ($v === null || $v === '' || $v === false) unset($cur[$k]); else $cur[$k] = $v; }
  $db->prepare('UPDATE records SET fields=? WHERE id=? AND user_id=?')->execute([json_encode($cur, JSON_UNESCAPED_UNICODE), $id, $uid]);
}
// Google-Zugang (ohne Abbruch bei Fehlern)
function a_gtoken(int $uid): ?string {
  $tok = kg_get($uid, 'google_tok');
  if (!$tok || empty($tok['refresh'])) return null;
  if (!empty($tok['access']) && ($tok['exp'] ?? 0) > time()) return $tok['access'];
  $cfg = kg_get($uid, 'google_cfg');
  $r = kg_http('POST', 'https://oauth2.googleapis.com/token', [], ['client_id' => $cfg['client_id'] ?? '', 'client_secret' => $cfg['client_secret'] ?? '', 'refresh_token' => $tok['refresh'], 'grant_type' => 'refresh_token']);
  $j = $r['json'] ?? [];
  if (empty($j['access_token'])) return null;
  $tok['access'] = $j['access_token']; $tok['exp'] = time() + (int)($j['expires_in'] ?? 3500) - 60;
  kg_set($uid, 'google_tok', $tok);
  return $tok['access'];
}

$today = date('Y-m-d');
$WD = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
$tasks = array_values(array_filter(a_records($db, $uid, A_BASE, A_TBL), fn($t) => empty($t['f']['Erledigt am'])));
$taskById = []; foreach ($tasks as $t) $taskById[$t['id']] = $t;
$lines = [];
foreach (array_slice($tasks, -80) as $t) {
  $f = $t['f']; $heute = (($f['Heute'] ?? '') === $today) || (!empty($f['Faellig']) && $f['Faellig'] <= $today);
  $lines[] = '- id=' . $t['id'] . ' | ' . ($f['Titel'] ?? '') . ' | Prio ' . ($f['Prio'] ?? 'B') . ' | ' . ($f['Bereich'] ?? '') . (!empty($f['Faellig']) ? ' | fällig ' . $f['Faellig'] : '') . ($heute ? ' | HEUTE' : '') . (!empty($f['Termin']) ? ' | Termin ' . $f['Termin'] : '');
}
$gtok = a_gtoken($uid);
$evLines = '(Google-Kalender nicht verbunden)';
if ($gtok) {
  $from = (new DateTime('today'))->format(DATE_RFC3339); $to = (new DateTime('today +8 days'))->format(DATE_RFC3339);
  $r = kg_http('GET', 'https://www.googleapis.com/calendar/v3/calendars/primary/events?' . http_build_query(['timeMin' => $from, 'timeMax' => $to, 'singleEvents' => 'true', 'orderBy' => 'startTime', 'maxResults' => 60]), ['Authorization: Bearer ' . $gtok]);
  $ev = [];
  foreach (($r['json']['items'] ?? []) as $e) {
    if (($e['status'] ?? '') === 'cancelled') continue;
    $ev[] = isset($e['start']['date']) ? '- ' . $e['start']['date'] . ' ganztags | ' . ($e['summary'] ?? '') : '- ' . substr(str_replace('T', ' ', $e['start']['dateTime'] ?? ''), 0, 16) . '–' . substr($e['end']['dateTime'] ?? '', 11, 5) . ' | ' . ($e['summary'] ?? '');
  }
  $evLines = $ev ? implode("\n", $ev) : '(keine)';
}
// aktuelles Buch = nicht fertiges Buch mit dem neuesten Eintrag
$books = array_values(array_filter(a_records($db, $uid, L_BASE, L_BOOKS), fn($b) => empty($b['f']['Abgeschlossen'])));
$book = null;
if ($books) {
  $last = []; foreach (a_records($db, $uid, L_BASE, L_ENTRIES) as $e) { $b = $e['f']['BuchId'] ?? ''; $c = $e['f']['Erstellt am'] ?? $e['created']; if (!isset($last[$b]) || $c > $last[$b]) $last[$b] = $c; }
  usort($books, fn($a, $b) => strcmp($last[$b['id']] ?? $b['created'], $last[$a['id']] ?? $a['created']));
  $book = $books[0];
}
$ctx = 'Jetzt: ' . $WD[(int)date('w')] . ', ' . date('Y-m-d H:i') . " (Europe/Berlin).\nOffene Aufgaben:\n" . ($lines ? implode("\n", $lines) : '(keine)') . "\nTermine nächste 7 Tage:\n" . $evLines . "\nAktuelles Buch: " . ($book ? ($book['f']['Titel'] ?? '') : '(keins)');

$prompt = <<<P
Du bist die Sprachsteuerung von Dominics App „Kongrow“, aufgerufen über Siri. Er spricht frei, du machst daraus Aktionen. Sie werden sofort ausgeführt, also nur eindeutige Aktionen. Antworte NUR mit JSON:
{"antwort":"1 bis 2 kurze Sätze zum Vorlesen, du-Form, ohne Emojis und ohne Markdown","aktionen":[ ... ]}
Mögliche Aktionen (Datum YYYY-MM-DD, Zeit HH:MM, relativ zu "Jetzt" rechnen: "morgen", "Freitag" = nächster Freitag, "nächste Woche" = Montag):
{"typ":"aufgabe","titel":"…","notiz":"","prio":"A|B|C","bereich":"Business|Privat","faellig":"","heute":false,"termin":null}
  Wenn er "auch in den Kalender" sagt oder eine Uhrzeit nennt: "termin":{"datum":"…","zeit":"…","dauer":60}. Fällig = Datum des Termins. heute=true nur wenn er "heute" sagt. prio A = wichtig/dringend, C = irgendwann, sonst B. bereich Business bei Kunden/Arbeit/Agentur/Akquise, sonst Privat.
{"typ":"termin","titel":"…","datum":"…","zeit":"…","dauer":60,"ganztags":false,"ort":"","notiz":""}
{"typ":"erledigt","id":"…"}  (id aus der Aufgabenliste, nur wenn eindeutig)
{"typ":"aendern","id":"…","faellig":"","heute":null,"prio":""}
{"typ":"lesenotiz","text":"…","seite":""}
Regeln: Mehrere Wünsche = mehrere Aktionen. Fragen beantwortest du nur in "antwort" aus dem Kontext. Mails, Öffnen und alles andere: sag kurz, dass das nur in der App geht. Titel kurz, in seinen Worten. Erfinde nichts. Unklar: nachfragen, keine Aktion. Keine Gedankenstriche.
P;
$r = kg_http('POST', 'https://api.anthropic.com/v1/messages', ['Content-Type: application/json', 'x-api-key: ' . $ak['v'], 'anthropic-version: 2023-06-01'],
  json_encode(['model' => 'claude-sonnet-4-6', 'max_tokens' => 1500, 'messages' => [['role' => 'user', 'content' => $prompt . "\n\nKONTEXT\n" . $ctx . "\n\nDominic sagt: " . $text]]], JSON_UNESCAPED_UNICODE), 40);
if ($r['code'] >= 400 || empty($r['json']['content'])) a_text_out('Die KI ist gerade nicht erreichbar. ' . (string)($r['json']['error']['message'] ?? ''));
$out = ''; foreach ($r['json']['content'] as $b) if (($b['type'] ?? '') === 'text') $out .= $b['text'];
$j = preg_match('/\{[\s\S]*\}/', $out, $m) ? json_decode($m[0], true) : null;
if (!is_array($j)) a_text_out('Das habe ich nicht verstanden. Sag es bitte nochmal anders.');

$okDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
$calEvent = function (string $title, string $datum, string $zeit, $dauer, bool $ganz, string $ort, string $notiz) use ($gtok, $okDate) {
  if (!$gtok) return [null, 'Google-Kalender nicht verbunden'];
  if (!$okDate($datum)) return [null, 'kein gültiges Datum'];
  if ($ganz) { $s = ['date' => $datum]; $e = ['date' => date('Y-m-d', strtotime($datum . ' +1 day'))]; $startStr = $datum; }
  else {
    $z = preg_match('/^\d{1,2}:\d{2}$/', $zeit) ? str_pad($zeit, 5, '0', STR_PAD_LEFT) : '09:00';
    $st = new DateTime($datum . ' ' . $z); $en = (clone $st)->modify('+' . max(5, (int)$dauer ?: 60) . ' minutes');
    $s = ['dateTime' => $st->format('Y-m-d\TH:i:s'), 'timeZone' => 'Europe/Berlin']; $e = ['dateTime' => $en->format('Y-m-d\TH:i:s'), 'timeZone' => 'Europe/Berlin']; $startStr = $st->format('Y-m-d\TH:i:s');
  }
  $res = kg_http('POST', 'https://www.googleapis.com/calendar/v3/calendars/primary/events', ['Authorization: Bearer ' . $gtok, 'Content-Type: application/json'],
    json_encode(['summary' => $title, 'location' => $ort, 'description' => $notiz, 'start' => $s, 'end' => $e], JSON_UNESCAPED_UNICODE));
  if ($res['code'] >= 400) return [null, 'Kalender-Fehler'];
  return [['id' => $res['json']['id'] ?? '', 'start' => $startStr], null];
};

$done = []; $fails = [];
foreach ((array)($j['aktionen'] ?? []) as $a) {
  if (!is_array($a)) continue;
  $typ = $a['typ'] ?? '';
  if ($typ === 'aufgabe') {
    $titel = trim((string)($a['titel'] ?? '')); if ($titel === '') continue;
    $t = is_array($a['termin'] ?? null) ? $a['termin'] : null;
    $faellig = $okDate($a['faellig'] ?? '') ? $a['faellig'] : ($t && $okDate($t['datum'] ?? '') ? $t['datum'] : '');
    $heute = !empty($a['heute']) && (!$faellig || $faellig <= $today) ? $today : '';
    $fields = ['Titel' => $titel, 'Prio' => in_array($a['prio'] ?? '', ['A','B','C'], true) ? $a['prio'] : 'B', 'Bereich' => ($a['bereich'] ?? '') === 'Business' ? 'Business' : 'Privat',
      'Faellig' => $faellig, 'Heute' => $heute, 'Notiz' => (string)($a['notiz'] ?? ''), 'Quelle' => json_encode(['typ' => 'siri']), 'Erstellt am' => gmdate('Y-m-d\TH:i:s.000\Z')];
    $id = a_insert($db, $uid, A_BASE, A_TBL, $fields);
    $extra = '';
    if ($t) {
      [$ev, $err] = $calEvent($titel, (string)($t['datum'] ?? ''), (string)($t['zeit'] ?? ''), $t['dauer'] ?? 60, false, '', $fields['Notiz']);
      if ($ev) { a_patch($db, $uid, $id, ['EventId' => $ev['id'], 'Termin' => $ev['start']]); $extra = ' mit Termin'; } else $fails[] = 'Kalender: ' . $err;
    }
    $done[] = 'Aufgabe ' . $titel . $extra;
  } elseif ($typ === 'termin') {
    $titel = trim((string)($a['titel'] ?? '')) ?: 'Termin';
    [$ev, $err] = $calEvent($titel, (string)($a['datum'] ?? ''), (string)($a['zeit'] ?? ''), $a['dauer'] ?? 60, !empty($a['ganztags']), (string)($a['ort'] ?? ''), (string)($a['notiz'] ?? ''));
    if ($ev) $done[] = 'Termin ' . $titel; else $fails[] = 'Termin ' . $titel . ': ' . $err;
  } elseif ($typ === 'erledigt') {
    $t = $taskById[(string)($a['id'] ?? '')] ?? null; if (!$t) { $fails[] = 'Aufgabe nicht gefunden'; continue; }
    a_patch($db, $uid, $t['id'], ['Erledigt am' => $today]); $done[] = ($t['f']['Titel'] ?? 'Aufgabe') . ' abgehakt';
  } elseif ($typ === 'aendern') {
    $t = $taskById[(string)($a['id'] ?? '')] ?? null; if (!$t) { $fails[] = 'Aufgabe nicht gefunden'; continue; }
    $f = [];
    if ($okDate($a['faellig'] ?? '')) $f['Faellig'] = $a['faellig'];
    if (($a['heute'] ?? null) === true) $f['Heute'] = $today; elseif (($a['heute'] ?? null) === false) $f['Heute'] = '';
    if (in_array($a['prio'] ?? '', ['A','B','C'], true)) $f['Prio'] = $a['prio'];
    if ($f) { a_patch($db, $uid, $t['id'], $f); $done[] = ($t['f']['Titel'] ?? 'Aufgabe') . ' geändert'; }
  } elseif ($typ === 'lesenotiz') {
    if (!$book) { $fails[] = 'kein aktuelles Buch'; continue; }
    $tx = trim((string)($a['text'] ?? '')); if ($tx === '') continue;
    a_insert($db, $uid, L_BASE, L_ENTRIES, ['Titel' => mb_substr($tx, 0, 60), 'BuchId' => $book['id'], 'Typ' => 'Notiz', 'Seite' => (string)($a['seite'] ?? ''), 'Text' => $tx, 'Erstellt am' => gmdate('Y-m-d\TH:i:s.000\Z')]);
    $done[] = 'Lese-Notiz';
  }
}
// Protokoll für die App (letzte 30)
$log = kg_get($uid, 'siri_log', []); array_unshift($log, ['t' => date('c'), 'text' => $text, 'done' => $done, 'fails' => $fails]); kg_set($uid, 'siri_log', array_slice($log, 0, 30));

$say = trim((string)($j['antwort'] ?? ''));
if ($fails) $say .= ' Hinweis: ' . implode(', ', $fails) . '.';
if ($say === '') $say = $done ? 'Erledigt: ' . implode(', ', $done) . '.' : 'Okay.';
if (($_GET['format'] ?? '') === 'json') kg_json(['say' => $say, 'done' => $done, 'fails' => $fails]);
a_text_out($say);
