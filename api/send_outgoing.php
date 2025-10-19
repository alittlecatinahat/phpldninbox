<?php

require __DIR__.'/../src/database.php';

# ==============================================================================
# STEP 1: Parse Request Payload
# ==============================================================================
$ct = $_SERVER['CONTENT_TYPE'] ?? '';

# Parse JSON payload if Content-Type is application/json
if (stripos($ct, 'application/json') !== false)
{
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
}
# Otherwise, parse as form-encoded data
else
{
    $payload = $_POST;
}

# ==============================================================================
# STEP 2: Extract and Validate Required Fields
# ==============================================================================
$toInbox   = trim($payload['to_inbox_iri'] ?? '');

# Extract the sender user ID (optional)
# Links this outgoing notification to a local user account
$fromUser  = isset($payload['from_user_id']) ? (int)$payload['from_user_id'] : null;

# Extract correlation token (optional)
# Used to track related messages and conversation threads
$corrToken = isset($payload['corr_token']) ? trim($payload['corr_token']) : null;

# Extract reply_to_notification_id (optional)
# Links this outgoing notification to an incoming notification we're replying to
$replyToId = isset($payload['reply_to_notification_id']) &&
             $payload['reply_to_notification_id'] !== ''
            ? (int)$payload['reply_to_notification_id']
            : null;

# Extract the JSON-LD body (required)
# This is the actual notification payload to send
$body      = $payload['body_jsonld'] ?? '';

# Validate required fields
# Both target inbox and notification body are mandatory
if ($toInbox === '' || $body === '')
{
  http_response_code(400);
  echo json_encode(['error' => 'to_inbox_iri and body_jsonld are required']);
  exit;
}

# ==============================================================================
# STEP 3: Parse ActivityStreams Type for Indexing
# ==============================================================================
$json = json_decode($body, true);
$asType = isset($json['type']) ? (is_array($json['type']) ? ($json['type'][0] ?? null) : $json['type']) : null;

# ==============================================================================
# STEP 4: Insert Outgoing Notification Record
# ==============================================================================
$pdo = db();

