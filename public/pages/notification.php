<?php
$pdo = db();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { echo '<p class="alert danger">Invalid id.</p>'; return; }

$stmt = $pdo->prepare("
  SELECT i_notifications.id,
         i_notifications.notification_iri,
         i_notifications.inbox_id,
         i_notifications.sender_id,
         i_notifications.content_type,
         i_notifications.body_jsonld,
         i_notifications.as_type,
         i_notifications.as_object_iri,
         i_notifications.as_target_iri,
         i_notifications.digest_sha256,
         i_notifications.status,
         i_notifications.received_at,
         i_notifications.corr_token,
         i_inboxes.inbox_iri AS inbox_inbox_iri,
         u_users.username,
         u_users.id AS owner_user_id
  FROM i_notifications
  JOIN i_inboxes ON i_inboxes.id = i_notifications.inbox_id
  JOIN u_users ON u_users.id = i_inboxes.owner_user_id
  WHERE i_notifications.id = :id
");
$stmt->execute([':id' => $id]);
$notif = $stmt->fetch();
if (!$notif) { echo '<p class="alert danger">Notification not found.</p>'; return; }

$decoded = json_decode($notif['body_jsonld'], true);
$pretty = $decoded ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $notif['body_jsonld'];

$stmt = $pdo->prepare("SELECT o_outgoing_notifications.id,
                              o_outgoing_notifications.to_inbox_iri,
                              o_outgoing_notifications.as_type,
                              o_outgoing_notifications.corr_token,
                              o_outgoing_notifications.delivery_status,
                              o_outgoing_notifications.created_at,
                              o_outgoing_notifications.body_jsonld
                       FROM o_outgoing_notifications
                       WHERE o_outgoing_notifications.reply_to_notification_id = :id
                       ORDER BY o_outgoing_notifications.created_at DESC");
$stmt->execute([':id' => $id]);
$replies = $stmt->fetchAll();

$msg = null; $err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__reply_form'])) {
  try {
    $payload = [
      'from_user_id' => $_POST['from_user_id'] ?? '',
      'to_inbox_iri' => $_POST['to_inbox_iri'] ?? '',
      'corr_token'   => $_POST['corr_token'] ?? '',
      'body_jsonld'  => $_POST['body_jsonld'] ?? '',
      'reply_to_notification_id' => (string)$notif['id'],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
      CURLOPT_POSTFIELDS => json_encode($payload),
      CURLOPT_RETURNTRANSFER => true,
    ]);
    $resp = curl_exec($ch);
    $errCurl = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errCurl || $code >= 400) {
      throw new RuntimeException($errCurl ?: "HTTP $code: $resp");
    }
    $msg = "Reply sent: $resp";
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}


$incomingActor = null;
$incomingActorInbox = '';
if ($decoded && isset($decoded['actor'])) {
  if (is_array($decoded['actor'])) {
    $incomingActor = $decoded['actor'];
    $incomingActorInbox = $decoded['actor']['inbox'] ?? '';
  } elseif (is_string($decoded['actor'])) {
    $incomingActor = ['id' => $decoded['actor']];
  }
}

$suggestedReplyTypes = [];
switch ($notif['as_type']) {
  case 'Offer':
    $suggestedReplyTypes = ['Accept', 'Reject'];
    break;
  case 'Create':
  case 'Update':
  case 'Announce':
    $suggestedReplyTypes = ['Accept', 'Reject'];
    break;
  case 'Accept':
  case 'Reject':
    $suggestedReplyTypes = ['Undo'];
    break;
  case 'Remove':
    $suggestedReplyTypes = ['Undo'];
    break;
  default:
    $suggestedReplyTypes = ['Accept', 'Reject'];
}

$defaultReplyInbox = $incomingActorInbox;

