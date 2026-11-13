<?php
// Withings-Waage: OAuth + Abgleich der Messungen in die Gewicht-Tabelle
require __DIR__ . '/lib.php';
$action = $_GET['action'] ?? 'status';
const W_BASE = 'appFqTB9ogHBBIFU9';
const W_TBL = 'tbleY0UkcFTlE0kOU';
function w_redirect_uri(): string { return kg_base_url() . '/api/withings.php?action=callback'; }

function w_token_request(int $uid, array $params): ?array {
  $cfg = kg_get($uid, 'withings_cfg');
  $r = kg_http('POST', 'https://wbsapi.withings.net/v2/oauth2', [], array_merge(['action' => 'requesttoken', 'client_id' => $cfg['client_id'], 'client_secret' => $cfg['client_secret']], $params));
  $j = $r['json'] ?? [];
  if (($j['status'] ?? -1) !== 0 || empty($j['body']['access_token'])) {
    // Grund merken, damit die App ihn anzeigen kann (Token selbst bleiben unangetastet)
    $t = kg_get($uid, 'withings_tok', []);
    $GLOBALS['w_net_error'] = !$r['code'] || !is_array($r['json']);
    $why = $GLOBALS['w_net_error'] ? 'Withings nicht erreichbar' . ($r['error'] ? ' (' . $r['error'] . ')' : '') : 'Anmeldung abgelaufen (' . ($j['error'] ?? ('Status ' . ($j['status'] ?? $r['code']))) . ')';
    if (is_array($t) && $t) { $t['lastError'] = $why; $t['lastErrorTs'] = time(); kg_set($uid, 'withings_tok', $t); }
    return null;
  }
  $b = $j['body'];
  $tok = kg_get($uid, 'withings_tok', []);
  unset($tok['lastError'], $tok['lastErrorTs']);
  $tok['access'] = $b['access_token']; $tok['refresh'] = $b['refresh_token'] ?? ($tok['refresh'] ?? '');
  $tok['exp'] = time() + (int)($b['expires_in'] ?? 10800) - 60; $tok['userid'] = $b['userid'] ?? ($tok['userid'] ?? '');
  kg_set($uid, 'withings_tok', $tok);
  return $tok;
}

if ($action === 'callback') {
  // Withings prüft die Callback-URL beim Anlegen der App per Aufruf ohne Parameter
  if (empty($_GET['state']) && empty($_GET['code'])) { header('Content-Type: text/plain'); exit('OK'); }
  $state = (string)($_GET['state'] ?? ''); kg_settings_init(); $uid = null;
  foreach (kg_db()->query('SELECT DISTINCT user_id FROM settings')->fetchAll(PDO::FETCH_COLUMN) as $u) {
    $s = kg_get((int)$u, 'withings_state');
    if ($s && hash_equals($s['v'], $state) && $s['exp'] > time()) { $uid = (int)$u; break; }
  }
  if ($uid === null) { http_response_code(400); exit('Ungültiger oder abgelaufener Anmeldevorgang.'); }
  kg_set($uid, 'withings_state', null);
  $tok = empty($_GET['code']) ? null : w_token_request($uid, ['grant_type' => 'authorization_code', 'code' => (string)$_GET['code'], 'redirect_uri' => w_redirect_uri()]);
  kg_back_to_app('open=settings&withings=' . ($tok ? 'ok' : 'error'));
}

$uid = kg_require_user();
$in = kg_input();

