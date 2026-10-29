<?php
// Datenzugriff im Stil der Airtable-REST-API, damit die App unverändert weiterarbeitet.
//   GET    data.php?p=BASE/TABLE            -> {records:[{id,createdTime,fields}]}
//   POST   data.php?p=BASE/TABLE            {fields}               -> {id,createdTime,fields}
//   PATCH  data.php?p=BASE/TABLE/RECID      {fields}               -> {id,createdTime,fields}
//   PATCH  data.php?p=BASE/TABLE            {records:[{id,fields}]} -> {records:[...]}
//   DELETE data.php?p=BASE/TABLE/RECID                             -> {id,deleted:true}
//   POST   data.php?p=BASE/TABLE&import=1   {records:[{id,createdTime,fields}]} (Übernahme aus Airtable, upsert)
require __DIR__ . '/lib.php';
$uid = kg_require_user();
$db = kg_db();
$method = $_SERVER['REQUEST_METHOD'];
$p = (string)($_GET['p'] ?? '');
if (!preg_match('#^(app[A-Za-z0-9]{14})/(tbl[A-Za-z0-9]{14})(?:/(rec[A-Za-z0-9]{14}))?$#', $p, $m)) {
  kg_json(['error' => ['message' => 'Ungültiger Pfad']], 400);
}
[, $base, $tbl] = $m; $rid = $m[3] ?? null;
$in = kg_input();

function clean_fields($f): array {
  $out = [];
  if (!is_array($f)) return $out;
  foreach ($f as $k => $v) {
    if ($v === null || $v === '' || $v === false || $v === []) continue;
    $out[(string)$k] = $v;
  }
  return $out;
}
function new_id(): string {
  $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'; $s = 'rec';
  for ($i = 0; $i < 14; $i++) $s .= $a[random_int(0, 61)];
  return $s;
}
function out_row(array $r): array {
  $f = json_decode($r['fields'], true);
  return ['id' => $r['id'], 'createdTime' => $r['created'], 'fields' => $f ?: new stdClass()];
}
function load_one(PDO $db, int $uid, string $base, string $tbl, string $id): ?array {
  $st = $db->prepare('SELECT * FROM records WHERE id=? AND user_id=? AND base=? AND tbl=?');
  $st->execute([$id, $uid, $base, $tbl]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}
function patch_one(PDO $db, int $uid, string $base, string $tbl, string $id, $fields): array {
  $r = load_one($db, $uid, $base, $tbl, $id);
  if (!$r) kg_json(['error' => ['type' => 'NOT_FOUND', 'message' => 'Eintrag nicht gefunden']], 404);
  $cur = json_decode($r['fields'], true) ?: [];
  foreach ((is_array($fields) ? $fields : []) as $k => $v) {
    if ($v === null || $v === '' || $v === false || $v === []) unset($cur[$k]); else $cur[$k] = $v;
  }
  $db->prepare('UPDATE records SET fields=? WHERE id=?')->execute([json_encode($cur, JSON_UNESCAPED_UNICODE), $id]);
  $r['fields'] = json_encode($cur, JSON_UNESCAPED_UNICODE);
  return out_row($r);
}
function now_iso(): string { return gmdate('Y-m-d\TH:i:s.000\Z'); }

if ($method === 'GET') {
  if ($rid) { $r = load_one($db, $uid, $base, $tbl, $rid); if (!$r) kg_json(['error' => ['message' => 'Nicht gefunden']], 404); kg_json(out_row($r)); }
  $st = $db->prepare('SELECT * FROM records WHERE user_id=? AND base=? AND tbl=? ORDER BY created, rowid');
  $st->execute([$uid, $base, $tbl]);
  kg_json(['records' => array_map('out_row', $st->fetchAll(PDO::FETCH_ASSOC))]);
}

if ($method === 'POST' && isset($_GET['import'])) {
  $n = 0;
  $db->beginTransaction();
  $st = $db->prepare('INSERT INTO records(id,user_id,base,tbl,fields,created) VALUES(?,?,?,?,?,?)
    ON CONFLICT(id) DO UPDATE SET fields=excluded.fields WHERE records.user_id=excluded.user_id');
  foreach (($in['records'] ?? []) as $r) {
    $id = (string)($r['id'] ?? '');
    if (!preg_match('/^rec[A-Za-z0-9]{14}$/', $id)) continue;
    $st->execute([$id, $uid, $base, $tbl, json_encode(clean_fields($r['fields'] ?? []), JSON_UNESCAPED_UNICODE), (string)($r['createdTime'] ?? now_iso())]);
    $n++;
  }
  $db->commit();
  kg_json(['ok' => true, 'imported' => $n]);
}

if ($method === 'POST') {
  $id = new_id(); $created = now_iso();
  $fields = clean_fields($in['fields'] ?? []);
  $db->prepare('INSERT INTO records(id,user_id,base,tbl,fields,created) VALUES(?,?,?,?,?,?)')
     ->execute([$id, $uid, $base, $tbl, json_encode($fields, JSON_UNESCAPED_UNICODE), $created]);
  kg_json(['id' => $id, 'createdTime' => $created, 'fields' => $fields ?: new stdClass()]);
}

if ($method === 'PATCH' || $method === 'PUT') {
  if ($rid) kg_json(patch_one($db, $uid, $base, $tbl, $rid, $in['fields'] ?? []));
  $out = [];
  $db->beginTransaction();
  foreach (($in['records'] ?? []) as $r) $out[] = patch_one($db, $uid, $base, $tbl, (string)($r['id'] ?? ''), $r['fields'] ?? []);
  $db->commit();
  kg_json(['records' => $out]);
}

if ($method === 'DELETE' && $rid) {
  $st = $db->prepare('DELETE FROM records WHERE id=? AND user_id=? AND base=? AND tbl=?');
  $st->execute([$rid, $uid, $base, $tbl]);
  kg_json(['id' => $rid, 'deleted' => true]);
}

kg_json(['error' => ['message' => 'Nicht unterstützt']], 405);
