<?php
// Google Workspace: Kalender + Gmail (OAuth, Tokens verschlüsselt auf dem Server)
require __DIR__ . '/lib.php';
$action = $_GET['action'] ?? 'status';
const G_SCOPES = 'openid email https://www.googleapis.com/auth/calendar https://www.googleapis.com/auth/gmail.modify https://www.googleapis.com/auth/gmail.send';

function g_redirect_uri(): string { return kg_base_url() . '/api/google.php?action=callback'; }

// OAuth-Rücksprung: Sitzung ist per Cookie vorhanden (SameSite=Strict wird bei Top-Level-Redirect von Google nicht mitgeschickt -> Lax-Fallback über state)
if ($action === 'callback') {
  $state = (string)($_GET['state'] ?? '');
  $st = kg_db(); kg_settings_init();
  // state -> user (state wurde beim Start pro Nutzer gespeichert)
  $uid = null;
  foreach ($st->query('SELECT DISTINCT user_id FROM settings')->fetchAll(PDO::FETCH_COLUMN) as $u) {
    $s = kg_get((int)$u, 'google_state');
    if ($s && hash_equals($s['v'], $state) && $s['exp'] > time()) { $uid = (int)$u; break; }
  }
  if ($uid === null) { http_response_code(400); exit('Ungültiger oder abgelaufener Anmeldevorgang. Bitte in Kongrow erneut verbinden.'); }
  kg_set($uid, 'google_state', null);
  if (!empty($_GET['error'])) { kg_back_to_app('open=settings&google=error'); }
  $cfg = kg_get($uid, 'google_cfg');
  $r = kg_http('POST', 'https://oauth2.googleapis.com/token', [], [
    'code' => (string)($_GET['code'] ?? ''), 'client_id' => $cfg['client_id'], 'client_secret' => $cfg['client_secret'],
    'redirect_uri' => g_redirect_uri(), 'grant_type' => 'authorization_code'
  ]);
  $j = $r['json'] ?? [];
  if (empty($j['access_token'])) { kg_back_to_app('open=settings&google=error'); }
  $tok = kg_get($uid, 'google_tok', []);
  $tok['access'] = $j['access_token']; $tok['exp'] = time() + (int)($j['expires_in'] ?? 3500) - 60;
  if (!empty($j['refresh_token'])) $tok['refresh'] = $j['refresh_token'];
  if (!empty($j['id_token'])) { $p = explode('.', $j['id_token']); $pl = json_decode(base64_decode(strtr($p[1] ?? '', '-_', '+/')), true); if (!empty($pl['email'])) $tok['email'] = $pl['email']; }
  kg_set($uid, 'google_tok', $tok);
  kg_back_to_app('open=settings&google=ok');
}

$uid = kg_require_user();
$in = kg_input();

function g_token(int $uid): string {
  $tok = kg_get($uid, 'google_tok');
  if (!$tok || empty($tok['refresh'])) kg_json(['error' => ['type' => 'NOT_CONNECTED', 'message' => 'Google ist nicht verbunden.']], 409);
  if (!empty($tok['access']) && ($tok['exp'] ?? 0) > time()) return $tok['access'];
  $cfg = kg_get($uid, 'google_cfg');
  $r = kg_http('POST', 'https://oauth2.googleapis.com/token', [], [
    'client_id' => $cfg['client_id'], 'client_secret' => $cfg['client_secret'], 'refresh_token' => $tok['refresh'], 'grant_type' => 'refresh_token'
  ]);
  $j = $r['json'] ?? [];
  if (empty($j['access_token'])) kg_json(['error' => ['type' => 'NOT_CONNECTED', 'message' => 'Google-Verbindung abgelaufen. Bitte neu verbinden.']], 409);
  $tok['access'] = $j['access_token']; $tok['exp'] = time() + (int)($j['expires_in'] ?? 3500) - 60;
  kg_set($uid, 'google_tok', $tok);
  return $tok['access'];
}
function g_api(int $uid, string $method, string $url, $body = null): array {
  $h = ['Authorization: Bearer ' . g_token($uid)];
  if ($body !== null) { $h[] = 'Content-Type: application/json'; $body = json_encode($body, JSON_UNESCAPED_UNICODE); }
  $r = kg_http($method, $url, $h, $body);
  if ($r['code'] >= 400) kg_json(['error' => ['message' => 'Google: ' . (($r['json']['error']['message'] ?? '') ?: ('Fehler ' . $r['code']))]], 502);
  return $r['json'] ?? [];
}
function b64url_dec(string $s): string { return base64_decode(strtr($s, '-_', '+/')); }

