<?php
declare(strict_types=1);
// Kongrow-Connector für Claude (MCP, Streamable HTTP, nur JSON-Antworten).
// Claude speichert hier fertige Upwork-Proposal-Entwürfe beim passenden Job. Kongrow spricht dabei nicht mit Upwork.
// Zugang nur mit dem geheimen Schlüssel aus Kongrow (Upwork → Filter → Claude-Verbindung): api/mcp.php?key=…
require __DIR__ . '/upwork_lib.php';

header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }

// Nutzer über den Schlüssel finden
$key = (string)($_GET['key'] ?? '');
$uid = null;
if (preg_match('/^[a-f0-9]{40}$/', $key)) {
  kg_settings_init();
  foreach (kg_db()->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN) as $u) {
    $k = kg_get((int)$u, 'mcp_key');
    if (is_string($k) && hash_equals($k, $key)) { $uid = (int)$u; break; }
  }
}
if ($uid === null) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Ungültiger Schlüssel']]); exit; }

$raw = json_decode(file_get_contents('php://input') ?: 'null', true);
if (!is_array($raw)) { http_response_code(400); header('Content-Type: application/json'); echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]); exit; }
$batch = array_is_list($raw) ? $raw : [$raw];

const MCP_TOOLS = [
  [
    'name' => 'save_proposal_draft',
    'description' => "Speichert einen fertigen Upwork-Proposal-Entwurf beim passenden Job in Dominics App Kongrow (legt den Job unter „Gemerkt“ an, falls er noch nicht da ist) und schickt ihm eine Push-Nachricht. Dominic bearbeitet, kopiert und reicht dort selbst ein. Pro Job nur einmal aufrufen; ein erneuter Aufruf überschreibt den Entwurf.",
    'inputSchema' => ['type' => 'object', 'properties' => [
      'job_url' => ['type' => 'string', 'description' => 'Link zum Upwork-Job (muss die Job-ID ~0… enthalten)'],
      'proposal' => ['type' => 'string', 'description' => 'Der vollständige Proposal-Text, genau so wie er eingefügt werden soll'],
      'screening_answers' => ['type' => 'array', 'description' => 'Optional: die Screening-Fragen des Jobs mit Antwort, eine pro Eintrag', 'items' => ['type' => 'object', 'properties' => [
        'question' => ['type' => 'string', 'description' => 'Die Frage genau wie im Job'], 'answer' => ['type' => 'string', 'description' => 'Die Antwort']], 'required' => ['question', 'answer']]],
      'title' => ['type' => 'string', 'description' => 'Der vollständige Job-Titel, genau wie auf Upwork (ersetzt abgeschnittene Titel aus der Alert-Mail)'],
      'notes' => ['type' => 'string', 'description' => 'Optional: sehr kurze Hinweise für Dominic (max. 2–3 Stichpunkte), z. B. gewähltes Template, offene Platzhalter'],
    ], 'required' => ['job_url', 'proposal']],
  ],
  [
    'name' => 'get_proposal_draft',
    'description' => 'Liest den aktuellen (ggf. von Dominic bearbeiteten) Proposal-Entwurf zu einem Upwork-Job aus Kongrow.',
    'inputSchema' => ['type' => 'object', 'properties' => [
      'job_url' => ['type' => 'string', 'description' => 'Link zum Upwork-Job oder die Job-ID ~0…'],
    ], 'required' => ['job_url']],
  ],
];

function mcp_text(string $t, bool $err = false): array { return ['content' => [['type' => 'text', 'text' => $t]], 'isError' => $err]; }

