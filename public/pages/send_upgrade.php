<?php

$pdo = db();

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
  try
  {
    $action = $_POST['action'] ?? 'send';
    $toInbox   = trim($_POST['to_inbox_iri'] ?? '');
    $fromUser  = !empty($_POST['from_user_id']) ? (int)$_POST['from_user_id'] : null;
    $corrToken = !empty($_POST['corr_token']) ? trim($_POST['corr_token']) : null;

    $activityType = trim($_POST['activity_type'] ?? 'Offer');
    $actorUserId  = !empty($_POST['actor_user_id']) ? (int)$_POST['actor_user_id'] : null;
    $objectResourceId = !empty($_POST['object_resource_id']) ? (int)$_POST['object_resource_id'] : null;
    $objectIri    = trim($_POST['object_iri'] ?? '');
    $targetUserId = !empty($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : null;
    $targetIri    = trim($_POST['target_iri'] ?? '');
    $contextIri   = trim($_POST['context_iri'] ?? '');
    $summary      = trim($_POST['summary'] ?? '');
    $content      = trim($_POST['content'] ?? '');

    if ($toInbox === '')
    {
      throw new RuntimeException('Target inbox IRI is required');
    }

    $actorRequiredTypes = ['Create', 'Update', 'Delete', 'Offer', 'Accept', 'Reject'];
    if (in_array($activityType, $actorRequiredTypes, true) && !$actorUserId)
    {
      throw new RuntimeException("Actor is required for {$activityType} activities");
    }

    $cfg = require __DIR__.'/../../src/config.php';
    $baseUrl = rtrim($cfg['base_url'], '/');


    $payload = [];


    $notificationId = $baseUrl . '/notifications/' . uniqid('notif_', true);
    $payload['id'] = $notificationId;

    $payload['type'] = $activityType;

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
      $payload['origin'] = [
        'id' => $baseUrl,
        'name' => 'LDN Inbox System',
        'type' => 'Application'
      ];
    }

    if ($actorUserId)
    {
      $stmt = $pdo->prepare("
        SELECT u.username, u.webid_iri, u.actor_name, u.actor_type, i.inbox_iri
        FROM u_users u
        LEFT JOIN i_inboxes i ON i.owner_user_id = u.id AND i.is_primary = 1
        WHERE u.id = :id
      ");
      $stmt->execute([':id' => $actorUserId]);
      $user = $stmt->fetch();
      if ($user)
      {
        $actorId = $user['webid_iri'] ?: ($baseUrl . '/users/' . $actorUserId);

        $actorName = $user['actor_name'] ?: $user['username'];

        $actorInbox = $user['inbox_iri'] ?: null;

        $payload['actor'] = [
          'id' => $actorId,
          'name' => $actorName,
          'type' => $user['actor_type'] ?: 'Person'
        ];

        if ($actorInbox) {
          $payload['actor']['inbox'] = $actorInbox;
        }
      }
    }

    if ($objectResourceId)
    {
      $stmt = $pdo->prepare("SELECT resource_iri, title, type FROM r_resources WHERE id = :id");
      $stmt->execute([':id' => $objectResourceId]);
      $resource = $stmt->fetch();
      if ($resource)
      {
        $payload['object'] = [
          'id' => $resource['resource_iri'],
          'type' => $resource['type'] ?: 'Object',
          'name' => $resource['title']
        ];
      }
    }
    elseif ($objectIri !== '')
    {
      $payload['object'] = $objectIri;
    }

    if ($targetUserId)
    {
      $stmt = $pdo->prepare("
        SELECT u.username, u.webid_iri, u.actor_name, u.actor_type, i.inbox_iri
        FROM u_users u
        LEFT JOIN i_inboxes i ON i.owner_user_id = u.id AND i.is_primary = 1
        WHERE u.id = :id
      ");
      $stmt->execute([':id' => $targetUserId]);
      $targetUser = $stmt->fetch();
      if ($targetUser)
      {
        $targetId = $targetUser['webid_iri'] ?: ($baseUrl . '/users/' . $targetUserId);
        $targetName = $targetUser['actor_name'] ?: $targetUser['username'];
        $targetInbox = $targetUser['inbox_iri'] ?: null;

        $payload['target'] = [
          'id' => $targetId,
          'name' => $targetName,
          'type' => $targetUser['actor_type'] ?: 'Person'
        ];

        if ($targetInbox) {
          $payload['target']['inbox'] = $targetInbox;
        }
      }
    }
    elseif ($targetIri !== '')
    {
      $payload['target'] = $targetIri;
    }

    if ($contextIri !== '')
    {
      $payload['context'] = $contextIri;
    }

    if ($summary !== '')
    {
      $payload['summary'] = $summary;
    }
    if ($content !== '')
    {
      $payload['content'] = $content;
    }

    if ($corrToken)
    {
      $payload['correlationId'] = $corrToken;
    }

    $payload['published'] = gmdate('Y-m-d\TH:i:s\Z');

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    if ($action === 'preview')
    {
      $msg = "<strong>Preview Mode - Not Sent</strong><br><br><strong>Generated JSON-LD:</strong><pre class='bg-light p-3 mt-2 rounded border'>" . htmlspecialchars($body) . "</pre>";
    }
    else
    {
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

      $isInternal = (strpos($toInbox, $baseUrl) === 0) ||

      if ($isInternal)
      {
        $parsedUrl = parse_url($toInbox);
        $path = $parsedUrl['path'] ?? '';
        parse_str($parsedUrl['query'] ?? '', $queryParams);

        $inboxId = isset($queryParams['inbox_id']) ? (int)$queryParams['inbox_id'] : null;

        if (!$inboxId)
        {
          throw new RuntimeException('Internal URL must contain inbox_id parameter');
        }

        $_oldServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/ld+json';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        ob_start();
        $inboxStmt = $pdo->prepare("SELECT id, visibility FROM i_inboxes WHERE id = :id");
        $inboxStmt->execute([':id' => $inboxId]);
        $inbox = $inboxStmt->fetch();

        if (!$inbox)
        {
          throw new RuntimeException("Inbox $inboxId not found");
        }

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
      }
      else
      {
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

      $stmt = $pdo->prepare("INSERT INTO o_delivery_attempts
                              (outgoing_notification_id, attempt_no, response_status, response_headers, response_body)
                          VALUES (:outgoing_notification_id, 1, :response_status, :response_headers, :response_body)");
      $stmt->execute([
        ':outgoing_notification_id' => $outId,
        ':response_status' => $status ?: null,
        ':response_headers' => substr($rawHeaders, 0, 65535),
        ':response_body' => substr($rawBody, 0, 1000000)
      ]);

      if ($status < 200 || $status >= 300)
      {
        $stmt = $pdo->prepare("UPDATE o_outgoing_notifications SET delivery_status = 'failed', last_error = :last_error WHERE id = :id");
        $stmt->execute([':last_error' => "HTTP $status", ':id' => $outId]);
        throw new RuntimeException("HTTP $status: $rawBody");
      }
      else
      {
        $stmt = $pdo->prepare("UPDATE o_outgoing_notifications SET delivery_status = 'delivered' WHERE id = :id");
        $stmt->execute([':id' => $outId]);
      }

      $msg = "<strong>Sent Successfully!</strong><br>Notification ID: $outId • HTTP Status: $status<br><br><strong>Generated JSON-LD:</strong><pre class='bg-light p-3 mt-2 rounded border'>" . htmlspecialchars($body) . "</pre>";
    }
  }
  catch (Throwable $e)
  {
    $err = $e->getMessage();
  }
}


$users = $pdo->query("
  SELECT u.id, u.username, u.webid_iri, u.actor_name, u.actor_type, i.inbox_iri
  FROM u_users u
  LEFT JOIN i_inboxes i ON i.owner_user_id = u.id AND i.is_primary = 1
  ORDER BY u.id ASC
")->fetchAll();

$resources = $pdo->query("SELECT id, resource_iri, title, type FROM r_resources ORDER BY id DESC")->fetchAll();

$inboxes = $pdo->query("SELECT i_inboxes.id,
                               i_inboxes.inbox_iri,
                               u_users.username
                        FROM i_inboxes
                        JOIN u_users ON i_inboxes.owner_user_id = u_users.id
                        ORDER BY i_inboxes.id DESC")->fetchAll();

$activityTypes = [
  'Create',
  'Update',
  'Remove',
  'Announce',
  'Offer',
  'Accept',
  'Reject',
  'Undo'
];

$cfg = require __DIR__.'/../../src/config.php';
$baseUrl = rtrim($cfg['base_url'], '/');
?>

<style>
  .sticky-preview {
    position: sticky;
    top: 20px;
    max-height: calc(100vh - 40px);
    overflow-y: auto;
  }
    font-size: 0.85rem;
    max-height: 500px;
    overflow-y: auto;
    background: #f8f9fa;
  }
  .form-label {
    font-weight: 500;
  }
  .accordion-button:not(.collapsed) {
    background-color: #e7f1ff;
    color: #0d6efd;
  }
  .template-btn {
    transition: all 0.2s;
  }
  .template-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
  }
</style>

<div class="row">
  <div class="col-12">
    <h1 class="mb-4">
      <i class="bi bi-send text-primary me-2"></i>Send Notification (Enhanced Form Builder)
    </h1>

    <div class="alert alert-info">
      <i class="bi bi-info-circle me-2"></i>
      <strong>Enhanced Form Builder:</strong> Build Event Notifications compliant JSON-LD with live preview and validation.
      For manual JSON editing, use <a href="?p=send" class="alert-link">Manual JSON Mode</a>.
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
  </div>
</div>

<div class="row">
  <div class="col-lg-6">
    <div class="card mb-4">
      <div class="card-header bg-primary text-white">
        <i class="bi bi-ui-checks me-2"></i>Notification Builder
      </div>
      <div class="card-body">
        <div class="mb-4">
          <label class="form-label">
            <i class="bi bi-lightning-fill me-1"></i>Quick Templates (State Types)
          </label>
          <div class="row g-2">
            <div class="col-6 col-md-3">
              <button type="button" class="btn btn-outline-secondary w-100 template-btn" onclick="loadTemplate('create')">
                <i class="bi bi-plus-circle"></i><br><small>Create</small>
              </button>
            </div>
            <div class="col-6 col-md-3">
              <button type="button" class="btn btn-outline-secondary w-100 template-btn" onclick="loadTemplate('update')">
                <i class="bi bi-pencil-square"></i><br><small>Update</small>
              </button>
            </div>
            <div class="col-6 col-md-3">
              <button type="button" class="btn btn-outline-secondary w-100 template-btn" onclick="loadTemplate('remove')">
                <i class="bi bi-trash"></i><br><small>Remove</small>
              </button>
            </div>
            <div class="col-6 col-md-3">
              <button type="button" class="btn btn-outline-secondary w-100 template-btn" onclick="loadTemplate('announce')">
                <i class="bi bi-megaphone"></i><br><small>Announce</small>
              </button>
            </div>
          </div>
        </div>

        <form method="post" id="notificationForm">
          <div class="accordion" id="formAccordion">

            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#deliverySection">
                  <i class="bi bi-geo-alt me-2"></i>Delivery Settings
                </button>
              </h2>
              <div id="deliverySection" class="accordion-collapse collapse show" data-bs-parent="#formAccordion">
                <div class="accordion-body">

                  <div class="mb-3">
                    <label class="form-label">
                      Quick Select Inbox
                      <i class="bi bi-question-circle text-muted" data-bs-toggle="tooltip"
                         title="Select an existing inbox or enter custom IRI below"></i>
                    </label>
                    <select class="form-select" id="inboxSelect" onchange="selectInbox(this.value)">
                      <option value="">— Select existing inbox or enter custom —</option>
                      <?php foreach ($inboxes as $inbox): ?>
                        <option value="<?= htmlspecialchars($inbox['inbox_iri']) ?>">
                          Inbox #<?= $inbox['id'] ?> - <?= htmlspecialchars($inbox['username']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="mb-3">
                    <label class="form-label">
                      <strong>To Inbox IRI (HTTP POST Destination)</strong> <span class="text-danger">*</span>
                      <i class="bi bi-question-circle text-muted" data-bs-toggle="tooltip"
                         title="The actual HTTP endpoint where cURL will POST this notification"></i>
                    </label>
                    <input type="url" name="to_inbox_iri" id="toInboxIri" class="form-control font-monospace"
                           oninput="validateAndUpdate(); validateInboxMismatch()" required>
                    <div class="form-text">
                      <i class="bi bi-info-circle text-primary"></i>
                      <strong>This is the transport destination</strong> - where the HTTP request is sent.
                      Usually matches the Target User's inbox, but can differ for group/relay inboxes.
                    </div>
                    <div class="invalid-feedback">Please enter a valid HTTP(S) URL</div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label">
                      Sent By (System Tracking) <span class="text-muted">(optional)</span>
                      <i class="bi bi-question-circle text-muted" data-bs-toggle="tooltip"
                         title="Tracks which user clicked the send button - NOT included in the notification JSON"></i>
                    </label>
                    <select name="from_user_id" class="form-select" onchange="updatePreview()">
                      <option value="">— none —</option>
                      <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username']) ?> (#<?= (int)$u['id'] ?>)</option>
                      <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                      <i class="bi bi-database text-muted"></i>
                      Database tracking only - records who initiated this send (not part of the semantic JSON-LD)
                    </div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label">
                      Correlation Token <span class="text-muted">(optional)</span>
                    </label>
                    <div class="input-group">
                      <input type="text" name="corr_token" id="corrToken" class="form-control font-monospace"
                             placeholder="req-<?= bin2hex(random_bytes(4)) ?>" oninput="updatePreview()">
                      <button type="button" class="btn btn-outline-secondary" onclick="generateToken()">
                        <i class="bi bi-arrow-repeat"></i> Generate
                      </button>
                    </div>
                    <div class="form-text">For tracking request/response pairs</div>
                  </div>

                </div>
              </div>
            </div>

            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#activitySection">
                  <i class="bi bi-lightning-fill me-2"></i>Activity Content
                </button>
              </h2>
              <div id="activitySection" class="accordion-collapse collapse show" data-bs-parent="#formAccordion">
                <div class="accordion-body">

                  <div class="mb-3">
                    <label class="form-label">
                      Activity Type <span class="text-danger">*</span>
                    </label>
                    <select name="activity_type" id="activityType" class="form-select" onchange="validateActorRequirement(); updatePreview()" required>
                      <?php foreach ($activityTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type) ?>" <?= $type === 'Offer' ? 'selected' : '' ?>>
                          <?= htmlspecialchars($type) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <div class="form-text">ActivityStreams activity type</div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label">
                      <strong>Actor - Who Performed the Activity</strong> <span class="text-muted" id="actorRequiredLabel">(optional)</span>
                      <i class="bi bi-question-circle text-muted" data-bs-toggle="tooltip"
                         title="This goes IN the JSON-LD as 'actor' field - the person/system who did the action"></i>
                    </label>
                    <select name="actor_user_id" id="actorUserId" class="form-select" onchange="validateActorRequirement(); updatePreview()">
                      <option value="">— none —</option>
                      <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"
                                data-webid="<?= htmlspecialchars($u['webid_iri'] ?? '') ?>"
                                data-name="<?= htmlspecialchars($u['actor_name'] ?? '') ?>"
                                data-type="<?= htmlspecialchars($u['actor_type'] ?? 'Person') ?>"
                                data-inbox="<?= htmlspecialchars($u['inbox_iri'] ?? '') ?>">
                          <?= $u['inbox_iri'] ? '✅ ' : '⚠️ ' ?>
                          <?= htmlspecialchars($u['username']) ?>
                          <?= $u['webid_iri'] ? ' (' . htmlspecialchars($u['webid_iri']) . ')' : '' ?>
                          <?= !$u['inbox_iri'] ? ' [No Inbox]' : '' ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                      <i class="bi bi-person-fill text-success"></i>
                      <strong>Semantic field</strong> - appears in JSON as actor.id, actor.inbox, actor.name, actor.type
                    </div>
                    <div id="actorRequiredWarning" class="invalid-feedback">
                      Actor is required for user-initiated activities (Create, Update, Delete, Offer, Accept, Reject)
                    </div>
                    <div id="actorNoInboxWarning" class="alert alert-warning d-none mt-2" role="alert">
                      <i class="bi bi-exclamation-triangle-fill me-2"></i>
                      <strong>Warning:</strong> This user has no primary inbox configured.
                      The actor.inbox field will be <strong>omitted</strong> from the JSON-LD.
                      <a href="?p=new_inbox" class="alert-link">Create an inbox</a> for this user.
                    </div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label">
                      Object (Resource) <span class="text-muted">(optional)</span>
                    </label>
                    <select name="object_resource_id" id="objectResourceId" class="form-select" onchange="updatePreview()">
                      <option value="">— Select resource or enter custom IRI below —</option>
                      <?php foreach ($resources as $r): ?>
                        <option value="<?= (int)$r['id'] ?>"
                                data-iri="<?= htmlspecialchars($r['resource_iri']) ?>"
                                data-type="<?= htmlspecialchars($r['type']) ?>"
                                data-title="<?= htmlspecialchars($r['title']) ?>">
                          <?= htmlspecialchars($r['title']) ?> (<?= htmlspecialchars($r['type']) ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="mb-3">
                    <label class="form-label">
                      Object IRI <span class="text-muted">(or use dropdown above)</span>
                    </label>
                    <input type="url" name="object_iri" id="objectIri" class="form-control font-monospace"
                    <div class="form-text">Custom object IRI (ignored if resource selected above)</div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label">
                      <strong>Target - Who/What Receives the Activity</strong> <span class="text-muted">(optional)</span>
                      <i class="bi bi-question-circle text-muted" data-bs-toggle="tooltip"
                         title="This goes IN the JSON-LD as 'target' field - who/what this activity is directed toward"></i>
                    </label>
                    <select name="target_user_id" id="targetUserId" class="form-select" onchange="autoFillTargetInbox(); validateTargetInbox(); validateInboxMismatch(); updatePreview()">
                      <option value="">— Select user or enter custom IRI below —</option>
                      <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"
                                data-webid="<?= htmlspecialchars($u['webid_iri'] ?? '') ?>"
                                data-name="<?= htmlspecialchars($u['actor_name'] ?? '') ?>"
                                data-type="<?= htmlspecialchars($u['actor_type'] ?? 'Person') ?>"
                                data-inbox="<?= htmlspecialchars($u['inbox_iri'] ?? '') ?>">
                          <?= $u['inbox_iri'] ? '✅ ' : '⚠️ ' ?>
                          <?= htmlspecialchars($u['username']) ?>
                          <?= $u['webid_iri'] ? ' (' . htmlspecialchars($u['webid_iri']) . ')' : '' ?>
                          <?= !$u['inbox_iri'] ? ' [No Inbox]' : '' ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                      <i class="bi bi-bullseye text-primary"></i>
                      <strong>Semantic field</strong> - appears in JSON as target.id, target.inbox, target.name, target.type.
                      Selecting a user auto-fills the "To Inbox IRI" above with their primary inbox.
                    </div>
                  </div>

                  <div id="targetNoInboxWarning" class="alert alert-warning d-none mt-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>Warning:</strong> This user has no primary inbox configured.
                    The target.inbox field will be <strong>omitted</strong> from the JSON-LD.
                    <a href="?p=new_inbox" class="alert-link">Create an inbox</a> for this user.
                  </div>

                  <div id="inboxMismatchWarning" class="alert alert-warning d-none mb-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>Notice:</strong> The "To Inbox IRI" (HTTP destination) differs from the Target's inbox (semantic field).
                    <br>
                    <small>This is OK for group inboxes or relay scenarios, but verify this is intentional.</small>
                  </div>

                  <div class="mb-3">
                    <label class="form-label">
                      Target IRI <span class="text-muted">(or use dropdown above)</span>
                    </label>
                    <input type="url" name="target_iri" id="targetIri" class="form-control font-monospace"
                    <div class="form-text">Custom target IRI (ignored if user selected above)</div>
                  </div>

                </div>
              </div>
            </div>

            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#additionalSection">
                  <i class="bi bi-sliders me-2"></i>Additional Properties
                </button>
              </h2>
              <div id="additionalSection" class="accordion-collapse collapse" data-bs-parent="#formAccordion">
                <div class="accordion-body">

                  <div class="mb-3">
                    <label class="form-label">
                      Context IRI <span class="text-muted">(optional)</span>
                    </label>
                    <input type="url" name="context_iri" id="contextIri" class="form-control font-monospace"
                    <div class="form-text">Context or conversation thread IRI</div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label">
                      Summary <span class="text-muted">(optional)</span>
                    </label>
                    <input type="text" name="summary" id="summary" class="form-control"
                           placeholder="Alice offered a document" oninput="updatePreview()">
                    <div class="form-text">Brief summary of the activity</div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label">
                      Content <span class="text-muted">(optional)</span>
                    </label>
                    <textarea name="content" id="content" rows="3" class="form-control"
                              placeholder="Detailed content or description..." oninput="updatePreview()"></textarea>
                    <div class="form-text">Detailed content (can be HTML or plain text)</div>
                  </div>

                </div>
              </div>
            </div>

          </div>

          <div class="d-flex gap-2 mt-4">
            <button class="btn btn-primary" type="submit" name="action" value="send">
              <i class="bi bi-send-fill me-1"></i>Build & Send
            </button>
            <button class="btn btn-outline-primary" type="submit" name="action" value="preview">
              <i class="bi bi-eye me-1"></i>Preview Only
            </button>
            <button class="btn btn-outline-secondary" type="button" onclick="resetForm()">
              <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
            </button>
          </div>
        </form>

      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card mb-3">
      <div class="card-header bg-secondary text-white">
        <i class="bi bi-info-circle me-2"></i>Delivery Metadata
      </div>
      <div class="card-body">
        <div class="row g-2">
          <div class="col-12">
            <small class="text-muted">Target Inbox:</small>
            <div id="metaInbox" class="font-monospace small text-break">
              <span class="text-muted">Not set</span>
            </div>
          </div>
          <div class="col-6">
            <small class="text-muted">From User (tracking):</small>
            <div id="metaFromUser" class="small">
              <span class="text-muted">None</span>
            </div>
          </div>
          <div class="col-6">
            <small class="text-muted">Actor (in JSON):</small>
            <div id="metaActor" class="small text-break">
              <span class="text-muted">None</span>
            </div>
          </div>
          <div class="col-12">
            <small class="text-muted">Correlation Token:</small>
            <div id="metaCorrToken" class="font-monospace small text-break">
              <span class="text-muted">None</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card sticky-preview">
      <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-eye me-2"></i>Live JSON-LD Preview</span>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="copyJson()">
          <i class="bi bi-clipboard"></i> Copy
        </button>
      </div>
      <div class="card-body">
        <pre id="jsonPreview" class="p-3 rounded border mb-0"><code>{
  "type": "Offer",
  "published": "<?= gmdate('Y-m-d\TH:i:s\Z') ?>"
}</code></pre>
      </div>
      <div class="card-footer text-muted">
        <small>
          <i class="bi bi-info-circle me-1"></i>
          Preview updates in real-time as you fill the form
        </small>
      </div>
    </div>
  </div>
</div>

<script>

const baseUrl = '<?= $baseUrl ?>';

document.addEventListener('DOMContentLoaded', function() {

  const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
  tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });

  updatePreview();

  validateActorRequirement();
});

function updatePreview()
{
  updateMetadata();


  const payload = {

    'id': `${baseUrl}/notifications/notif_preview_${Date.now()}`,

    'type': document.getElementById('activityType').value,

    'origin': {
      'id': '<?= $baseUrl ?>',
      'name': 'LDN Inbox System',
      'type': 'Application'
    }
  };


  const actorId = document.getElementById('actorUserId').value;

  if (actorId)
  {
    const actorOption = document.querySelector(`#actorUserId option[value="${actorId}"]`);

    const webid = actorOption.dataset.webid;
    const actorName = actorOption.dataset.name;

    const actorIdValue = webid || `${baseUrl}/users/${actorId}`;

    const actorUsername = actorOption.textContent.trim().split('(')[0].trim();

    const actorNameValue = actorName || actorUsername;

    const actorInboxValue = actorOption.dataset.inbox;

    payload.actor = {
      'id': actorIdValue,
      'name': actorNameValue,
      'type': actorType
    };

    if (actorInboxValue) {
      payload.actor.inbox = actorInboxValue;
    }
  }

  const objectResourceId = document.getElementById('objectResourceId').value;
  if (objectResourceId)
  {
    const resourceOption = document.querySelector(`#objectResourceId option[value="${objectResourceId}"]`);
    payload.object = {
      'id': resourceOption.dataset.iri,
      'type': resourceOption.dataset.type || 'Object',
      'name': resourceOption.dataset.title
    };
  }
  else
  {
    const objectIri = document.getElementById('objectIri').value.trim();
    if (objectIri)
    {
      payload.object = objectIri;
    }
  }

  const targetUserId = document.getElementById('targetUserId').value;
  if (targetUserId)
  {
    const targetOption = document.querySelector(`#targetUserId option[value="${targetUserId}"]`);
    const webid = targetOption.dataset.webid;
    const targetName = targetOption.dataset.name;
    const targetType = targetOption.dataset.type || 'Person';
    const targetIdValue = webid || `${baseUrl}/users/${targetUserId}`;
    const targetUsername = targetOption.textContent.trim().split('(')[0].trim();
    const targetNameValue = targetName || targetUsername;
    const targetInboxValue = targetOption.dataset.inbox;

    payload.target = {
      'id': targetIdValue,
      'name': targetNameValue,
      'type': targetType
    };

    if (targetInboxValue) {
      payload.target.inbox = targetInboxValue;
    }
  }
  else
  {
    const targetIri = document.getElementById('targetIri').value.trim();
    if (targetIri)
    {
      payload.target = targetIri;
    }
  }

  const contextIri = document.getElementById('contextIri').value.trim();
  if (contextIri)
  {
    payload.context = contextIri;
  }

  const summary = document.getElementById('summary').value.trim();
  if (summary)
  {
    payload.summary = summary;
  }

  const content = document.getElementById('content').value.trim();
  if (content)
  {
    payload.content = content;
  }

  const corrToken = document.getElementById('corrToken').value.trim();
  if (corrToken)
  {
    payload.correlationId = corrToken;
  }


  payload.published = new Date().toISOString();

  document.getElementById('jsonPreview').textContent = JSON.stringify(payload, null, 2);
}

function updateMetadata()
{
  const inboxIri = document.getElementById('toInboxIri').value.trim();
  const metaInbox = document.getElementById('metaInbox');
  if (inboxIri)
  {
    metaInbox.innerHTML = `<strong class="text-success">${escapeHtml(inboxIri)}</strong>`;
  }
  else
  {
    metaInbox.innerHTML = '<span class="text-muted">Not set</span>';
  }

  const fromUserId = document.querySelector('[name="from_user_id"]').value;
  const metaFromUser = document.getElementById('metaFromUser');
  if (fromUserId)
  {
    const fromUserOption = document.querySelector(`[name="from_user_id"] option[value="${fromUserId}"]`);
    metaFromUser.innerHTML = `<strong class="text-success">${escapeHtml(fromUserOption.textContent)}</strong>`;
  }
  else
  {
    metaFromUser.innerHTML = '<span class="text-muted">None</span>';
  }

  const actorId = document.getElementById('actorUserId').value;
  const metaActor = document.getElementById('metaActor');
  if (actorId)
  {
    const actorOption = document.querySelector(`#actorUserId option[value="${actorId}"]`);
    const webid = actorOption.dataset.webid;
    const actorIri = webid || `${baseUrl}/users/${actorId}`;
    const actorName = actorOption.textContent.trim();
    metaActor.innerHTML = `<strong class="text-success">${escapeHtml(actorName)}</strong><br><span class="font-monospace text-primary" style="font-size: 0.75rem;">${escapeHtml(actorIri)}</span>`;
  }
  else
  {
    metaActor.innerHTML = '<span class="text-muted">None</span>';
  }

  const corrToken = document.getElementById('corrToken').value.trim();
  const metaCorrToken = document.getElementById('metaCorrToken');
  if (corrToken)
  {
    metaCorrToken.innerHTML = `<strong class="text-success">${escapeHtml(corrToken)}</strong>`;
  }
  else
  {
    metaCorrToken.innerHTML = '<span class="text-muted">None</span>';
  }
}

function escapeHtml(text)
{
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function validateAndUpdate()
{
  const input = document.getElementById('toInboxIri');
  const value = input.value.trim();

  if (value === '')
  {
    input.classList.remove('is-valid', 'is-invalid');
    updatePreview();
    return;
  }

  try
  {
    new URL(value);
    input.classList.remove('is-invalid');
    input.classList.add('is-valid');
  }
  catch
  {
    input.classList.remove('is-valid');
    input.classList.add('is-invalid');
  }

  updatePreview();
}

function copyJson()
{
  const text = document.getElementById('jsonPreview').textContent;
  navigator.clipboard.writeText(text).then(function() {
    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check"></i> Copied!';
    setTimeout(() => { btn.innerHTML = originalHtml; }, 2000);
  });
}

function selectInbox(iri)
{
  document.getElementById('toInboxIri').value = iri;
  validateAndUpdate();
}

function autoFillTargetInbox()
{
  const targetUserId = document.getElementById('targetUserId').value;

  if (!targetUserId)
  {
    return;
  }

  const targetOption = document.querySelector(`#targetUserId option[value="${targetUserId}"]`);

  const primaryInbox = targetOption.dataset.inbox;

  const toInboxField = document.getElementById('toInboxIri');

  if (primaryInbox && toInboxField.value.trim() === '')
  {
    toInboxField.value = primaryInbox;

    validateAndUpdate();
  }
}

function validateTargetInbox()
{
  const targetUserId = document.getElementById('targetUserId').value;
  const noInboxWarning = document.getElementById('targetNoInboxWarning');

  if (targetUserId)
  {
    const targetOption = document.querySelector(`#targetUserId option[value="${targetUserId}"]`);
    const targetInbox = targetOption.dataset.inbox;

    if (!targetInbox)
    {
      noInboxWarning.classList.remove('d-none');
    }
    else
    {
      noInboxWarning.classList.add('d-none');
    }
  }
  else
  {
    noInboxWarning.classList.add('d-none');
  }
}

function validateInboxMismatch()
{
  const targetUserId = document.getElementById('targetUserId').value;
  const toInboxIri = document.getElementById('toInboxIri').value.trim();
  const warningDiv = document.getElementById('inboxMismatchWarning');

  if (!targetUserId || !toInboxIri)
  {
    warningDiv.classList.add('d-none');
    return;
  }

  const targetOption = document.querySelector(`#targetUserId option[value="${targetUserId}"]`);
  const targetInbox = targetOption.dataset.inbox;

  if (targetInbox && targetInbox !== toInboxIri)
  {
    warningDiv.classList.remove('d-none');
  }
  else
  {
    warningDiv.classList.add('d-none');
  }
}

function validateActorRequirement()
{
  const activityType = document.getElementById('activityType').value;
  const actorUserId = document.getElementById('actorUserId').value;
  const actorField = document.getElementById('actorUserId');
  const actorLabel = document.getElementById('actorRequiredLabel');
  const noInboxWarning = document.getElementById('actorNoInboxWarning');

  const actorRequiredTypes = ['Create', 'Update', 'Delete', 'Offer', 'Accept', 'Reject'];

  const isActorRequired = actorRequiredTypes.includes(activityType);

  if (isActorRequired)
  {
    actorLabel.innerHTML = '<span class="text-danger">*</span>';
    actorLabel.classList.remove('text-muted');
    actorLabel.classList.add('text-danger');

    if (!actorUserId)
    {
      actorField.classList.add('is-invalid');
    }
    else
    {
      actorField.classList.remove('is-invalid');
      actorField.classList.add('is-valid');
    }
  }
  else
  {
    actorLabel.innerHTML = '(optional)';
    actorLabel.classList.remove('text-danger');
    actorLabel.classList.add('text-muted');
    actorField.classList.remove('is-invalid', 'is-valid');
  }

  if (actorUserId)
  {
    const actorOption = document.querySelector(`#actorUserId option[value="${actorUserId}"]`);
    const actorInbox = actorOption.dataset.inbox;

    if (!actorInbox)
    {
      noInboxWarning.classList.remove('d-none');
    }
    else
    {
      noInboxWarning.classList.add('d-none');
    }
  }
  else
  {
    noInboxWarning.classList.add('d-none');
  }
}

function generateToken()
{
  const token = 'req-' + Array.from(crypto.getRandomValues(new Uint8Array(4)))
    .map(b => b.toString(16).padStart(2, '0')).join('');
  document.getElementById('corrToken').value = token;
  updatePreview();
}

function resetForm()
{
  if (confirm('Reset form to defaults?'))
  {
    document.getElementById('notificationForm').reset();
    document.getElementById('inboxSelect').value = '';
    document.getElementById('toInboxIri').classList.remove('is-valid', 'is-invalid');
    updatePreview();
  }
}

function loadTemplate(templateName)
{
  document.getElementById('notificationForm').reset();

  switch(templateName)
  {
    case 'create':
      document.getElementById('activityType').value = 'Create';
      document.getElementById('summary').value = 'Created new artifact';
      document.getElementById('content').value = 'A new resource has been created and is now available for use.';
      break;

    case 'update':
      document.getElementById('activityType').value = 'Update';
      document.getElementById('summary').value = 'Updated existing artifact';
      document.getElementById('content').value = 'The resource has been updated with new information.';
      break;

    case 'remove':
      document.getElementById('activityType').value = 'Remove';
      document.getElementById('summary').value = 'Removed artifact';
      document.getElementById('content').value = 'The resource has been removed and is no longer available.';
      break;

    case 'announce':
      document.getElementById('activityType').value = 'Announce';
      document.getElementById('summary').value = 'New announcement';
      document.getElementById('content').value = 'Important notification: Please review the following update.';
      break;
  }

  updatePreview();

  const toast = document.createElement('div');
  toast.className = 'position-fixed bottom-0 end-0 p-3';
  toast.style.zIndex = '9999';
  toast.innerHTML = `
    <div class="toast show" role="alert">
      <div class="toast-header bg-success text-white">
        <i class="bi bi-check-circle me-2"></i>
        <strong class="me-auto">Template Loaded</strong>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
      </div>
      <div class="toast-body">
        ${templateName.charAt(0).toUpperCase() + templateName.slice(1)} template has been loaded.
      </div>
    </div>
  `;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 3000);
}
</script>
