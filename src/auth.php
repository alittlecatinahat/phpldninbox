<?php

function initSession(): void
{
  if (session_status() === PHP_SESSION_NONE)
  {
    session_start();
  }
}

function isLoggedIn(): bool
{
  initSession();

  return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

function isAdmin(): bool
{
  initSession();

  return isLoggedIn() && ($_SESSION['role'] ?? 'user') === 'admin';
}

function currentUserId(): ?int
{
  initSession();

  return $_SESSION['user_id'] ?? null;
}

function currentUser(): ?array
{
  if (!isLoggedIn())
  {
    return null;
  }

  return [
    'id' => $_SESSION['user_id'],
    'username' => $_SESSION['username'],
    'role' => $_SESSION['role'] ?? 'user',
  ];
}

function isOwner(int $ownerId): bool
{
  return isLoggedIn() && currentUserId() === $ownerId;
}

function canAccess(int $ownerId): bool
{
  return isAdmin() || isOwner($ownerId);
}

function requireAuth(string $redirectUrl = '?p=login'): void
{
  if (!isLoggedIn())
  {
    header("Location: $redirectUrl");
    exit;
  }
}

function requireAdmin(): void
{
  requireAuth();

  if (!isAdmin())
  {
    http_response_code(403);
    die('Access denied. Admin role required.');
  }
}

function attemptLogin(PDO $pdo, string $username, string $password): bool|string
{
  $stmt = $pdo->prepare("
    SELECT u_users.id,
           u_users.username,
           u_users.password_hash,
           u_users.role
    FROM u_users
    WHERE u_users.username = :username
  ");
  $stmt->execute([':username' => $username]);
  $user = $stmt->fetch();

  if (!$user)
  {
    return 'Invalid username or password';
  }

  if (!$user['password_hash'])
  {
    return 'Account has no password set. Please contact administrator.';
  }

  if (!password_verify($password, $user['password_hash']))
  {
    return 'Invalid username or password';
  }

  initSession();

  session_regenerate_id(true);

  $_SESSION['user_id'] = (int)$user['id'];
  $_SESSION['username'] = $user['username'];
  $_SESSION['role'] = $user['role'];

  $stmt = $pdo->prepare("
    UPDATE u_users
    SET u_users.last_login = NOW()
    WHERE u_users.id = :id
  ");
  $stmt->execute([':id' => $user['id']]);

  return true;
}

function logout(): void
{
  initSession();

  $_SESSION = [];

  if (ini_get("session.use_cookies"))
  {
    $params = session_get_cookie_params();

    setcookie(
      session_name(),
      '',
      time() - 42000,
      $params["path"],
      $params["domain"],
      $params["secure"],
      $params["httponly"]
    );
  }

  session_destroy();
}

function ownershipFilter(string $ownerColumn = 'owner_user_id'): string
{
  if (isAdmin())
  {
    return '';
  }

  $userId = currentUserId();

  return $userId ? " AND $ownerColumn = $userId" : " AND 1=0";
}

function hashPassword(string $password): string
{
  return password_hash($password, PASSWORD_DEFAULT);
}
