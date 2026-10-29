<?php
// Kongrow Web-Push (ohne externe Bibliotheken): VAPID (ES256) + Verschlüsselung nach RFC 8291 (aes128gcm)
declare(strict_types=1);

const KG_DATA_DIR = __DIR__ . '/data';
const KG_VAPID_SUBJECT = 'https://kongrow.bdriven-digital.com';

function b64u_enc(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

function kg_ensure_data_dir(): void {
  if (!is_dir(KG_DATA_DIR)) mkdir(KG_DATA_DIR, 0700, true);
  $ht = KG_DATA_DIR . '/.htaccess';
  if (!file_exists($ht)) file_put_contents($ht, "Require all denied\nDeny from all\n");
}

function kg_ec_public_raw($key): string {
  $d = openssl_pkey_get_details($key);
  return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

function kg_raw_public_to_pem(string $raw): string {
  $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
  return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

// VAPID-Schlüssel: werden beim ersten Aufruf auf dem Server erzeugt und nie ins Repo geschrieben
function kg_vapid(): array {
  kg_ensure_data_dir();
  $f = KG_DATA_DIR . '/vapid.json';
  if (file_exists($f)) return json_decode(file_get_contents($f), true);
  $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
  openssl_pkey_export($key, $privPem);
  $v = ['private_pem' => $privPem, 'public' => b64u_enc(kg_ec_public_raw($key))];
  file_put_contents($f, json_encode($v));
  chmod($f, 0600);
  return $v;
}

// DER-ECDSA-Signatur in rohes r||s (64 Byte) umwandeln
function kg_der_to_raw(string $der): string {
  $off = 2; if (ord($der[1]) & 0x80) $off += ord($der[1]) & 0x7f;
  $rLen = ord($der[$off + 1]); $r = substr($der, $off + 2, $rLen); $off += 2 + $rLen;
  $sLen = ord($der[$off + 1]); $s = substr($der, $off + 2, $sLen);
  $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT);
  $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
  return $r . $s;
}

function kg_vapid_jwt(string $endpoint, array $vapid): string {
  $p = parse_url($endpoint);
  $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
  $h = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
  $c = b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => KG_VAPID_SUBJECT]));
  $priv = openssl_pkey_get_private($vapid['private_pem']);
  openssl_sign("$h.$c", $sigDer, $priv, OPENSSL_ALGO_SHA256);
  return "$h.$c." . b64u_enc(kg_der_to_raw($sigDer));
}

// RFC 8291 / RFC 8188: Nachricht für das Gerät verschlüsseln
function kg_encrypt(string $payload, string $p256dhB64, string $authB64): string {
  $uaPublic = b64u_dec($p256dhB64);
  $auth = b64u_dec($authB64);
  $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
  $asPublic = kg_ec_public_raw($eph);
  $shared = openssl_pkey_derive(openssl_pkey_get_public(kg_raw_public_to_pem($uaPublic)), $eph, 32);
  if ($shared === false) throw new RuntimeException('ECDH fehlgeschlagen');
  $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $auth);
  $salt = random_bytes(16);
  $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
  $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
  $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
  return $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $ct . $tag;
}

function kg_send(array $sub, array $message, array $vapid): int {
  $body = kg_encrypt(json_encode($message, JSON_UNESCAPED_UNICODE), $sub['keys']['p256dh'], $sub['keys']['auth']);
  $ch = curl_init($sub['endpoint']);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/octet-stream',
      'Content-Encoding: aes128gcm',
      'TTL: 21600',
      'Urgency: high',
      'Authorization: vapid t=' . kg_vapid_jwt($sub['endpoint'], $vapid) . ', k=' . $vapid['public'],
    ],
  ]);
  curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return $code;
}

function kg_subs_file(): string { kg_ensure_data_dir(); return KG_DATA_DIR . '/subscriptions.json'; }
function kg_subs_load(): array { $f = kg_subs_file(); return file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; }
function kg_subs_save(array $subs): void { file_put_contents(kg_subs_file(), json_encode(array_values($subs), JSON_UNESCAPED_SLASHES), LOCK_EX); }
