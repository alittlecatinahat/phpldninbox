<?php
# ==============================================================================
# Admin Outgoing Notifications Page
# ==============================================================================
# This page displays ALL outgoing notifications across all users
# Accessible only to admin users
# Provides ability to view JSON content inline
#
# Usage: ?p=admin_outgoing
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
# Fetch all outgoing notifications with user information
# ==============================================================================
# Pagination settings
$limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

# Filter parameters
$filterUserId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
$filterType = isset($_GET['type']) && $_GET['type'] !== '' ? $_GET['type'] : null;
$filterStatus = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null;

# Build query with filters
$whereClauses = [];
$params = [];

if ($filterUserId)
{
  $whereClauses[] = 'o_outgoing_notifications.from_user_id = :user_id';
  $params[':user_id'] = $filterUserId;
}

if ($filterType)
{
  $whereClauses[] = 'o_outgoing_notifications.as_type = :type';
  $params[':type'] = $filterType;
}

if ($filterStatus)
{
  $whereClauses[] = 'o_outgoing_notifications.delivery_status = :status';
  $params[':status'] = $filterStatus;
}

$whereSQL = $whereClauses ? 'WHERE '.implode(' AND ', $whereClauses) : '';

# Count total outgoing notifications
$stmt = $pdo->prepare("
  SELECT COUNT(*) AS total
  FROM o_outgoing_notifications
  LEFT JOIN u_users ON u_users.id = o_outgoing_notifications.from_user_id
  $whereSQL
");
$stmt->execute($params);
$totalCount = (int)$stmt->fetchColumn();

# Fetch outgoing notifications with pagination
$params[':limit'] = $limit;
$params[':offset'] = $offset;

$stmt = $pdo->prepare("
  SELECT o_outgoing_notifications.id,
         o_outgoing_notifications.from_user_id,
         o_outgoing_notifications.to_inbox_iri,
         o_outgoing_notifications.body_jsonld,
         o_outgoing_notifications.content_type,
         o_outgoing_notifications.as_type,
         o_outgoing_notifications.corr_token,
         o_outgoing_notifications.reply_to_notification_id,
         o_outgoing_notifications.delivery_status,
         o_outgoing_notifications.last_error,
         o_outgoing_notifications.created_at,
         o_outgoing_notifications.updated_at,
         u_users.username,
         (SELECT COUNT(*)
          FROM o_delivery_attempts
          WHERE o_delivery_attempts.outgoing_notification_id = o_outgoing_notifications.id) AS attempt_count
  FROM o_outgoing_notifications
  LEFT JOIN u_users ON u_users.id = o_outgoing_notifications.from_user_id
  $whereSQL
  ORDER BY o_outgoing_notifications.created_at DESC
  LIMIT :limit OFFSET :offset
");
$stmt->execute($params);
$notifications = $stmt->fetchAll();

# Fetch unique types for filter dropdown
$typesStmt = $pdo->query("
  SELECT DISTINCT o_outgoing_notifications.as_type
  FROM o_outgoing_notifications
  WHERE o_outgoing_notifications.as_type IS NOT NULL
  ORDER BY o_outgoing_notifications.as_type
");
$types = $typesStmt->fetchAll(PDO::FETCH_COLUMN);

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
      <li class="breadcrumb-item active">Admin: All Outgoing Notifications</li>
    </ol>
  </nav>

  <h1 class="mb-4">
    <i class="bi bi-shield-check text-danger me-2"></i>Admin: All Outgoing Notifications
    <span class="badge bg-secondary"><?= number_format($totalCount) ?></span>
  </h1>

  <!-- Filters Card -->
  <div class="card mb-4">
    <div class="card-header bg-light">
      <strong><i class="bi bi-funnel me-1"></i>Filters</strong>
    </div>
    <div class="card-body">
      <form method="get" class="row g-3">
        <input type="hidden" name="p" value="admin_outgoing">

        <div class="col-md-4">
          <label class="form-label">From User</label>
          <select name="user_id" class="form-select form-select-sm">
            <option value="">All Users</option>
            <?php foreach ($users as $user): ?>
              <option value="<?= (int)$user['id'] ?>" <?= $filterUserId === (int)$user['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($user['username']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3">
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

        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select form-select-sm">
            <option value="">All Statuses</option>
            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="delivered" <?= $filterStatus === 'delivered' ? 'selected' : '' ?>>Delivered</option>
            <option value="failed" <?= $filterStatus === 'failed' ? 'selected' : '' ?>>Failed</option>
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
          <a href="?p=admin_outgoing" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-x-circle me-1"></i>Clear Filters
          </a>
        </div>
      </form>
    </div>
  </div>

  <!-- Notifications Table -->
  <?php if (!$notifications): ?>
    <div class="alert alert-info">
      <i class="bi bi-info-circle me-2"></i>No outgoing notifications found.
    </div>
  <?php else: ?>
    <div class="card">
      <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <span>
          <strong>Outgoing Notifications</strong>
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
              <th style="width: 120px;">From User</th>
              <th style="width: 100px;">Type</th>
              <th style="width: 100px;">Status</th>
              <th>To Inbox</th>
              <th style="width: 80px;">Attempts</th>
              <th style="width: 140px;">Created</th>
              <th style="width: 140px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($notifications as $n): ?>
              <?php
                # Prepare JSON for display
                $jsonDecoded = json_decode($n['body_jsonld'], true);
                $jsonPretty = $jsonDecoded ? json_encode($jsonDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $n['body_jsonld'];
                $collapseId = 'json-out-'.(int)$n['id'];
              ?>
              <tr>
                <td>
                  <span class="badge bg-info">#<?= (int)$n['id'] ?></span>
                </td>
                <td>
                  <small><?= $n['username'] ? htmlspecialchars($n['username']) : '<em class="text-muted">—</em>' ?></small>
                </td>
                <td>
                  <span class="badge bg-secondary"><?= htmlspecialchars($n['as_type'] ?? '—') ?></span>
                </td>
                <td>
                  <?php if ($n['delivery_status'] === 'delivered'): ?>
                    <span class="badge bg-success">delivered</span>
                  <?php elseif ($n['delivery_status'] === 'failed'): ?>
                    <span class="badge bg-danger">failed</span>
                  <?php else: ?>
                    <span class="badge bg-warning text-dark">pending</span>
                  <?php endif; ?>
                </td>
                <td>
                  <small class="font-monospace text-muted text-truncate d-inline-block" style="max-width: 300px;" title="<?= htmlspecialchars($n['to_inbox_iri']) ?>">
                    <?= htmlspecialchars($n['to_inbox_iri']) ?>
                  </small>
                </td>
                <td class="text-center">
                  <?php if ($n['attempt_count'] > 0): ?>
                    <span class="badge bg-secondary"><?= (int)$n['attempt_count'] ?></span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-muted">
                  <small><?= htmlspecialchars($n['created_at']) ?></small>
                </td>
                <td>
                  <div class="btn-group btn-group-sm" role="group">
                    <?php if ($n['reply_to_notification_id']): ?>
                      <a class="btn btn-outline-info" href="?p=notification&id=<?= (int)$n['reply_to_notification_id'] ?>" title="View Original Notification">
                        <i class="bi bi-reply"></i>
                      </a>
                    <?php endif; ?>
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
                      <button class="btn btn-sm btn-outline-secondary" onclick="copyToClipboardOut<?= (int)$n['id'] ?>()">
                        <i class="bi bi-clipboard me-1"></i>Copy
                      </button>
                    </div>
                    <pre class="bg-white p-3 rounded border mb-2" style="max-height: 400px; overflow-y: auto;"><code id="json-out-content-<?= (int)$n['id'] ?>"><?= htmlspecialchars($jsonPretty) ?></code></pre>
                    <div class="row g-2 text-muted">
                      <div class="col-md-4">
                        <small><strong>Content-Type:</strong> <code><?= htmlspecialchars($n['content_type']) ?></code></small>
                      </div>
                      <div class="col-md-4">
                        <small><strong>Updated:</strong> <?= htmlspecialchars($n['updated_at']) ?></small>
                      </div>
                      <?php if ($n['corr_token']): ?>
                      <div class="col-md-4">
                        <small><strong>Correlation:</strong> <code><?= htmlspecialchars($n['corr_token']) ?></code></small>
                      </div>
                      <?php endif; ?>
                      <?php if ($n['last_error']): ?>
                      <div class="col-md-12">
                        <small><strong class="text-danger">Last Error:</strong> <code class="text-danger"><?= htmlspecialchars($n['last_error']) ?></code></small>
                      </div>
                      <?php endif; ?>
                    </div>
                    <script>
                    function copyToClipboardOut<?= (int)$n['id'] ?>() {
                      const text = document.getElementById('json-out-content-<?= (int)$n['id'] ?>').textContent;
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
                <a class="page-link" href="?p=admin_outgoing&offset=<?= max(0, $offset - $limit) ?>&limit=<?= $limit ?><?= $filterUserId ? '&user_id='.$filterUserId : '' ?><?= $filterType ? '&type='.urlencode($filterType) : '' ?><?= $filterStatus ? '&status='.urlencode($filterStatus) : '' ?>">
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
                <a class="page-link" href="?p=admin_outgoing&offset=<?= $offset + $limit ?>&limit=<?= $limit ?><?= $filterUserId ? '&user_id='.$filterUserId : '' ?><?= $filterType ? '&type='.urlencode($filterType) : '' ?><?= $filterStatus ? '&status='.urlencode($filterStatus) : '' ?>">
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