function w_access(int $uid, bool $force = false): string {
  $tok = kg_get($uid, 'withings_tok');
  if (!$tok || empty($tok['refresh'])) kg_json(['error' => ['type' => 'NOT_CONNECTED', 'message' => 'Withings ist nicht verbunden.']], 409);
  if (!$force && ($tok['exp'] ?? 0) > time()) return $tok['access'];
  // Nur ein Erneuern gleichzeitig: Withings tauscht das Refresh-Token bei jedem Erneuern aus
  $lock = fopen(sys_get_temp_dir() . '/kg_withings_' . $uid . '.lock', 'c'); if ($lock) flock($lock, LOCK_EX);
  $cur = kg_get($uid, 'withings_tok');
  if (!$force && ($cur['exp'] ?? 0) > time()) { if ($lock) flock($lock, LOCK_UN); return $cur['access']; }   // hat ein paralleler Aufruf schon erneuert
  if ($force && ($cur['access'] ?? '') !== ($tok['access'] ?? '') && ($cur['exp'] ?? 0) > time()) { if ($lock) flock($lock, LOCK_UN); return $cur['access']; }
  $new = w_token_request($uid, ['grant_type' => 'refresh_token', 'refresh_token' => $cur['refresh']]);
  if ($lock) flock($lock, LOCK_UN);
  if (!$new && !empty($GLOBALS['w_net_error'])) kg_json(['error' => ['message' => 'Withings ist gerade nicht erreichbar. Bitte später erneut versuchen.']], 502);
  if (!$new) kg_json(['error' => ['type' => 'NOT_CONNECTED', 'message' => 'Die Withings-Anmeldung ist abgelaufen. Bitte „Neu verbinden“ tippen.']], 409);
  return $new['access'];
}
function w_fail(int $uid, string $msg, int $code = 502): void {
  $t = kg_get($uid, 'withings_tok', []); if (is_array($t) && $t) { $t['lastError'] = $msg; $t['lastErrorTs'] = time(); kg_set($uid, 'withings_tok', $t); }
  kg_json(['error' => ['message' => $msg]], $code);
}
// Bekannte Withings-Statuscodes verständlich machen
function w_status_text($st): string {
  $m = [100 => 'Anmeldung ungültig', 101 => 'Anmeldung ungültig', 102 => 'Anmeldung ungültig', 200 => 'Anmeldung ungültig', 401 => 'Anmeldung ungültig', 293 => 'Anmeldung ungültig', 2554 => 'Unbekannte Aktion', 601 => 'Zu viele Anfragen, später erneut versuchen', 2555 => 'Withings-Serverfehler'];
  return ($m[(int)$st] ?? 'Fehler') . ' (Status ' . $st . ')';
}

