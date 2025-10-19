<?php

require __DIR__.'/../src/config.php';

# Get configuration
$cfg = require __DIR__.'/../src/config.php';
$baseUrl = rtrim($cfg['base_url'], '/');

# Build API documentation structure
$apiDoc = [
    'name' => 'LDN Inbox API',
    'version' => '1.0.0',
    'description' => 'Linked Data Notifications (LDN) API implementing W3C LDN specification and Event Notifications',
    'base_url' => $baseUrl . '/api',
    'specification' => [
        'W3C LDN' => 'https://www.w3.org/TR/ldn/',
        'Event Notifications' => 'https://www.eventnotifications.net/',
        'ActivityStreams 2.0' => 'https://www.w3.org/TR/activitystreams-core/'
    ],
    'endpoints' => [
        [
            'path' => '/api/inbox_receive.php',
            'method' => 'POST',
            'description' => 'Receive incoming LDN notification',
            'parameters' => [
                'inbox_id' => 'required, integer, inbox identifier'
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json (required)',
                'X-Auth-Token' => 'optional, for ACL validation'
            ],
            'returns' => 'HTTP 201 Created with Location header',
            'example' => $baseUrl . '/api/inbox_receive.php?inbox_id=1'
        ],
        [
            'path' => '/api/inbox_list.php',
            'method' => 'GET',
            'description' => 'List notifications in an inbox',
            'parameters' => [
                'inbox_id' => 'required, integer, inbox identifier',
                'limit' => 'optional, integer (1-200), default 50'
            ],
            'returns' => 'JSON array of notifications',
            'example' => $baseUrl . '/api/inbox_list.php?inbox_id=1&limit=10'
        ],
        [
            'path' => '/api/notification_get.php',
            'method' => 'GET',
            'description' => 'Retrieve single notification (dereferenceable)',
            'parameters' => [
                'id' => 'required, integer, notification database ID'
            ],
            'returns' => 'Original JSON-LD notification',
            'example' => $baseUrl . '/api/notification_get.php?id=1'
        ],
        [
            'path' => '/api/outgoing_list.php',
            'method' => 'GET',
            'description' => 'List outgoing notifications',
            'parameters' => [
                'status' => 'optional, filter by: pending, delivered, failed',
                'reply_to_notification_id' => 'optional, filter by reply target',
                'limit' => 'optional, integer (1-1000), default 50'
            ],
            'returns' => 'JSON array of outgoing notifications',
            'example' => $baseUrl . '/api/outgoing_list.php?status=delivered&limit=20'
        ],
        [
            'path' => '/api/outgoing_get.php',
            'method' => 'GET',
            'description' => 'Retrieve single outgoing notification',
            'parameters' => [
                'id' => 'required, integer, outgoing notification ID'
            ],
            'returns' => 'JSON with notification and delivery details',
            'example' => $baseUrl . '/api/outgoing_get.php?id=1'
        ],
        [
            'path' => '/api/send_outgoing.php',
            'method' => 'POST',
            'description' => 'Send notification to remote inbox',
            'headers' => [
                'Content-Type' => 'application/json (required)'
            ],
            'body' => [
                'to_inbox_iri' => 'required, string, target inbox URL',
                'from_user_id' => 'optional, integer',
                'corr_token' => 'optional, string, correlation ID',
                'reply_to_notification_id' => 'optional, integer',
                'body_jsonld' => 'required, string, JSON-LD notification'
            ],
            'returns' => 'JSON with delivery status',
            'example' => $baseUrl . '/api/send_outgoing.php'
        ]
    ],
    'supported_types' => [
        'state' => ['Create', 'Update', 'Remove', 'Announce'],
        'activity' => ['Offer', 'Accept', 'Reject', 'Undo']
    ],
    'json_ld_structure' => [
        '@context' => 'https://www.w3.org/ns/activitystreams (required)',
        'id' => 'unique notification identifier (required)',
        'type' => 'ActivityStreams type (required)',
        'actor' => 'full object with id, inbox, name, type (Event Notifications)',
        'origin' => 'system origin with id, name, type (Event Notifications)',
        'object' => 'the resource being acted upon',
        'target' => 'the target of the activity (optional)',
        'published' => 'ISO 8601 timestamp (optional)',
        'correlationId' => 'for request-response tracking (optional)'
    ],
    'authentication' => [
        'method' => 'ACL (Access Control Lists)',
        'types' => [
            'auth_token' => 'X-Auth-Token header matching',
            'actor_iri_prefix' => 'Actor IRI prefix matching',
            'exact_actor' => 'Exact actor IRI matching',
            'domain_suffix' => 'Actor domain suffix matching',
            'mtls_dn' => 'mTLS Distinguished Name (stub)'
        ]
    ],
    'links' => [
        'web_ui' => $baseUrl,
        'api_test' => $baseUrl . '/?p=api_test',
        'documentation' => $baseUrl . '/README.md'
    ]
];

# Set response headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');

# Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

# Return API documentation as JSON
echo json_encode($apiDoc, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
