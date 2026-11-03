<?php
// E-Mail-Konten per IMAP/SMTP (GMX, netcup, ...). Passwörter verschlüsselt auf dem Server.
require __DIR__ . '/lib.php';
$uid = kg_require_user();
$action = $_GET['action'] ?? 'accounts';
$in = kg_input();
imap_timeout(IMAP_OPENTIMEOUT, 15); imap_timeout(IMAP_READTIMEOUT, 20);

const MAIL_PRESETS = [
  'gmx'    => ['imap_host' => 'imap.gmx.net', 'imap_port' => 993, 'smtp_host' => 'mail.gmx.net', 'smtp_port' => 587, 'smtp_secure' => 'tls'],
  'netcup' => ['imap_host' => 'a2ed0.netcup.net', 'imap_port' => 993, 'smtp_host' => 'a2ed0.netcup.net', 'smtp_port' => 465, 'smtp_secure' => 'ssl'],
];

function m_accounts(int $uid): array { return kg_get($uid, 'mail_accounts', []); }
function m_find(int $uid, string $id): array {
  foreach (m_accounts($uid) as $a) if ($a['id'] === $id) return $a;
  kg_json(['error' => ['message' => 'Konto nicht gefunden.']], 404);
}
function m_open(array $a, string $folder = 'INBOX', int $opts = 0) {
  $mb = @imap_open('{' . $a['imap_host'] . ':' . (int)$a['imap_port'] . '/imap/ssl}' . $folder, $a['user'], $a['pass'], $opts, 1);
  if (!$mb) { $e = imap_last_error(); imap_errors(); return [null, $e ?: 'Login fehlgeschlagen']; }
  return [$mb, null];
}
function m_dec(?string $s): string {
  if ($s === null || $s === '') return '';
  $r = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
  return $r !== false ? $r : (string)imap_utf8($s);
}
function m_part_text($mb, int $uidn, $st, string $num, string &$plain, string &$html): void {
  $type = $st->type ?? 0; $sub = strtolower($st->subtype ?? '');
  if (!empty($st->parts)) { foreach ($st->parts as $i => $p) m_part_text($mb, $uidn, $p, $num === '' ? (string)($i + 1) : $num . '.' . ($i + 1), $plain, $html); return; }
  if ($type !== 0) return;
  $data = imap_fetchbody($mb, $uidn, $num === '' ? '1' : $num, FT_UID | FT_PEEK);
  if (($st->encoding ?? 0) == 3) $data = base64_decode($data); elseif (($st->encoding ?? 0) == 4) $data = quoted_printable_decode($data);
  $cs = 'UTF-8';
  foreach (array_merge($st->parameters ?? [], $st->dparameters ?? []) as $p) if (strtolower($p->attribute) === 'charset') $cs = $p->value;
  if (strtoupper($cs) !== 'UTF-8') { $c = @mb_convert_encoding($data, 'UTF-8', $cs); if ($c !== false) $data = $c; }
  if ($sub === 'plain' && $plain === '') $plain = $data;
  if ($sub === 'html' && $html === '') $html = $data;
}
function m_sent_folder($mb, array $a): ?string {
  $ref = '{' . $a['imap_host'] . ':' . (int)$a['imap_port'] . '/imap/ssl}';
  $list = imap_list($mb, $ref, '*') ?: [];
  foreach ($list as $f) { $n = substr($f, strlen($ref)); if (preg_match('/(^|[.\/])(sent|gesendet)/i', imap_utf7_decode($n) ?: $n)) return $n; }
  return null;
}
function m_smtp(array $a, string $from, array $rcpts, string $data): ?string {
  $host = ($a['smtp_secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $a['smtp_host'] . ':' . (int)$a['smtp_port'];
  $fp = @stream_socket_client($host, $errno, $errstr, 20);
  if (!$fp) return 'SMTP-Verbindung fehlgeschlagen: ' . $errstr;
  stream_set_timeout($fp, 20);
  $read = function () use ($fp) { $r = ''; while (($l = fgets($fp, 1024)) !== false) { $r .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $r; };
  $cmd = function (string $c, array $ok) use ($fp, $read) { if ($c !== '') fwrite($fp, $c . "\r\n"); $r = $read(); if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new Exception(trim($r)); return $r; };
  try {
    $cmd('', [220]); $cmd('EHLO kongrow', [250]);
    if ($a['smtp_secure'] === 'tls') { $cmd('STARTTLS', [220]); stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT); $cmd('EHLO kongrow', [250]); }
    $cmd('AUTH LOGIN', [334]); $cmd(base64_encode($a['user']), [334]); $cmd(base64_encode($a['pass']), [235]);
    $cmd('MAIL FROM:<' . $from . '>', [250]);
    foreach ($rcpts as $r) $cmd('RCPT TO:<' . $r . '>', [250, 251]);
    $cmd('DATA', [354]);
    fwrite($fp, preg_replace('/^\./m', '..', $data) . "\r\n.\r\n"); $cmd('', [250]);
    $cmd('QUIT', [221]);
  } catch (Exception $e) { fclose($fp); return 'SMTP: ' . $e->getMessage(); }
  fclose($fp); return null;
}
// Anhänge einer Mail finden (Teilnummer, Name, Typ, Kodierung)
function m_attachments($st, string $num = ''): array {
  $out = [];
  if (!empty($st->parts)) { foreach ($st->parts as $i => $p) $out = array_merge($out, m_attachments($p, $num === '' ? (string)($i + 1) : $num . '.' . ($i + 1))); return $out; }
  $name = '';
  foreach (array_merge($st->dparameters ?? [], $st->parameters ?? []) as $p) if (in_array(strtolower($p->attribute), ['filename', 'name'], true)) { $name = m_dec($p->value); break; }
  $disp = strtolower($st->disposition ?? '');
  if ($name !== '' && ($disp === 'attachment' || ($st->type ?? 0) !== 0)) {
    $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'model', 'other'];
    $out[] = ['num' => $num === '' ? '1' : $num, 'name' => $name, 'size' => (int)($st->bytes ?? 0), 'enc' => (int)($st->encoding ?? 0), 'mime' => ($types[$st->type ?? 8] ?? 'application') . '/' . strtolower($st->subtype ?? 'octet-stream')];
  }
  return $out;
}
function m_public(array $a): array { unset($a['pass']); return $a; }

switch ($action) {
  case 'accounts':
    kg_json(['accounts' => array_map('m_public', m_accounts($uid)), 'presets' => array_keys(MAIL_PRESETS)]);

  case 'account_save':
    $preset = (string)($in['preset'] ?? '');
    $email = trim((string)($in['email'] ?? '')); $pass = (string)($in['pass'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) kg_json(['error' => ['message' => 'Bitte eine gültige E-Mail-Adresse eingeben.']], 400);
    $a = MAIL_PRESETS[$preset] ?? [
      'imap_host' => trim((string)($in['imap_host'] ?? '')), 'imap_port' => (int)($in['imap_port'] ?? 993),
      'smtp_host' => trim((string)($in['smtp_host'] ?? '')), 'smtp_port' => (int)($in['smtp_port'] ?? 465), 'smtp_secure' => ($in['smtp_secure'] ?? 'ssl') === 'tls' ? 'tls' : 'ssl'];
    $a += ['id' => 'm' . bin2hex(random_bytes(5)), 'email' => $email, 'user' => trim((string)($in['user'] ?? '')) ?: $email, 'pass' => $pass,
      'label' => trim((string)($in['label'] ?? '')) ?: $email, 'preset' => $preset ?: 'custom', 'name' => trim((string)($in['name'] ?? ''))];
    [$mb, $err] = m_open($a, 'INBOX', OP_HALFOPEN);
    if (!$mb) kg_json(['error' => ['message' => 'Anmeldung am Postfach fehlgeschlagen: ' . $err . ($preset === 'gmx' ? '. Ist in den GMX-Einstellungen „POP3/IMAP Abruf“ aktiviert?' : '')]], 400);
    imap_close($mb);
    $all = m_accounts($uid); $all[] = $a; kg_set($uid, 'mail_accounts', $all);
    kg_json(['ok' => true, 'account' => m_public($a)]);

  case 'account_delete':
    $id = (string)($in['id'] ?? '');
    kg_set($uid, 'mail_accounts', array_values(array_filter(m_accounts($uid), fn($a) => $a['id'] !== $id)));
    kg_json(['ok' => true]);

  case 'list':
    $a = m_find($uid, (string)($_GET['account'] ?? ''));
    [$mb, $err] = m_open($a, 'INBOX', OP_READONLY);
    if (!$mb) kg_json(['error' => ['message' => $a['label'] . ': ' . $err]], 502);
    $uids = imap_search($mb, 'SINCE "' . date('j-M-Y', strtotime('-21 days')) . '"', SE_UID) ?: [];
    rsort($uids); $uids = array_slice($uids, 0, (int)($_GET['max'] ?? 30));
    $out = [];
    if ($uids) foreach (imap_fetch_overview($mb, implode(',', $uids), FT_UID) ?: [] as $o) {
      $out[] = ['source' => 'mail', 'account' => $a['label'], 'accountId' => $a['id'], 'id' => (string)$o->uid, 'from' => m_dec($o->from ?? ''), 'subject' => m_dec($o->subject ?? '') ?: '(kein Betreff)',
        'snippet' => '', 'date' => gmdate('c', isset($o->udate) ? (int)$o->udate : (strtotime($o->date ?? '') ?: time())), 'unread' => empty($o->seen)];
    }
    imap_close($mb);
    usort($out, fn($x, $y) => strcmp($y['date'], $x['date']));
    kg_json(['messages' => $out]);

  case 'get':
    $a = m_find($uid, (string)($_GET['account'] ?? '')); $u = (int)($_GET['id'] ?? 0);
    [$mb, $err] = m_open($a);
    if (!$mb) kg_json(['error' => ['message' => $err]], 502);
    $hdrRaw = imap_fetchheader($mb, $u, FT_UID);
    $h = imap_rfc822_parse_headers($hdrRaw);
    $st = imap_fetchstructure($mb, $u, FT_UID);
    $plain = ''; $html = '';
    if ($st) m_part_text($mb, $u, $st, '', $plain, $html);
    $attList = $st ? array_map(fn($x) => ['name' => $x['name'], 'size' => $x['size'], 'mime' => $x['mime']], m_attachments($st)) : [];
    imap_setflag_full($mb, (string)$u, '\\Seen', ST_UID);
    imap_close($mb);
    preg_match('/^References:\s*(.+(?:\r?\n[ \t].+)*)/mi', $hdrRaw, $rm);
    $addr = function ($list) { $o = []; foreach ((array)$list as $x) { if (empty($x->mailbox)) continue; $o[] = (isset($x->personal) ? m_dec($x->personal) . ' ' : '') . '<' . $x->mailbox . '@' . ($x->host ?? '') . '>'; } return implode(', ', $o); };
    kg_json(['id' => (string)$u, 'from' => $addr($h->from ?? []), 'to' => $addr($h->to ?? []), 'cc' => $addr($h->cc ?? []), 'replyTo' => $addr($h->reply_to ?? []),
      'subject' => m_dec($h->subject ?? ''), 'date' => $h->date ?? '', 'messageId' => $h->message_id ?? '', 'references' => trim(preg_replace('/\s+/', ' ', $rm[1] ?? '')),
      'attachments' => $attList, 'text' => $plain !== '' ? trim($plain) : kg_html_to_text($html)]);

  case 'forward':
    // Weiterleiten inkl. Anhängen
    $a = m_find($uid, (string)($in['account'] ?? '')); $u = (int)($in['id'] ?? 0);
    $to = trim((string)($in['to'] ?? '')); $subject = (string)($in['subject'] ?? ''); $body = (string)($in['body'] ?? '');
    preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $to, $mm); $rcpts = array_unique($mm[0]);
    if (!$rcpts || !$u) kg_json(['error' => ['message' => 'Empfänger fehlt.']], 400);
    [$mb, $err] = m_open($a);
    if (!$mb) kg_json(['error' => ['message' => $err]], 502);
    $st = imap_fetchstructure($mb, $u, FT_UID);
    $atts = []; $total = 0; $skipped = [];
    foreach ($st ? m_attachments($st) : [] as $x) {
      if ($total + $x['size'] > 20 * 1024 * 1024) { $skipped[] = $x['name']; continue; }
      $d = imap_fetchbody($mb, $u, $x['num'], FT_UID | FT_PEEK);
      if ($x['enc'] == 3) $d = base64_decode($d); elseif ($x['enc'] == 4) $d = quoted_printable_decode($d);
      $atts[] = ['name' => $x['name'], 'mime' => $x['mime'], 'data' => $d]; $total += strlen($d);
    }
    if ($skipped) $body .= "\n\n(Nicht mitgeschickt, zu groß: " . implode(', ', $skipped) . ')';
    $from = $a['email']; $fromHdr = $a['name'] ? (mb_encode_mimeheader($a['name'], 'UTF-8', 'B') . ' <' . $from . '>') : $from;
    $domain = substr(strrchr($from, '@'), 1);
    $data = kg_mime_mixed(['Date: ' . date('r'), 'From: ' . $fromHdr, 'To: ' . $to, 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'B'), 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>'], $body, $atts);
    $e2 = m_smtp($a, $from, $rcpts, $data);
    if ($e2) { imap_close($mb); kg_json(['error' => ['message' => $e2]], 502); }
    $sent = m_sent_folder($mb, $a); if ($sent) @imap_append($mb, '{' . $a['imap_host'] . ':' . (int)$a['imap_port'] . '/imap/ssl}' . $sent, $data . "\r\n", '\\Seen');
    imap_close($mb);
    kg_json(['ok' => true, 'attachments' => count($atts), 'skipped' => $skipped]);

  case 'send':
    $a = m_find($uid, (string)($in['account'] ?? ''));
    $to = trim((string)($in['to'] ?? '')); $subject = (string)($in['subject'] ?? ''); $body = (string)($in['body'] ?? '');
    preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $to, $mm); $rcpts = array_unique($mm[0]);
    if (!$rcpts || trim($body) === '') kg_json(['error' => ['message' => 'Empfänger oder Text fehlt.']], 400);
    $from = $a['email']; $fromHdr = $a['name'] ? (mb_encode_mimeheader($a['name'], 'UTF-8', 'B') . ' <' . $from . '>') : $from;
    $domain = substr(strrchr($from, '@'), 1);
    $hdr = ['Date: ' . date('r'), 'From: ' . $fromHdr, 'To: ' . $to, 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'B'),
      'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64'];
    if (!empty($in['inReplyTo'])) { $hdr[] = 'In-Reply-To: ' . $in['inReplyTo']; $hdr[] = 'References: ' . trim(($in['references'] ?? '') . ' ' . $in['inReplyTo']); }
    $data = implode("\r\n", $hdr) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body)));
    $err = m_smtp($a, $from, $rcpts, $data);
    if ($err) kg_json(['error' => ['message' => $err]], 502);
    // Kopie in "Gesendet" ablegen
    [$mb] = m_open($a, 'INBOX', 0);
    if ($mb) { $sent = m_sent_folder($mb, $a); if ($sent) @imap_append($mb, '{' . $a['imap_host'] . ':' . (int)$a['imap_port'] . '/imap/ssl}' . $sent, $data . "\r\n", '\\Seen'); imap_close($mb); }
    kg_json(['ok' => true]);
}
kg_json(['error' => ['message' => 'Unbekannte Aktion']], 400);
