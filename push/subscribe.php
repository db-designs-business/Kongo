<?php
// Speichert / entfernt das Push-Abo eines Geräts
require __DIR__ . '/lib.php';
require __DIR__ . '/../api/lib.php';
kg_require_user();
header('Content-Type: application/json');
$in = json_decode(file_get_contents('php://input'), true);
$sub = $in['subscription'] ?? null;
if (!$sub || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])
    || !preg_match('#^https://#', $sub['endpoint'])) {
  http_response_code(400); echo json_encode(['ok' => false]); exit;
}
$subs = array_filter(kg_subs_load(), fn($s) => $s['endpoint'] !== $sub['endpoint']);
if (($in['action'] ?? 'subscribe') === 'subscribe') {
  $subs[] = ['endpoint' => $sub['endpoint'], 'keys' => ['p256dh' => $sub['keys']['p256dh'], 'auth' => $sub['keys']['auth']], 'created' => date('c')];
}
kg_subs_save($subs);
echo json_encode(['ok' => true, 'count' => count($subs)]);
