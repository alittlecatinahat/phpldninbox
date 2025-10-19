<?php
# ==============================================================================
# LDN Inbox GUI - Main Router
# ==============================================================================
# Front-end router that handles page routing, authentication, and layout
# Uses PHP includes to load individual page components
# ==============================================================================

require __DIR__.'/../src/database.php';
require __DIR__.'/../src/utils.php';
require __DIR__.'/../src/auth.php';

# Initialize PHP session for authentication
initSession();

# Get requested page from query parameter (default: home)
$page = $_GET['p'] ?? 'home';

# Define public pages that don't require authentication
$publicPages = ['login'];

# Whitelist of allowed pages (security measure to prevent arbitrary includes)
$allowed = ['home',
            'inbox',
            'notification',
            'new_inbox',
            'new_user',
            'new_resource',
            'send',
            'send_new',
            'send_upgrade',
            'login',
            'logout',
            'profile',
            'origin_settings',
            'api_test',
            'acl_manage',
            'admin_notifications',
            'admin_outgoing'];

# If requested page is not in whitelist, redirect to home
if (!in_array($page, $allowed, true))
{
  $page = 'home';
}

# Load configuration for base URL display
$cfg = require __DIR__.'/../src/config.php';
$baseUrl = rtrim($cfg['base_url'], '/');

# ==============================================================================
# Handle Logout (must be before any HTML output)
# ==============================================================================
if ($page === 'logout')
{
  logout();
  header('Location: ?p=login');
  exit;
}

# ==============================================================================
# Handle Login Form Submission (must be before any HTML output)
# ==============================================================================
$loginError = null;

if (in_array($page, $publicPages, true))
{
  # Process login form submission
  if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST')
  {
    $pdo = db();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username !== '' && $password !== '')
    {
      $result = attemptLogin($pdo, $username, $password);

      if ($result === true)
      {
        # Login successful - redirect to home page
        header('Location: ?p=home');
        exit;
      }
      else
      {
        # Login failed - store error message for display
        $loginError = $result;
      }
    }
  }
}

# ==============================================================================
# Require Authentication for Protected Pages
# ==============================================================================
# All pages except those in $publicPages require authentication
if (!in_array($page, $publicPages, true))
{
  requireAuth();
}

# Get current user information for display in navigation
$currentUser = currentUser();

# ==============================================================================
# HTML Layout Begins
# ==============================================================================
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>LDN Inbox GUI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    body { background: #f8f9fa; }
    .navbar-brand { font-weight: 600; }
    main { padding: 2rem 0; min-height: calc(100vh - 200px); }
    footer { background: #343a40; color: #adb5bd; padding: 1.5rem 0; margin-top: 3rem; }
    .card { box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container">
    <a class="navbar-brand" href="?p=home">
      <i class="bi bi-inbox-fill me-2"></i>LDN Inbox
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <?php if (isLoggedIn()): ?>
        <li class="nav-item">
          <a class="nav-link <?= $page === 'home' ? 'active' : '' ?>" href="?p=home">
            <i class="bi bi-house-door me-1"></i>Inboxes
          </a>
        </li>
        <?php if (isAdmin()): ?>
        <li class="nav-item">
          <a class="nav-link <?= $page === 'new_user' ? 'active' : '' ?>" href="?p=new_user">
            <i class="bi bi-person-plus me-1"></i>New User
          </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
          <a class="nav-link <?= $page === 'new_resource' ? 'active' : '' ?>" href="?p=new_resource">
            <i class="bi bi-file-earmark-plus me-1"></i>New Resource
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $page === 'new_inbox' ? 'active' : '' ?>" href="?p=new_inbox">
            <i class="bi bi-plus-circle me-1"></i>New Inbox
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $page === 'acl_manage' ? 'active' : '' ?>" href="?p=acl_manage">
            <i class="bi bi-shield-lock me-1"></i>ACL Rules
          </a>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= in_array($page, ['send', 'send_upgrade']) ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
            <i class="bi bi-send me-1"></i>Send Outgoing
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="?p=send_upgrade"><i class="bi bi-ui-checks me-2"></i>Form Builder</a></li>
            <li><a class="dropdown-item" href="?p=send"><i class="bi bi-code-square me-2"></i>Manual JSON</a></li>
          </ul>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
            <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($currentUser['username']) ?>
            <?php if (isAdmin()): ?>
              <span class="badge bg-danger ms-1">Admin</span>
            <?php endif; ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="?p=profile"><i class="bi bi-gear me-2"></i>Profile</a></li>
            <?php if (isAdmin()): ?>
            <li><a class="dropdown-item" href="?p=admin_notifications"><i class="bi bi-shield-check me-2"></i>All Notifications</a></li>
            <li><a class="dropdown-item" href="?p=admin_outgoing"><i class="bi bi-send-check me-2"></i>All Outgoing</a></li>
            <li><a class="dropdown-item" href="?p=origin_settings"><i class="bi bi-globe me-2"></i>Origin Settings</a></li>
            <li><a class="dropdown-item" href="?p=api_test"><i class="bi bi-heartbeat me-2"></i>API Test</a></li>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="?p=logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<main>
  <div class="container">
    <?php include __DIR__."/pages/$page.php"; ?>
  </div>
</main>

<footer class="text-center">
  <div class="container">
    <small>
      <i class="bi bi-database me-1"></i>
      LDN Inbox Implementation • Base URL: <?= htmlspecialchars($baseUrl) ?>
    </small>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