function mcp_call(int $uid, string $name, array $a): array {
  $db = uw_db();
  if (!preg_match('/(~0[0-9a-z]{10,})/i', (string)($a['job_url'] ?? ''), $m)) return mcp_text('Kein gültiger Upwork-Job-Link: Er muss eine Job-ID wie ~02… enthalten.', true);
  $jk = $m[1];
  if ($name === 'get_proposal_draft') {
    $st = $db->prepare('SELECT title, draft, draft_answers, draft_ts FROM upwork_jobs WHERE user_id=? AND job_key=?'); $st->execute([$uid, $jk]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || ($r['draft'] ?? '') === '') return mcp_text('Zu diesem Job gibt es in Kongrow noch keinen Entwurf.');
    $qa = ''; foreach (uw_answers_list((string)($r['draft_answers'] ?? '')) as $x) $qa .= "\n\nFrage: {$x['question']}\nAntwort: {$x['answer']}";
    return mcp_text("Job: {$r['title']}\nStand: {$r['draft_ts']}\n\n--- Proposal ---\n{$r['draft']}" . ($qa !== '' ? "\n\n--- Screening-Antworten ---" . $qa : ''));
  }
  if ($name !== 'save_proposal_draft') return mcp_text('Unbekanntes Werkzeug: ' . $name, true);
  $text = trim((string)($a['proposal'] ?? ''));
  if ($text === '') return mcp_text('Der Proposal-Text fehlt.', true);
  $text = mb_substr($text, 0, 6000);
  $ans = uw_answers_json($a['screening_answers'] ?? null);
  $notes = mb_substr(trim((string)($a['notes'] ?? '')), 0, 1000);
  $title = mb_substr(trim((string)($a['title'] ?? '')), 0, 200);
  $now = gmdate('c');
  $st = $db->prepare('SELECT id, title, status FROM upwork_jobs WHERE user_id=? AND job_key=?'); $st->execute([$uid, $jk]);
  if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $id = (int)$row['id'];
    // vollständigen Titel übernehmen, wenn bisher nur ein Platzhalter oder ein abgeschnittener Titel aus der Alert-Mail da ist
    $cut = rtrim((string)preg_replace('/(…|\.\.\.)\s*$/u', '', (string)$row['title']));
    $better = $title !== '' && (str_starts_with((string)$row['title'], 'Upwork-Job ') || ($cut !== $row['title'] && mb_stripos($title, $cut) === 0));
    $newStatus = in_array($row['status'], ['uebersprungen', 'gefiltert'], true) ? 'gemerkt' : $row['status'];
    $db->prepare('UPDATE upwork_jobs SET draft=?, draft_answers=?, draft_notes=?, draft_ts=?, status=?, updated=?' . ($better ? ', title=?' : '') . ' WHERE id=?')
      ->execute(array_merge([$text, $ans, $notes, $now, $newStatus, $now], $better ? [$title] : [], [$id]));
    if ($better) { $db->prepare('UPDATE upwork_applied SET title=? WHERE user_id=? AND job_key=?')->execute([$title, $uid, $jk]); try { uw_sheet_title($uid, $jk, $title); } catch (Throwable $e) {} }
    $jobTitle = $better ? $title : $row['title'];
  } else {
    $db->prepare('DELETE FROM upwork_deleted WHERE user_id=? AND job_key=?')->execute([$uid, $jk]);
    $db->prepare("INSERT INTO upwork_jobs(user_id,msg_id,job_key,url,title,kind,budget,snippet,received,status,updated,draft,draft_answers,draft_notes,draft_ts) VALUES(?,?,?,?,?,'Manuell','','',?,'gemerkt',?,?,?,?,?)")
      ->execute([$uid, 'manual:' . $jk, $jk, 'https://www.upwork.com/jobs/' . $jk, $title !== '' ? $title : 'Upwork-Job ' . $jk, $now, $now, $text, $ans, $notes, $now]);
    $id = (int)$db->lastInsertId(); $jobTitle = $title !== '' ? $title : 'Upwork-Job ' . $jk;
  }
  uw_log($uid, 'Entwurf von Claude: ' . mb_substr($jobTitle, 0, 60));
  // Push: Entwurf ist fertig
  $pushed = 0;
  try {
    require_once __DIR__ . '/../push/lib.php';
    if (n_on(n_prefs($uid), 'upwork')) { ob_start(); $pushed = kg_push_all([['title' => '✍️ Proposal fertig', 'body' => $jobTitle, 'url' => './?open=upwork&job=' . $id . '&draft=1', 'tag' => 'upwork-draft-' . $id]]); ob_end_clean(); }
  } catch (Throwable $e) { if (ob_get_level()) ob_end_clean(); }
  return mcp_text("Gespeichert in Kongrow beim Job „{$jobTitle}“ (Kongrow-Job #$id)." . ($pushed ? ' Dominic hat eine Push-Nachricht bekommen.' : '') . " Er bearbeitet den Text dort, kopiert ihn und reicht selbst auf Upwork ein – nicht selbst einreichen.");
}

$out = [];
foreach ($batch as $req) {
  if (!is_array($req)) continue;
  $id = $req['id'] ?? null; $method = (string)($req['method'] ?? ''); $p = is_array($req['params'] ?? null) ? $req['params'] : [];
  if ($id === null) continue;   // Benachrichtigungen (z. B. notifications/initialized) brauchen keine Antwort
  $res = null; $err = null;
  switch ($method) {
    case 'initialize':
      $v = (string)($p['protocolVersion'] ?? '');
      $res = ['protocolVersion' => in_array($v, ['2025-06-18', '2025-03-26', '2024-11-05'], true) ? $v : '2025-03-26',
        'capabilities' => ['tools' => ['listChanged' => false]],
        'serverInfo' => ['name' => 'kongrow', 'version' => '1.0.0'],
        'instructions' => 'Kongrow ist Dominics persönliche App. Hier werden fertige Upwork-Proposal-Entwürfe beim Job abgelegt (save_proposal_draft). Dominic reicht selbst ein.'];
      break;
    case 'ping': $res = new stdClass(); break;
    case 'tools/list': $res = ['tools' => MCP_TOOLS]; break;
    case 'tools/call':
      try { $res = mcp_call($uid, (string)($p['name'] ?? ''), is_array($p['arguments'] ?? null) ? $p['arguments'] : []); }
      catch (Throwable $e) { $res = mcp_text('Fehler in Kongrow: ' . $e->getMessage(), true); }
      break;
    case 'resources/list': $res = ['resources' => []]; break;
    case 'prompts/list': $res = ['prompts' => []]; break;
    default: $err = ['code' => -32601, 'message' => 'Method not found: ' . $method];
  }
  $out[] = $err ? ['jsonrpc' => '2.0', 'id' => $id, 'error' => $err] : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $res];
}
if (!$out) { http_response_code(202); exit; }
header('Content-Type: application/json');
echo json_encode(array_is_list($raw) ? $out : $out[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
