<?php
// public/pages/new_inbox.php
$pdo = db();
$msg = null;
$err = null;

// Provide a couple of demo users if table is empty
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $owner = (int)($_POST['owner_user_id'] ?? 0);
    $resource = trim($_POST['resource_iri'] ?? '');
    # All inboxes are now public by default
    # Access control is managed via ACL rules (see ACL Management page)
    $visibility = 'public';
    $isPrimary = isset($_POST['is_primary']) ? 1 : 0;

    if ($owner <= 0) {
      throw new RuntimeException('Owner is required.');
    }

    # If this inbox is being set as primary, unset any existing primary inbox for this user
    if ($isPrimary) {
      $stmt = $pdo->prepare("UPDATE i_inboxes SET is_primary = 0 WHERE owner_user_id = :owner_user_id");
      $stmt->execute([':owner_user_id' => $owner]);
    }

    // First insert without inbox_iri to get the ID
    $stmt = $pdo->prepare("INSERT INTO i_inboxes (owner_user_id, inbox_iri, resource_iri, visibility, is_primary)
                           VALUES (:owner_user_id, '', NULLIF(:resource_iri,''), :visibility, :is_primary)");
    $stmt->execute([
      ':owner_user_id' => $owner,
      ':resource_iri' => $resource,
      ':visibility' => $visibility,
      ':is_primary' => $isPrimary
    ]);
    $newId = (int)$pdo->lastInsertId();

    // Generate the inbox IRI based on the ID
    $cfg = require __DIR__.'/../../src/config.php';
    $baseUrl = rtrim($cfg['base_url'], '/');
    $inboxIri = $baseUrl . '/api/inbox_receive.php?inbox_id=' . $newId;

    // Update with the generated IRI
    $stmt = $pdo->prepare("UPDATE i_inboxes SET inbox_iri = :inbox_iri WHERE id = :id");
    $stmt->execute([':inbox_iri' => $inboxIri, ':id' => $newId]);

    $msg = "Inbox #$newId created with IRI: $inboxIri";
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

// Get users for dropdown (admins see all, regular users see only themselves)
if (isAdmin()) {
  $users = $pdo->query("SELECT id, username FROM u_users ORDER BY id ASC")->fetchAll();
} else {
  $userId = currentUserId();
  $stmt = $pdo->prepare("SELECT id, username FROM u_users WHERE id = :id");
  $stmt->execute([':id' => $userId]);
  $users = $stmt->fetchAll();
}
?>
<div class="row">
  <div class="col-lg-8">
    <h1 class="mb-4">
      <i class="bi bi-plus-circle text-primary me-2"></i>Create Inbox
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
            <label class="form-label">Owner</label>
            <select name="owner_user_id" class="form-select" required>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>">
                  <i class="bi bi-person"></i><?= htmlspecialchars($u['username']) ?> (#<?= (int)$u['id'] ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Resource IRI <span class="text-muted">(optional)</span></label>
            <input type="url" name="resource_iri" class="form-control font-monospace"
                   placeholder="https://yourdomain/resource/42">
            <div class="form-text">If this is a per-resource inbox. The inbox IRI will be auto-generated based on the database ID.</div>
          </div>

          <div class="alert alert-info mb-3">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Access Control:</strong> All inboxes are public by default (anyone can POST).
            To restrict access, use <a href="?p=acl_manage" class="alert-link">ACL Management</a> to add allow/deny rules after creating the inbox.
          </div>

          <div class="mb-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="is_primary" id="is_primary">
              <label class="form-check-label" for="is_primary">
                Set as primary inbox for actor representation
              </label>
              <div class="form-text">Primary inbox is used when representing this user as an actor in JSON-LD</div>
            </div>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">
              <i class="bi bi-check-lg me-1"></i>Create Inbox
            </button>
            <a class="btn btn-outline-secondary" href="?p=home">
              <i class="bi bi-x-lg me-1"></i>Cancel
            </a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

