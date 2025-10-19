<?php
// public/pages/home.php
$pdo = db();

// Fetch inboxes with owner usernames (admins see all, regular users see only their own)
if (isAdmin()) {
  $stmt = $pdo->query("
    SELECT i_inboxes.id,
           i_inboxes.inbox_iri,
           i_inboxes.resource_iri,
           i_inboxes.visibility,
           i_inboxes.created_at,
           u_users.username
    FROM i_inboxes
    JOIN u_users ON u_users.id = i_inboxes.owner_user_id
    ORDER BY i_inboxes.created_at DESC
  ");
  $inboxes = $stmt->fetchAll();
} else {
  $userId = currentUserId();
  $stmt = $pdo->prepare("
    SELECT i_inboxes.id,
           i_inboxes.inbox_iri,
           i_inboxes.resource_iri,
           i_inboxes.visibility,
           i_inboxes.created_at,
           u_users.username
    FROM i_inboxes
    JOIN u_users ON u_users.id = i_inboxes.owner_user_id
    WHERE i_inboxes.owner_user_id = :user_id
    ORDER BY i_inboxes.created_at DESC
  ");
  $stmt->execute([':user_id' => $userId]);
  $inboxes = $stmt->fetchAll();
}
?>

<div class="row mb-4">
  <div class="col">
    <h1 class="mb-3">
      <i class="bi bi-inbox text-primary me-2"></i>Inboxes
    </h1>
    <p class="text-muted">Click an inbox to view recent notifications.</p>
  </div>
  <div class="col-auto">
    <a class="btn btn-primary" href="?p=new_inbox">
      <i class="bi bi-plus-circle me-1"></i>Create Inbox
    </a>
  </div>
</div>

<?php if (empty($inboxes)): ?>
  <div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    No inboxes yet. <a href="?p=new_inbox" class="alert-link">Create your first inbox</a>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th style="width: 60px;">ID</th>
            <th>Owner</th>
            <th>Inbox IRI</th>
            <th>Resource IRI</th>
            <th style="width: 100px;">Visibility</th>
            <th style="width: 160px;">Created</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($inboxes as $i): ?>
            <tr>
              <td>
                <a href="?p=inbox&id=<?= (int)$i['id'] ?>" class="badge bg-primary text-decoration-none">
                  #<?= (int)$i['id'] ?>
                </a>
              </td>
              <td>
                <i class="bi bi-person-circle me-1"></i>
                <strong><?= htmlspecialchars($i['username']) ?></strong>
              </td>
              <td>
                <small class="font-monospace text-muted"><?= htmlspecialchars($i['inbox_iri']) ?></small>
              </td>
              <td>
                <small class="font-monospace text-muted"><?= htmlspecialchars($i['resource_iri'] ?? '—') ?></small>
              </td>
              <td>
                <span class="badge bg-<?= $i['visibility'] === 'public' ? 'success' : 'secondary' ?>">
                  <?= htmlspecialchars($i['visibility']) ?>
                </span>
              </td>
              <td class="text-muted">
                <small><?= htmlspecialchars($i['created_at']) ?></small>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-3">
    <a class="btn btn-outline-secondary" href="?p=send">
      <i class="bi bi-send me-1"></i>Send Outgoing Notification
    </a>
  </div>
<?php endif; ?>

