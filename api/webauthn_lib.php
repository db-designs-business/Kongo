<?php
// Passkeys (WebAuthn) für Face ID / Touch ID: Registrierung und Anmeldung, ohne externe Bibliotheken.
require_once __DIR__ . '/lib.php';

function wa_b64u(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function wa_unb64u(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
function wa_rp_id(): string { return preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')); }
function wa_origin(): string { return 'https://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost'); }

// Minimaler CBOR-Decoder (reicht für attestationObject und COSE-Schlüssel)
function wa_cbor(string $d, int &$o = 0) {
  $ib = ord($d[$o++]); $mt = $ib >> 5; $ai = $ib & 31;
  $len = function (int $ai) use ($d, &$o) {
    if ($ai < 24) return $ai;
    $n = [24 => 1, 25 => 2, 26 => 4, 27 => 8][$ai] ?? 0; if (!$n) throw new Exception('CBOR');
    $v = 0; for ($i = 0; $i < $n; $i++) $v = ($v << 8) | ord($d[$o++]); return $v;
  };
  switch ($mt) {
    case 0: return $len($ai);
    case 1: return -1 - $len($ai);
    case 2: case 3: $n = $len($ai); $s = substr($d, $o, $n); $o += $n; return $s;
    case 4: $n = $len($ai); $a = []; for ($i = 0; $i < $n; $i++) $a[] = wa_cbor($d, $o); return $a;
    case 5: $n = $len($ai); $m = []; for ($i = 0; $i < $n; $i++) { $k = wa_cbor($d, $o); $m[$k] = wa_cbor($d, $o); } return $m;
    case 7: if ($ai === 20) return false; if ($ai === 21) return true; if ($ai === 22) return null; throw new Exception('CBOR');
  }
  throw new Exception('CBOR');
}
// COSE EC2 P-256 -> PEM
function wa_cose_to_pem(array $k): string {
  if (($k[1] ?? 0) !== 2 || ($k[3] ?? 0) !== -7 || ($k[-1] ?? 0) !== 1) throw new Exception('Nur ES256-Schlüssel werden unterstützt.');
  $raw = "\x04" . $k[-2] . $k[-3];
  $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
  return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function wa_challenges_init(): void {
  kg_db()->exec('CREATE TABLE IF NOT EXISTS wa_challenges (ch TEXT PRIMARY KEY, uid INTEGER, kind TEXT, exp INTEGER NOT NULL)');
  kg_db()->prepare('DELETE FROM wa_challenges WHERE exp < ?')->execute([time()]);
}
function wa_new_challenge(?int $uid, string $kind): string {
  wa_challenges_init();
  $c = random_bytes(32);
  kg_db()->prepare('INSERT INTO wa_challenges(ch,uid,kind,exp) VALUES(?,?,?,?)')->execute([wa_b64u($c), $uid, $kind, time() + 300]);
  return wa_b64u($c);
}
function wa_take_challenge(string $ch, string $kind): ?array {
  wa_challenges_init();
  $st = kg_db()->prepare('SELECT uid FROM wa_challenges WHERE ch=? AND kind=? AND exp>=?'); $st->execute([$ch, $kind, time()]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  kg_db()->prepare('DELETE FROM wa_challenges WHERE ch=?')->execute([$ch]);
  return $r ?: null;
}
function wa_client_data(string $cdj, string $type, string $kind): array {
  $c = json_decode($cdj, true);
  if (!$c || ($c['type'] ?? '') !== $type) throw new Exception('Ungültige Antwort.');
  $orig = (string)($c['origin'] ?? '');
  if ($orig !== wa_origin() && !preg_match('#^http://localhost(:\d+)?$#', $orig)) throw new Exception('Falsche Herkunft.');
  $ch = wa_take_challenge((string)($c['challenge'] ?? ''), $kind);
  if (!$ch) throw new Exception('Anfrage abgelaufen, bitte erneut versuchen.');
  return $ch;
}
function wa_check_authdata(string $ad): int {
  if (strlen($ad) < 37 || !hash_equals(hash('sha256', wa_rp_id(), true), substr($ad, 0, 32))) throw new Exception('Falsche Domain.');
  $flags = ord($ad[32]);
  if (!($flags & 0x01)) throw new Exception('Keine Bestätigung.');
  if (!($flags & 0x04)) throw new Exception('Face ID wurde nicht geprüft.');
  return $flags;
}

// Registrierung abschließen -> Schlüssel speichern
function wa_register(int $uid, array $in): array {
  $cdj = wa_unb64u((string)($in['clientDataJSON'] ?? ''));
  wa_client_data($cdj, 'webauthn.create', 'reg');
  $o = 0; $att = wa_cbor(wa_unb64u((string)($in['attestationObject'] ?? '')), $o);
  $ad = $att['authData'] ?? ''; $flags = wa_check_authdata($ad);
  if (!($flags & 0x40)) throw new Exception('Kein Schlüssel enthalten.');
  $p = 37 + 16; $idLen = (ord($ad[$p]) << 8) | ord($ad[$p + 1]); $p += 2;
  $credId = substr($ad, $p, $idLen); $p += $idLen;
  $cose = wa_cbor($ad, $p);
  $pem = wa_cose_to_pem($cose);
  $creds = kg_get($uid, 'webauthn_creds', []);
  $creds = array_values(array_filter($creds, fn($c) => $c['id'] !== wa_b64u($credId)));
  $creds[] = ['id' => wa_b64u($credId), 'pem' => $pem, 'count' => unpack('N', substr($ad, 33, 4))[1], 'name' => mb_substr(trim((string)($in['name'] ?? '')) ?: 'Gerät', 0, 40), 'created' => date('c')];
  kg_set($uid, 'webauthn_creds', $creds);
  return $creds;
}

// Anmeldung prüfen -> User-ID
function wa_verify_login(array $in): int {
  $cdj = wa_unb64u((string)($in['clientDataJSON'] ?? ''));
  $ad = wa_unb64u((string)($in['authenticatorData'] ?? ''));
  $sig = wa_unb64u((string)($in['signature'] ?? ''));
  $id = (string)($in['id'] ?? '');
  wa_client_data($cdj, 'webauthn.get', 'login');
  wa_check_authdata($ad);
  kg_settings_init();
  foreach (kg_db()->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN) as $u) {
    $creds = kg_get((int)$u, 'webauthn_creds', []);
    foreach ($creds as $i => $c) {
      if (!hash_equals($c['id'], $id)) continue;
      $ok = openssl_verify($ad . hash('sha256', $cdj, true), $sig, $c['pem'], OPENSSL_ALGO_SHA256);
      if ($ok !== 1) throw new Exception('Signatur ungültig.');
      $cnt = unpack('N', substr($ad, 33, 4))[1];
      if ($cnt > 0 && $cnt <= (int)$c['count']) throw new Exception('Schlüssel wurde möglicherweise kopiert.');
      $creds[$i]['count'] = $cnt; $creds[$i]['used'] = date('c');
      kg_set((int)$u, 'webauthn_creds', $creds);
      return (int)$u;
    }
  }
  throw new Exception('Dieser Passkey ist nicht bekannt. Melde dich mit Passwort an und richte Face ID neu ein.');
}
