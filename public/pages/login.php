<?php
# ==============================================================================
# Login Page
# ==============================================================================
# Authentication form with demo credentials display
# Actual login processing handled in index.php before HTML output
# ==============================================================================

$err = null;

# Check for login errors from index.php
if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';

  if ($username === '' || $password === '')
  {
    $err = 'Username and password are required';
  }
  elseif (isset($loginError))
  {
    $err = $loginError;
  }
}
?>
<div class="row justify-content-center">
  <div class="col-lg-5 col-md-7">
    <div class="text-center mb-4 mt-5">
      <i class="bi bi-inbox-fill" style="font-size: 3rem; color: #0d6efd;"></i>
      <h1 class="mt-3">LDN Inbox</h1>
      <p class="text-muted">Sign in to continue</p>
    </div>

    <?php if ($err): ?>
      <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($err) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <div class="card shadow">
      <div class="card-body p-4">
        <form method="post">
          <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-control"
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                   placeholder="Enter username" required autofocus>
          </div>

          <div class="mb-4">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control"
                   placeholder="Enter password" required>
          </div>

          <button class="btn btn-primary w-100" type="submit">
            <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
          </button>
        </form>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-body">
        <h6 class="card-title mb-3"><i class="bi bi-info-circle me-2"></i>Demo Accounts</h6>
        <div class="small">
          <strong>Admin:</strong> <code>admin / admin123</code><br>
          <strong>Users:</strong> <code>alice / password</code>, <code>bob / password</code>
        </div>
      </div>
    </div>
  </div>
</div>
