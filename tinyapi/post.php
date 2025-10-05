<?php

require __DIR__.'/../src/database.php';
require __DIR__.'/../src/utils.php';

$inboxId = isset($_GET['inbox_id']) ? (int)$_GET['inbox_id'] : 0;

if ($inboxId <= 0)
{
  http_response_code(400);
  echo json_encode(['error' => 'Missing or invalid inbox_id']);
  exit;
}

$pdo = db();
$pdo->beginTransaction();

try
{
  $contentType = $_SERVER['CONTENT_TYPE'] ?? 'application/ld+json';


  if ($raw === '')
  {
    http_response_code(400);
    echo json_encode(['error' => 'Empty request body']);
    exit;
  }

  $json = json_decode($raw, true);

  if (json_last_error() !== JSON_ERROR_NONE)
  {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
  }

  [$asType, $asObject, $asTarget, $actorIri, $corrToken] = extract_as_fields($json);

  $digest = hash('sha256', $raw, true);

  $senderId = null;

  if ($actorIri)
  {
    $stmt = $pdo->prepare("
      INSERT INTO s_senders (s_senders.actor_iri)
      VALUES (:actor_iri)
      ON DUPLICATE KEY UPDATE s_senders.actor_iri = VALUES(s_senders.actor_iri)
    ");
    $stmt->execute([':actor_iri' => $actorIri]);

    $stmt = $pdo->prepare("
      SELECT s_senders.id
      FROM s_senders
      WHERE s_senders.actor_iri = :actor_iri
      LIMIT 1
    ");
    $stmt->execute([':actor_iri' => $actorIri]);
    $senderId = (int)$stmt->fetchColumn();
  }

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

  $stmt = $pdo->prepare("
    SELECT i_notifications.id
    FROM i_notifications
    WHERE i_notifications.inbox_id = :inbox_id AND
          i_notifications.digest_sha256 = :digest_sha256
    LIMIT 1
  ");
  $stmt->execute([':inbox_id' => $inboxId, ':digest_sha256' => $digest]);
  $notifId = (int)$stmt->fetchColumn();

  $notifIri = mint_notification_iri($notifId);

  $stmt = $pdo->prepare("
    UPDATE i_notifications
    SET i_notifications.notification_iri = :notification_iri
    WHERE i_notifications.id = :id AND i_notifications.notification_iri IS NULL
  ");
  $stmt->execute([':notification_iri' => $notifIri, ':id' => $notifId]);

  $pdo->commit();

  header('Location: '.$notifIri, true, 201);
  header('Content-Type: application/json');

  echo json_encode([
    'id' => $notifId,
    'iri' => $notifIri,
    'status' => 'accepted',
  ]);
}
catch (Throwable $e)
{
  $pdo->rollBack();

  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Server error', 'detail' => $e->getMessage()]);
}
