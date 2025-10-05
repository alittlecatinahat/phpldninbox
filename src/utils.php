<?php

function client_ip_bin(): ?string
{
  $ip = $_SERVER['REMOTE_ADDR'] ?? null;

  if (!$ip)
  {
    return null;
  }

  $packed = @inet_pton($ip);

  return $packed === false ? null : $packed;
}

function mint_notification_iri(int $notificationId): string
{
  $cfg = require __DIR__.'/config.php';

  return rtrim($cfg['base_url'], '/').'/notification.php?id='.$notificationId;
}

function extract_as_fields(array $json): array
{
  $type = null;
  $obj = null;
  $target = null;
  $actor = null;
  $corrToken = null;

  if (isset($json['type']))
  {
    $type = is_array($json['type']) ? ($json['type'][0] ?? null) : $json['type'];
  }

  if (isset($json['actor']))
  {
    $actor = is_array($json['actor']) && isset($json['actor']['id'])
      ? $json['actor']['id']
      : (is_string($json['actor']) ? $json['actor'] : null);
  }
  elseif (isset($json['attributedTo']))
  {
    $actor = is_string($json['attributedTo']) ? $json['attributedTo'] : null;
  }

  if (isset($json['object']))
  {
    $obj = is_array($json['object']) && isset($json['object']['id'])
      ? $json['object']['id']
      : (is_string($json['object']) ? $json['object'] : null);
  }

  if (isset($json['target']))
  {
    $target = is_array($json['target']) && isset($json['target']['id'])
      ? $json['target']['id']
      : (is_string($json['target']) ? $json['target'] : null);
  }

  if (isset($json['correlationId']) && is_string($json['correlationId']))
  {
    $corrToken = $json['correlationId'];
  }
  elseif (isset($json['inReplyTo']) && is_string($json['inReplyTo']))
  {
    $corrToken = $json['inReplyTo'];
  }

  return [$type, $obj, $target, $actor, $corrToken];
}

function acl_allows(PDO $pdo, int $inboxId, ?string $actorIri, ?string $authToken): bool
{
  $stmt = $pdo->prepare("
    SELECT i_inbox_acls.rule_type,
           i_inbox_acls.match_kind,
           i_inbox_acls.match_value
    FROM i_inbox_acls
    WHERE i_inbox_acls.inbox_id = ?
  ");
  $stmt->execute([$inboxId]);
  $rules = $stmt->fetchAll();

  if (empty($rules))
  {
    return true;
  }

  $hasAllowRules = false;
  foreach ($rules as $r)
  {
    if ($r['rule_type'] === 'allow')
    {
      $hasAllowRules = true;
      break;
    }
  }

  $allowed = !$hasAllowRules;

  foreach ($rules as $r)
  {
    $match = false;

    switch ($r['match_kind'])
    {
      case 'auth_token':
        $match = $authToken && hash_equals($r['match_value'], $authToken);
        break;

      case 'actor_iri_prefix':
        $match = $actorIri && str_starts_with($actorIri, $r['match_value']);
        break;

      case 'exact_actor':
        $match = $actorIri && $actorIri === $r['match_value'];
        break;

      case 'domain_suffix':
        if ($actorIri && ($host = parse_url($actorIri, PHP_URL_HOST)))
        {
          $suffix = ltrim($r['match_value'], '.');

          $match = $host === $suffix || str_ends_with($host, '.'.$suffix);
        }
        break;

      case 'mtls_dn':
        $match = false;
        break;
    }

    if ($match)
    {
      if ($r['rule_type'] === 'deny')
      {
        return false;
      }

      if ($r['rule_type'] === 'allow')
      {
        $allowed = true;
      }
    }
  }

  return $allowed;
}

