<?php
function client_ip_bin(): ?string
{
  # Get the remote IP address from PHP server variables
  # This is set by the web server (Apache, Nginx, etc.)
  $ip = $_SERVER['REMOTE_ADDR'] ?? null;

  # If no IP address is available, return null
  if (!$ip)
  {
    return null;
  }

  # Convert IP address string to binary packed format
  # inet_pton handles both IPv4 and IPv6
  # The @ suppresses warnings for invalid IP formats
  $packed = @inet_pton($ip);

  # Return the binary representation, or null if conversion failed
  return $packed === false ? null : $packed;
}

function mint_notification_iri(int $notificationId): string
{
  # Load configuration to get the base URL
  $cfg = require __DIR__.'/config.php';

  # Build the notification IRI by combining base URL with endpoint and ID
  # The rtrim ensures we don't end up with double slashes
  return rtrim($cfg['base_url'], '/').'/notification.php?id='.$notificationId;
}

function extract_as_fields(array $json): array
{
  # Initialize all fields to null (safe defaults)
  $type = null;
  $obj = null;
  $target = null;
  $actor = null;
  $corrToken = null;

  # ==============================================================================
  # Extract ActivityStreams 'type' field
  # ==============================================================================
  if (isset($json['type']))
  {
    $type = is_array($json['type']) ? ($json['type'][0] ?? null) : $json['type'];
  }

  # ==============================================================================
  # Extract ActivityStreams 'actor' field
  # ==============================================================================
  if (isset($json['actor']))
  {
    # If actor is an object with 'id', extract the ID
    # Otherwise, if it's a string, use it directly
    $actor = is_array($json['actor']) && isset($json['actor']['id'])
      ? $json['actor']['id']
      : (is_string($json['actor']) ? $json['actor'] : null);
  }
  elseif (isset($json['attributedTo']))
  {
    # Fallback to 'attributedTo' if 'actor' is not present
    $actor = is_string($json['attributedTo']) ? $json['attributedTo'] : null;
  }

  # ==============================================================================
  # Extract ActivityStreams 'object' field
  # ==============================================================================
  if (isset($json['object']))
  {
    $obj = is_array($json['object']) && isset($json['object']['id'])
      ? $json['object']['id']
      : (is_string($json['object']) ? $json['object'] : null);
  }

  # ==============================================================================
  # Extract ActivityStreams 'target' field
  # ==============================================================================
  if (isset($json['target']))
  {
    $target = is_array($json['target']) && isset($json['target']['id'])
      ? $json['target']['id']
      : (is_string($json['target']) ? $json['target'] : null);
  }

  # ==============================================================================
  # Extract correlation token for tracking related messages
  # ==============================================================================
  if (isset($json['correlationId']) && is_string($json['correlationId']))
  {
    $corrToken = $json['correlationId'];
  }
  elseif (isset($json['inReplyTo']) && is_string($json['inReplyTo']))
  {
    # 'inReplyTo' is commonly used for linking replies to original messages
    $corrToken = $json['inReplyTo'];
  }

  # Return all extracted fields as an array
  # Order: [type, object, target, actor, correlationToken]
  return [$type, $obj, $target, $actor, $corrToken];
}

# ==============================================================================
# Access Control List (ACL) Validator
# ==============================================================================
function acl_allows(PDO $pdo, int $inboxId, ?string $actorIri, ?string $authToken): bool
{
  # Fetch all ACL rules for this inbox from the database
  $stmt = $pdo->prepare("
    SELECT i_inbox_acls.rule_type,
           i_inbox_acls.match_kind,
           i_inbox_acls.match_value
    FROM i_inbox_acls
    WHERE i_inbox_acls.inbox_id = ?
  ");
  $stmt->execute([$inboxId]);
  $rules = $stmt->fetchAll();

  # If NO ACL rules exist, default to ALLOW (public inbox)
  # If ANY rules exist, default to DENY unless an ALLOW rule matches
  if (empty($rules))
  {
    return true;
  }

  # Check if there are any ALLOW rules
  # If only DENY rules exist, default to ALLOW (blacklist mode)
  # If ALLOW rules exist, default to DENY (whitelist mode)
  $hasAllowRules = false;
  foreach ($rules as $r)
  {
    if ($r['rule_type'] === 'allow')
    {
      $hasAllowRules = true;
      break;
    }
  }

  # Default behavior depends on rule types:
  # - Only DENY rules: default ALLOW (blacklist mode)
  # - Any ALLOW rules: default DENY (whitelist mode)
  $allowed = !$hasAllowRules;

  # Process each ACL rule
  foreach ($rules as $r)
  {
    # Determine if this rule matches the current request
    $match = false;

    # Check different match kinds
    switch ($r['match_kind'])
    {
      # ==============================================================================
      # Auth Token Match
      # ==============================================================================
      case 'auth_token':
        $match = $authToken && hash_equals($r['match_value'], $authToken);
        break;

      # ==============================================================================
      # Actor IRI Prefix Match
      # ==============================================================================
      case 'actor_iri_prefix':
        $match = $actorIri && str_starts_with($actorIri, $r['match_value']);
        break;

      # ==============================================================================
      # Exact Actor Match
      # ==============================================================================
      case 'exact_actor':
        $match = $actorIri && $actorIri === $r['match_value'];
        break;

      # ==============================================================================
      # Domain Suffix Match
      # ==============================================================================
      case 'domain_suffix':
        if ($actorIri && ($host = parse_url($actorIri, PHP_URL_HOST)))
        {
          # Remove leading dot from suffix for consistency
          $suffix = ltrim($r['match_value'], '.');

          # Match if hostname equals suffix or ends with .suffix
          $match = $host === $suffix || str_ends_with($host, '.'.$suffix);
        }
        break;

      # ==============================================================================
      # Mutual TLS Distinguished Name Match
      # ==============================================================================
      case 'mtls_dn':
        # TODO: Implement mTLS certificate validation
        # Extract DN from TLS session if present
        $match = false;
        break;
    }

    # Apply rule if it matched
    if ($match)
    {
      # DENY rules take precedence - immediately reject
      if ($r['rule_type'] === 'deny')
      {
        return false;
      }

      # ALLOW rules grant access
      if ($r['rule_type'] === 'allow')
      {
        $allowed = true;
      }
    }
  }

  # Return final decision
  return $allowed;
}

