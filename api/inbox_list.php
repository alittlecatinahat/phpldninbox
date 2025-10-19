<?php

require __DIR__.'/../src/database.php';
require __DIR__.'/../src/utils.php';

$inboxId = isset($_GET['inbox_id']) ? (int)$_GET['inbox_id'] : 0;

$limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));

if ($inboxId <= 0)
{
  http_response_code(400);
  echo json_encode(['error' => 'Missing or invalid inbox_id']);
  exit;
}

$pdo = db();

# Prepare SQL query to fetch notifications from the specified inbox
# We only return 'accepted' notifications - rejected and deleted ones are hidden
# Results are ordered by received_at DESC to show newest first
$stmt = $pdo->prepare("
  SELECT id,
         notification_iri,
         as_type,
         as_object_iri,
         as_target_iri,
         received_at
  FROM i_notifications
  WHERE inbox_id = :inbox_id AND
        status = 'accepted'
  ORDER BY received_at DESC
  LIMIT :limit
");

# Execute the query with bound parameters
# Using prepared statements prevents SQL injection
$stmt->execute([':inbox_id' => $inboxId, ':limit' => $limit]);
$rows = $stmt->fetchAll();

# Set JSON response header with UTF-8 encoding
header('Content-Type: application/json; charset=utf-8');

echo json_encode(
    [
        'inbox_id' => $inboxId,
        'items' => array_map(
            function($r)
            {
                return [
                    'id' => (int)$r['id'],
                    'iri' => $r['notification_iri'],
                    'type' => $r['as_type'],
                    'object' => $r['as_object_iri'],
                    'target' => $r['as_target_iri'],
                    'received_at' => $r['received_at'],
                ];
            },
            $rows
        ),
    ],
    JSON_UNESCAPED_SLASHES
);

