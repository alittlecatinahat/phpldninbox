<?php
// public/pages/new_resource.php
$pdo = db();
$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') 
{
  try 
  {
    $owner = (int)($_POST['owner_user_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $type = trim($_POST['type'] ?? 'Article');
    $description = trim($_POST['description'] ?? '');
    $contentUrl = trim($_POST['content_url'] ?? '');

    if ($owner <= 0 || $title === '') 
    {
      throw new RuntimeException('Owner and title are required.');
    }

    // First insert to get the ID
    $stmt = $pdo->prepare("INSERT INTO r_resources 
                                       (owner_user_id, 
                                       resource_iri, 
                                       title, 
                                       type, 
                                       description, 
                                       content_url)
                           VALUES (:owner_user_id, 
                                   '', 
                                   :title, 
                                   :type, 
                                   NULLIF(:description,''), 
                                   NULLIF(:content_url,''))");
    $stmt->execute([
      ':owner_user_id' => $owner,
      ':title' => $title,
      ':type' => $type,
      ':description' => $description,
      ':content_url' => $contentUrl
    ]);
    $newId = (int)$pdo->lastInsertId();

    // Generate the resource IRI based on the ID
    $cfg = require __DIR__.'/../../src/config.php';
    $baseUrl = rtrim($cfg['base_url'], '/');
    $resourceIri = $baseUrl . '/resources/' . $newId;

    // Update with the generated IRI
    $stmt = $pdo->prepare("UPDATE r_resources 
                          SET resource_iri = :resource_iri 
                          WHERE id = :id");
    $stmt->execute([':resource_iri' => $resourceIri, ':id' => $newId]);

    $msg = "Resource #$newId created with IRI: $resourceIri";
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

// Get existing resources (admins see all, regular users see only their own)
if (isAdmin()) {
  $resources = $pdo->query("SELECT r_resources.id,
                                   r_resources.resource_iri,
                                   r_resources.title,
                                   r_resources.type,
                                   r_resources.description,
                                   r_resources.content_url,
                                   r_resources.created_at,
                                   u_users.username
                            FROM r_resources
                            JOIN u_users ON r_resources.owner_user_id = u_users.id
                            ORDER BY r_resources.id DESC")->fetchAll();
} else {
  $userId = currentUserId();
  $stmt = $pdo->prepare("SELECT r_resources.id,
                                r_resources.resource_iri,
                                r_resources.title,
                                r_resources.type,
                                r_resources.description,
                                r_resources.content_url,
                                r_resources.created_at,
                                u_users.username
                         FROM r_resources
                         JOIN u_users ON r_resources.owner_user_id = u_users.id
                         WHERE r_resources.owner_user_id = :user_id
                         ORDER BY r_resources.id DESC");
  $stmt->execute([':user_id' => $userId]);
  $resources = $stmt->fetchAll();
}

// ActivityStreams common types
$asTypes = [
  'Article', 'Note', 'Document', 'Page', 'Image', 'Video', 'Audio',
  'Event', 'Place', 'Profile', 'Relationship', 'Tombstone'
];
?>
<div class="row">
  <div class="col-lg-10">
    <h1 class="mb-4">
      <i class="bi bi-file-earmark-text text-primary me-2"></i>Create Resource
    </h1>

    <div class="alert alert-warning">
      <i class="bi bi-exclamation-triangle me-2"></i>
      <strong>Known Limitation:</strong> Resource IRIs are currently <strong>semantic identifiers only</strong> - they are <strong>not dereferenceable endpoints</strong> (clicking them returns 404).
      To link to actual hosted content, use the <strong>"Content URL"</strong> field below.
      <br><small class="mt-1 d-block">Unlike notification IRIs (which ARE dereferenceable), resource endpoints are not yet implemented.</small>
    </div>

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
                  <?= htmlspecialchars($u['username']) ?> (#<?= (int)$u['id'] ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Title</label>
            <input type="text" name="title" class="form-control"
                   placeholder="My Article Title" required>
            <div class="form-text">Human-readable title for this resource</div>
          </div>

          <div class="mb-3">
            <label class="form-label">ActivityStreams Type</label>
            <select name="type" class="form-select">
              <?php foreach ($asTypes as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $t === 'Article' ? 'selected' : '' ?>>
                  <?= htmlspecialchars($t) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Type of resource according to ActivityStreams vocabulary</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Description <span class="text-muted">(optional)</span></label>
            <textarea name="description" rows="3" class="form-control"
                      placeholder="A brief description of this resource..."></textarea>
          </div>

          <div class="mb-4">
            <label class="form-label">Content URL <span class="text-muted">(optional)</span></label>
            <input type="url" name="content_url" class="form-control font-monospace"
                   placeholder="https://example.com/content/article-123.html">
            <div class="form-text">Optional URL to the actual content (if hosted elsewhere). The resource IRI will be auto-generated.</div>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">
              <i class="bi bi-check-lg me-1"></i>Create Resource
            </button>
            <a class="btn btn-outline-secondary" href="?p=home">
              <i class="bi bi-x-lg me-1"></i>Cancel
            </a>
          </div>
        </form>
      </div>
    </div>

    <?php if ($resources): ?>
      <div class="card mt-4">
        <div class="card-header">
          <h5 class="mb-0"><i class="bi bi-collection me-2"></i>Existing Resources</h5>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Title</th>
                  <th>Type</th>
                  <th>Resource IRI</th>
                  <th>Owner</th>
                  <th>Created</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($resources as $r): ?>
                  <tr>
                    <td><span class="badge bg-secondary">#<?= (int)$r['id'] ?></span></td>
                    <td><strong><?= htmlspecialchars($r['title']) ?></strong></td>
                    <td><span class="badge bg-info"><?= htmlspecialchars($r['type']) ?></span></td>
                    <td>
                      <a href="<?= htmlspecialchars($r['resource_iri']) ?>" target="_blank" class="font-monospace small">
                        <?= htmlspecialchars($r['resource_iri']) ?>
                      </a>
                    </td>
                    <td><?= htmlspecialchars($r['username']) ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($r['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
