<?php
require __DIR__.'/../src/database.php';
require __DIR__.'/../src/utils.php';

# ==============================================================================
# STEP 1: Validate Inbox ID
# ==============================================================================
$inboxId = isset($_GET['inbox_id']) ? (int)$_GET['inbox_id'] : 0;

# Validate that inbox_id is a positive integer
if ($inboxId <= 0)
{
  http_response_code(400);
  echo json_encode(['error' => 'Missing or invalid inbox_id']);
  exit;
}

# Initialize database connection and begin transaction
# Using a transaction ensures atomicity - either all steps succeed or none do
$pdo = db();
$pdo->beginTransaction();

try
{
  # ==============================================================================
  # STEP 2: Verify Inbox Exists
  # ==============================================================================
  $stmt = $pdo->prepare("
    SELECT i_inboxes.id,
           i_inboxes.visibility
    FROM i_inboxes
    WHERE i_inboxes.id = :inbox_id
  ");
  $stmt->execute([':inbox_id' => $inboxId]);
  $inbox = $stmt->fetch();

  # If inbox not found, return 404 Not Found
  if (!$inbox)
  {
    http_response_code(404);
    echo json_encode(['error' => 'Inbox not found']);
    exit;
  }

  # ==============================================================================
  # STEP 3: Read and Validate Request Body
  # ==============================================================================
  $contentType = $_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream';

  # Read the raw request body from PHP input stream
  # This contains the JSON-LD notification payload
  $raw = file_get_contents('php://input') ?: '';

  # Reject empty requests - LDN requires a notification body
  if ($raw === '')
  {
    http_response_code(400);
    echo json_encode(['error' => 'Empty request body']);
    exit;
  }

  # ==============================================================================
  # STEP 4: Parse JSON and Extract ActivityStreams Fields
  # ==============================================================================
  $json = json_decode($raw, true);

  # If JSON parsing fails, reject with 415 Unsupported Media Type
  # The LDN spec requires valid JSON-LD
  if (json_last_error() !== JSON_ERROR_NONE)
  {
    http_response_code(415);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
  }

  # Extract key ActivityStreams fields for indexing and querying
  [$asType, $asObject, $asTarget, $actorIri, $corrToken] = extract_as_fields($json);

  # ==============================================================================
  # STEP 5: ACL (Access Control List) Validation
  # ==============================================================================
  $authToken = $_GET['token'] ?? ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? null);

  # Verify ACL permissions using the actor IRI and optional auth token
  # If not allowed, reject with 403 Forbidden
  if (!acl_allows($pdo, $inboxId, $actorIri, $authToken))
  {
    http_response_code(403);
    echo json_encode(['error' => 'Sender not allowed by ACL']);
    exit;
  }

  # ==============================================================================
  # STEP 6: Deduplication via SHA-256 Digest
  # ==============================================================================
  $digest = hash('sha256', $raw, true);

  # ==============================================================================
  # STEP 7: Record Sender Information
  # ==============================================================================
  $senderId = null;

  if ($actorIri)
  {
    # Insert or update the sender record
    # ON DUPLICATE KEY UPDATE ensures idempotency if sender already exists
    $stmt = $pdo->prepare("
      INSERT INTO s_senders (s_senders.actor_iri)
      VALUES (:actor_iri)
      ON DUPLICATE KEY UPDATE s_senders.actor_iri = VALUES(s_senders.actor_iri)
    ");
    $stmt->execute([':actor_iri' => $actorIri]);

    # Fetch the sender ID
    # We query instead of using lastInsertId because ON DUPLICATE KEY
    # doesn't reliably return ID when the row already existed
    $stmt = $pdo->prepare("
      SELECT s_senders.id
      FROM s_senders
      WHERE s_senders.actor_iri = :actor_iri
      LIMIT 1
    ");
    $stmt->execute([':actor_iri' => $actorIri]);
    $senderId = (int)$stmt->fetchColumn();
  }

  # ==============================================================================
  # STEP 8: Insert Notification (Idempotent by Digest)
  # ==============================================================================
  $stmt = $pdo->prepare("
    INSERT INTO i_notifications
      (i_notifications.inbox_id,
      i_notifications.sender_id,
      i_notifications.content_type,
      i_notifications.body_jsonld,
      i_notifications.as_type,
      i_notifications.as_object_iri,
      i_notifications.as_target_iri,
      i_notifications.digest_sha256,
      i_notifications.corr_token)
    VALUES
      (:inbox_id,
      :sender_id,
      :content_type,
      :body_jsonld,
      :as_type,
      :as_object_iri,
      :as_target_iri,
      :digest_sha256,
      :corr_token)
    ON DUPLICATE KEY UPDATE i_notifications.id = i_notifications.id
  ");

  $stmt->execute([
    ':inbox_id' => $inboxId,
    ':sender_id' => $senderId,
    ':content_type' => $contentType,
    ':body_jsonld' => $raw,
    ':as_type' => $asType,
    ':as_object_iri' => $asObject,
    ':as_target_iri' => $asTarget,
    ':digest_sha256' => $digest,
    ':corr_token' => $corrToken
  ]);

  # ==============================================================================
  # STEP 9: Fetch Notification ID
  # ==============================================================================
  $stmt = $pdo->prepare("
    SELECT i_notifications.id
    FROM i_notifications
    WHERE i_notifications.inbox_id = :inbox_id AND
          i_notifications.digest_sha256 = :digest_sha256
    LIMIT 1
  ");
  $stmt->execute([':inbox_id' => $inboxId, ':digest_sha256' => $digest]);
  $notifId = (int)$stmt->fetchColumn();

  # ==============================================================================
  # STEP 10: Mint and Store Notification IRI
  # ==============================================================================
  $notifIri = mint_notification_iri($notifId);

  # Update the notification with its public IRI
  # Only update if notification_iri is NULL (first time processing)
  $stmt = $pdo->prepare("
    UPDATE i_notifications
    SET i_notifications.notification_iri = :notification_iri
    WHERE i_notifications.id = :id AND i_notifications.notification_iri IS NULL
  ");
  $stmt->execute([':notification_iri' => $notifIri, ':id' => $notifId]);

  # ==============================================================================
  # STEP 11: Record HTTP Metadata for Audit Trail
  # ==============================================================================
  $stmt = $pdo->prepare("
    INSERT INTO i_notification_http_meta
      (i_notification_http_meta.notification_id,
      i_notification_http_meta.method,
      i_notification_http_meta.origin_ip,
      i_notification_http_meta.user_agent,
      i_notification_http_meta.header_host,
      i_notification_http_meta.header_signature,
      i_notification_http_meta.status_code)
    VALUES
      (:notification_id,
      'POST',
      :origin_ip,
      :user_agent,
      :header_host,
      :header_signature,
      201)
  ");

  $stmt->execute([
    ':notification_id' => $notifId,
    ':origin_ip' => client_ip_bin(),
    ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ':header_host' => $_SERVER['HTTP_HOST'] ?? null,
    ':header_signature' => $_SERVER['HTTP_SIGNATURE'] ?? null,
  ]);

  # ==============================================================================
  # STEP 12: Commit Transaction and Return Success
  # ==============================================================================
  $pdo->commit();

  # Return 201 Created with Location header per LDN specification
  # The Location header contains the IRI where the notification can be retrieved
  header('Location: '.$notifIri, true, 201);
  header('Content-Type: application/json');

  # Return JSON response with notification details
  echo json_encode([
    'id' => $notifId,
    'iri' => $notifIri,
    'status' => 'accepted',
  ]);
}
catch (Throwable $e)
{
  # ==============================================================================
  # Error Handling: Rollback Transaction
  # ==============================================================================
  $pdo->rollBack();

  # Return 500 Internal Server Error with error details
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Server error', 'detail' => $e->getMessage()]);
}

