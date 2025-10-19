<?php
# ==============================================================================
# Send Outgoing Notification Page (Manual JSON)
# ==============================================================================
# Form for manually sending JSON-LD notifications to remote inboxes
# Supports both external (HTTP) and internal (same system) delivery
# ==============================================================================

$pdo = db();
$msg = null;
$err = null;

# Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
  try
  {
    # Extract form data
    $toInbox   = trim($_POST['to_inbox_iri'] ?? '');
    $fromUser  = !empty($_POST['from_user_id']) ? (int)$_POST['from_user_id'] : null;
    $corrToken = !empty($_POST['corr_token']) ? trim($_POST['corr_token']) : null;
    $body      = $_POST['body_jsonld'] ?? '';

    # Validate required fields
    if ($toInbox === '' || $body === '')
    {
      throw new RuntimeException('to_inbox_iri and body_jsonld are required');
    }

    # Parse JSON to extract ActivityStreams type
    $json = json_decode($body, true);
    $asType = isset($json['type']) ? (is_array($json['type']) ? ($json['type'][0] ?? null) : $json['type']) : null;

    # Insert outgoing notification record
    $stmt = $pdo->prepare("
      INSERT INTO o_outgoing_notifications
        (o_outgoing_notifications.from_user_id,
        o_outgoing_notifications.to_inbox_iri,
        o_outgoing_notifications.body_jsonld,
        o_outgoing_notifications.as_type,
        o_outgoing_notifications.corr_token,
        o_outgoing_notifications.delivery_status)
      VALUES
        (:from_user_id, :to_inbox_iri, :body_jsonld, :as_type, :corr_token, 'pending')
    ");
    $stmt->execute([
      ':from_user_id' => $fromUser,
      ':to_inbox_iri' => $toInbox,
      ':body_jsonld' => $body,
      ':as_type' => $asType,
      ':corr_token' => $corrToken,
    ]);

    $outId = (int)$pdo->lastInsertId();

    # Check if target inbox is internal (same system)
    $cfg = require __DIR__.'/../../src/config.php';
    $baseUrl = rtrim($cfg['base_url'], '/');
    $isInternal = (strpos($toInbox, $baseUrl) === 0) ||
                  (strpos($toInbox, 'http://localhost') === 0) ||
                  (strpos($toInbox, 'http://127.0.0.1') === 0);

    if ($isInternal)
    {
      # Internal delivery - insert directly into database
      $parsedUrl = parse_url($toInbox);
      $path = $parsedUrl['path'] ?? '';
      parse_str($parsedUrl['query'] ?? '', $queryParams);

      $inboxId = isset($queryParams['inbox_id']) ? (int)$queryParams['inbox_id'] : null;

      if (!$inboxId)
      {
        throw new RuntimeException('Internal URL must contain inbox_id parameter');
      }

      # Verify inbox exists
      $_oldServer = $_SERVER;
      $_SERVER['REQUEST_METHOD'] = 'POST';
      $_SERVER['CONTENT_TYPE'] = 'application/ld+json';
      $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

      ob_start();
      $inboxStmt = $pdo->prepare("
        SELECT i_inboxes.id,
               i_inboxes.visibility
        FROM i_inboxes
        WHERE i_inboxes.id = :id
      ");
      $inboxStmt->execute([':id' => $inboxId]);
      $inbox = $inboxStmt->fetch();

      if (!$inbox)
      {
        throw new RuntimeException("Inbox $inboxId not found");
      }

      # Extract ActivityStreams fields
      require_once __DIR__.'/../../src/utils.php';
      $jsonParsed = json_decode($body, true);
      [$asType, $asObject, $asTarget, $asActor, $corrTokenParsed] = extract_as_fields($jsonParsed);

      # Insert notification directly (simplified ACL for internal)
      $nStmt = $pdo->prepare("
        INSERT INTO i_notifications
          (i_notifications.inbox_id,
          i_notifications.body_jsonld,
          i_notifications.content_type,
          i_notifications.as_type,
          i_notifications.as_object_iri,
          i_notifications.as_target_iri,
          i_notifications.sender_id,
          i_notifications.digest_sha256,
          i_notifications.corr_token)
        VALUES
          (:inbox_id, :body_jsonld, 'application/ld+json', :as_type, :as_object, :as_target, NULL, :digest_sha256, :corr_token)
      ");
      $nStmt->execute([
        ':inbox_id' => $inboxId,
        ':body_jsonld' => $body,
        ':as_type' => $asType,
        ':as_object' => $asObject,
        ':as_target' => $asTarget,
        ':digest_sha256' => hash('sha256', $body, true),
        ':corr_token' => $corrToken
      ]);

      $notificationId = (int)$pdo->lastInsertId();
      ob_end_clean();
      $_SERVER = $_oldServer;

      $status = 201;
      $rawHeaders = "HTTP/1.1 201 Created\r\nLocation: {$baseUrl}/notification.php?id={$notificationId}";
      $rawBody = json_encode(['id' => $notificationId, 'status' => 'accepted (internal)']);
    }
    else
    {
      # External delivery - use cURL to POST to remote inbox
      $ch = curl_init($toInbox);
      curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/ld+json'],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
      ]);
      $response = curl_exec($ch);
      $curlErr  = curl_error($ch);
      $info     = curl_getinfo($ch);
      curl_close($ch);

      $status = $info['http_code'] ?? 0;
      list($rawHeaders, $rawBody) = explode("\r\n\r\n", $response, 2) + [1 => ''];

      if ($curlErr)
      {
        throw new RuntimeException($curlErr);
      }
    }

    # Record delivery attempt
    $stmt = $pdo->prepare("
      INSERT INTO o_delivery_attempts
        (o_delivery_attempts.outgoing_notification_id,
        o_delivery_attempts.attempt_no,
        o_delivery_attempts.response_status,
        o_delivery_attempts.response_headers,
        o_delivery_attempts.response_body)
      VALUES
        (:outgoing_notification_id, 1, :response_status, :response_headers, :response_body)
    ");
    $stmt->execute([
      ':outgoing_notification_id' => $outId,
      ':response_status' => $status ?: null,
      ':response_headers' => substr($rawHeaders, 0, 65535),
      ':response_body' => substr($rawBody, 0, 1000000)
    ]);

    # Update delivery status based on HTTP response
    if ($status < 200 || $status >= 300)
    {
      $stmt = $pdo->prepare("
        UPDATE o_outgoing_notifications
        SET o_outgoing_notifications.delivery_status = 'failed',
            o_outgoing_notifications.last_error = :last_error
        WHERE o_outgoing_notifications.id = :id
      ");
      $stmt->execute([':last_error' => "HTTP $status", ':id' => $outId]);
      throw new RuntimeException("HTTP $status: $rawBody");
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

    $msg = "Sent OK (ID: $outId, HTTP $status)";
  }
  catch (Throwable $e)
  {
    $err = $e->getMessage();
  }
}

# Build example JSON-LD for help text
$example = json_encode([
  "@context" => "https://www.w3.org/ns/activitystreams",
  "type" => "Offer",
  "actor" => "https://yourdomain.example/alice",
  "object" => "https://receiver.example/resource/99",
  "correlationId" => "req-abc-123"
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

# Fetch users for dropdown
$users = $pdo->query("
  SELECT u_users.id,
         u_users.username
  FROM u_users
  ORDER BY u_users.id ASC
")->fetchAll();
?>
<div class="row">
  <div class="col-lg-10">
    <h1 class="mb-4">
      <i class="bi bi-send text-primary me-2"></i>Send Outgoing Notification
    </h1>

    <?php if ($msg): ?>
      <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <?php if ($err): ?>
      <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($err) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-body">
        <form method="post">
          <div class="mb-3">
            <label class="form-label">From User <span class="text-muted">(optional)</span></label>
            <select name="from_user_id" class="form-select">
              <option value="">— none —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username']) ?> (#<?= (int)$u['id'] ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">To Inbox IRI</label>
            <input type="url" name="to_inbox_iri" class="form-control font-monospace"
                   placeholder="https://receiver.example/ldn/inbox_receive.php?inbox_id=7" required>
            <div class="form-text">Target inbox URL to send the notification to</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Correlation Token <span class="text-muted">(optional)</span></label>
            <input type="text" name="corr_token" class="form-control font-monospace"
                   placeholder="req-abc-123">
            <div class="form-text">For tracking request/response pairs</div>
          </div>

          <div class="mb-4">
            <label class="form-label">Body (JSON-LD)</label>
            <textarea name="body_jsonld" rows="14" class="form-control font-monospace"
                      placeholder="<?= htmlspecialchars($example) ?>" required></textarea>
            <div class="form-text">ActivityStreams JSON-LD notification payload</div>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">
              <i class="bi bi-send-fill me-1"></i>Send Notification
            </button>
            <a class="btn btn-outline-secondary" href="?p=home">
              <i class="bi bi-x-lg me-1"></i>Cancel
            </a>
          </div>
        </form>
      </div>
    </div>

    <div class="accordion mt-4">
      <div class="accordion-item">
        <h2 class="accordion-header">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#exampleJson">
            <i class="bi bi-code-square me-2"></i>Example JSON-LD
          </button>
        </h2>
        <div id="exampleJson" class="accordion-collapse collapse">
          <div class="accordion-body">
            <pre class="bg-light p-3 rounded"><code><?= htmlspecialchars($example) ?></code></pre>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

