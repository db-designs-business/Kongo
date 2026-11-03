<?php
// Slack: mehrere Workspaces über eine eigene Slack-App (User-Token, lesen + antworten)
require __DIR__ . '/slack_lib.php';
$action = $_GET['action'] ?? 'status';
const S_USER_SCOPES = 'channels:history,channels:read,groups:history,groups:read,im:history,im:read,mpim:history,mpim:read,users:read,chat:write';
function s_redirect_uri(): string { return kg_base_url() . '/api/slack.php?action=callback'; }

if ($action === 'callback') {
  $state = (string)($_GET['state'] ?? ''); kg_settings_init(); $uid = null;
  foreach (kg_db()->query('SELECT DISTINCT user_id FROM settings')->fetchAll(PDO::FETCH_COLUMN) as $u) {
    $s = kg_get((int)$u, 'slack_state');
    if ($s && hash_equals($s['v'], $state) && $s['exp'] > time()) { $uid = (int)$u; break; }
  }
  if ($uid === null) { http_response_code(400); exit('Ungültiger oder abgelaufener Anmeldevorgang.'); }
  kg_set($uid, 'slack_state', null);
  $cfg = kg_get($uid, 'slack_cfg');
  $r = kg_http('POST', 'https://slack.com/api/oauth.v2.access', [], ['client_id' => $cfg['client_id'], 'client_secret' => $cfg['client_secret'], 'code' => (string)($_GET['code'] ?? ''), 'redirect_uri' => s_redirect_uri()]);
  $j = $r['json'] ?? [];
  if (empty($j['ok']) || empty($j['authed_user']['access_token'])) kg_back_to_app('open=settings&slack=error');
  $teams = array_values(array_filter(kg_get($uid, 'slack_teams', []), fn($t) => $t['team_id'] !== ($j['team']['id'] ?? '')));
  $teams[] = ['team_id' => $j['team']['id'] ?? '', 'team_name' => $j['team']['name'] ?? 'Slack', 'user_id' => $j['authed_user']['id'] ?? '', 'token' => $j['authed_user']['access_token']];
  kg_set($uid, 'slack_teams', $teams);
  kg_back_to_app('open=settings&slack=ok');
}

$uid = kg_require_user();
$in = kg_input();

