<?php
// Slack-Hilfsfunktionen (für die API und den Benachrichtigungs-Cronjob)
require_once __DIR__ . '/lib.php';

function s_api(array $t, string $method, array $params = [], bool $post = false): array {
  $h = ['Authorization: Bearer ' . $t['token']];
  $r = $post ? kg_http('POST', 'https://slack.com/api/' . $method, array_merge($h, ['Content-Type: application/json; charset=utf-8']), json_encode($params, JSON_UNESCAPED_UNICODE))
             : kg_http('GET', 'https://slack.com/api/' . $method . '?' . http_build_query($params), $h);
  return $r['json'] ?? ['ok' => false, 'error' => 'http_' . $r['code']];
}
// Nutzernamen pro Workspace (Cache 1 Tag)
function s_users(int $uid, array $t): array {
  $key = 'slack_users_' . $t['team_id']; $c = kg_get($uid, $key);
  if ($c && ($c['t'] ?? 0) > time() - 86400) return $c['m'];
  $m = []; $cursor = '';
  do {
    $j = s_api($t, 'users.list', ['limit' => 500, 'cursor' => $cursor]);
    foreach ($j['members'] ?? [] as $u) $m[$u['id']] = $u['profile']['display_name'] ?: ($u['real_name'] ?? $u['name'] ?? $u['id']);
    $cursor = $j['response_metadata']['next_cursor'] ?? '';
  } while ($cursor);
  if ($m) kg_set($uid, $key, ['t' => time(), 'm' => $m]);   // leere Antwort (Fehler) nicht einen Tag lang merken
  return $m;
}
// Einzelnen Nutzer nachschlagen (z. B. Gäste oder Leute aus verbundenen Workspaces, die users.list nicht liefert)
function s_name(int $uid, ?array $t, array &$users, string $id): string {
  if ($id === '') return '';
  if (isset($users[$id])) return $users[$id];
  static $tries = 0;
  if ($t && $tries < 25) {
    $tries++;
    $j = s_api($t, 'users.info', ['user' => $id]);
    $u = $j['user'] ?? null;
    $name = $u ? (($u['profile']['display_name'] ?? '') ?: ($u['profile']['real_name'] ?? '') ?: ($u['real_name'] ?? '') ?: ($u['name'] ?? '')) : '';
    if ($name !== '') {
      $users[$id] = $name;
      $key = 'slack_users_' . $t['team_id']; $c = kg_get($uid, $key);
      if ($c) { $c['m'][$id] = $name; kg_set($uid, $key, $c); }
      return $name;
    }
  }
  return $id;
}
function s_text(string $txt, array &$users, ?array $t = null, int $uid = 0): string {
  $txt = preg_replace_callback('/<@([A-Z0-9]+)(?:\|([^>]*))?>/', function ($m) use (&$users, $t, $uid) {
    if (!isset($users[$m[1]]) && !empty($m[2])) return '@' . $m[2];
    return '@' . s_name($uid, $t, $users, $m[1]);
  }, $txt);
  $txt = preg_replace('/<!subteam\^[A-Z0-9]+\|([^>]+)>/', '$1', $txt);
  $txt = preg_replace('/<!(here|channel|everyone)(\|[^>]*)?>/', '@$1', $txt);
  $txt = preg_replace('/<#[A-Z0-9]+\|([^>]+)>/', '#$1', $txt);
  $txt = preg_replace('/<(https?:[^|>]+)\|([^>]+)>/', '$2 ($1)', $txt);
  $txt = preg_replace('/<(https?:[^>]+)>/', '$1', $txt);
  return html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
function s_conv_name(array $c, array $users): string {
  if (!empty($c['is_im'])) return $users[$c['user'] ?? ''] ?? 'Direktnachricht';
  if (!empty($c['is_mpim'])) return 'Gruppe: ' . preg_replace('/^mpdm-|-1$/', '', str_replace('--', ', ', $c['name'] ?? ''));
  return '#' . ($c['name'] ?? $c['id']);
}

// Gelesen-Status: beim ersten Sehen gilt ein Kanal als gelesen; danach ist alles Neuere (nicht von mir) ungelesen
function s_apply_seen(int $uid, array $msgs): array {
  $seen = kg_get($uid, 'slack_seen', []); $changed = false;
  foreach ($msgs as &$m) {
    if (!isset($seen[$m['id']])) { $seen[$m['id']] = $m['ts']; $changed = true; }
    $m['unread'] = !$m['mine'] && (float)$m['ts'] > (float)$seen[$m['id']];
  }
  if ($changed) kg_set($uid, 'slack_seen', $seen);
  return $msgs;
}

// Neue Slack-Nachrichten seit dem letzten Lauf finden (für Push). Beim ersten Lauf nur Stand merken.
function s_new_messages(int $uid): array {
  $state = kg_get($uid, 'slack_notified', []); $first = !$state; $new = [];
  $seen = kg_get($uid, 'slack_seen', []);
  foreach (kg_get($uid, 'slack_teams', []) as $t) {
    $users = s_users($uid, $t);
    $ck = 'slack_convs_' . $t['team_id']; $cc = kg_get($uid, $ck);
    if ($cc && ($cc['t'] ?? 0) > time() - 1800) $convs = $cc['c'];
    else {
      $convs = []; $cursor = '';
      do {
        $j = s_api($t, 'users.conversations', ['types' => 'public_channel,private_channel,mpim,im', 'exclude_archived' => 'true', 'limit' => 200, 'cursor' => $cursor]);
        if (empty($j['ok'])) break;
        foreach ($j['channels'] ?? [] as $c) $convs[] = ['id' => $c['id'], 'is_im' => !empty($c['is_im']), 'is_mpim' => !empty($c['is_mpim']), 'name' => $c['name'] ?? '', 'user' => $c['user'] ?? ''];
        $cursor = $j['response_metadata']['next_cursor'] ?? '';
      } while ($cursor);
      kg_set($uid, $ck, ['t' => time(), 'c' => $convs]);
    }
    $urls = []; $byId = [];
    foreach (array_slice($convs, 0, 60) as $c) {
      $since = max((float)($state[$c['id']] ?? 0), time() - 86400);
      $urls[$c['id']] = 'https://slack.com/api/conversations.history?' . http_build_query(['channel' => $c['id'], 'limit' => 5, 'oldest' => sprintf('%.6f', $since)]);
      $byId[$c['id']] = $c;
    }
    foreach (kg_http_multi($urls, ['Authorization: Bearer ' . $t['token']]) as $cid => $j) {
      $msgs = $j['messages'] ?? []; if (!$msgs) continue;
      $top = $msgs[0]['ts'];
      $prev = (float)($state[$cid] ?? 0);
      $state[$cid] = $top;
      if ($first || !$prev) continue;
      foreach (array_reverse($msgs) as $m) {
        if ((float)$m['ts'] <= $prev || ($m['user'] ?? '') === $t['user_id'] || (float)$m['ts'] <= (float)($seen[$cid] ?? 0)) continue;
        if (!empty($m['subtype']) && !in_array($m['subtype'], ['bot_message', 'file_share', 'thread_broadcast'], true)) continue;
        $new[] = ['team' => $t['team_id'], 'account' => $t['team_name'], 'channel' => $cid, 'conv' => s_conv_name($byId[$cid], $users),
          'from' => !empty($m['user']) ? s_name($uid, $t, $users, $m['user']) : ($m['username'] ?? 'Slack'), 'text' => mb_substr(s_text($m['text'] ?? '', $users, $t, $uid), 0, 140), 'ts' => $m['ts']];
      }
    }
  }
  kg_set($uid, 'slack_notified', $state ?: ['_' => time()]);
  return $new;
}
