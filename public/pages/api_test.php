<?php
# ==============================================================================
# API Test Page
# ==============================================================================
# This page tests all LDN API endpoints and provides diagnostic information
# Admin only access
# ==============================================================================

# Check if user is admin
if (!isAdmin()) {
    echo '<div class="alert alert-danger">Access denied. Admin only.</div>';
    return;
}

$pdo = db();
$cfg = require __DIR__.'/../../src/config.php';
$baseUrl = rtrim($cfg['base_url'], '/');

# For internal cURL requests from within Docker, use localhost:80
# The external base_url (localhost:8081) is for browser access only
$internalBaseUrl = 'http://localhost:80';

# Handle test notification submission
$testResult = null;
$testError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_post'])) {
    $testInboxId = (int)$_POST['test_inbox_id'];

    # Build test notification
    $testPayload = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $baseUrl . '/notifications/test_' . uniqid(),
        'type' => 'Announce',
        'actor' => [
            'id' => $baseUrl . '/system',
            'inbox' => $baseUrl . '/api/inbox_receive.php?inbox_id=' . $testInboxId,
            'name' => 'API Test System',
            'type' => 'Application'
        ],
        'origin' => [
            'id' => $baseUrl,
            'name' => 'LDN Inbox Test',
            'type' => 'Application'
        ],
        'object' => [
            'id' => $baseUrl . '/test/status',
            'type' => 'Note',
            'content' => 'API test notification sent at ' . date('Y-m-d H:i:s')
        ],
        'summary' => 'API Test Notification',
        'published' => gmdate('Y-m-d\TH:i:s\Z')
    ];

    $testBody = json_encode($testPayload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    # Send to inbox (use internal URL for cURL)
    $testUrl = $internalBaseUrl . '/api/inbox_receive.php?inbox_id=' . $testInboxId;

    $ch = curl_init($testUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/ld+json'],
        CURLOPT_POSTFIELDS => $testBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        $testResult = [
            'success' => true,
            'http_code' => $httpCode,
            'response' => $response,
            'payload' => $testPayload
        ];
    } else {
        $testError = "HTTP $httpCode: " . substr($response, 0, 500);
    }
}

# Get first available inbox for testing
$stmt = $pdo->query("SELECT i.id, i.inbox_iri, u.username FROM i_inboxes i JOIN u_users u ON i.owner_user_id = u.id ORDER BY i.id LIMIT 1");
$testInbox = $stmt->fetch();

# Test GET endpoints
$apiTests = [];

