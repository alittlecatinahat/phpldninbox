<?php

require __DIR__.'/../src/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0)
{
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid id']);
    exit;
}

$pdo = db();

$stmt = $pdo->prepare("
  SELECT i_notifications.content_type,
         i_notifications.body_jsonld
  FROM i_notifications
  WHERE i_notifications.id = :id
");

$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if (!$row)
{
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

header('Content-Type: '.$row['content_type']);
echo $row['body_jsonld'];
