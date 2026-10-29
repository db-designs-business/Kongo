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
  if (($j['status'] ?? -1) !== 0 || empty($j['body']['access_token'])) return null;
  $b = $j['body'];
  $tok = kg_get($uid, 'withings_tok', []);
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

function w_access(int $uid): string {
  $tok = kg_get($uid, 'withings_tok');
  if (!$tok || empty($tok['refresh'])) kg_json(['error' => ['type' => 'NOT_CONNECTED', 'message' => 'Withings ist nicht verbunden.']], 409);
  if (($tok['exp'] ?? 0) > time()) return $tok['access'];
  $tok = w_token_request($uid, ['grant_type' => 'refresh_token', 'refresh_token' => $tok['refresh']]);
  if (!$tok) kg_json(['error' => ['type' => 'NOT_CONNECTED', 'message' => 'Withings-Verbindung abgelaufen. Bitte neu verbinden.']], 409);
  return $tok['access'];
}

switch ($action) {
  case 'status':
    $cfg = kg_get($uid, 'withings_cfg'); $tok = kg_get($uid, 'withings_tok');
    kg_json(['configured' => !empty($cfg['client_id']), 'connected' => !empty($tok['refresh']), 'lastSync' => $tok['lastSync'] ?? null, 'redirect_uri' => w_redirect_uri()]);

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
    $r = kg_http('POST', 'https://wbsapi.withings.net/measure', ['Authorization: Bearer ' . $access],
      ['action' => 'getmeas', 'meastypes' => '1,6,76', 'category' => 1, 'startdate' => $since, 'enddate' => time()]);
    $j = $r['json'] ?? [];
    if (($j['status'] ?? -1) !== 0) kg_json(['error' => ['message' => 'Withings: Fehler ' . ($j['status'] ?? $r['code'])]], 502);
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
      if (isset($d['v'][6])) $f['Fett %'] = round($d['v'][6], 1);
      if (isset($d['v'][76])) $f['Muskel kg'] = round($d['v'][76], 1);
      if (isset($byDate[$date])) {
        $merged = array_merge($byDate[$date]['f'], $f);
        $db->prepare('UPDATE records SET fields=? WHERE id=?')->execute([json_encode($merged, JSON_UNESCAPED_UNICODE), $byDate[$date]['id']]);
      } else {
        $id = 'rec'; $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'; for ($i = 0; $i < 14; $i++) $id .= $a[random_int(0, 61)];
        $db->prepare('INSERT INTO records(id,user_id,base,tbl,fields,created) VALUES(?,?,?,?,?,?)')->execute([$id, $uid, W_BASE, W_TBL, json_encode($f, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d\TH:i:s.000\Z', $d['ts'])]);
      }
      $n++;
    }
    $tok['lastSync'] = time(); kg_set($uid, 'withings_tok', $tok);
    kg_json(['ok' => true, 'days' => $n, 'dates' => array_keys($days)]);
}
kg_json(['error' => ['message' => 'Unbekannte Aktion']], 400);
