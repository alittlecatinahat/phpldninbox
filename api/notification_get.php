<?php

require __DIR__.'/../src/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0)
{
    http_response_code(400);
    echo 'Missing id';
    exit;
}

$pdo = db();

$stmt = $pdo->prepare("
  SELECT i_notifications.content_type,
         i_notifications.body_jsonld
  FROM i_notifications
  WHERE i_notifications.id = :id AND
        i_notifications.status = 'accepted'
");

$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if (!$row)
{
    http_response_code(404);
    echo 'Not found';
    exit;
}

header('Content-Type: '.$row['content_type']);
echo $row['body_jsonld'];

