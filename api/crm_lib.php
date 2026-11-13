<?php
declare(strict_types=1);
// CRM-Helfer für Server (Cron) und Kongrow-Connector. Die Karten liegen in derselben Tabelle wie in der App.
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/notify_lib.php';

const CRM_BASE = 'appFqTB9ogHBBIFU9'; const CRM_TBL = 'tblKgCrmDeals0001'; const CRM_TASK_TBL = 'tblibWJMIvlTBELVO';
// Spalten, aus denen ein gebuchter Call automatisch nach „Meeting booked“ schiebt (weiter hinten wird nichts zurückgeschoben)
const CRM_BEFORE_MEETING = ['lead', 'active', 'noshow', 'cancelled', 'fu_budget', 'fu_ghosted', 'fu_logistics'];
const CRM_COL_NAMES = ['lead' => 'Leads', 'meeting' => 'Meeting booked', 'showup' => 'Show up', 'noshow' => 'No Show', 'cancelled' => 'Cancelled', 'proposal' => 'Proposal Delivered', 'active' => 'Active conversation',
  'won_paid' => 'Won (paid)', 'won_upsell' => 'Won (upselling)', 'fu_budget' => 'Follow up later: Budget', 'fu_ghosted' => 'Follow up later: Ghosted', 'fu_logistics' => 'Follow up later: Logistics', 'notfit' => 'Not a good fit'];

function crm_rows(int $uid): array {
  $st = kg_db()->prepare('SELECT id, fields FROM records WHERE user_id=? AND base=? AND tbl=?'); $st->execute([$uid, CRM_BASE, CRM_TBL]);
  return array_map(fn($r) => ['id' => $r['id'], 'f' => json_decode($r['fields'], true) ?: []], $st->fetchAll(PDO::FETCH_ASSOC));
}
function crm_new_id(): string { $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'; $s = 'rec'; for ($i = 0; $i < 14; $i++) $s .= $a[random_int(0, 61)]; return $s; }
function crm_insert(int $uid, string $tbl, array $f): string {
  $f = array_filter($f, fn($v) => !($v === null || $v === '' || $v === false || $v === []));
  $id = crm_new_id();
  kg_db()->prepare('INSERT INTO records(id,user_id,base,tbl,fields,created) VALUES(?,?,?,?,?,?)')->execute([$id, $uid, CRM_BASE, $tbl, json_encode($f, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d\TH:i:s.000\Z')]);
  return $id;
}
function crm_write(string $id, array $f): void { kg_db()->prepare('UPDATE records SET fields=? WHERE id=?')->execute([json_encode($f, JSON_UNESCAPED_UNICODE), $id]); }
function crm_today(): string { return (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d'); }

// Karte auf „Meeting booked“ setzen (nur vorwärts), Call-Zeit eintragen, offene Follow-up-Aufgabe erledigen
function crm_set_meeting(int $uid, array $row, string $callIso, string $via): ?array {
  $f = $row['f']; $st = $f['Status'] ?? 'lead';
  $call = (new DateTime('@' . (strtotime($callIso) ?: time())))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i');   // wie in der App: Ortszeit
  if ($st === 'meeting' && ($f['Call'] ?? '') === $call) return null;
  if ($st !== 'meeting' && !in_array($st, CRM_BEFORE_MEETING, true)) return null;
  $log = json_decode((string)($f['Verlauf'] ?? '[]'), true) ?: [];
  if ($st !== 'meeting') $log[] = ['d' => gmdate('c'), 's' => 'meeting', 'auto' => 1, 'via' => $via];
  $f['Status'] = 'meeting'; $f['Call'] = $call; $f['Verlauf'] = json_encode(array_slice($log, -60)); $f['Geaendert'] = gmdate('c');
  // Follow-up ist durch den Termin erledigt
  if (!empty($f['FollowUpTaskId'])) {
    $t = kg_db()->prepare('SELECT fields FROM records WHERE id=? AND user_id=?'); $t->execute([$f['FollowUpTaskId'], $uid]);
    $tf = json_decode((string)$t->fetchColumn(), true);
    if (is_array($tf) && empty($tf['Erledigt am'])) { $tf['Erledigt am'] = crm_today(); crm_write($f['FollowUpTaskId'], $tf); }
    unset($f['FollowUp']);
  }
  crm_write($row['id'], $f);
  $when = (new DateTime($callIso))->setTimezone(new DateTimeZone('Europe/Berlin'));
  $wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int)$when->format('w')];
  return ['title' => '📞 Call gebucht: ' . mb_substr((string)($f['Name'] ?? ''), 0, 40), 'body' => $wd . ', ' . $when->format('d.m., H:i') . ' Uhr · im CRM auf „Meeting booked“ gesetzt', 'url' => './?open=crm&deal=' . $row['id'], 'tag' => 'crm-' . $row['id']];
}

// Google-Kalender: gebuchte Termine (z. B. über das Buchungstool) einem Lead zuordnen, über den vollen Namen
function crm_norm(string $s): string { $s = mb_strtolower($s); $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']); return preg_replace('/[^a-z0-9@. ]+/', ' ', $s); }
function crm_name_hit(string $name, string $hay): bool {
  $words = array_values(array_filter(explode(' ', crm_norm($name)), fn($w) => mb_strlen($w) >= 2));
  if (count($words) < 2 && (count($words) === 0 || mb_strlen($words[0]) < 5)) return false;   // ein kurzer Vorname allein ist zu unsicher
  $h = ' ' . crm_norm($hay) . ' ';
  foreach ($words as $w) if (!preg_match('/\b' . preg_quote($w, '/') . '\b/', $h)) return false;
  return true;
}
function crm_calendar_check(int $uid): array {
  $rows = array_filter(crm_rows($uid), fn($r) => in_array($r['f']['Status'] ?? '', array_merge(CRM_BEFORE_MEETING, ['meeting']), true) && trim((string)($r['f']['Name'] ?? '')) !== '');
  if (!$rows) return [];
  $at = n_google_token($uid); if ($at === '') return [];
  $q = http_build_query(['timeMin' => gmdate('c', time() - 3600), 'timeMax' => gmdate('c', time() + 45 * 86400), 'singleEvents' => 'true', 'orderBy' => 'startTime', 'maxResults' => 250]);
  $r = kg_http('GET', 'https://www.googleapis.com/calendar/v3/calendars/primary/events?' . $q, ['Authorization: Bearer ' . $at]);
  if (($r['code'] ?? 0) !== 200) return [];
  $msgs = [];
  foreach ($r['json']['items'] ?? [] as $e) {
    if (empty($e['start']['dateTime']) || ($e['status'] ?? '') === 'cancelled') continue;
    $hay = ($e['summary'] ?? '') . ' ' . ($e['description'] ?? '');
    foreach ($e['attendees'] ?? [] as $a) $hay .= ' ' . ($a['displayName'] ?? '') . ' ' . ($a['email'] ?? '');
    foreach ($rows as $k => $row) {
      if (!crm_name_hit((string)$row['f']['Name'], $hay)) continue;
      if (($row['f']['Status'] ?? '') === 'meeting' && !empty($row['f']['Call'])) { unset($rows[$k]); continue; }   // Termin steht schon drin
      $m = crm_set_meeting($uid, $row, $e['start']['dateTime'], 'kalender'); if ($m) $msgs[] = $m;
      unset($rows[$k]);
    }
  }
  return $msgs;
}
