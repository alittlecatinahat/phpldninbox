<?php
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';

# Database name where LDN tables are stored
$dbName = getenv('DB_NAME') ?: 'ldn_inbox';

# MySQL username for database connection
$dbUser = getenv('DB_USER') ?: 'ldn_user';

# MySQL password for the database user
$dbPassword = getenv('DB_PASSWORD') ?: 'ldn_password';

# ==============================================================================
# Application Base URL
# ==============================================================================
$baseUrl = getenv('BASE_URL') ?: 'http://localhost:8081';

return [
  'db' => [
    'dsn'      => "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",

    'user'     => $dbUser,

    'password' => $dbPassword,

    'options'  => [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

      PDO::ATTR_EMULATE_PREPARES   => false,
    ],
  ],

  'base_url' => $baseUrl,
];