function s_team(int $uid, string $tid): array {
  foreach (kg_get($uid, 'slack_teams', []) as $t) if ($t['team_id'] === $tid) return $t;
  kg_json(['error' => ['message' => 'Workspace nicht verbunden.']], 404);
}
switch ($action) {
  case 'status':
    $cfg = kg_get($uid, 'slack_cfg');
    kg_json(['configured' => !empty($cfg['client_id']), 'teams' => array_map(fn($t) => ['team_id' => $t['team_id'], 'team_name' => $t['team_name']], kg_get($uid, 'slack_teams', [])), 'redirect_uri' => s_redirect_uri()]);

  case 'config':
    $cid = trim((string)($in['client_id'] ?? '')); $sec = trim((string)($in['client_secret'] ?? ''));
    if (!preg_match('/^\d+\.\d+$/', $cid) || strlen($sec) < 10) kg_json(['error' => ['message' => 'Client-ID oder Secret sieht nicht richtig aus.']], 400);
    kg_set($uid, 'slack_cfg', ['client_id' => $cid, 'client_secret' => $sec]);
    kg_json(['ok' => true]);

  case 'connect':
    $cfg = kg_get($uid, 'slack_cfg');
    if (empty($cfg['client_id'])) kg_json(['error' => ['message' => 'Zuerst Client-ID und Secret eintragen.']], 400);
    $state = bin2hex(random_bytes(16)); kg_set($uid, 'slack_state', ['v' => $state, 'exp' => time() + 900]);
    kg_json(['url' => 'https://slack.com/oauth/v2/authorize?' . http_build_query(['client_id' => $cfg['client_id'], 'user_scope' => S_USER_SCOPES, 'redirect_uri' => s_redirect_uri(), 'state' => $state])]);

  case 'disconnect':
    $tid = (string)($in['team_id'] ?? '');
    foreach (kg_get($uid, 'slack_teams', []) as $t) if ($t['team_id'] === $tid) s_api($t, 'auth.revoke');
    kg_set($uid, 'slack_teams', array_values(array_filter(kg_get($uid, 'slack_teams', []), fn($t) => $t['team_id'] !== $tid)));
    kg_json(['ok' => true]);

  case 'list':
    // Kurzer Cache, um Slack-Limits zu schonen
    $cache = kg_get($uid, 'slack_list_cache');
    if ($cache && ($cache['t'] ?? 0) > time() - 90 && empty($_GET['fresh'])) kg_json(['messages' => s_apply_seen($uid, $cache['m'])]);
    $out = []; $errors = [];
    foreach (kg_get($uid, 'slack_teams', []) as $t) {
      $users = s_users($uid, $t);
      $convs = []; $cursor = '';
      do {
        $j = s_api($t, 'users.conversations', ['types' => 'public_channel,private_channel,mpim,im', 'exclude_archived' => 'true', 'limit' => 200, 'cursor' => $cursor]);
        if (empty($j['ok'])) { $errors[] = $t['team_name'] . ': ' . ($j['error'] ?? 'Fehler'); break; }
        $convs = array_merge($convs, $j['channels'] ?? []); $cursor = $j['response_metadata']['next_cursor'] ?? '';
      } while ($cursor);
      $oldest = time() - 21 * 86400; $urls = []; $byId = [];
      foreach (array_slice($convs, 0, 60) as $c) {
        $urls[$c['id']] = 'https://slack.com/api/conversations.history?' . http_build_query(['channel' => $c['id'], 'limit' => 1, 'oldest' => $oldest]);
        $byId[$c['id']] = $c;
      }
      $res = kg_http_multi($urls, ['Authorization: Bearer ' . $t['token']]);
      foreach ($res as $cid => $j) {
        $m = $j['messages'][0] ?? null; if (!$m) continue;
        $out[] = ['source' => 'slack', 'account' => $t['team_name'], 'team' => $t['team_id'], 'id' => $cid, 'from' => !empty($m['user']) ? s_name($uid, $t, $users, $m['user']) : ($m['username'] ?? 'Slack'),
          'subject' => s_conv_name($byId[$cid], $users), 'snippet' => mb_substr(s_text($m['text'] ?? '', $users, $t, $uid), 0, 200), 'ts' => $m['ts'],
          'date' => gmdate('c', (int)$m['ts']), 'mine' => ($m['user'] ?? '') === $t['user_id']];
      }
    }
    usort($out, fn($a, $b) => strcmp($b['date'], $a['date']));
    kg_set($uid, 'slack_list_cache', ['t' => time(), 'm' => $out]);
    kg_json(['messages' => s_apply_seen($uid, $out), 'errors' => $errors]);

  case 'history':
    $t = s_team($uid, (string)($_GET['team'] ?? '')); $ch = (string)($_GET['channel'] ?? '');
    $users = s_users($uid, $t);
    $j = s_api($t, 'conversations.history', ['channel' => $ch, 'limit' => 30]);
    if (empty($j['ok'])) kg_json(['error' => ['message' => 'Slack: ' . ($j['error'] ?? 'Fehler')]], 502);
    $msgs = [];
    foreach (array_reverse($j['messages'] ?? []) as $m) $msgs[] = ['ts' => $m['ts'], 'user' => !empty($m['user']) ? s_name($uid, $t, $users, $m['user']) : ($m['username'] ?? 'Slack'), 'mine' => ($m['user'] ?? '') === $t['user_id'],
      'text' => s_text($m['text'] ?? '', $users, $t, $uid), 'date' => gmdate('c', (int)$m['ts']), 'replies' => (int)($m['reply_count'] ?? 0)];
    $seen = kg_get($uid, 'slack_seen', []); if ($msgs) $seen[$ch] = end($msgs)['ts']; kg_set($uid, 'slack_seen', $seen);
    kg_json(['messages' => $msgs]);

  case 'send':
    $t = s_team($uid, (string)($in['team'] ?? ''));
    $p = ['channel' => (string)($in['channel'] ?? ''), 'text' => (string)($in['text'] ?? '')];
    if (trim($p['text']) === '') kg_json(['error' => ['message' => 'Text fehlt.']], 400);
    if (!empty($in['thread_ts'])) $p['thread_ts'] = $in['thread_ts'];
    $j = s_api($t, 'chat.postMessage', $p, true);
    if (empty($j['ok'])) kg_json(['error' => ['message' => 'Slack: ' . ($j['error'] ?? 'Fehler')]], 502);
    $seen = kg_get($uid, 'slack_seen', []); $seen[$p['channel']] = $j['ts'] ?? ''; kg_set($uid, 'slack_seen', $seen);
    kg_set($uid, 'slack_list_cache', null);
    kg_json(['ok' => true]);
}
kg_json(['error' => ['message' => 'Unbekannte Aktion']], 400);
