<?php
function db(): PDO
{
  static $pdo = null;

  # If connection already exists, return it immediately
  # This avoids creating multiple connections
  if ($pdo)
  {
    return $pdo;
  }

  # Load database configuration from config.php
  # This returns an array with 'db' and 'base_url' keys
  $cfg = require __DIR__.'/config.php';

  $pdo = new PDO(
    $cfg['db']['dsn'],
    $cfg['db']['user'],
    $cfg['db']['password'],
    $cfg['db']['options']
  );

  return $pdo;
}
?>
