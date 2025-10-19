<?php
function initSession(): void
{
  # Check if session is not already active
  # PHP_SESSION_NONE means no session exists yet
  if (session_status() === PHP_SESSION_NONE)
  {
    # Start new session
    # This creates a session cookie and initializes $_SESSION array
    session_start();
  }
}

# ==============================================================================
# Login Status Check
# ==============================================================================
function isLoggedIn(): bool
{
  # Ensure session is started
  initSession();

  # User is logged in if both user_id and username are in session
  # Both must be present to be considered authenticated
  return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

# ==============================================================================
# Admin Role Check
# ==============================================================================
function isAdmin(): bool
{
  # Ensure session is started
  initSession();

  # User must be logged in AND have role='admin'
  # If role is not set in session, defaults to 'user'
  return isLoggedIn() && ($_SESSION['role'] ?? 'user') === 'admin';
}

# ==============================================================================
# Get Current User ID
# ==============================================================================
function currentUserId(): ?int
{
  # Ensure session is started
  initSession();

  # Return user_id from session, or null if not set
  return $_SESSION['user_id'] ?? null;
}

# ==============================================================================
# Get Current User Data
# ==============================================================================
function currentUser(): ?array
{
  # Check if user is logged in
  if (!isLoggedIn())
  {
    return null;
  }

  # Return user data from session
  # All values are guaranteed to exist because isLoggedIn() verified them
  return [
    'id' => $_SESSION['user_id'],
    'username' => $_SESSION['username'],
    'role' => $_SESSION['role'] ?? 'user',
  ];
}

# ==============================================================================
# Ownership Check
# ==============================================================================
function isOwner(int $ownerId): bool
{
  # User must be logged in AND their ID must match the owner ID
  return isLoggedIn() && currentUserId() === $ownerId;
}

# ==============================================================================
# Access Control Check
# ==============================================================================
function canAccess(int $ownerId): bool
{
  # Grant access to admins OR owners
  # Admins can access all resources regardless of ownership
  return isAdmin() || isOwner($ownerId);
}

# ==============================================================================
# Require Authentication
# ==============================================================================
function requireAuth(string $redirectUrl = '?p=login'): void
{
  # Check if user is logged in
  if (!isLoggedIn())
  {
    # Redirect to login page and stop execution
    header("Location: $redirectUrl");
    exit;
  }
}

# ==============================================================================
# Require Admin Role
# ==============================================================================
function requireAdmin(): void
{
  # First ensure user is logged in
  requireAuth();

  # Then check if user is admin
  if (!isAdmin())
  {
    # Return 403 Forbidden status
    http_response_code(403);
    die('Access denied. Admin role required.');
  }
}

# ==============================================================================
# User Login Attempt
# ==============================================================================
function attemptLogin(PDO $pdo, string $username, string $password): bool|string
{
  # Query database for user with matching username
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

  # Check if user exists
  if (!$user)
  {
    return 'Invalid username or password';
  }

  # Check if user has a password set
  if (!$user['password_hash'])
  {
    return 'Account has no password set. Please contact administrator.';
  }

  # Verify password using secure password_verify function
  if (!password_verify($password, $user['password_hash']))
  {
    # Use same generic error message to prevent username enumeration
    return 'Invalid username or password';
  }

  # ==============================================================================
  # Login Successful - Create Session
  # ==============================================================================
  # Initialize session
  initSession();

  # Regenerate session ID to prevent session fixation attacks
  # The 'true' parameter deletes the old session file
  session_regenerate_id(true);

  # Store user information in session
  $_SESSION['user_id'] = (int)$user['id'];
  $_SESSION['username'] = $user['username'];
  $_SESSION['role'] = $user['role'];

  # ==============================================================================
  # Update Last Login Timestamp
  # ==============================================================================
  # Record when this user last logged in for auditing purposes
  $stmt = $pdo->prepare("
    UPDATE u_users
    SET u_users.last_login = NOW()
    WHERE u_users.id = :id
  ");
  $stmt->execute([':id' => $user['id']]);

  # Return true to indicate successful login
  return true;
}

# ==============================================================================
# User Logout
# ==============================================================================
function logout(): void
{
  # Ensure session is started
  initSession();

  # Clear all session variables
  $_SESSION = [];

  # Remove the session cookie from the browser
  # This is important for complete logout
  if (ini_get("session.use_cookies"))
  {
    # Get current session cookie parameters
    $params = session_get_cookie_params();

    # Set cookie expiration to past time to delete it
    # time() - 42000 is safely in the past
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

  # Destroy the session file on the server
  # This completely removes all session data
  session_destroy();
}

# ==============================================================================
# Ownership Filter SQL Builder
# ==============================================================================
function ownershipFilter(string $ownerColumn = 'owner_user_id'): string
{
  # Admins see everything - no filter needed
  if (isAdmin())
  {
    return '';
  }

  # Get current user ID
  $userId = currentUserId();

  # If user is logged in, filter by their ID
  # If not logged in, return impossible condition " AND 1=0" to block all results
  return $userId ? " AND $ownerColumn = $userId" : " AND 1=0";
}

# ==============================================================================
# Password Hashing
# ==============================================================================
function hashPassword(string $password): string
{
  # Use password_hash with default algorithm (currently bcrypt)
  # This automatically handles salting and cost factor
  return password_hash($password, PASSWORD_DEFAULT);
}
