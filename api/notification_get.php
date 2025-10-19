<?php

require __DIR__.'/../src/database.php';

# Extract and validate the notification ID from query parameters
# The ID must be a positive integer to be valid
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

# Validate that we have a valid notification ID
# Return 400 Bad Request if the ID is missing or invalid
if ($id <= 0)
{
    http_response_code(400);
    echo 'Missing id';
    exit;
}

# Initialize database connection
$pdo = db();

# Prepare SQL query to fetch the notification content
$stmt = $pdo->prepare("
  SELECT i_notifications.content_type,
         i_notifications.body_jsonld
  FROM i_notifications
  WHERE i_notifications.id = :id AND
        i_notifications.status = 'accepted'
");

# Execute the query with bound parameter
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

# Check if the notification was found
if (!$row)
{
    http_response_code(404);
    echo 'Not found';
    exit;
}

# Return the notification content exactly as it was stored
header('Content-Type: '.$row['content_type']);
echo $row['body_jsonld'];

