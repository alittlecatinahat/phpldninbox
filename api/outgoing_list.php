<?php

require __DIR__.'/../src/database.php';

# Set JSON response header with UTF-8 encoding
# This ensures proper character encoding for international content
header('Content-Type: application/json');

# Extract and validate the status filter from query parameters
# Status filtering allows clients to view only pending, delivered, or failed messages
$status = isset($_GET['status']) ? trim($_GET['status']) : null;
$validStatus = ['pending','delivered','failed'];

# Validate the status parameter if provided
# Only allow the three valid delivery status values
if ($status !== null && !in_array($status, $validStatus, true))
{
  http_response_code(400);
  echo json_encode(['error' => 'Invalid status']);
  exit;
}

# Extract the reply_to_notification_id filter from query parameters
$replyToId = isset($_GET['reply_to_notification_id']) && $_GET['reply_to_notification_id'] !== ''
  ? (int)$_GET['reply_to_notification_id']
  : null;

# Extract and validate the result limit from query parameters
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if ($limit <= 0)
{
    $limit = 50;
}
if ($limit > 1000)
{
    $limit = 1000;
}

$pdo = db();

$sql = "SELECT o_outgoing_notifications.id,
               o_outgoing_notifications.from_user_id,
               o_outgoing_notifications.to_inbox_iri,
               o_outgoing_notifications.as_type,
               o_outgoing_notifications.corr_token,
               o_outgoing_notifications.reply_to_notification_id,
               o_outgoing_notifications.delivery_status,
               o_outgoing_notifications.content_type,
               o_outgoing_notifications.created_at
        FROM o_outgoing_notifications";

$conds = [];
$params = [];

# Add status condition if status filter was provided
if ($status !== null)
{
    $conds[] = 'o_outgoing_notifications.delivery_status = :status';
    $params[':status'] = $status;
}

# Add reply_to_notification_id condition if filter was provided
if ($replyToId !== null)
{
    $conds[] = 'o_outgoing_notifications.reply_to_notification_id = :reply_to_id';
    $params[':reply_to_id'] = $replyToId;
}

# Append WHERE clause if any conditions were added
if ($conds)
{
    $sql .= ' WHERE '.implode(' AND ', $conds);
}

# Order by created_at DESC to show newest outgoing notifications first
# Add the LIMIT clause to restrict result set size
$sql .= ' ORDER BY o_outgoing_notifications.created_at DESC LIMIT :limit';

$stmt = $pdo->prepare($sql);

foreach ($params as $k => $v)
{
    $stmt->bindValue($k, $v);
}

$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

$stmt->execute();

$rows = $stmt->fetchAll();

# Return the results as a JSON response
echo json_encode(['items' => $rows]);

