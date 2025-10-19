<?php

require __DIR__.'/../src/database.php';

# Extract and validate the outgoing notification ID from query parameters
# The ID must be a positive integer to be valid
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

# Validate that we have a valid outgoing notification ID
# Return 400 Bad Request if the ID is missing or invalid
if ($id <= 0)
{
    http_response_code(400);
    echo 'Missing id';
    exit;
}

# Initialize database connection
$pdo = db();

$stmt = $pdo->prepare("
  SELECT o_outgoing_notifications.content_type,
         o_outgoing_notifications.body_jsonld
  FROM o_outgoing_notifications
  WHERE o_outgoing_notifications.id = :id
");

$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

# If not found, return a 404 Not Found status
if (!$row)
{
    http_response_code(404);
    echo 'Not found';
    exit;
}

header('Content-Type: '.($row['content_type'] ?: 'application/ld+json'));
echo $row['body_jsonld'];