# Test 1: List inbox notifications
if ($testInbox) {
    $internalUrl = $internalBaseUrl . '/api/inbox_list.php?inbox_id=' . $testInbox['id'] . '&limit=5';
    $externalUrl = $baseUrl . '/api/inbox_list.php?inbox_id=' . $testInbox['id'] . '&limit=5';

    $ch = curl_init($internalUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $apiTests[] = [
        'name' => 'List Inbox Notifications',
        'endpoint' => '/api/inbox_list.php',
        'method' => 'GET',
        'url' => $externalUrl,
        'status' => $httpCode,
        'success' => ($httpCode >= 200 && $httpCode < 300),
        'curl' => "curl '$externalUrl'",
        'response' => substr($response, 0, 200)
    ];
}

# Test 2: Get single notification
$stmt = $pdo->query("SELECT id FROM i_notifications WHERE status='accepted' ORDER BY id DESC LIMIT 1");
$testNotif = $stmt->fetch();

if ($testNotif) {
    $internalUrl = $internalBaseUrl . '/api/notification_get.php?id=' . $testNotif['id'];
    $externalUrl = $baseUrl . '/api/notification_get.php?id=' . $testNotif['id'];

    $ch = curl_init($internalUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $apiTests[] = [
        'name' => 'Get Notification',
        'endpoint' => '/api/notification_get.php',
        'method' => 'GET',
        'url' => $externalUrl,
        'status' => $httpCode,
        'success' => ($httpCode >= 200 && $httpCode < 300),
        'curl' => "curl '$externalUrl'",
        'response' => substr($response, 0, 200)
    ];
}

# Test 3: List outgoing notifications
$internalUrl = $internalBaseUrl . '/api/outgoing_list.php?limit=5';
$externalUrl = $baseUrl . '/api/outgoing_list.php?limit=5';

$ch = curl_init($internalUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$apiTests[] = [
    'name' => 'List Outgoing Notifications',
    'endpoint' => '/api/outgoing_list.php',
    'method' => 'GET',
    'url' => $externalUrl,
    'status' => $httpCode,
    'success' => ($httpCode >= 200 && $httpCode < 300),
    'curl' => "curl '$externalUrl'",
    'response' => substr($response, 0, 200)
];

# Get stats
$stats = [];
$stats['total_inboxes'] = $pdo->query("SELECT COUNT(*) FROM i_inboxes")->fetchColumn();
$stats['total_notifications'] = $pdo->query("SELECT COUNT(*) FROM i_notifications WHERE status='accepted'")->fetchColumn();
$stats['total_outgoing'] = $pdo->query("SELECT COUNT(*) FROM o_outgoing_notifications")->fetchColumn();
$stats['total_users'] = $pdo->query("SELECT COUNT(*) FROM u_users")->fetchColumn();
?>

<style>
.api-status-success { color: #28a745; }
.api-status-error { color: #dc3545; }
.code-block {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 12px;
    font-family: 'Courier New', monospace;
    font-size: 13px;
    overflow-x: auto;
}
.endpoint-card {
    border-left: 4px solid #0d6efd;
}
.endpoint-card.success {
    border-left-color: #28a745;
}
.endpoint-card.error {
    border-left-color: #dc3545;
}
</style>

<div class="container-fluid">
    <h1 class="mb-4">
        <i class="bi bi-heartbeat text-primary me-2"></i>API Test & Diagnostics
    </h1>

    <!-- Test Result Alert -->
    <?php if ($testResult): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <h5><i class="bi bi-check-circle me-2"></i>Test POST Successful!</h5>
            <p><strong>HTTP Status:</strong> <?= $testResult['http_code'] ?></p>
            <p><strong>Response Headers:</strong></p>
            <pre class="code-block"><?= htmlspecialchars(substr($testResult['response'], 0, 400)) ?></pre>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($testError): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <h5><i class="bi bi-exclamation-triangle me-2"></i>Test POST Failed</h5>
            <p><?= htmlspecialchars($testError) ?></p>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- System Stats -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <h3 class="text-primary"><?= $stats['total_inboxes'] ?></h3>
                    <p class="text-muted mb-0">Inboxes</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <h3 class="text-success"><?= $stats['total_notifications'] ?></h3>
                    <p class="text-muted mb-0">Incoming Notifications</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <h3 class="text-info"><?= $stats['total_outgoing'] ?></h3>
                    <p class="text-muted mb-0">Outgoing Notifications</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <h3 class="text-warning"><?= $stats['total_users'] ?></h3>
                    <p class="text-muted mb-0">Users</p>
                </div>
            </div>
        </div>
    </div>

    <!-- GET Endpoint Tests -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-cloud-download me-2"></i>GET Endpoint Tests</h5>
        </div>
        <div class="card-body">
            <?php foreach ($apiTests as $test): ?>
                <div class="card endpoint-card <?= $test['success'] ? 'success' : 'error' ?> mb-3">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h6>
                                    <?php if ($test['success']): ?>
                                        <i class="bi bi-check-circle api-status-success me-2"></i>
                                    <?php else: ?>
                                        <i class="bi bi-x-circle api-status-error me-2"></i>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($test['name']) ?>
                                </h6>
                                <p class="mb-2">
                                    <span class="badge bg-secondary"><?= $test['method'] ?></span>
                                    <code class="ms-2"><?= htmlspecialchars($test['endpoint']) ?></code>
                                </p>
                                <p class="mb-2"><strong>Full URL:</strong> <small class="text-muted"><?= htmlspecialchars($test['url']) ?></small></p>
                            </div>
                            <div class="col-md-4 text-end">
                                <span class="badge bg-<?= $test['success'] ? 'success' : 'danger' ?> fs-6">
                                    HTTP <?= $test['status'] ?>
                                </span>
                            </div>
                        </div>
                        <div class="mt-3">
                            <strong>cURL Command:</strong>
                            <pre class="code-block mb-2"><?= htmlspecialchars($test['curl']) ?></pre>
                            <strong>Response Preview:</strong>
                            <pre class="code-block"><?= htmlspecialchars($test['response']) ?>...</pre>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- POST Test -->
    <div class="card mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0"><i class="bi bi-cloud-upload me-2"></i>POST Endpoint Test</h5>
        </div>
        <div class="card-body">
            <?php if ($testInbox): ?>
                <p class="mb-3">
                    Send a test notification to <strong><?= htmlspecialchars($testInbox['username']) ?>'s inbox (#<?= $testInbox['id'] ?>)</strong>
                </p>

                <form method="post" class="mb-3">
                    <input type="hidden" name="test_inbox_id" value="<?= $testInbox['id'] ?>">
                    <button type="submit" name="test_post" class="btn btn-success">
                        <i class="bi bi-send-fill me-2"></i>Send Test POST Notification
                    </button>
                </form>

                <div class="card bg-light">
                    <div class="card-body">
                        <strong>Test will POST to:</strong>
                        <pre class="code-block mt-2"><?= htmlspecialchars($baseUrl) ?>/api/inbox_receive.php?inbox_id=<?= $testInbox['id'] ?></pre>

                        <strong class="mt-3 d-block">cURL equivalent:</strong>
                        <pre class="code-block">curl -i -X POST '<?= htmlspecialchars($baseUrl) ?>/api/inbox_receive.php?inbox_id=<?= $testInbox['id'] ?>' \
  -H 'Content-Type: application/ld+json' \
  --data '{
    "@context": "https://www.w3.org/ns/activitystreams",
    "id": "<?= htmlspecialchars($baseUrl) ?>/notifications/test_123",
    "type": "Announce",
    "actor": {
      "id": "<?= htmlspecialchars($baseUrl) ?>/system",
      "name": "API Test System",
      "type": "Application"
    },
    "object": {
      "id": "<?= htmlspecialchars($baseUrl) ?>/test/status",
      "type": "Note",
      "content": "API test notification"
    },
    "summary": "API Test Notification"
  }'</pre>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    No inboxes available for testing. <a href="?p=new_inbox" class="alert-link">Create an inbox first</a>.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- API Documentation -->
    <div class="card">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="bi bi-book me-2"></i>API Endpoints Reference</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Endpoint</th>
                            <th>Method</th>
                            <th>Description</th>
                            <th>Parameters</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>/api/inbox_receive.php</code></td>
                            <td><span class="badge bg-success">POST</span></td>
                            <td>Receive incoming notification</td>
                            <td><code>inbox_id</code> (required)</td>
                        </tr>
                        <tr>
                            <td><code>/api/inbox_list.php</code></td>
                            <td><span class="badge bg-primary">GET</span></td>
                            <td>List inbox notifications</td>
                            <td><code>inbox_id</code>, <code>limit</code></td>
                        </tr>
                        <tr>
                            <td><code>/api/notification_get.php</code></td>
                            <td><span class="badge bg-primary">GET</span></td>
                            <td>Get single notification</td>
                            <td><code>id</code> (required)</td>
                        </tr>
                        <tr>
                            <td><code>/api/outgoing_list.php</code></td>
                            <td><span class="badge bg-primary">GET</span></td>
                            <td>List outgoing notifications</td>
                            <td><code>limit</code>, <code>status</code></td>
                        </tr>
                        <tr>
                            <td><code>/api/send_outgoing.php</code></td>
                            <td><span class="badge bg-success">POST</span></td>
                            <td>Send notification to remote inbox</td>
                            <td>JSON body with <code>to_inbox_iri</code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
