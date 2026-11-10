<?php
// Mitteilungen: Einstellungen pro Quelle + Suche nach neuen E-Mails für den Cronjob (bricht nie mit kg_json ab)
require_once __DIR__ . '/lib.php';

function n_prefs(int $uid): array { return kg_get($uid, 'notify_prefs', []); }
function n_on(array $prefs, string $key, bool $default = true): bool { return array_key_exists($key, $prefs) ? (bool)$prefs[$key] : $default; }

// Alle Quellen, die man an/aus schalten kann
function n_sources(int $uid): array {
  $p = n_prefs($uid); $out = [];
  $out[] = ['key' => 'morning', 'group' => 'Erinnerungen', 'name' => 'Morgenreflexion', 'sub' => 'täglich 5:30', 'on' => n_on($p, 'morning')];
  $out[] = ['key' => 'evening', 'group' => 'Erinnerungen', 'name' => 'Abendreflexion', 'sub' => 'täglich 20:30', 'on' => n_on($p, 'evening')];
  $out[] = ['key' => 'upwork', 'group' => 'Upwork', 'name' => 'Upwork-Jobs', 'sub' => 'neue Job-Alerts aus Gmail', 'on' => n_on($p, 'upwork')];
  $tok = kg_get($uid, 'google_tok');
  if (!empty($tok['refresh'])) $out[] = ['key' => 'gmail', 'group' => 'E-Mail', 'src' => 'gmail', 'account' => $tok['email'] ?? 'Google', 'name' => $tok['email'] ?? 'Gmail', 'on' => n_on($p, 'gmail')];
  foreach (kg_get($uid, 'mail_accounts', []) as $a) $out[] = ['key' => 'mail:' . $a['id'], 'group' => 'E-Mail', 'src' => 'mail', 'account' => $a['label'], 'name' => $a['email'], 'on' => n_on($p, 'mail:' . $a['id'])];
  foreach (kg_get($uid, 'slack_teams', []) as $t) $out[] = ['key' => 'slack:' . $t['team_id'], 'group' => 'Slack', 'src' => 'slack', 'account' => $t['team_name'], 'name' => $t['team_name'], 'on' => n_on($p, 'slack:' . $t['team_id'])];
  return $out;
}

function n_google_token(int $uid): string {
  $tok = kg_get($uid, 'google_tok');
  if (!$tok || empty($tok['refresh'])) return '';
  if (!empty($tok['access']) && ($tok['exp'] ?? 0) > time()) return $tok['access'];
  $cfg = kg_get($uid, 'google_cfg');
  $r = kg_http('POST', 'https://oauth2.googleapis.com/token', [], ['client_id' => $cfg['client_id'] ?? '', 'client_secret' => $cfg['client_secret'] ?? '', 'refresh_token' => $tok['refresh'], 'grant_type' => 'refresh_token']);
  $j = $r['json'] ?? [];
  if (empty($j['access_token'])) return '';
  $tok['access'] = $j['access_token']; $tok['exp'] = time() + (int)($j['expires_in'] ?? 3500) - 60;
  if (!empty($j['scope'])) $tok['scope'] = $j['scope'];
  kg_set($uid, 'google_tok', $tok);
  return $tok['access'];
}
function n_from_name(string $from): string { return preg_match('/^\s*"?([^"<]+?)"?\s*</', $from, $m) ? trim($m[1]) : trim($from); }

// Neue ungelesene Gmail-Nachrichten im Posteingang (erster Lauf: nur merken)
function n_gmail_new(int $uid): array {
  $at = n_google_token($uid); if ($at === '') return [];
  $tok = kg_get($uid, 'google_tok');
  $r = kg_http('GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages?' . http_build_query(['maxResults' => 15, 'q' => 'in:inbox is:unread newer_than:2d']), ['Authorization: Bearer ' . $at]);
  if (($r['code'] ?? 0) >= 400) return [];
  $ids = array_map(fn($m) => $m['id'], $r['json']['messages'] ?? []);
  $st = kg_get($uid, 'notify_gmail'); $known = $st['ids'] ?? null;
  kg_set($uid, 'notify_gmail', ['ids' => array_slice(array_values(array_unique(array_merge($ids, $known ?? []))), 0, 300)]);
  if ($known === null) return [];
  $new = array_values(array_diff($ids, $known));
  if (!$new) return [];
  $urls = [];
  foreach (array_slice($new, 0, 6) as $id) $urls[$id] = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $id . '?format=metadata&metadataHeaders=From&metadataHeaders=Subject';
  $out = [];
  foreach (kg_http_multi($urls, ['Authorization: Bearer ' . $at]) as $id => $m) {
    if (!$m || empty($m['id'])) continue;
    $h = []; foreach ($m['payload']['headers'] ?? [] as $x) $h[strtolower($x['name'])] = $x['value'];
    $out[] = ['kind' => 'mail', 'src' => 'gmail', 'account' => $tok['email'] ?? 'Gmail', 'accountId' => '', 'id' => $id, 'from' => n_from_name($h['from'] ?? ''), 'subject' => $h['subject'] ?? '(kein Betreff)'];
  }
  return $out;
}

// Neue ungelesene Mails in einem IMAP-Postfach (über die höchste bekannte UID)
function n_imap_new(int $uid, array $a): array {
  if (!function_exists('imap_open')) return [];
  imap_timeout(IMAP_OPENTIMEOUT, 15); imap_timeout(IMAP_READTIMEOUT, 20);
  $mb = @imap_open('{' . $a['imap_host'] . ':' . (int)$a['imap_port'] . '/imap/ssl}INBOX', $a['user'], $a['pass'], OP_READONLY, 1, ['DISABLE_AUTHENTICATOR' => ['GSSAPI', 'NTLM']]);
  imap_errors(); imap_alerts();
  if (!$mb) return [];
  $key = 'notify_imap_' . $a['id']; $st = kg_get($uid, $key); $last = $st['last'] ?? null;
  $uids = imap_search($mb, 'SINCE "' . date('j-M-Y', strtotime('-2 days')) . '"', SE_UID) ?: [];
  $max = $uids ? max($uids) : (int)($last ?? 0);
  kg_set($uid, $key, ['last' => max($max, (int)($last ?? 0))]);
  if ($last === null) { imap_close($mb); return []; }
  $new = array_values(array_filter($uids, fn($u) => $u > (int)$last));
  $out = [];
  if ($new) foreach (imap_fetch_overview($mb, implode(',', array_slice($new, -6)), FT_UID) ?: [] as $o) {
    if (!empty($o->seen)) continue;
    $dec = function ($s) { if (!$s) return ''; $r = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8'); return $r !== false ? $r : (string)imap_utf8($s); };
    $out[] = ['kind' => 'mail', 'src' => 'mail', 'account' => $a['label'], 'accountId' => $a['id'], 'id' => (string)$o->uid, 'from' => n_from_name($dec($o->from ?? '')), 'subject' => $dec($o->subject ?? '') ?: '(kein Betreff)'];
  }
  imap_close($mb);
  return $out;
}

// Alle neuen E-Mails aus eingeschalteten Postfächern
function n_mail_new(int $uid): array {
  $p = n_prefs($uid); $out = [];
  if (n_on($p, 'gmail')) { try { $out = array_merge($out, n_gmail_new($uid)); } catch (Throwable $e) {} }
  foreach (kg_get($uid, 'mail_accounts', []) as $a) {
    if (!n_on($p, 'mail:' . $a['id'])) continue;
    try { $out = array_merge($out, n_imap_new($uid, $a)); } catch (Throwable $e) {}
  }
  return $out;
}
