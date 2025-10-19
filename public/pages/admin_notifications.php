<?php
# ==============================================================================
# Admin Notifications Page
# ==============================================================================
# This page displays ALL notifications across all inboxes and users
# Accessible only to admin users
# Provides ability to view JSON content inline
#
# Usage: ?p=admin_notifications
# ==============================================================================

$pdo = db();

# ==============================================================================
# Check if user is admin
# ==============================================================================
if (!isAdmin())
{
  echo '<div class="alert alert-danger"><i class="bi bi-shield-exclamation me-2"></i>Access denied. Admin privileges required.</div>';
  return;
}

# ==============================================================================
# Fetch all notifications with inbox and user information
# ==============================================================================
# Pagination settings
$limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

# Filter parameters
$filterInboxId = isset($_GET['inbox_id']) && $_GET['inbox_id'] !== '' ? (int)$_GET['inbox_id'] : null;
$filterUserId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
$filterType = isset($_GET['type']) && $_GET['type'] !== '' ? $_GET['type'] : null;

# Build query with filters
$whereClauses = [];
$params = [];

if ($filterInboxId)
{
  $whereClauses[] = 'i_notifications.inbox_id = :inbox_id';
  $params[':inbox_id'] = $filterInboxId;
}

if ($filterUserId)
{
  $whereClauses[] = 'u_users.id = :user_id';
  $params[':user_id'] = $filterUserId;
}

if ($filterType)
{
  $whereClauses[] = 'i_notifications.as_type = :type';
  $params[':type'] = $filterType;
}

$whereSQL = $whereClauses ? 'WHERE '.implode(' AND ', $whereClauses) : '';

