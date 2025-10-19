<?php
# ==============================================================================
# ACL Management Page
# ==============================================================================
# Manage Access Control Lists for inboxes
# Admins see all inboxes, regular users see only their own
# ==============================================================================

$pdo = db();
$msg = null;
$err = null;

# Handle ACL rule addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_rule') {
    try {
        $inboxId = (int)($_POST['inbox_id'] ?? 0);
        $ruleType = $_POST['rule_type'] ?? 'allow';
        $matchKind = $_POST['match_kind'] ?? '';
        $matchValue = trim($_POST['match_value'] ?? '');

        if ($inboxId <= 0 || $matchKind === '' || $matchValue === '') {
            throw new RuntimeException('All fields are required');
        }

        # Check ownership (users can only manage their own inboxes)
        if (!isAdmin()) {
            $stmt = $pdo->prepare("SELECT owner_user_id FROM i_inboxes WHERE id = :id");
            $stmt->execute([':id' => $inboxId]);
            $inbox = $stmt->fetch();
            if (!$inbox || $inbox['owner_user_id'] != currentUserId()) {
                throw new RuntimeException('Access denied');
            }
        }

        # Insert ACL rule
        $stmt = $pdo->prepare("INSERT INTO i_inbox_acls (inbox_id, rule_type, match_kind, match_value)
                               VALUES (:inbox_id, :rule_type, :match_kind, :match_value)");
        $stmt->execute([
            ':inbox_id' => $inboxId,
            ':rule_type' => $ruleType,
            ':match_kind' => $matchKind,
            ':match_value' => $matchValue
        ]);

        $msg = "ACL rule added successfully for inbox #$inboxId";
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

# Handle ACL rule deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_rule') {
    try {
        $ruleId = (int)($_POST['rule_id'] ?? 0);

        if ($ruleId <= 0) {
            throw new RuntimeException('Invalid rule ID');
        }

        # Check ownership (users can only delete rules for their own inboxes)
        if (!isAdmin()) {
            $stmt = $pdo->prepare("
                SELECT i_inboxes.owner_user_id
                FROM i_inbox_acls
                JOIN i_inboxes ON i_inbox_acls.inbox_id = i_inboxes.id
                WHERE i_inbox_acls.id = :id
            ");
            $stmt->execute([':id' => $ruleId]);
            $inbox = $stmt->fetch();
            if (!$inbox || $inbox['owner_user_id'] != currentUserId()) {
                throw new RuntimeException('Access denied');
            }
        }

        # Delete ACL rule
        $stmt = $pdo->prepare("DELETE FROM i_inbox_acls WHERE id = :id");
        $stmt->execute([':id' => $ruleId]);

        $msg = "ACL rule deleted successfully";
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

# Fetch inboxes with their ACL rules (admins see all, users see only their own)
if (isAdmin()) {
    $inboxes = $pdo->query("
        SELECT i_inboxes.id,
               i_inboxes.inbox_iri,
               u_users.username as owner_username,
               i_inboxes.owner_user_id,
               (SELECT COUNT(*) FROM i_inbox_acls WHERE inbox_id = i_inboxes.id) as acl_count
        FROM i_inboxes
        JOIN u_users ON i_inboxes.owner_user_id = u_users.id
        ORDER BY i_inboxes.id ASC
    ")->fetchAll();
} else {
    $userId = currentUserId();
    $stmt = $pdo->prepare("
        SELECT i_inboxes.id,
               i_inboxes.inbox_iri,
               u_users.username as owner_username,
               i_inboxes.owner_user_id,
               (SELECT COUNT(*) FROM i_inbox_acls WHERE inbox_id = i_inboxes.id) as acl_count
        FROM i_inboxes
        JOIN u_users ON i_inboxes.owner_user_id = u_users.id
        WHERE i_inboxes.owner_user_id = :user_id
        ORDER BY i_inboxes.id ASC
    ");
    $stmt->execute([':user_id' => $userId]);
    $inboxes = $stmt->fetchAll();
}

# ACL match kinds
$matchKinds = [
    'auth_token' => 'Auth Token (X-Auth-Token header)',
    'exact_actor' => 'Exact Actor IRI',
    'actor_iri_prefix' => 'Actor IRI Prefix',
    'domain_suffix' => 'Actor Domain Suffix',
    'mtls_dn' => 'mTLS Distinguished Name (stub)'
];
?>

<style>
.acl-rule-card {
    border-left: 3px solid #0d6efd;
}
.acl-rule-card.allow {
    border-left-color: #28a745;
}
.acl-rule-card.deny {
    border-left-color: #dc3545;
}
</style>

<div class="row">
    <div class="col-lg-10">
        <h1 class="mb-4">
            <i class="bi bi-shield-lock text-primary me-2"></i>ACL Management
        </h1>

        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            <strong>How ACLs Work:</strong> All inboxes are <strong>public by default</strong> (anyone can POST).
            Add <strong>ALLOW rules</strong> to create a whitelist (only specified senders can POST).
            Add <strong>DENY rules</strong> to block specific senders (deny takes precedence over allow).
        </div>

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

        <?php if (empty($inboxes)): ?>
            <div class="alert alert-warning">
                <i class="bi bi-inbox me-2"></i>No inboxes found. <a href="?p=new_inbox" class="alert-link">Create an inbox first</a>.
            </div>
        <?php else: ?>
            <?php foreach ($inboxes as $inbox): ?>
                <?php
                # Fetch ACL rules for this inbox
                $stmt = $pdo->prepare("SELECT * FROM i_inbox_acls WHERE inbox_id = :inbox_id ORDER BY id ASC");
                $stmt->execute([':inbox_id' => $inbox['id']]);
                $rules = $stmt->fetchAll();
                ?>

                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <div class="row align-items-center">
                            <div class="col">
                                <h5 class="mb-0">
                                    <i class="bi bi-inbox me-2"></i>Inbox #<?= (int)$inbox['id'] ?>
                                    <span class="text-muted small">- <?= htmlspecialchars($inbox['owner_username']) ?></span>
                                </h5>
                                <small class="text-muted font-monospace"><?= htmlspecialchars($inbox['inbox_iri']) ?></small>
                            </div>
                            <div class="col-auto">
                                <span class="badge bg-<?= $inbox['acl_count'] > 0 ? 'success' : 'warning' ?>">
                                    <?= (int)$inbox['acl_count'] ?> ACL <?= $inbox['acl_count'] == 1 ? 'Rule' : 'Rules' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Existing ACL Rules -->
                        <?php if (empty($rules)): ?>
                            <div class="alert alert-warning mb-3">
                                <i class="bi bi-unlock me-2"></i>
                                <strong>No ACL rules.</strong> This inbox is <strong>fully public</strong> - anyone can POST notifications.
                            </div>
                        <?php else: ?>
                            <h6 class="mb-3">Current ACL Rules:</h6>
                            <div class="row g-2 mb-3">
                                <?php foreach ($rules as $rule): ?>
                                    <div class="col-md-6">
                                        <div class="card acl-rule-card <?= htmlspecialchars($rule['rule_type']) ?>">
                                            <div class="card-body p-3">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <span class="badge bg-<?= $rule['rule_type'] === 'allow' ? 'success' : 'danger' ?> mb-2">
                                                            <?= strtoupper($rule['rule_type']) ?>
                                                        </span>
                                                        <div><strong><?= htmlspecialchars($matchKinds[$rule['match_kind']] ?? $rule['match_kind']) ?></strong></div>
                                                        <div class="font-monospace small text-break"><?= htmlspecialchars($rule['match_value']) ?></div>
                                                    </div>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this ACL rule?');">
                                                        <input type="hidden" name="action" value="delete_rule">
                                                        <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Add New ACL Rule Form -->
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="mb-3"><i class="bi bi-plus-circle me-2"></i>Add ACL Rule</h6>
                                <form method="post">
                                    <input type="hidden" name="action" value="add_rule">
                                    <input type="hidden" name="inbox_id" value="<?= (int)$inbox['id'] ?>">
                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <label class="form-label small">Rule Type</label>
                                            <select name="rule_type" class="form-select form-select-sm" required>
                                                <option value="allow">ALLOW</option>
                                                <option value="deny">DENY</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small">Match Kind</label>
                                            <select name="match_kind" class="form-select form-select-sm" required>
                                                <?php foreach ($matchKinds as $value => $label): ?>
                                                    <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-5">
                                            <label class="form-label small">Match Value</label>
                                            <div class="input-group input-group-sm">
                                                <input type="text" name="match_value" class="form-control font-monospace"
                                                       placeholder="e.g., secret-token or *.example.org" required>
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="bi bi-plus-lg"></i> Add
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="card bg-light">
            <div class="card-body">
                <h5 class="mb-3"><i class="bi bi-book me-2"></i>ACL Rule Examples</h5>
                <div class="row">
                    <div class="col-md-6">
                        <h6>ALLOW Rules (Whitelist)</h6>
                        <ul class="small">
                            <li><strong>Auth Token:</strong> <code>my-secret-123</code> - Only requests with matching X-Auth-Token</li>
                            <li><strong>Exact Actor:</strong> <code>https://alice.edu/profile</code> - Only this specific actor</li>
                            <li><strong>Actor Prefix:</strong> <code>https://university.edu/users/</code> - All actors from this path</li>
                            <li><strong>Domain Suffix:</strong> <code>orcid.org</code> - All actors from *.orcid.org</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>DENY Rules (Blacklist)</h6>
                        <ul class="small">
                            <li><strong>Domain Suffix:</strong> <code>spam.example</code> - Block all *.spam.example</li>
                            <li><strong>Exact Actor:</strong> <code>https://bad.actor/profile</code> - Block this actor</li>
                            <li><strong>Note:</strong> DENY rules always take precedence over ALLOW rules</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
