<?php
# ==============================================================================
# Notification Dereference Endpoint
# ==============================================================================

require __DIR__.'/../src/database.php';

# Parse the URL to extract notification ID
# URL format: /notifications/notif_68e22343480ba5.98100709
$requestUri = $_SERVER['REQUEST_URI'] ?? '';

# Extract notification ID from URL path
# Match pattern: /notifications/{id} or /public/notifications/{id}
if (preg_match('#/notifications/([a-zA-Z0-9_\.]+)#', $requestUri, $matches)) {
    $notificationId = $matches[1];
} else {
    http_response_code(400);
    echo 'Invalid notification URL format';
    exit;
}

# Get base URL for constructing full notification ID
$cfg = require __DIR__.'/../src/config.php';
$baseUrl = rtrim($cfg['base_url'], '/');

# Construct the full notification IRI that would be stored in JSON
$fullNotificationId = $baseUrl . '/notifications/' . $notificationId;

# Initialize database
$pdo = db();

# Query notification by JSON id field
# We search in the body_jsonld JSON column for matching id
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

# Check if notification was found
if (!$row) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Notification not found',
        'requested_id' => $fullNotificationId
    ]);
    exit;
}

# Return the notification content with proper headers
# Include Link header pointing to the dereferenceable database URL
header('Content-Type: ' . $row['content_type']);
header('Link: <' . $row['notification_iri'] . '>; rel="alternate"');

echo $row['body_jsonld'];
