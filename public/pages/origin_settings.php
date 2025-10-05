<?php

$pdo = db();
$msg = null;
$err = null;

$user = currentUser();

if (!isAdmin())
{
  header('Location: ?p=home');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
  try
  {
    $id_iri = trim($_POST['id_iri'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? 'application';

    if ($id_iri === '')
    {
      throw new RuntimeException('Origin IRI is required.');
    }

    if (strlen($id_iri) > 1000)
    {
      throw new RuntimeException('Origin IRI must not exceed 1000 characters.');
    }

    if ($name !== '' && strlen($name) > 1000)
    {
      throw new RuntimeException('Name must not exceed 1000 characters.');
    }

    $stmt = $pdo->prepare("SELECT id FROM i_origin LIMIT 1");
    $stmt->execute();
    $existing = $stmt->fetch();

    if ($existing)
    {
      $stmt = $pdo->prepare("
        UPDATE i_origin
        SET id_iri = :id_iri,
            name = :name,
            type = :type
        WHERE id = :id
      ");
      $stmt->execute([
        ':id_iri' => $id_iri,
        ':name' => $name !== '' ? $name : null,
        ':type' => $type,
        ':id' => $existing['id']
      ]);
    }
    else
    {
      $stmt = $pdo->prepare("
        INSERT INTO i_origin (id_iri, name, type)
        VALUES (:id_iri, :name, :type)
      ");
      $stmt->execute([
        ':id_iri' => $id_iri,
        ':name' => $name !== '' ? $name : null,
        ':type' => $type
      ]);
    }

    $msg = 'Origin settings updated successfully.';
  }
  catch (Throwable $e)
  {
    $err = $e->getMessage();
  }
}

$stmt = $pdo->prepare("SELECT * FROM i_origin LIMIT 1");
$stmt->execute();
$origin = $stmt->fetch();

if (!$origin)
{
  $origin = [
    'id_iri' => '',
    'name' => '',
    'type' => 'application'
  ];
}
?>
<div class="row justify-content-center">
  <div class="col-lg-8">
    <h1 class="mb-4">
      <i class="bi bi-globe text-primary me-2"></i>Origin Settings
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
      <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-gear me-2"></i>Application Origin Configuration</h5>
      </div>
      <div class="card-body">
        <p class="text-muted mb-4">
          Configure the origin settings for this LDN Inbox application. This information identifies your application in the Linked Data Notification ecosystem.
        </p>

        <form method="post">
          <div class="mb-3">
            <label class="form-label">Origin IRI <span class="text-danger">*</span></label>
            <input
              type="text"
              name="id_iri"
              class="form-control"
              value="<?= htmlspecialchars($origin['id_iri']) ?>"
              required
            >
            <div class="form-text">The unique IRI that identifies this application (max 1000 characters)</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Name</label>
            <input
              type="text"
              name="name"
              class="form-control"
              value="<?= htmlspecialchars($origin['name']) ?>"
              placeholder="My LDN Application"
            >
            <div class="form-text">Human-readable name for this application (optional, max 1000 characters)</div>
          </div>

          <div class="mb-4">
            <label class="form-label">Type</label>
            <select name="type" class="form-select">
              <option value="application" <?= $origin['type'] === 'application' ? 'selected' : '' ?>>Application</option>
            </select>
            <div class="form-text">The type of this origin</div>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">
              <i class="bi bi-check-lg me-1"></i>Save Settings
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
