<?php

require __DIR__.'/../src/database.php';

$ct = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($ct, 'application/json') !== false)
{
}
else
{
    $payload = $_POST;
}

$toInbox   = trim($payload['to_inbox_iri'] ?? '');

$fromUser  = isset($payload['from_user_id']) ? (int)$payload['from_user_id'] : null;

$corrToken = isset($payload['corr_token']) ? trim($payload['corr_token']) : null;

$replyToId = isset($payload['reply_to_notification_id']) &&
             $payload['reply_to_notification_id'] !== ''
            ? (int)$payload['reply_to_notification_id']
            : null;

$body      = $payload['body_jsonld'] ?? '';

if ($toInbox === '' || $body === '')
{
  http_response_code(400);
  echo json_encode(['error' => 'to_inbox_iri and body_jsonld are required']);
  exit;
}

$json = json_decode($body, true);
$asType = isset($json['type']) ? (is_array($json['type']) ? ($json['type'][0] ?? null) : $json['type']) : null;

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

$stmt->execute([
  ':from_user_id' => $fromUser,
  ':to_inbox_iri' => $toInbox,
  ':body_jsonld' => $body,
  ':as_type' => $asType,
  ':corr_token' => $corrToken,
  ':reply_to_notification_id' => $replyToId
]);

$outId = (int)$pdo->lastInsertId();

$cfg = require __DIR__.'/../src/config.php';
$externalBaseUrl = rtrim($cfg['base_url'], '/');

$targetInboxUrl = $toInbox;
if (strpos($toInbox, $externalBaseUrl) === 0)
{
  $targetInboxUrl = str_replace($externalBaseUrl, $internalBaseUrl, $toInbox);
}

$ch = curl_init($targetInboxUrl);

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

$response = curl_exec($ch);
$err      = curl_error($ch);
$info     = curl_getinfo($ch);
curl_close($ch);

$status = $info['http_code'] ?? 0;

list($rawHeaders, $rawBody) = explode("\r\n\r\n", $response, 2) + [1 => ''];

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

$stmt->execute([
  ':outgoing_notification_id' => $outId,
  ':response_status' => $status ?: null,
  ':response_headers' => substr($rawHeaders, 0, 65535),
  ':response_body' => substr($rawBody, 0, 1000000)
]);

if ($err || $status < 200 || $status >= 300)
{
    $errorMessage = $err ?: "HTTP $status";

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
    $stmt = $pdo->prepare("
      UPDATE o_outgoing_notifications
      SET o_outgoing_notifications.delivery_status = 'delivered'
      WHERE o_outgoing_notifications.id = :id
    ");
    $stmt->execute([':id' => $outId]);
}

header('Content-Type: application/json');
echo json_encode([
  'id' => $outId,
  'status' => $err ? 'failed' : 'delivered',
  'http_code' => $status,
  'error' => $err ?: null,
]);
