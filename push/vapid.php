<?php
// Liefert den öffentlichen VAPID-Schlüssel an die App
require __DIR__ . '/lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['publicKey' => kg_vapid()['public']]);
