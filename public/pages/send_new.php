<?php
// public/pages/send_new.php - Form-based notification builder
$pdo = db();
$msg = null; $err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
  try {
    $toInbox   = trim($_POST['to_inbox_iri'] ?? '');
    $fromUser  = !empty($_POST['from_user_id']) ? (int)$_POST['from_user_id'] : null;
    $corrToken = !empty($_POST['corr_token']) ? trim($_POST['corr_token']) : null;

    // Build JSON-LD from form fields
    $activityType = trim($_POST['activity_type'] ?? 'Offer');
    $actorUserId  = !empty($_POST['actor_user_id']) ? (int)$_POST['actor_user_id'] : null;
    $objectResourceId = !empty($_POST['object_resource_id']) ? (int)$_POST['object_resource_id'] : null;
    $objectIri    = trim($_POST['object_iri'] ?? '');
    $targetIri    = trim($_POST['target_iri'] ?? '');
    $summary      = trim($_POST['summary'] ?? '');
    $content      = trim($_POST['content'] ?? '');

    if ($toInbox === '') {
      throw new RuntimeException('Target inbox IRI is required');
    }

    // Get base URL
    $cfg = require __DIR__.'/../../src/config.php';
    $baseUrl = rtrim($cfg['base_url'], '/');

    // Build the JSON-LD object
    $payload = ['@context' => 'https://www.w3.org/ns/activitystreams'];

    # Generate unique notification ID
    $notificationId = $baseUrl . '/notifications/' . uniqid('notif_', true);
    $payload['id'] = $notificationId;

    $payload['type'] = $activityType;

    # Add origin - fetch from i_origin table (required per Event Notifications spec)
    $stmt = $pdo->prepare("SELECT id_iri, name, type FROM i_origin LIMIT 1");
    $stmt->execute();
    $origin = $stmt->fetch();
    if ($origin)
    {
      $payload['origin'] = [
        'id' => $origin['id_iri'],
        'name' => $origin['name'] ?: 'LDN Inbox System',
        'type' => $origin['type']
      ];
    }
    else
    {
      # Fallback if origin not configured
      $payload['origin'] = [
        'id' => $baseUrl,
        'name' => 'LDN Inbox System',
        'type' => 'Application'
      ];
    }

    // Add actor if selected - build full actor object with id, inbox, name, type
    if ($actorUserId) {
      $stmt = $pdo->prepare("
        SELECT u.username, u.webid_iri, u.actor_name, u.actor_type, i.inbox_iri
        FROM u_users u
        LEFT JOIN i_inboxes i ON i.owner_user_id = u.id AND i.is_primary = 1
        WHERE u.id = :id
      ");
      $stmt->execute([':id' => $actorUserId]);
      $user = $stmt->fetch();
      if ($user) {
        # Build actor with all required fields per Event Notifications spec
        $actorId = $user['webid_iri'] ?: ($baseUrl . '/users/' . $actorUserId);
        $actorName = $user['actor_name'] ?: $user['username'];
        $actorInbox = $user['inbox_iri'] ?: null;

        $payload['actor'] = [
          'id' => $actorId,
          'name' => $actorName,
          'type' => $user['actor_type'] ?: 'Person'
        ];

        # Only add inbox if user has a primary inbox configured
        if ($actorInbox) {
          $payload['actor']['inbox'] = $actorInbox;
        }
      }
    }

    // Add object - either from resource or manual IRI
    if ($objectResourceId) {
      $stmt = $pdo->prepare("SELECT resource_iri, title, type FROM r_resources WHERE id = :id");
      $stmt->execute([':id' => $objectResourceId]);
      $resource = $stmt->fetch();
      if ($resource) {
        $payload['object'] = [
          'id' => $resource['resource_iri'],
          'type' => $resource['type'] ?: 'Object',
          'name' => $resource['title']
        ];
      }
    } elseif ($objectIri !== '') {
      $payload['object'] = $objectIri;
    }

    // Add target if provided (currently only supports manual IRI, not user selection like send_upgrade.php)
    if ($targetIri !== '') {
      $payload['target'] = $targetIri;
    }

    // Add summary and content if provided
    if ($summary !== '') {
      $payload['summary'] = $summary;
    }
    if ($content !== '') {
      $payload['content'] = $content;
    }

    // Add correlation ID if provided
    if ($corrToken) {
      $payload['correlationId'] = $corrToken;
    }

    // Add published timestamp
    $payload['published'] = gmdate('Y-m-d\TH:i:s\Z');

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    // Insert outgoing notification
    $json = json_decode($body, true);
    $asType = isset($json['type']) ? (is_array($json['type']) ? ($json['type'][0] ?? null) : $json['type']) : null;

    $stmt = $pdo->prepare("INSERT INTO o_outgoing_notifications
                            (from_user_id, to_inbox_iri, body_jsonld, as_type, corr_token, delivery_status)
                        VALUES (:from_user_id, :to_inbox_iri, :body_jsonld, :as_type, :corr_token, 'pending')");
    $stmt->execute([
      ':from_user_id' => $fromUser,
      ':to_inbox_iri' => $toInbox,
      ':body_jsonld' => $body,
      ':as_type' => $asType,
      ':corr_token' => $corrToken,
    ]);

    $outId = (int)$pdo->lastInsertId();

    // Check if this is an internal URL
    $cfg = require __DIR__.'/../../src/config.php';
    $baseUrl = rtrim($cfg['base_url'], '/');
    $isInternal = (strpos($toInbox, $baseUrl) === 0) ||
                  (strpos($toInbox, 'http://localhost') === 0) ||
                  (strpos($toInbox, 'http://127.0.0.1') === 0);

    if ($isInternal) {
      // Internal delivery - call directly
      $parsedUrl = parse_url($toInbox);
      $path = $parsedUrl['path'] ?? '';
      parse_str($parsedUrl['query'] ?? '', $queryParams);

      // Extract inbox_id from query parameters
      $inboxId = isset($queryParams['inbox_id']) ? (int)$queryParams['inbox_id'] : null;

      if (!$inboxId) {
        throw new RuntimeException('Internal URL must contain inbox_id parameter');
      }

      // Simulate the inbox_receive.php logic
      $_oldServer = $_SERVER;
      $_SERVER['REQUEST_METHOD'] = 'POST';
      $_SERVER['CONTENT_TYPE'] = 'application/ld+json';
      $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

      ob_start();
      $inboxStmt = $pdo->prepare("SELECT id, visibility FROM i_inboxes WHERE id = :id");
      $inboxStmt->execute([':id' => $inboxId]);
      $inbox = $inboxStmt->fetch();

      if (!$inbox) {
        throw new RuntimeException("Inbox $inboxId not found");
      }

      // Insert notification (simplified, without full ACL check for internal)
      require_once __DIR__.'/../../src/utils.php';

      $jsonParsed = json_decode($body, true);
      [$asType, $asObject, $asTarget, $asActor, $corrTokenParsed] = extract_as_fields($jsonParsed);
      $digest = hash('sha256', $body);

      $nStmt = $pdo->prepare("INSERT INTO i_notifications
                              (inbox_id, body_jsonld, content_type, as_type, as_object_iri, as_target_iri, sender_id, digest_sha256, corr_token)
                              VALUES (:inbox_id, :body_jsonld, 'application/ld+json', :as_type, :as_object, :as_target, NULL, :digest_sha256, :corr_token)");
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

    } else {
      // External delivery - use cURL
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

      if ($curlErr) {
        throw new RuntimeException($curlErr);
      }
    }

    // Record attempt
    $stmt = $pdo->prepare("INSERT INTO o_delivery_attempts
                            (outgoing_notification_id, attempt_no, response_status, response_headers, response_body)
                        VALUES (:outgoing_notification_id, 1, :response_status, :response_headers, :response_body)");
    $stmt->execute([
      ':outgoing_notification_id' => $outId,
      ':response_status' => $status ?: null,
      ':response_headers' => substr($rawHeaders, 0, 65535),
      ':response_body' => substr($rawBody, 0, 1000000)
    ]);

    if ($status < 200 || $status >= 300) {
      $stmt = $pdo->prepare("UPDATE o_outgoing_notifications SET delivery_status = 'failed', last_error = :last_error WHERE id = :id");
      $stmt->execute([':last_error' => "HTTP $status", ':id' => $outId]);
      throw new RuntimeException("HTTP $status: $rawBody");
    } else {
      $stmt = $pdo->prepare("UPDATE o_outgoing_notifications SET delivery_status = 'delivered' WHERE id = :id");
      $stmt->execute([':id' => $outId]);
    }

    $msg = "Sent OK (ID: $outId, HTTP $status)<br><br><strong>Generated JSON-LD:</strong><pre class='bg-light p-2 mt-2 rounded'>" . htmlspecialchars($body) . "</pre>";
  }
  catch (Throwable $e)
  {
    $err = $e->getMessage();
  }
}

// Get users for dropdowns
$users = $pdo->query("
  SELECT u.id, u.username, u.webid_iri, i.inbox_iri
  FROM u_users u
  LEFT JOIN i_inboxes i ON i.owner_user_id = u.id AND i.is_primary = 1
  ORDER BY u.id ASC
")->fetchAll();

// Get resources for dropdown
$resources = $pdo->query("SELECT id, resource_iri, title, type FROM r_resources ORDER BY id DESC")->fetchAll();

// Get inboxes for quick selection
$inboxes = $pdo->query("SELECT i_inboxes.id,
                               i_inboxes.inbox_iri,
                               u_users.username
                        FROM i_inboxes
                        JOIN u_users ON i_inboxes.owner_user_id = u_users.id
                        ORDER BY i_inboxes.id DESC")->fetchAll();

// ActivityStreams activity types
$activityTypes = [
  'Accept', 'Add', 'Announce', 'Arrive', 'Block', 'Create', 'Delete',
  'Dislike', 'Flag', 'Follow', 'Ignore', 'Invite', 'Join', 'Leave',
  'Like', 'Listen', 'Move', 'Offer', 'Question', 'Reject', 'Read',
  'Remove', 'TentativeReject', 'TentativeAccept', 'Travel', 'Undo',
  'Update', 'View'
];
?>
<div class="row">
  <div class="col-lg-10">
    <h1 class="mb-4">
      <i class="bi bi-send text-primary me-2"></i>Send Notification (Form Builder)
    </h1>

    <div class="alert alert-info">
      <i class="bi bi-info-circle me-2"></i>
      <strong>Form Mode:</strong> Build JSON-LD notifications using form fields.
      For manual JSON editing, use <a href="?p=send" class="alert-link">Manual Mode</a>.
    </div>

    <?php if ($msg): ?>
      <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle me-2"></i><?= $msg ?>
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
          <h5 class="mb-3"><i class="bi bi-gear me-2"></i>Delivery Settings</h5>

          <div class="mb-3">
            <label class="form-label">To Inbox</label>
            <select class="form-select" id="inboxSelect" onchange="document.getElementById('toInboxIri').value = this.value">
              <option value="">— Select existing inbox or enter custom —</option>
              <?php foreach ($inboxes as $inbox): ?>
                <option value="<?= htmlspecialchars($inbox['inbox_iri']) ?>">
                  Inbox #<?= $inbox['id'] ?> - <?= htmlspecialchars($inbox['username']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">To Inbox IRI</label>
            <input type="url" name="to_inbox_iri" id="toInboxIri" class="form-control font-monospace"
                   placeholder="http://localhost:8081/api/inbox_receive.php?inbox_id=1" required>
            <div class="form-text">Target inbox URL</div>
          </div>

          <div class="mb-3">
            <label class="form-label">From User <span class="text-muted">(optional)</span></label>
            <select name="from_user_id" class="form-select">
              <option value="">— none —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>">
                  <?= $u['inbox_iri'] ? '✅ ' : '⚠️ ' ?>
                  <?= htmlspecialchars($u['username']) ?> (#<?= (int)$u['id'] ?>)
                  <?= !$u['inbox_iri'] ? ' [No Inbox]' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Who is sending this notification (for tracking)</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Correlation Token <span class="text-muted">(optional)</span></label>
            <input type="text" name="corr_token" class="form-control font-monospace"
                   placeholder="req-<?= bin2hex(random_bytes(4)) ?>">
            <div class="form-text">For tracking request/response pairs</div>
          </div>

          <hr class="my-4">
          <h5 class="mb-3"><i class="bi bi-code-square me-2"></i>Activity Content</h5>

          <div class="mb-3">
            <label class="form-label">Activity Type</label>
            <select name="activity_type" class="form-select">
              <?php foreach ($activityTypes as $type): ?>
                <option value="<?= htmlspecialchars($type) ?>" <?= $type === 'Offer' ? 'selected' : '' ?>>
                  <?= htmlspecialchars($type) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">ActivityStreams activity type</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Actor (User) <span class="text-muted">(optional)</span></label>
            <select name="actor_user_id" class="form-select">
              <option value="">— none —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>">
                  <?= $u['inbox_iri'] ? '✅ ' : '⚠️ ' ?>
                  <?= htmlspecialchars($u['username']) ?>
                  <?= $u['webid_iri'] ? ' (' . htmlspecialchars($u['webid_iri']) . ')' : '' ?>
                  <?= !$u['inbox_iri'] ? ' [No Inbox]' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Who is performing the activity</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Object (Resource) <span class="text-muted">(optional)</span></label>
            <select name="object_resource_id" class="form-select" id="objectResourceSelect">
              <option value="">— Select resource or enter custom IRI below —</option>
              <?php foreach ($resources as $r): ?>
                <option value="<?= (int)$r['id'] ?>">
                  <?= htmlspecialchars($r['title']) ?> (<?= htmlspecialchars($r['type']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Object IRI <span class="text-muted">(or use dropdown above)</span></label>
            <input type="url" name="object_iri" class="form-control font-monospace"
                   placeholder="https://example.com/objects/123">
            <div class="form-text">Custom object IRI (ignored if resource selected above)</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Target IRI <span class="text-muted">(optional)</span></label>
            <input type="url" name="target_iri" class="form-control font-monospace"
                   placeholder="https://example.com/targets/456">
            <div class="form-text">Optional target of the activity</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Summary <span class="text-muted">(optional)</span></label>
            <input type="text" name="summary" class="form-control"
                   placeholder="Alice offered a document">
            <div class="form-text">Brief summary of the activity</div>
          </div>

          <div class="mb-4">
            <label class="form-label">Content <span class="text-muted">(optional)</span></label>
            <textarea name="content" rows="3" class="form-control"
                      placeholder="Detailed content or description..."></textarea>
            <div class="form-text">Detailed content (can be HTML or plain text)</div>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">
              <i class="bi bi-send-fill me-1"></i>Build & Send Notification
            </button>
            <a class="btn btn-outline-secondary" href="?p=send">
              <i class="bi bi-code me-1"></i>Switch to Manual Mode
            </a>
            <a class="btn btn-outline-secondary" href="?p=home">
              <i class="bi bi-x-lg me-1"></i>Cancel
            </a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