$stmt = $pdo->prepare("
  INSERT INTO o_outgoing_notifications
    (o_outgoing_notifications.from_user_id,
    o_outgoing_notifications.to_inbox_iri,
    o_outgoing_notifications.body_jsonld,
    o_outgoing_notifications.as_type,
    o_outgoing_notifications.corr_token,
    o_outgoing_notifications.reply_to_notification_id,
    o_outgoing_notifications.delivery_status)
  VALUES
    (:from_user_id,
    :to_inbox_iri,
    :body_jsonld,
    :as_type,
    :corr_token,
    :reply_to_notification_id,
    'pending')
");

# Execute the insert with all extracted parameters
$stmt->execute([
  ':from_user_id' => $fromUser,
  ':to_inbox_iri' => $toInbox,
  ':body_jsonld' => $body,
  ':as_type' => $asType,
  ':corr_token' => $corrToken,
  ':reply_to_notification_id' => $replyToId
]);

# Get the ID of the newly created outgoing notification
$outId = (int)$pdo->lastInsertId();

# ==============================================================================
# STEP 5: Attempt Delivery via HTTP POST
# ==============================================================================
$cfg = require __DIR__.'/../src/config.php';
$externalBaseUrl = rtrim($cfg['base_url'], '/');
$internalBaseUrl = 'http://localhost:80';

# If the target inbox is on this system, translate to internal URL
$targetInboxUrl = $toInbox;
if (strpos($toInbox, $externalBaseUrl) === 0)
{
  # This is a local inbox - use internal URL to avoid Docker port mapping issues
  $targetInboxUrl = str_replace($externalBaseUrl, $internalBaseUrl, $toInbox);
}

# Initialize cURL session to send the notification
$ch = curl_init($targetInboxUrl);

# Configure cURL options for POST request
curl_setopt_array($ch, [
  CURLOPT_POST => true,                                      # Use POST method
  CURLOPT_HTTPHEADER => ['Content-Type: application/ld+json'], # LDN content type
  CURLOPT_POSTFIELDS => $body,                               # Send JSON-LD body
  CURLOPT_RETURNTRANSFER => true,                            # Return response as string
  CURLOPT_HEADER => true,                                    # Include headers in response
  CURLOPT_TIMEOUT => 10,                                     # 10-second timeout
  CURLOPT_SSL_VERIFYPEER => false,                           # Disable SSL peer verification (dev only)
  CURLOPT_SSL_VERIFYHOST => false,                           # Disable SSL host verification (dev only)
  CURLOPT_FOLLOWLOCATION => true,                            # Follow redirects
]);

# Execute the HTTP request
$response = curl_exec($ch);
$err      = curl_error($ch);
$info     = curl_getinfo($ch);
curl_close($ch);

# Extract HTTP status code from response
$status = $info['http_code'] ?? 0;

# ==============================================================================
# STEP 6: Parse Response and Separate Headers from Body
# ==============================================================================
list($rawHeaders, $rawBody) = explode("\r\n\r\n", $response, 2) + [1 => ''];

# ==============================================================================
# STEP 7: Record Delivery Attempt
# ==============================================================================
$stmt = $pdo->prepare("
  INSERT INTO o_delivery_attempts
    (o_delivery_attempts.outgoing_notification_id,
    o_delivery_attempts.attempt_no,
    o_delivery_attempts.response_status,
    o_delivery_attempts.response_headers,
    o_delivery_attempts.response_body)
  VALUES
    (:outgoing_notification_id,
    1,
    :response_status,
    :response_headers,
    :response_body)
");

# Execute with response data
$stmt->execute([
  ':outgoing_notification_id' => $outId,
  ':response_status' => $status ?: null,
  ':response_headers' => substr($rawHeaders, 0, 65535),
  ':response_body' => substr($rawBody, 0, 1000000)
]);

# ==============================================================================
# STEP 8: Update Delivery Status
# ==============================================================================
if ($err || $status < 200 || $status >= 300)
{
    # Delivery failed - update status and record error message
    $errorMessage = $err ?: "HTTP $status";

    # Add more specific error messages for common HTTP status codes
    if (!$err && $status > 0) {
        switch ($status) {
            case 400: $errorMessage = "HTTP 400 - Bad Request (malformed notification)"; break;
            case 403: $errorMessage = "HTTP 403 - Forbidden (rejected by ACL rules)"; break;
            case 404: $errorMessage = "HTTP 404 - Not Found (inbox does not exist)"; break;
            case 415: $errorMessage = "HTTP 415 - Unsupported Media Type (invalid JSON)"; break;
            case 500: $errorMessage = "HTTP 500 - Internal Server Error (receiver error)"; break;
            default: $errorMessage = "HTTP $status"; break;
        }
    }

    $stmt = $pdo->prepare("
      UPDATE o_outgoing_notifications
      SET o_outgoing_notifications.delivery_status = 'failed',
          o_outgoing_notifications.last_error = :last_error
      WHERE o_outgoing_notifications.id = :id
    ");
    $stmt->execute([':last_error' => $errorMessage, ':id' => $outId]);
}
else
{
    # Delivery successful - update status to delivered
    $stmt = $pdo->prepare("
      UPDATE o_outgoing_notifications
      SET o_outgoing_notifications.delivery_status = 'delivered'
      WHERE o_outgoing_notifications.id = :id
    ");
    $stmt->execute([':id' => $outId]);
}

# ==============================================================================
# STEP 9: Return JSON Response
# ==============================================================================
header('Content-Type: application/json');
echo json_encode([
  'id' => $outId,
  'status' => $err ? 'failed' : 'delivered',
  'http_code' => $status,
  'error' => $err ?: null,
]);