$defaultCorr = $notif['corr_token'] ?: ($notif['notification_iri'] ?? '');
$originActivityId = is_array($decoded) && isset($decoded['id']) && is_string($decoded['id']) ? $decoded['id'] : null;
$defaultObject = $originActivityId ?: ($notif['as_object_iri'] ?: ($notif['notification_iri'] ?? ''));
$replyExample = json_encode([
  'type' => 'Accept',
  'object' => $defaultObject,
  'inReplyTo' => $defaultCorr,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$users = $pdo->query("
  SELECT u.id, u.username, u.webid_iri, u.actor_name, u.actor_type, i.inbox_iri
  FROM u_users u
  LEFT JOIN i_inboxes i ON i.owner_user_id = u.id AND i.is_primary = 1
  ORDER BY u.id ASC
")->fetchAll();

$cfg = require __DIR__.'/../../src/config.php';
$baseUrl = rtrim($cfg['base_url'], '/');
?>
<div>
  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="?p=home">Inboxes</a></li>
      <li class="breadcrumb-item"><a href="?p=inbox&id=<?= (int)$notif['inbox_id'] ?>">Inbox #<?= (int)$notif['inbox_id'] ?></a></li>
      <li class="breadcrumb-item active">Notification #<?= (int)$notif['id'] ?></li>
    </ol>
  </nav>

  <h1 class="mb-4">
    <i class="bi bi-envelope-open text-primary me-2"></i>Notification #<?= (int)$notif['id'] ?>
  </h1>

  <div class="card mb-4">
    <div class="card-header bg-light">
      <strong><i class="bi bi-info-circle me-1"></i>Metadata</strong>
    </div>
    <div class="card-body">
      <div class="row mb-2">
        <div class="col-md-6">
          <strong><i class="bi bi-inbox me-1"></i>Inbox:</strong>
          <a href="?p=inbox&id=<?= (int)$notif['inbox_id'] ?>" class="badge bg-primary text-decoration-none">
          </a>
        </div>
        <div class="col-md-6">
          <strong><i class="bi bi-person me-1"></i>Owner:</strong>
          <?= htmlspecialchars($notif['username']) ?>
        </div>
      </div>
      <div class="row mb-2">
        <div class="col-12">
          <strong><i class="bi bi-link-45deg me-1"></i>Notification IRI:</strong>
          <code class="small"><?= htmlspecialchars($notif['notification_iri'] ?? '—') ?></code>
        </div>
      </div>
      <div class="row mb-2">
        <div class="col-md-6">
          <strong><i class="bi bi-calendar3 me-1"></i>Received:</strong>
          <span class="text-muted"><?= htmlspecialchars($notif['received_at']) ?></span>
        </div>
        <div class="col-md-6">
          <strong><i class="bi bi-file-earmark-code me-1"></i>Content-Type:</strong>
          <code class="small"><?= htmlspecialchars($notif['content_type']) ?></code>
        </div>
      </div>
      <hr>
      <div class="row mb-2">
        <div class="col-md-4">
          <strong><i class="bi bi-tag me-1"></i>Type:</strong>
          <span class="badge bg-secondary"><?= htmlspecialchars($notif['as_type'] ?? '—') ?></span>
        </div>
        <div class="col-md-8">
          <strong><i class="bi bi-diagram-3 me-1"></i>Correlation Token:</strong>
          <code class="small"><?= htmlspecialchars($notif['corr_token'] ?? '—') ?></code>
        </div>
      </div>
      <?php if ($notif['as_object_iri']): ?>
      <div class="row mb-2">
        <div class="col-12">
          <strong><i class="bi bi-box me-1"></i>Object IRI:</strong>
          <code class="small"><?= htmlspecialchars($notif['as_object_iri']) ?></code>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($notif['as_target_iri']): ?>
      <div class="row mb-2">
        <div class="col-12">
          <strong><i class="bi bi-bullseye me-1"></i>Target IRI:</strong>
          <code class="small"><?= htmlspecialchars($notif['as_target_iri']) ?></code>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <h2 class="mb-3"><i class="bi bi-code-square me-2"></i>Payload</h2>
  <div class="card">
    <div class="card-body">
      <pre class="bg-light p-3 rounded mb-0" style="max-height: 500px; overflow-y: auto;"><code><?= htmlspecialchars($pretty) ?></code></pre>
    </div>
  </div>

  <div class="mt-4 d-flex gap-2">
    <a class="btn btn-outline-secondary" href="?p=inbox&id=<?= (int)$notif['inbox_id'] ?>">
      <i class="bi bi-arrow-left me-1"></i>Back to Inbox
    </a>
    <a class="btn btn-primary" href="#reply-form">
      <i class="bi bi-reply me-1"></i>Reply
    </a>
  </div>
</div>

<?php if ($replies): ?>
<hr class="my-5">
<div class="container px-0">
  <h2 class="mb-3">
    <i class="bi bi-arrow-return-right me-2"></i>Outgoing Replies
    <span class="badge bg-secondary"><?= count($replies) ?></span>
  </h2>
  <div class="card mb-4">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th style="width: 70px;">ID</th>
            <th>To Inbox</th>
            <th>Type</th>
            <th>Correlation</th>
            <th>Status</th>
            <th style="width: 170px;">Created</th>
            <th style="width: 160px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($replies as $r): ?>
            <tr>
              <td><span class="badge bg-info">#<?= (int)$r['id'] ?></span></td>
              <td><small class="font-monospace text-muted"><?= htmlspecialchars($r['to_inbox_iri']) ?></small></td>
              <td><span class="badge bg-secondary"><?= htmlspecialchars($r['as_type'] ?? '—') ?></span></td>
              <td><small class="font-monospace"><?= htmlspecialchars($r['corr_token'] ?? '—') ?></small></td>
              <td>
                <?php if ($r['delivery_status'] === 'delivered'): ?>
                  <span class="badge bg-success">delivered</span>
                <?php elseif ($r['delivery_status'] === 'failed'): ?>
                  <span class="badge bg-danger">failed</span>
                <?php else: ?>
                  <span class="badge bg-warning text-dark">pending</span>
                <?php endif; ?>
              </td>
              <td class="text-muted"><small><?= htmlspecialchars($r['created_at']) ?></small></td>
              <td>
                <a class="btn btn-sm btn-outline-success" href="/ldn/outgoing_get.php?id=<?= (int)$r['id'] ?>" target="_blank">
                  <i class="bi bi-file-earmark-arrow-down me-1"></i>Raw
                </a>
                <?php 
                  $jp = json_decode($r['body_jsonld'], true);
                  $rPretty = $jp ? json_encode($jp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $r['body_jsonld'];
                  $cid = 'reply-json-'.(int)$r['id'];
                ?>
                <button class="btn btn-sm btn-outline-primary ms-2" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $cid ?>">
                  <i class="bi bi-code-square me-1"></i>Preview
                </button>
                <div id="<?= $cid ?>" class="collapse mt-2">
                  <pre class="bg-light p-2 rounded mb-0" style="max-height: 300px; overflow-y: auto;"><code><?= htmlspecialchars($rPretty) ?></code></pre>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<hr class="my-5">
<div class="container px-0">
  <h2 id="reply-form" class="mb-3">
    <i class="bi bi-reply text-primary me-2"></i>Reply to Notification
  </h2>

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
    <div class="card-header">
      <ul class="nav nav-tabs card-header-tabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="form-builder-tab" data-bs-toggle="tab" data-bs-target="#form-builder-pane" type="button" role="tab">
            <i class="bi bi-ui-checks me-1"></i>Form Builder
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="manual-json-tab" data-bs-toggle="tab" data-bs-target="#manual-json-pane" type="button" role="tab">
            <i class="bi bi-code-square me-1"></i>Manual JSON
          </button>
        </li>
      </ul>
    </div>
    <div class="card-body">
      <div class="tab-content">
        <div class="tab-pane fade show active" id="form-builder-pane" role="tabpanel">
          <form method="post" id="replyFormBuilder">
            <input type="hidden" name="__reply_form" value="1">
            <input type="hidden" name="reply_to_notification_id" value="<?= (int)$notif['id'] ?>">
            <input type="hidden" name="body_jsonld" id="generatedReplyJson">

            <div class="mb-3">
              <label class="form-label">Reply Type <span class="text-danger">*</span></label>
              <select name="reply_type" id="replyType" class="form-select" onchange="updateReplyPreview()" required>
                <?php foreach ($suggestedReplyTypes as $type): ?>
                  <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Suggested based on incoming notification type: <strong><?= htmlspecialchars($notif['as_type']) ?></strong></div>
            </div>

            <div class="mb-3">
              <label class="form-label">From User (Actor) <span class="text-danger">*</span></label>
              <select name="from_user_id" id="replyFromUser" class="form-select" onchange="updateReplyPreview()" required>
                <?php foreach ($users as $u):
                  $sel = ((int)$u['id'] === (int)$notif['owner_user_id']) ? 'selected' : '';
                ?>
                  <option value="<?= (int)$u['id'] ?>" <?= $sel ?>
                          data-webid="<?= htmlspecialchars($u['webid_iri'] ?? '') ?>"
                          data-name="<?= htmlspecialchars($u['actor_name'] ?? '') ?>"
                          data-type="<?= htmlspecialchars($u['actor_type'] ?? 'Person') ?>"
                          data-inbox="<?= htmlspecialchars($u['inbox_iri'] ?? '') ?>">
                    <?= $u['inbox_iri'] ? '✅ ' : '⚠️ ' ?>
                    <?= htmlspecialchars($u['username']) ?> (#<?= (int)$u['id'] ?>)
                    <?= !$u['inbox_iri'] ? ' [No Inbox]' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">You are replying on behalf of this user</div>
            </div>

            <div class="mb-3">
              <label class="form-label">To Inbox IRI <span class="text-danger">*</span></label>
              <input type="url" name="to_inbox_iri" id="replyToInbox" class="form-control font-monospace"
                     value="<?= htmlspecialchars($defaultReplyInbox) ?>"
                     onchange="updateReplyPreview()"
              <div class="form-text">
                <?php if ($defaultReplyInbox): ?>
                  <i class="bi bi-check-circle text-success me-1"></i>Auto-filled from incoming actor's inbox
                <?php else: ?>
                  Target inbox URL to send the reply to
                <?php endif; ?>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">Correlation Token</label>
              <input type="text" name="corr_token" id="replyCorrToken" class="form-control font-monospace"
                     value="<?= htmlspecialchars($defaultCorr) ?>"
                     onchange="updateReplyPreview()"
                     placeholder="req-abc-123">
              <div class="form-text">Auto-filled to match incoming notification</div>
            </div>

            <div class="mb-3">
              <label class="form-label">Summary <span class="text-muted">(optional)</span></label>
              <input type="text" id="replySummary" class="form-control"
                     onchange="updateReplyPreview()"
                     placeholder="Accepting your offer">
              <div class="form-text">Brief description of the reply</div>
            </div>

            <div class="mb-4">
              <label class="form-label">JSON-LD Preview</label>
              <pre id="replyJsonPreview" class="bg-light p-3 rounded border" style="max-height: 400px; overflow-y: auto;"><code>{}</code></pre>
            </div>

            <div class="d-flex gap-2">
              <button class="btn btn-primary" type="submit">
                <i class="bi bi-send-fill me-1"></i>Send Reply
              </button>
              <a class="btn btn-outline-secondary" href="?p=inbox&id=<?= (int)$notif['inbox_id'] ?>">
                <i class="bi bi-x-lg me-1"></i>Cancel
              </a>
            </div>
          </form>
        </div>

        <div class="tab-pane fade" id="manual-json-pane" role="tabpanel">
          <form method="post">
            <input type="hidden" name="__reply_form" value="1">
            <input type="hidden" name="reply_to_notification_id" value="<?= (int)$notif['id'] ?>">

            <div class="mb-3">
              <label class="form-label">From User <span class="text-muted">(optional)</span></label>
              <select name="from_user_id" class="form-select">
                <option value="">— none —</option>
                <?php foreach ($users as $u): $sel = ((int)$u['id'] === (int)$notif['owner_user_id']) ? 'selected' : ''; ?>
                  <option value="<?= (int)$u['id'] ?>" <?= $sel ?>><?= htmlspecialchars($u['username']) ?> (#<?= (int)$u['id'] ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label">To Inbox IRI</label>
              <input type="url" name="to_inbox_iri" class="form-control font-monospace"
                     value="<?= htmlspecialchars($defaultReplyInbox) ?>"
              <div class="form-text">Target inbox URL to send the reply to</div>
            </div>

            <div class="mb-3">
              <label class="form-label">Correlation Token <span class="text-muted">(optional)</span></label>
              <input type="text" name="corr_token" class="form-control font-monospace"
                     value="<?= htmlspecialchars($defaultCorr) ?>"
                     placeholder="req-abc-123">
              <div class="form-text">Reply correlation, typically match <code>inReplyTo</code> in JSON</div>
            </div>

            <div class="mb-4">
              <label class="form-label">Body (JSON-LD)</label>
              <textarea name="body_jsonld" rows="14" class="form-control font-monospace"
                        placeholder="<?= htmlspecialchars($replyExample) ?>"></textarea>
              <div class="form-text">ActivityStreams reply payload. Prefill uses <code>inReplyTo</code> from this notification.</div>
            </div>

            <div class="d-flex gap-2">
              <button class="btn btn-primary" type="submit">
                <i class="bi bi-send-fill me-1"></i>Send Reply
              </button>
              <a class="btn btn-outline-secondary" href="?p=inbox&id=<?= (int)$notif['inbox_id'] ?>">
                <i class="bi bi-x-lg me-1"></i>Cancel
              </a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const incomingNotification = <?= json_encode($decoded) ?>;
const incomingActor = <?= json_encode($incomingActor) ?>;
const baseUrl = '<?= $baseUrl ?>';
const notificationOwnerId = <?= (int)$notif['owner_user_id'] ?>;

function updateReplyPreview() {
  const replyType = document.getElementById('replyType').value;
  const fromUserId = document.getElementById('replyFromUser').value;
  const corrToken = document.getElementById('replyCorrToken').value;
  const summary = document.getElementById('replySummary').value;

  const fromUserSelect = document.getElementById('replyFromUser');
  const fromUserOption = fromUserSelect.selectedOptions[0];
  const actorWebId = fromUserOption.dataset.webid || `${baseUrl}/users/${fromUserId}`;
  const actorName = fromUserOption.dataset.name || fromUserOption.text.split('(')[0].trim();
  const actorType = fromUserOption.dataset.type || 'Person';

  const payload = {
    'id': `${baseUrl}/notifications/reply_${Date.now()}`,
    'type': replyType
  };

  payload.origin = {
    'id': baseUrl,
    'name': 'LDN Inbox Demo System',
    'type': 'Application'
  };

  const actorInbox = fromUserOption.dataset.inbox;

  payload.actor = {
    'id': actorWebId,
    'name': actorName,
    'type': actorType
  };

  if (actorInbox) {
    payload.actor.inbox = actorInbox;
  }

  if (incomingNotification && incomingNotification.id) {
    payload.object = incomingNotification.id;
  }

  if (incomingActor) {
    payload.target = incomingActor;
  }

  if (corrToken) {
    payload.correlationId = corrToken;
  }

  if (summary) {
    payload.summary = summary;
  }

  payload.published = new Date().toISOString();

  const jsonString = JSON.stringify(payload, null, 2);

  document.getElementById('replyJsonPreview').innerHTML = '<code>' + escapeHtml(jsonString) + '</code>';

  document.getElementById('generatedReplyJson').value = jsonString;

  return payload;
}

function escapeHtml(text) {
  const map = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  };
  return text.replace(/[&<>"']/g, m => map[m]);
}

document.addEventListener('DOMContentLoaded', function() {
  updateReplyPreview();

  document.getElementById('replyFormBuilder').addEventListener('submit', function(e) {
    updateReplyPreview();
  });
});
</script>