# Count total notifications
$stmt = $pdo->prepare("
  SELECT COUNT(*) AS total
  FROM i_notifications
  JOIN i_inboxes ON i_inboxes.id = i_notifications.inbox_id
  JOIN u_users ON u_users.id = i_inboxes.owner_user_id
  $whereSQL
");
$stmt->execute($params);
$totalCount = (int)$stmt->fetchColumn();

# Fetch notifications with pagination
$params[':limit'] = $limit;
$params[':offset'] = $offset;

$stmt = $pdo->prepare("
  SELECT i_notifications.id,
         i_notifications.inbox_id,
         i_notifications.notification_iri,
         i_notifications.sender_id,
         i_notifications.content_type,
         i_notifications.body_jsonld,
         i_notifications.as_type,
         i_notifications.as_object_iri,
         i_notifications.as_target_iri,
         i_notifications.status,
         i_notifications.received_at,
         i_notifications.corr_token,
         i_inboxes.inbox_iri,
         u_users.id AS user_id,
         u_users.username,
         s_senders.actor_iri
  FROM i_notifications
  JOIN i_inboxes ON i_inboxes.id = i_notifications.inbox_id
  JOIN u_users ON u_users.id = i_inboxes.owner_user_id
  LEFT JOIN s_senders ON s_senders.id = i_notifications.sender_id
  $whereSQL
  ORDER BY i_notifications.received_at DESC
  LIMIT :limit OFFSET :offset
");
$stmt->execute($params);
$notifications = $stmt->fetchAll();

# Fetch unique types for filter dropdown
$typesStmt = $pdo->query("
  SELECT DISTINCT i_notifications.as_type
  FROM i_notifications
  WHERE i_notifications.as_type IS NOT NULL
  ORDER BY i_notifications.as_type
");
$types = $typesStmt->fetchAll(PDO::FETCH_COLUMN);

# Fetch all inboxes for filter dropdown
$inboxesStmt = $pdo->query("
  SELECT i_inboxes.id, i_inboxes.inbox_iri, u_users.username
  FROM i_inboxes
  JOIN u_users ON u_users.id = i_inboxes.owner_user_id
  ORDER BY i_inboxes.id
");
$inboxes = $inboxesStmt->fetchAll();

# Fetch all users for filter dropdown
$usersStmt = $pdo->query("
  SELECT u_users.id, u_users.username
  FROM u_users
  ORDER BY u_users.username
");
$users = $usersStmt->fetchAll();

# Calculate pagination info
$hasNext = ($offset + $limit) < $totalCount;
$hasPrev = $offset > 0;
$currentPage = floor($offset / $limit) + 1;
$totalPages = ceil($totalCount / $limit);
?>

<div>
  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="?p=home">Home</a></li>
      <li class="breadcrumb-item active">Admin: All Notifications</li>
    </ol>
  </nav>

  <h1 class="mb-4">
    <i class="bi bi-shield-check text-danger me-2"></i>Admin: All Notifications
    <span class="badge bg-secondary"><?= number_format($totalCount) ?></span>
  </h1>

  <!-- Filters Card -->
  <div class="card mb-4">
    <div class="card-header bg-light">
      <strong><i class="bi bi-funnel me-1"></i>Filters</strong>
    </div>
    <div class="card-body">
      <form method="get" class="row g-3">
        <input type="hidden" name="p" value="admin_notifications">

        <div class="col-md-3">
          <label class="form-label">Inbox</label>
          <select name="inbox_id" class="form-select form-select-sm">
            <option value="">All Inboxes</option>
            <?php foreach ($inboxes as $inbox): ?>
              <option value="<?= (int)$inbox['id'] ?>" <?= $filterInboxId === (int)$inbox['id'] ? 'selected' : '' ?>>
                #<?= (int)$inbox['id'] ?> - <?= htmlspecialchars($inbox['username']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label">User</label>
          <select name="user_id" class="form-select form-select-sm">
            <option value="">All Users</option>
            <?php foreach ($users as $user): ?>
              <option value="<?= (int)$user['id'] ?>" <?= $filterUserId === (int)$user['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($user['username']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Type</label>
          <select name="type" class="form-select form-select-sm">
            <option value="">All Types</option>
            <?php foreach ($types as $type): ?>
              <option value="<?= htmlspecialchars($type) ?>" <?= $filterType === $type ? 'selected' : '' ?>>
                <?= htmlspecialchars($type) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-2">
          <label class="form-label">Per Page</label>
          <select name="limit" class="form-select form-select-sm">
            <option value="25" <?= $limit === 25 ? 'selected' : '' ?>>25</option>
            <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
            <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
            <option value="200" <?= $limit === 200 ? 'selected' : '' ?>>200</option>
            <option value="500" <?= $limit === 500 ? 'selected' : '' ?>>500</option>
          </select>
        </div>

        <div class="col-12">
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-search me-1"></i>Apply Filters
          </button>
          <a href="?p=admin_notifications" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-x-circle me-1"></i>Clear Filters
          </a>
        </div>
      </form>
    </div>
  </div>

  <!-- Notifications Table -->
  <?php if (!$notifications): ?>
    <div class="alert alert-info">
      <i class="bi bi-info-circle me-2"></i>No notifications found.
    </div>
  <?php else: ?>
    <div class="card">
      <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <span>
          <strong>Notifications</strong>
          <span class="text-muted ms-2">
            Showing <?= number_format($offset + 1) ?>-<?= number_format(min($offset + $limit, $totalCount)) ?> of <?= number_format($totalCount) ?>
          </span>
        </span>
        <?php if ($totalPages > 1): ?>
          <span class="text-muted">
            Page <?= $currentPage ?> of <?= $totalPages ?>
          </span>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
          <thead class="table-light">
            <tr>
              <th style="width: 60px;">ID</th>
              <th style="width: 80px;">Inbox</th>
              <th style="width: 120px;">User</th>
              <th style="width: 100px;">Type</th>
              <th>Actor</th>
              <th>Object</th>
              <th style="width: 140px;">Received</th>
              <th style="width: 140px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($notifications as $n): ?>
              <?php
                # Prepare JSON for display
                $jsonDecoded = json_decode($n['body_jsonld'], true);
                $jsonPretty = $jsonDecoded ? json_encode($jsonDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $n['body_jsonld'];
                $collapseId = 'json-'.(int)$n['id'];
              ?>
              <tr>
                <td>
                  <span class="badge bg-info">#<?= (int)$n['id'] ?></span>
                </td>
                <td>
                  <a href="?p=inbox&id=<?= (int)$n['inbox_id'] ?>" class="badge bg-primary text-decoration-none">
                    #<?= (int)$n['inbox_id'] ?>
                  </a>
                </td>
                <td>
                  <small><?= htmlspecialchars($n['username']) ?></small>
                </td>
                <td>
                  <span class="badge bg-secondary"><?= htmlspecialchars($n['as_type'] ?? '—') ?></span>
                </td>
                <td>
                  <small class="font-monospace text-muted text-truncate d-inline-block" style="max-width: 200px;" title="<?= htmlspecialchars($n['actor_iri'] ?? '') ?>">
                    <?= htmlspecialchars($n['actor_iri'] ?? '—') ?>
                  </small>
                </td>
                <td>
                  <small class="font-monospace text-muted text-truncate d-inline-block" style="max-width: 200px;" title="<?= htmlspecialchars($n['as_object_iri'] ?? '') ?>">
                    <?= htmlspecialchars($n['as_object_iri'] ?? '—') ?>
                  </small>
                </td>
                <td class="text-muted">
                  <small><?= htmlspecialchars($n['received_at']) ?></small>
                </td>
                <td>
                  <div class="btn-group btn-group-sm" role="group">
                    <a class="btn btn-outline-primary" href="?p=notification&id=<?= (int)$n['id'] ?>" title="View Details">
                      <i class="bi bi-eye"></i>
                    </a>
                    <button class="btn btn-outline-secondary" type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#<?= $collapseId ?>"
                            title="View JSON">
                      <i class="bi bi-code-square"></i>
                    </button>
                  </div>
                </td>
              </tr>
              <tr class="collapse" id="<?= $collapseId ?>">
                <td colspan="8" class="bg-light">
                  <div class="p-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                      <strong><i class="bi bi-file-earmark-code me-1"></i>JSON-LD Payload</strong>
                      <button class="btn btn-sm btn-outline-secondary" onclick="copyToClipboard<?= (int)$n['id'] ?>()">
                        <i class="bi bi-clipboard me-1"></i>Copy
                      </button>
                    </div>
                    <pre class="bg-white p-3 rounded border mb-2" style="max-height: 400px; overflow-y: auto;"><code id="json-content-<?= (int)$n['id'] ?>"><?= htmlspecialchars($jsonPretty) ?></code></pre>
                    <div class="row g-2 text-muted">
                      <div class="col-md-6">
                        <small><strong>Content-Type:</strong> <code><?= htmlspecialchars($n['content_type']) ?></code></small>
                      </div>
                      <div class="col-md-6">
                        <small><strong>Notification IRI:</strong> <code><?= htmlspecialchars($n['notification_iri'] ?? '—') ?></code></small>
                      </div>
                      <?php if ($n['corr_token']): ?>
                      <div class="col-md-12">
                        <small><strong>Correlation Token:</strong> <code><?= htmlspecialchars($n['corr_token']) ?></code></small>
                      </div>
                      <?php endif; ?>
                    </div>
                    <script>
                    function copyToClipboard<?= (int)$n['id'] ?>() {
                      const text = document.getElementById('json-content-<?= (int)$n['id'] ?>').textContent;
                      navigator.clipboard.writeText(text).then(() => {
                        alert('JSON copied to clipboard!');
                      }).catch(err => {
                        console.error('Failed to copy:', err);
                      });
                    }
                    </script>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
      <div class="card-footer bg-light">
        <nav aria-label="Pagination">
          <ul class="pagination pagination-sm mb-0 justify-content-center">
            <?php if ($hasPrev): ?>
              <li class="page-item">
                <a class="page-link" href="?p=admin_notifications&offset=<?= max(0, $offset - $limit) ?>&limit=<?= $limit ?><?= $filterInboxId ? '&inbox_id='.$filterInboxId : '' ?><?= $filterUserId ? '&user_id='.$filterUserId : '' ?><?= $filterType ? '&type='.urlencode($filterType) : '' ?>">
                  <i class="bi bi-chevron-left"></i> Previous
                </a>
              </li>
            <?php else: ?>
              <li class="page-item disabled">
                <span class="page-link"><i class="bi bi-chevron-left"></i> Previous</span>
              </li>
            <?php endif; ?>

            <li class="page-item disabled">
              <span class="page-link">
                Page <?= $currentPage ?> of <?= $totalPages ?>
              </span>
            </li>

            <?php if ($hasNext): ?>
              <li class="page-item">
                <a class="page-link" href="?p=admin_notifications&offset=<?= $offset + $limit ?>&limit=<?= $limit ?><?= $filterInboxId ? '&inbox_id='.$filterInboxId : '' ?><?= $filterUserId ? '&user_id='.$filterUserId : '' ?><?= $filterType ? '&type='.urlencode($filterType) : '' ?>">
                  Next <i class="bi bi-chevron-right"></i>
                </a>
              </li>
            <?php else: ?>
              <li class="page-item disabled">
                <span class="page-link">Next <i class="bi bi-chevron-right"></i></span>
              </li>
            <?php endif; ?>
          </ul>
        </nav>
      </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="mt-4">
    <a class="btn btn-outline-secondary" href="?p=home">
      <i class="bi bi-arrow-left me-1"></i>Back to Home
    </a>
  </div>
</div>
