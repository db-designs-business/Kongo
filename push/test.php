<?php
// Schickt eine Testnachricht an genau das Gerät, das sie anfordert
require __DIR__ . '/lib.php';
header('Content-Type: application/json');
$in = json_decode(file_get_contents('php://input'), true);
$endpoint = $in['endpoint'] ?? '';
$sub = null;
foreach (kg_subs_load() as $s) if ($s['endpoint'] === $endpoint) $sub = $s;
if (!$sub) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Gerät nicht registriert']); exit; }
$code = kg_send($sub, ['title' => 'Kongrow', 'body' => 'Push funktioniert ✓ – du bekommst um 5:30 und 20:30 deine Erinnerungen.', 'url' => './'], kg_vapid());
echo json_encode(['ok' => $code >= 200 && $code < 300, 'status' => $code]);
