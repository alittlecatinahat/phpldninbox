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
  SELECT o_outgoing_notifications.content_type,
         o_outgoing_notifications.body_jsonld
  FROM o_outgoing_notifications
  WHERE o_outgoing_notifications.id = :id
");

$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if (!$row)
{
    http_response_code(404);
    echo 'Not found';
    exit;
}

header('Content-Type: '.($row['content_type'] ?: 'application/ld+json'));
echo $row['body_jsonld'];