switch ($action) {
  case 'status':
    $cfg = kg_get($uid, 'google_cfg'); $tok = kg_get($uid, 'google_tok');
    kg_json(['configured' => !empty($cfg['client_id']), 'connected' => !empty($tok['refresh']), 'email' => $tok['email'] ?? null, 'redirect_uri' => g_redirect_uri()]);

  case 'config':
    $cid = trim((string)($in['client_id'] ?? '')); $sec = trim((string)($in['client_secret'] ?? ''));
    if (!preg_match('/\.apps\.googleusercontent\.com$/', $cid) || strlen($sec) < 10) kg_json(['error' => ['message' => 'Client-ID oder Secret sieht nicht richtig aus.']], 400);
    kg_set($uid, 'google_cfg', ['client_id' => $cid, 'client_secret' => $sec]);
    kg_json(['ok' => true]);

  case 'connect':
    $cfg = kg_get($uid, 'google_cfg');
    if (empty($cfg['client_id'])) kg_json(['error' => ['message' => 'Zuerst Client-ID und Secret eintragen.']], 400);
    $state = bin2hex(random_bytes(16));
    kg_set($uid, 'google_state', ['v' => $state, 'exp' => time() + 900]);
    kg_json(['url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
      'client_id' => $cfg['client_id'], 'redirect_uri' => g_redirect_uri(), 'response_type' => 'code', 'scope' => G_SCOPES,
      'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state
    ])]);

  case 'disconnect':
    $tok = kg_get($uid, 'google_tok');
    if (!empty($tok['refresh'])) kg_http('POST', 'https://oauth2.googleapis.com/revoke', [], ['token' => $tok['refresh']]);
    kg_set($uid, 'google_tok', null);
    kg_json(['ok' => true]);

  /* ---------- Kalender ---------- */
  case 'calendars':
    $j = g_api($uid, 'GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250');
    $hidden = kg_get($uid, 'gcal_hidden', []);
    $out = [];
    foreach ($j['items'] ?? [] as $c) $out[] = ['id' => $c['id'], 'name' => $c['summaryOverride'] ?? $c['summary'] ?? $c['id'], 'color' => $c['backgroundColor'] ?? '#22E3D3',
      'primary' => !empty($c['primary']), 'writable' => in_array($c['accessRole'] ?? '', ['owner', 'writer'], true), 'visible' => !in_array($c['id'], $hidden, true)];
    usort($out, fn($a, $b) => ($b['primary'] <=> $a['primary']) ?: strcasecmp($a['name'], $b['name']));
    kg_json(['calendars' => $out]);

  case 'calendars_visibility':
    kg_set($uid, 'gcal_hidden', array_values(array_filter((array)($in['hidden'] ?? []), 'is_string')));
    kg_json(['ok' => true]);

  case 'events':
    $from = (string)($_GET['from'] ?? ''); $to = (string)($_GET['to'] ?? '');
    if (!$from || !$to) kg_json(['error' => ['message' => 'from/to fehlen']], 400);
    $cals = g_api($uid, 'GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250')['items'] ?? [];
    $hidden = kg_get($uid, 'gcal_hidden', []);
    $urls = []; $meta = [];
    foreach ($cals as $c) {
      if (in_array($c['id'], $hidden, true)) continue;
      $urls[$c['id']] = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($c['id']) . '/events?' . http_build_query(['timeMin' => $from, 'timeMax' => $to, 'singleEvents' => 'true', 'orderBy' => 'startTime', 'maxResults' => 500]);
      $meta[$c['id']] = ['name' => $c['summaryOverride'] ?? $c['summary'] ?? '', 'color' => $c['backgroundColor'] ?? '#22E3D3', 'writable' => in_array($c['accessRole'] ?? '', ['owner', 'writer'], true)];
    }
    $res = kg_http_multi($urls, ['Authorization: Bearer ' . g_token($uid)]);
    $events = [];
    foreach ($res as $cid => $j) foreach (($j['items'] ?? []) as $e) {
      if (($e['status'] ?? '') === 'cancelled') continue;
      $allDay = isset($e['start']['date']);
      $events[] = ['id' => $e['id'], 'calendarId' => $cid, 'calendar' => $meta[$cid]['name'], 'color' => $meta[$cid]['color'], 'writable' => $meta[$cid]['writable'],
        'title' => $e['summary'] ?? '(ohne Titel)', 'start' => $allDay ? $e['start']['date'] : ($e['start']['dateTime'] ?? ''), 'end' => $allDay ? $e['end']['date'] : ($e['end']['dateTime'] ?? ''),
        'allDay' => $allDay, 'location' => $e['location'] ?? '', 'description' => $e['description'] ?? '', 'link' => $e['htmlLink'] ?? '', 'meet' => $e['hangoutLink'] ?? ''];
    }
    usort($events, fn($a, $b) => strcmp($a['start'], $b['start']));
    kg_json(['events' => $events]);

  case 'event_save':
    $cid = (string)($in['calendarId'] ?? 'primary'); $id = (string)($in['id'] ?? '');
    $ev = ['summary' => (string)($in['title'] ?? ''), 'location' => (string)($in['location'] ?? ''), 'description' => (string)($in['description'] ?? '')];
    if (!empty($in['allDay'])) { $ev['start'] = ['date' => $in['start']]; $ev['end'] = ['date' => $in['end']]; }
    else { $ev['start'] = ['dateTime' => $in['start'], 'timeZone' => 'Europe/Berlin']; $ev['end'] = ['dateTime' => $in['end'], 'timeZone' => 'Europe/Berlin']; }
    if (array_key_exists('reminder', $in)) {
      $ev['reminders'] = ($in['reminder'] === '' || $in['reminder'] === null) ? ['useDefault' => true]
        : ['useDefault' => false, 'overrides' => ((int)$in['reminder'] < 0) ? [] : [['method' => 'popup', 'minutes' => (int)$in['reminder']]]];
    }
    $base = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cid) . '/events';
    $j = $id ? g_api($uid, 'PATCH', $base . '/' . rawurlencode($id), $ev) : g_api($uid, 'POST', $base, $ev);
    kg_json(['ok' => true, 'id' => $j['id'] ?? null]);

  case 'event_delete':
    $cid = (string)($in['calendarId'] ?? 'primary'); $id = (string)($in['id'] ?? '');
    g_api($uid, 'DELETE', 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cid) . '/events/' . rawurlencode($id));
    kg_json(['ok' => true]);

  /* ---------- Gmail ---------- */
  case 'mail_list':
    $max = min(50, max(5, (int)($_GET['max'] ?? 30)));
    $q = (string)($_GET['q'] ?? 'in:inbox');
    $list = g_api($uid, 'GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages?' . http_build_query(['maxResults' => $max, 'q' => $q]));
    $urls = [];
    foreach ($list['messages'] ?? [] as $m) $urls[$m['id']] = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $m['id'] . '?format=metadata&metadataHeaders=From&metadataHeaders=Subject&metadataHeaders=Date';
    $res = kg_http_multi($urls, ['Authorization: Bearer ' . g_token($uid)]);
    $tok = kg_get($uid, 'google_tok');
    $out = [];
    foreach ($res as $id => $m) {
      if (!$m || empty($m['id'])) continue;
      $h = []; foreach ($m['payload']['headers'] ?? [] as $x) $h[strtolower($x['name'])] = $x['value'];
      $out[] = ['source' => 'gmail', 'account' => $tok['email'] ?? 'Google', 'id' => $m['id'], 'thread' => $m['threadId'] ?? '', 'from' => $h['from'] ?? '', 'subject' => $h['subject'] ?? '(kein Betreff)',
        'snippet' => html_entity_decode($m['snippet'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'date' => gmdate('c', (int)(($m['internalDate'] ?? 0) / 1000)), 'unread' => in_array('UNREAD', $m['labelIds'] ?? [], true)];
    }
    usort($out, fn($a, $b) => strcmp($b['date'], $a['date']));
    kg_json(['messages' => $out]);

  case 'mail_get':
    $id = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['id'] ?? ''));
    $m = g_api($uid, 'GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $id . '?format=full');
    $h = []; foreach ($m['payload']['headers'] ?? [] as $x) $h[strtolower($x['name'])] = $x['value'];
    $plain = ''; $html = '';
    $walk = function ($p) use (&$walk, &$plain, &$html) {
      $mt = $p['mimeType'] ?? '';
      if (!empty($p['body']['data'])) {
        if ($mt === 'text/plain' && $plain === '') $plain = b64url_dec($p['body']['data']);
        if ($mt === 'text/html' && $html === '') $html = b64url_dec($p['body']['data']);
      }
      foreach ($p['parts'] ?? [] as $c) $walk($c);
    };
    $walk($m['payload'] ?? []);
    if (in_array('UNREAD', $m['labelIds'] ?? [], true)) g_api($uid, 'POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $id . '/modify', ['removeLabelIds' => ['UNREAD']]);
    kg_json(['id' => $id, 'thread' => $m['threadId'] ?? '', 'from' => $h['from'] ?? '', 'to' => $h['to'] ?? '', 'cc' => $h['cc'] ?? '', 'subject' => $h['subject'] ?? '', 'date' => $h['date'] ?? '',
      'messageId' => $h['message-id'] ?? '', 'references' => $h['references'] ?? '', 'replyTo' => $h['reply-to'] ?? '', 'text' => $plain !== '' ? $plain : kg_html_to_text($html)]);

  case 'mail_send':
    $tok = kg_get($uid, 'google_tok');
    $to = trim((string)($in['to'] ?? '')); $subject = (string)($in['subject'] ?? ''); $body = (string)($in['body'] ?? '');
    if (!$to || trim($body) === '') kg_json(['error' => ['message' => 'Empfänger oder Text fehlt.']], 400);
    $hdr = ['From: ' . ($tok['email'] ?? ''), 'To: ' . $to, 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'B'), 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64'];
    if (!empty($in['inReplyTo'])) { $hdr[] = 'In-Reply-To: ' . $in['inReplyTo']; $hdr[] = 'References: ' . trim(($in['references'] ?? '') . ' ' . $in['inReplyTo']); }
    $raw = implode("\r\n", $hdr) . "\r\n\r\n" . chunk_split(base64_encode($body));
    $payload = ['raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=')];
    if (!empty($in['thread'])) $payload['threadId'] = $in['thread'];
    g_api($uid, 'POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', $payload);
    kg_json(['ok' => true]);
}
kg_json(['error' => ['message' => 'Unbekannte Aktion']], 400);
