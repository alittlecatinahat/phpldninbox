<?php

require __DIR__.'/../src/database.php';

$requestUri = $_SERVER['REQUEST_URI'] ?? '';

if (preg_match('#/notifications/([a-zA-Z0-9_\.]+)#', $requestUri, $matches)) {
    $notificationId = $matches[1];
} else {
    http_response_code(400);
    echo 'Invalid notification URL format';
    exit;
}

$cfg = require __DIR__.'/../src/config.php';
$baseUrl = rtrim($cfg['base_url'], '/');

$fullNotificationId = $baseUrl . '/notifications/' . $notificationId;

$pdo = db();

$stmt = $pdo->prepare("
  SELECT i_notifications.id,
         i_notifications.content_type,
         i_notifications.body_jsonld,
         i_notifications.notification_iri
  FROM i_notifications
  WHERE i_notifications.body_jsonld->>'$.id' = :notification_id
    AND i_notifications.status = 'accepted'
  LIMIT 1
");

$stmt->execute([':notification_id' => $fullNotificationId]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Notification not found',
        'requested_id' => $fullNotificationId
    ]);
    exit;
}

header('Content-Type: ' . $row['content_type']);
header('Link: <' . $row['notification_iri'] . '>; rel="alternate"');

echo $row['body_jsonld'];
