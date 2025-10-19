<?php
// public/pages/inbox.php
$pdo = db();
$inboxId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($inboxId <= 0) { echo '<p class="alert danger">Invalid inbox id.</p>'; return; }

// Inbox info
$stmt = $pdo->prepare("
  SELECT i_inboxes.id,
         i_inboxes.inbox_iri,
         i_inboxes.resource_iri,
         i_inboxes.visibility,
         i_inboxes.owner_user_id,
         i_inboxes.created_at,
         u_users.username
  FROM i_inboxes
  JOIN u_users ON u_users.id = i_inboxes.owner_user_id
  WHERE i_inboxes.id = :inbox_id
");
$stmt->execute([':inbox_id' => $inboxId]);
$inbox = $stmt->fetch();
if (!$inbox) { echo '<p class="alert danger">Inbox not found.</p>'; return; }

// Fetch notifications
$limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$stmt = $pdo->prepare("
  SELECT i_notifications.id,
         i_notifications.notification_iri,
         i_notifications.as_type,
         i_notifications.as_object_iri,
         i_notifications.as_target_iri,
         i_notifications.received_at,
         i_notifications.corr_token,
         (SELECT COUNT(*)
          FROM o_outgoing_notifications
          WHERE o_outgoing_notifications.reply_to_notification_id = i_notifications.id) AS reply_count
  FROM i_notifications
  WHERE i_notifications.inbox_id = :inbox_id AND i_notifications.status = 'accepted'
  ORDER BY i_notifications.received_at DESC
  LIMIT :limit
");
$stmt->execute([':inbox_id' => $inboxId, ':limit' => $limit]);
$items = $stmt->fetchAll();

// POST URL (receive endpoint)
$postUrl = "/api/inbox_receive.php?inbox_id=".$inboxId;
?>
<div>
  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="?p=home">Inboxes</a></li>
      <li class="breadcrumb-item active">Inbox #<?= (int)$inbox['id'] ?></li>
    </ol>
  </nav>

  <h1 class="mb-4">
    <i class="bi bi-inbox text-primary me-2"></i>Inbox #<?= (int)$inbox['id'] ?>
  </h1>

  <div class="card mb-4">
    <div class="card-body">
      <div class="row mb-2">
        <div class="col-md-6">
          <strong><i class="bi bi-person me-1"></i>Owner:</strong>
          <?= htmlspecialchars($inbox['username']) ?>
        </div>
        <div class="col-md-6">
          <strong><i class="bi bi-eye me-1"></i>Visibility:</strong>
          <span class="badge bg-<?= $inbox['visibility'] === 'public' ? 'success' : 'secondary' ?>">
            <?= htmlspecialchars($inbox['visibility']) ?>
          </span>
        </div>
      </div>
      <div class="row mb-2">
        <div class="col-12">
          <strong><i class="bi bi-link-45deg me-1"></i>IRI:</strong>
          <code class="small"><?= htmlspecialchars($inbox['inbox_iri']) ?></code>
        </div>
      </div>
      <?php if ($inbox['resource_iri']): ?>
      <div class="row mb-2">
        <div class="col-12">
          <strong><i class="bi bi-file-earmark me-1"></i>Resource:</strong>
          <code class="small"><?= htmlspecialchars($inbox['resource_iri']) ?></code>
        </div>
      </div>
      <?php endif; ?>
      <div class="row mb-2">
        <div class="col-12">
          <strong><i class="bi bi-calendar3 me-1"></i>Created:</strong>
          <span class="text-muted"><?= htmlspecialchars($inbox['created_at']) ?></span>
        </div>
      </div>
      <hr>
      <div class="row">
        <div class="col-12">
          <strong><i class="bi bi-mailbox me-1"></i>POST Endpoint:</strong>
          <div class="input-group mt-2">
            <input type="text" class="form-control font-monospace" value="<?= htmlspecialchars($postUrl) ?>" readonly>
            <button class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($postUrl) ?>')">
              <i class="bi bi-clipboard"></i>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <h2 class="mb-3">
    <i class="bi bi-bell me-2"></i>Recent Notifications
    <span class="badge bg-secondary"><?= count($items) ?></span>
  </h2>

  <?php if (!$items): ?>
    <div class="alert alert-info">
      <i class="bi bi-info-circle me-2"></i>No notifications received yet.
    </div>
  <?php else: ?>
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th style="width: 60px;">ID</th>
              <th>Type</th>
              <th>Object</th>
              <th>Target</th>
              <th>Correlation</th>
              <th style="width: 160px;">Received</th>
              <th style="width: 80px;"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $n): ?>
              <tr>
                <td><span class="badge bg-info">#<?= (int)$n['id'] ?></span></td>
                <td><span class="badge bg-secondary"><?= htmlspecialchars($n['as_type'] ?? '—') ?></span></td>
                <td><small class="font-monospace text-muted"><?= htmlspecialchars($n['as_object_iri'] ?? '—') ?></small></td>
                <td><small class="font-monospace text-muted"><?= htmlspecialchars($n['as_target_iri'] ?? '—') ?></small></td>
                <td><small class="font-monospace"><?= htmlspecialchars($n['corr_token'] ?? '—') ?></small></td>
                <td class="text-muted"><small><?= htmlspecialchars($n['received_at']) ?></small></td>
                <td>
                  <a class="btn btn-sm btn-outline-primary" href="?p=notification&id=<?= (int)$n['id'] ?>">
                    <i class="bi bi-eye"></i> View
                  </a>
                  <?php if (!empty($n['reply_count'])): ?>
                    <span class="badge bg-primary ms-2" title="Outgoing replies sent">
                      ↩ <?= (int)$n['reply_count'] ?>
                    </span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="mt-3">
    <a class="btn btn-outline-secondary" href="?p=home">
      <i class="bi bi-arrow-left me-1"></i>Back to Inboxes
    </a>
  </div>
</div>