switch ($action) {
  case 'status':
    $cfg = kg_get($uid, 'withings_cfg'); $tok = kg_get($uid, 'withings_tok');
    kg_json(['configured' => !empty($cfg['client_id']), 'connected' => !empty($tok['refresh']), 'lastSync' => $tok['lastSync'] ?? null, 'lastError' => $tok['lastError'] ?? null, 'lastErrorTs' => $tok['lastErrorTs'] ?? null, 'redirect_uri' => w_redirect_uri()]);

  case 'config':
    $cid = trim((string)($in['client_id'] ?? '')); $sec = trim((string)($in['client_secret'] ?? ''));
    if (strlen($cid) < 20 || strlen($sec) < 20) kg_json(['error' => ['message' => 'Client-ID oder Secret sieht nicht richtig aus.']], 400);
    kg_set($uid, 'withings_cfg', ['client_id' => $cid, 'client_secret' => $sec]);
    kg_json(['ok' => true]);

  case 'connect':
    $cfg = kg_get($uid, 'withings_cfg');
    if (empty($cfg['client_id'])) kg_json(['error' => ['message' => 'Zuerst Client-ID und Secret eintragen.']], 400);
    $state = bin2hex(random_bytes(16)); kg_set($uid, 'withings_state', ['v' => $state, 'exp' => time() + 900]);
    kg_json(['url' => 'https://account.withings.com/oauth2_user/authorize2?' . http_build_query(['response_type' => 'code', 'client_id' => $cfg['client_id'], 'scope' => 'user.metrics', 'redirect_uri' => w_redirect_uri(), 'state' => $state])]);

  case 'disconnect':
    kg_set($uid, 'withings_tok', null);
    kg_json(['ok' => true]);

  case 'sync':
    $access = w_access($uid);
    $tok = kg_get($uid, 'withings_tok');
    $since = !empty($tok['lastSync']) && empty($_GET['full']) ? (int)$tok['lastSync'] - 86400 : time() - 365 * 86400;
    // 1 Gewicht, 6 Fettanteil %, 76 Muskelmasse, 77 Wasser, 88 Knochenmasse
    // lastupdate statt Messdatum: so kommen auch Messungen an, die die Waage erst später hochgeladen hat
    $params = ['action' => 'getmeas', 'meastype' => 1, 'category' => 1, 'lastupdate' => $since];
    $r = kg_http('POST', 'https://wbsapi.withings.net/measure', ['Authorization: Bearer ' . $access], $params);
    $j = $r['json'] ?? [];
    // Token von Withings vorzeitig ungültig gemacht: einmal erneuern und nochmal versuchen
    if (in_array((int)($j['status'] ?? 0), [100, 101, 102, 200, 401, 293], true)) {
      $access = w_access($uid, true);
      $r = kg_http('POST', 'https://wbsapi.withings.net/measure', ['Authorization: Bearer ' . $access], $params);
      $j = $r['json'] ?? [];
    }
    if (!$r['code'] || $r['error']) w_fail($uid, 'Withings nicht erreichbar (' . ($r['error'] ?: 'keine Antwort') . ')');
    if (($j['status'] ?? -1) !== 0) w_fail($uid, 'Withings: ' . w_status_text($j['status'] ?? $r['code']) . (!empty($j['error']) ? ' · ' . mb_substr((string)$j['error'], 0, 80) : ''));
    // Weitere Seiten holen, falls Withings nicht alles auf einmal liefert
    for ($pg = 0; $pg < 10 && !empty($j['body']['more']) && isset($j['body']['offset']); $pg++) {
      $r3 = kg_http('POST', 'https://wbsapi.withings.net/measure', ['Authorization: Bearer ' . $access], $params + ['offset' => $j['body']['offset']]);
      if (($r3['json']['status'] ?? -1) !== 0) break;
      $j['body']['measuregrps'] = array_merge($j['body']['measuregrps'] ?? [], $r3['json']['body']['measuregrps'] ?? []);
      $j['body']['more'] = $r3['json']['body']['more'] ?? 0; $j['body']['offset'] = $r3['json']['body']['offset'] ?? null;
    }
    $tok = kg_get($uid, 'withings_tok');   // nach evtl. Erneuern neu lesen, sonst überschreibt lastSync das neue Token
    // Pro Tag die neueste Messung
    $days = [];
    foreach ($j['body']['measuregrps'] ?? [] as $g) {
      $date = (new DateTime('@' . (int)$g['date']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
      $v = [];
      foreach ($g['measures'] ?? [] as $m) $v[(int)$m['type']] = $m['value'] * pow(10, (int)$m['unit']);
      if (!isset($v[1])) continue;
      if (!isset($days[$date]) || $g['date'] > $days[$date]['ts']) $days[$date] = ['ts' => (int)$g['date'], 'v' => $v];
    }
    $db = kg_db(); $n = 0;
    $st = $db->prepare('SELECT id, fields FROM records WHERE user_id=? AND base=? AND tbl=?'); $st->execute([$uid, W_BASE, W_TBL]);
    $byDate = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) { $f = json_decode($row['fields'], true) ?: []; if (!empty($f['Datum'])) $byDate[$f['Datum']] = ['id' => $row['id'], 'f' => $f]; }
    foreach ($days as $date => $d) {
      $f = ['Datum' => $date, 'Kg' => round($d['v'][1], 2), 'Quelle' => 'Withings'];
      if (isset($byDate[$date])) {
        $merged = array_merge($byDate[$date]['f'], $f);
        $db->prepare('UPDATE records SET fields=? WHERE id=?')->execute([json_encode($merged, JSON_UNESCAPED_UNICODE), $byDate[$date]['id']]);
      } else {
        $id = 'rec'; $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'; for ($i = 0; $i < 14; $i++) $id .= $a[random_int(0, 61)];
        $db->prepare('INSERT INTO records(id,user_id,base,tbl,fields,created) VALUES(?,?,?,?,?,?)')->execute([$id, $uid, W_BASE, W_TBL, json_encode($f, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d\TH:i:s.000\Z', $d['ts'])]);
      }
      $n++;
    }
    // Zur Kontrolle: jüngste Messung, die Withings überhaupt hat (letzte 90 Tage)
    $latest = null;
    if (!$days) {
      $r2 = kg_http('POST', 'https://wbsapi.withings.net/measure', ['Authorization: Bearer ' . $access], ['action' => 'getmeas', 'meastype' => 1, 'category' => 1, 'startdate' => time() - 90 * 86400, 'enddate' => time()]);
      foreach ($r2['json']['body']['measuregrps'] ?? [] as $g) if (!$latest || $g['date'] > $latest['ts']) {
        $v = null; foreach ($g['measures'] ?? [] as $m) if ((int)$m['type'] === 1) $v = $m['value'] * pow(10, (int)$m['unit']);
        if ($v !== null) $latest = ['ts' => (int)$g['date'], 'kg' => round($v, 2), 'attrib' => (int)($g['attrib'] ?? 0)];
      }
    }
    $tok['lastSync'] = time(); unset($tok['lastError'], $tok['lastErrorTs']); kg_set($uid, 'withings_tok', $tok);
    kg_json(['ok' => true, 'days' => $n, 'dates' => array_keys($days), 'groups' => count($j['body']['measuregrps'] ?? []), 'latest' => $latest]);
}
kg_json(['error' => ['message' => 'Unbekannte Aktion']], 400);
