<?php
// public/pages/profile.php
$pdo = db();
$msg = null;
$err = null;

$user = currentUser();
$userId = $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($currentPassword === '' || $newPassword === '') {
      throw new RuntimeException('Current password and new password are required.');
    }

    if ($newPassword !== $confirmPassword) {
      throw new RuntimeException('New passwords do not match.');
    }

    if (strlen($newPassword) < 6) {
      throw new RuntimeException('New password must be at least 6 characters.');
    }

    // Verify current password
    $stmt = $pdo->prepare("SELECT password_hash FROM u_users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $userData = $stmt->fetch();

    if (!password_verify($currentPassword, $userData['password_hash'])) {
      throw new RuntimeException('Current password is incorrect.');
    }

    // Update password
    $newHash = hashPassword($newPassword);
    $stmt = $pdo->prepare("UPDATE u_users SET password_hash = :password_hash WHERE id = :id");
    $stmt->execute([
      ':password_hash' => $newHash,
      ':id' => $userId
    ]);

    $msg = 'Password changed successfully.';
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

// Get user stats
$stmt = $pdo->prepare("SELECT COUNT(*) FROM i_inboxes WHERE owner_user_id = :id");
$stmt->execute([':id' => $userId]);
$inboxCount = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM r_resources WHERE owner_user_id = :id");
$stmt->execute([':id' => $userId]);
$resourceCount = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT last_login, created_at FROM u_users WHERE id = :id");
$stmt->execute([':id' => $userId]);
$userInfo = $stmt->fetch();
?>
<div class="row justify-content-center">
  <div class="col-lg-8">
    <h1 class="mb-4">
      <i class="bi bi-person-circle text-primary me-2"></i>Profile Settings
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

    <div class="card mb-4">
      <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Account Information</h5>
      </div>
      <div class="card-body">
        <div class="row mb-3">
          <div class="col-sm-3 text-muted">Username:</div>
          <div class="col-sm-9"><strong><?= htmlspecialchars($user['username']) ?></strong></div>
        </div>
        <div class="row mb-3">
          <div class="col-sm-3 text-muted">Role:</div>
          <div class="col-sm-9">
            <?php if ($user['role'] === 'admin'): ?>
              <span class="badge bg-danger">Admin</span>
            <?php else: ?>
              <span class="badge bg-secondary">User</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="row mb-3">
          <div class="col-sm-3 text-muted">Member Since:</div>
          <div class="col-sm-9"><?= htmlspecialchars($userInfo['created_at']) ?></div>
        </div>
        <div class="row mb-3">
          <div class="col-sm-3 text-muted">Last Login:</div>
          <div class="col-sm-9"><?= $userInfo['last_login'] ? htmlspecialchars($userInfo['last_login']) : 'Never' ?></div>
        </div>
        <div class="row mb-3">
          <div class="col-sm-3 text-muted">Inboxes:</div>
          <div class="col-sm-9"><?= (int)$inboxCount ?></div>
        </div>
        <div class="row">
          <div class="col-sm-3 text-muted">Resources:</div>
          <div class="col-sm-9"><?= (int)$resourceCount ?></div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-key me-2"></i>Change Password</h5>
      </div>
      <div class="card-body">
        <form method="post">
          <div class="mb-3">
            <label class="form-label">Current Password</label>
            <input type="password" name="current_password" class="form-control" required>
          </div>

          <div class="mb-3">
            <label class="form-label">New Password</label>
            <input type="password" name="new_password" class="form-control" required>
            <div class="form-text">Minimum 6 characters</div>
          </div>

          <div class="mb-4">
            <label class="form-label">Confirm New Password</label>
            <input type="password" name="confirm_password" class="form-control" required>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">
              <i class="bi bi-check-lg me-1"></i>Update Password
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
