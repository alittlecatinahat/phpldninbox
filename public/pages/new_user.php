<?php
// public/pages/new_user.php
$pdo = db();
$msg = null;
$err = null;

// Only admins can create users
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $webidIri = trim($_POST['webid_iri'] ?? '');
    $actorName = trim($_POST['actor_name'] ?? '');
    $actorType = trim($_POST['actor_type'] ?? 'Person');
    $role = $_POST['role'] ?? 'user';

    if ($username === '' || $password === '') {
      throw new RuntimeException('Username and password are required.');
    }

    if (!in_array($role, ['admin', 'user'], true)) {
      $role = 'user';
    }

    $passwordHash = hashPassword($password);

    $stmt = $pdo->prepare("INSERT INTO u_users (username, password_hash, role, webid_iri, actor_name, actor_type)
                           VALUES (:username, :password_hash, :role, NULLIF(:webid_iri,''), NULLIF(:actor_name,''), :actor_type)");
    $stmt->execute([
      ':username' => $username,
      ':password_hash' => $passwordHash,
      ':role' => $role,
      ':webid_iri' => $webidIri,
      ':actor_name' => $actorName,
      ':actor_type' => $actorType
    ]);
    $newId = (int)$pdo->lastInsertId();
    $msg = "User #$newId '$username' created successfully as $role.";
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

// Get existing users
$users = $pdo->query("SELECT id, username, role, webid_iri, actor_name, actor_type, last_login, created_at FROM u_users ORDER BY id DESC")->fetchAll();
?>
<div class="row">
  <div class="col-lg-8">
    <h1 class="mb-4">
      <i class="bi bi-person-plus text-primary me-2"></i>Create User
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
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-control"
                   placeholder="alice" required>
            <div class="form-text">Unique username for the user</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control"
                   placeholder="Enter password" required>
            <div class="form-text">User's login password</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Role</label>
            <select name="role" class="form-select">
              <option value="user">User (Regular access)</option>
              <option value="admin">Admin (Full access)</option>
            </select>
            <div class="form-text">Admin sees all data, regular users see only their own</div>
          </div>

          <div class="mb-3">
            <label class="form-label">WebID IRI <span class="text-muted">(optional)</span></label>
            <input type="url" name="webid_iri" class="form-control font-monospace"
                   placeholder="https://example.com/profile/alice#me">
            <div class="form-text">Optional WebID for decentralized identity (used as actor id)</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Actor Name <span class="text-muted">(optional)</span></label>
            <input type="text" name="actor_name" class="form-control"
                   placeholder="Alice Smith">
            <div class="form-text">Human-readable name for actor representation</div>
          </div>

          <div class="mb-4">
            <label class="form-label">Actor Type</label>
            <select name="actor_type" class="form-select">
              <option value="Person">Person</option>
              <option value="Organization">Organization</option>
              <option value="Service">Service</option>
              <option value="Application">Application</option>
            </select>
            <div class="form-text">Activity Streams actor type</div>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">
              <i class="bi bi-check-lg me-1"></i>Create User
            </button>
            <a class="btn btn-outline-secondary" href="?p=home">
              <i class="bi bi-x-lg me-1"></i>Cancel
            </a>
          </div>
        </form>
      </div>
    </div>

    <?php if ($users): ?>
      <div class="card mt-4">
        <div class="card-header">
          <h5 class="mb-0"><i class="bi bi-people me-2"></i>Existing Users</h5>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Username</th>
                  <th>Role</th>
                  <th>Actor Name</th>
                  <th>Actor Type</th>
                  <th>WebID IRI</th>
                  <th>Last Login</th>
                  <th>Created</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $u): ?>
                  <tr>
                    <td><span class="badge bg-secondary">#<?= (int)$u['id'] ?></span></td>
                    <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                    <td>
                      <?php if ($u['role'] === 'admin'): ?>
                        <span class="badge bg-danger">Admin</span>
                      <?php else: ?>
                        <span class="badge bg-secondary">User</span>
                      <?php endif; ?>
                    </td>
                    <td><?= $u['actor_name'] ? htmlspecialchars($u['actor_name']) : '<span class="text-muted">—</span>' ?></td>
                    <td><span class="badge bg-info"><?= htmlspecialchars($u['actor_type'] ?? 'Person') ?></span></td>
                    <td>
                      <?php if ($u['webid_iri']): ?>
                        <a href="<?= htmlspecialchars($u['webid_iri']) ?>" target="_blank" class="font-monospace small">
                          <?= htmlspecialchars($u['webid_iri']) ?>
                        </a>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= $u['last_login'] ? htmlspecialchars($u['last_login']) : '—' ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($u['created_at']) ?></td>
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
