# LDN Inbox Implementation

An exeperimental  **Linked Data Notifications (LDN)** inbox system following W3C specifications, with MySQL JSON column support for efficient ActivityStreams JSON-LD processing and a  web-based management GUI and addedd features like ACL, user management, resources management.

## Quick Start

### Prerequisites

- Docker and Docker Compose installed
- Ports 8081 (web) and 3307 (MySQL) available (ports can be change in Docker coponse file)

### Run with Docker

```bash
./bash_docker_scripts/run.sh
./bash_docker_scripts/docker_mysql_load_demo.sh
```

Or manually:

```bash
docker compose up -d
```

The application will:
1. Start MySQL 8.0 database container
2. Start PHP 8.2-Apache web server
3. Automatically create database schema and if you want seed demo data

### Access

- **Web UI:** http://localhost:8081/ (auto-redirects to `/public/`)
- **API Documentation:** http://localhost:8081/api/ (JSON endpoint listing)
- **MySQL:** localhost:3307 (user: `ldn_user`, password: `ldn_password`)

### Default Login Credentials from seed

- **Admin:** `admin` / `admin123`
- **Regular Users:** `alice` / `password`, `bob` / `password`

These credential must be imported from the seed .sql file.
## Features

### Web-Based Management GUI

**User Authentication & Authorization**
- Session-based login system with bcrypt password hashing
- Role-based access control (admin vs regular user)
- Admins see all data, users see only their own resources
- Profile management with password change

**Inbox Management**
- Create LDN inboxes (all public by default)
- Link inboxes to resources
- View inbox notifications with reply tracking
- **Experimental ACL Management UI** for granular access control (allow/deny rules)

**Resource Management for use case demonstration **
- Support for various ActivityStreams types (Article, Document, Video, etc.)
- Link resources to inboxes for notifications

**Origin Settings** (Admin Only)
- Configure system origin metadata (id, name, type)
- Used in Event Notifications specification compliance

**Send Notifications**
- **Experimental :) Form Builder:** Event Notifications compliant with auto-fill UX
  - Live JSON-LD preview
  - Auto-fill target inbox when selecting target user (reduces duplicate entry)
  - Support for full actor/target/origin structures
  - Template quick-start for common notification types
- **Manual JSON:** Advanced mode for custom JSON-LD payloads
- Supports both internal (same system) and external (HTTP, curl) delivery
- **Experimental context-aware reply builder** with smart reply type suggestions:
  - Incoming Offer → suggests Accept/Reject replies
  - Incoming Create/Update → suggests Accept/Reject
  - Incoming Accept/Reject → suggests Undo
  - Auto-fills correlation tokens and reply inbox

### Core LDN Implementation

**W3C LDN Compliant**
- POST returns 201 + Location header
- Notifications are dereferenceable (GET returns original JSON-LD)
- Full ActivityStreams 2.0 JSON-LD support
- Content negotiation support


**Notification Processing**
- Automatic JSON validation via MySQL JSON column type
- SHA-256 deduplication prevents duplicate notifications
- ActivityStreams field extraction for efficient querying:
  - Type (e.g., "Offer", "Accept", "Announce")
  - Actor (who performed the activity)
  - Object (what the activity is about)
  - Target (where the activity is directed)
  - Correlation token (for request/response tracking)
- Full HTTP metadata auditing (IP, User-Agent, headers)

**Outgoing Notifications**
- Send to remote LDN inboxes via HTTP POST
- Internal delivery optimization (direct database insert)
- Delivery status tracking (pending, delivered, failed)
- Complete response logging (headers + body)
- Reply-to relationship tracking

### Database Schema

**10 Core Tables:**

1. **u_users** - User accounts with authentication
   - Password hashing (bcrypt)
   - Role-based access (admin/user)
   - Last login tracking
   - WebID integration support
   - Actor metadata (actor_name, actor_type) for Event Notifications

2. **i_inboxes** - LDN inbox containers
   - Per-user or per-resource inboxes
   - All inboxes are public by default (access controlled via ACLs)
   - Auto-generated inbox IRIs
   - Primary inbox designation (is_primary flag)
   - Created timestamps

3. **i_origin** - System origin metadata
   - Single-row table for origin configuration
   - Contains id_iri, name, type
   - Used in Event Notifications compliance

4. **s_senders** - Known sender actor registry
   - Tracks unique actor IRIs
   - Enables sender-based queries
   - Deduplicates sender information

5. **i_notifications** - Incoming notifications
   - **JSON column type** for body_jsonld (validated, queryable)
   - Extracted ActivityStreams fields (indexed)
   - Status tracking (accepted, rejected, deleted)
   - SHA-256 digest for deduplication
   - Correlation token support

6. **i_notification_http_meta** - HTTP request audit trail
   - Source IP addresses (binary storage)
   - User-Agent strings
   - HTTP headers (Host, Signature, etc.)
   - Response status codes
   - Forensics and debugging support

7. **i_inbox_acls** - Access control rules
   - Per-inbox granular permissions
   - Allow/deny rule types
   - Multiple match kinds (token, IRI, domain, mTLS)
   - Rule priority (deny > allow)

8. **o_outgoing_notifications** - Outbound notification queue
   - **JSON column type** for body_jsonld
   - Delivery status tracking
   - From user association
   - Reply-to notification linking
   - Error message logging

9. **o_delivery_attempts** - Delivery retry tracking
   - Multiple attempts per notification
   - Full HTTP response capture
   - Response headers and body logging
   - Timestamp tracking

10. **r_resources** - Local resource catalog
   - Title, type, description
   - Content URL
   - **JSON column** for flexible metadata
   - Owner association


## Project Structure

```
.
├── api/                        # LDN API Endpoints (JSON responses)
│   ├── inbox_receive.php       # POST - Receive incoming notifications (LDN entry point)
│   ├── inbox_list.php          # GET - List notifications in an inbox
│   ├── notification_get.php    # GET - Retrieve single notification (dereferenceable)
│   ├── outgoing_get.php        # GET - Retrieve outgoing notification
│   ├── outgoing_list.php       # GET - List outgoing notifications (with filters)
│   ├── send_outgoing.php       # POST - Send notification to remote inbox
│   └── index.php               # GET - API discovery endpoint (REST documentation)
│
├── src/                        # Core Application Logic
│   ├── config.php              # Configuration (env vars, DB settings, base URL)
│   ├── database.php            # Database singleton connection
│   ├── utils.php               # Utilities (ACL validation, AS parsing, IP handling, IRI minting)
│   └── auth.php                # Authentication & authorization (sessions, roles, RBAC)
│
└── public/                     # Web UI (Bootstrap 5)
    ├── index.php               # Main router (handles auth, page loading)
    ├── notifications.php       # Dereferenceable notification endpoint (clean URLs)
    ├── .htaccess               # Apache mod_rewrite rules for clean URLs
    └── pages/                  # Page components
        ├── home.php            # Inbox list (filtered by user role)
        ├── inbox.php           # View inbox notifications
        ├── notification.php    # Notification details + context-aware reply builder
        ├── new_inbox.php       # Create new inbox (with primary inbox option)
        ├── new_resource.php    # Create new resource
        ├── new_user.php        # Create new user with actor metadata (admin only)
        ├── origin_settings.php # Configure system origin (admin only)
        ├── send.php            # Send notification (manual JSON)
        ├── send_upgrade.php    # Enhanced form builder (Event Notifications compliant)
        ├── acl_manage.php      # ACL management UI (add/remove allow/deny rules)
        ├── api_test.php        # API diagnostic tool (admin only)
        ├── profile.php         # User profile + password change
        ├── login.php           # Login page
        └── logout.php          # Logout handler
```

## API Reference

### API Discovery Endpoint

**Endpoint:** `GET /api/` or `GET /api/index.php`

The API root provides **machine-readable JSON documentation** of all available endpoints following REST best practices.

```bash
curl http://localhost:8081/api/
```

**Returns:**
- Complete endpoint listing with methods, parameters, examples
- Supported ActivityStreams types
- JSON-LD structure requirements
- Authentication methods
- Specification links (W3C LDN, Event Notifications, ActivityStreams)

**Example response:**
```json
{
  "name": "LDN Inbox API",
  "version": "1.0.0",
  "base_url": "http://localhost:8081/api",
  "endpoints": [
    {
      "path": "/api/inbox_receive.php",
      "method": "POST",
      "description": "Receive incoming LDN notification",
      "example": "http://localhost:8081/api/inbox_receive.php?inbox_id=1"
    },
    ...
  ]
}
```

### 1. Receive Notification (LDN Inbox Entry Point)

**Endpoint:** `POST /api/inbox_receive.php?inbox_id={id}`

Receive an incoming LDN notification. This is the primary W3C LDN specification endpoint.

**Headers:**
- `Content-Type: application/ld+json` (required)
- `X-Auth-Token: {token}` (optional, for ACL validation)

**Request Body:** JSON-LD ActivityStreams notification

**Example (Event Notifications Compliant):**
```bash
curl -i -X POST "http://localhost:8081/api/inbox_receive.php?inbox_id=1" \
  -H "Content-Type: application/ld+json" \
  -H "X-Auth-Token: SECRET123" \
  --data '{
    "@context": "https://www.w3.org/ns/activitystreams",
    "id": "https://sender.example/notifications/n123",
    "type": "Offer",
    "actor": {
      "id": "https://sender.example/alice",
      "inbox": "https://sender.example/inbox/alice",
      "name": "Alice Smith",
      "type": "Person"
    },
    "origin": {
      "id": "https://sender.example",
      "name": "Sender System",
      "type": "Application"
    },
    "object": {
      "id": "https://yourdomain.example/resource/42",
      "type": "Document",
      "name": "Research Paper"
    },
    "target": {
      "id": "https://yourdomain.example/users/bob",
      "inbox": "http://localhost:8081/api/inbox_receive.php?inbox_id=1",
      "name": "Bob Jones",
      "type": "Person"
    },
    "correlationId": "req-abc-123"
  }'
```

**Success Response (201 Created):**
```http
HTTP/1.1 201 Created
Location: http://localhost:8081/notification.php?id=1
Content-Type: application/json

{"id":1,"iri":"http://localhost:8081/notification.php?id=1","status":"accepted"}
```

**Error Responses:**
- `400 Bad Request` - Missing inbox_id or empty body
- `403 Forbidden` - Sender not allowed by ACL
- `404 Not Found` - Inbox doesn't exist
- `415 Unsupported Media Type` - Invalid JSON
- `500 Internal Server Error` - Database error

### 2. List Inbox Notifications

**Endpoint:** `GET /api/inbox_list.php?inbox_id={id}&limit={count}`

List all notifications in an inbox (only accepted notifications are returned).

**Parameters:**
- `inbox_id` (required) - Inbox ID to list notifications from
- `limit` (optional) - Maximum results (1-200, default: 50)

**Example:**
```bash
curl "http://localhost:8081/api/inbox_list.php?inbox_id=1&limit=10"
```

**Response:**
```json
{
  "inbox_id": 1,
  "items": [
    {
      "id": 42,
      "iri": "http://localhost:8081/notification.php?id=42",
      "type": "Offer",
      "object": "http://example.org/resource/123",
      "target": null,
      "received_at": "2025-10-04 12:34:56"
    }
  ]
}
```

### 3. Get Single Notification

**Endpoint:** `GET /api/notification_get.php?id={id}`

Retrieve the full JSON-LD content of a specific notification (dereferenceable IRI).

**Example:**
```bash
curl "http://localhost:8081/api/notification_get.php?id=1"
```

**Response:** Original JSON-LD notification with preserved Content-Type
```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://sender.example/notifications/n123",
  "type": "Offer",
  "actor": {
    "id": "https://sender.example/alice",
    "inbox": "https://sender.example/inbox/alice",
    "name": "Alice Smith",
    "type": "Person"
  },
  "origin": {
    "id": "https://sender.example",
    "name": "Sender System",
    "type": "Application"
  },
  "object": {
    "id": "https://yourdomain.example/resource/42",
    "type": "Document",
    "name": "Research Paper"
  },
  "target": {
    "id": "https://yourdomain.example/users/bob",
    "inbox": "http://localhost:8081/api/inbox_receive.php?inbox_id=1",
    "name": "Bob Jones",
    "type": "Person"
  },
  "correlationId": "req-abc-123"
}
```

### 4. Send Outgoing Notification

**Endpoint:** `POST /api/send_outgoing.php`

Send a notification from this system to a remote LDN inbox.

**Request Body (JSON):**
```json
{
    "@context": "https://www.w3.org/ns/activitystreams",
    "id": "http://localhost:8081/notifications/notif_68e2aebf048b63.79111484",
    "type": "Create",
    "origin": {
        "id": "http://localhost:8081",
        "name": "LDN Inbox Demo System",
        "type": "application"
    },
    "actor": {
        "id": "http://localhost:8081/users/admin",
        "inbox": "http://localhost:8081/api/inbox_receive.php?user_id=1",
        "name": "System Administrator",
        "type": "Person"
    },
    "object": {
        "id": "http://localhost:8081/resources/3",
        "type": "Note",
        "name": "Weekly Dev Update #42"
    },
    "target": "http://sigma.ldninbox.cloud/petr/",
    "summary": "Created new artifact",
    "content": "A new resource has been created and is now available for use.",
    "published": "2025-10-05T17:45:35Z"
}
```

**Example:**
```bash
curl -X POST http://localhost:8081/api/send_outgoing.php \
  -H "Content-Type: application/json" \
  -d '
{
    "@context": "https://www.w3.org/ns/activitystreams",
    "id": "http://localhost:8081/notifications/notif_68e2aebf048b63.79111484",
    "type": "Create",
    "origin": {
        "id": "http://localhost:8081",
        "name": "LDN Inbox Demo System",
        "type": "application"
    },
    "actor": {
        "id": "http://localhost:8081/users/admin",
        "inbox": "http://localhost:8081/api/inbox_receive.php?user_id=1",
        "name": "System Administrator",
        "type": "Person"
    },
    "object": {
        "id": "http://localhost:8081/resources/3",
        "type": "Note",
        "name": "Weekly Dev Update #42"
    },
    "target": "http://sigma.ldninbox.cloud/petr/",
    "summary": "Created new artifact",
    "content": "A new resource has been created and is now available for use.",
    "published": "2025-10-05T17:45:35Z"
}'
```

**Response:**
```json
{
  "id": 15,
  "status": "delivered",
  "http_code": 201,
  "error": null
}
```

### 5. List Outgoing Notifications

**Endpoint:** `GET /api/outgoing_list.php?status={status}&limit={count}`

List outgoing notifications with optional filtering.

**Parameters:**
- `status` (optional) - Filter by status: `pending`, `delivered`, `failed`
- `reply_to_notification_id` (optional) - Filter by replies to specific notification
- `limit` (optional) - Maximum results (1-1000, default: 50)

**Example:**
```bash
curl "http://localhost:8081/api/outgoing_list.php?status=delivered&limit=20"
```

## Experimental Access Control Lists (ACL)

### Overview

**All inboxes are public by default** - any sender can POST notifications without authentication. This follows the open nature of Linked Data Notifications.

To restrict access to an inbox, use the **ACL Management UI** at `/?p=acl_manage` to add allow/deny rules after creating the inbox.

### ACL Management UI

**Access:** Main navigation → "ACL Rules" (all authenticated users)

**Features:**
- View all your inboxes with ACL rule counts
- Add ALLOW rules (whitelist mode) or DENY rules (blacklist mode)
- Delete existing rules with one click
- Visual indicators: green badges for ALLOW, red badges for DENY
- Admins see all inboxes, regular users see only their own

### How ACLs Work

**Default Behavior:**
- Inbox with **no ACL rules** = fully public (anyone can POST)
- Inbox with **ALLOW rules** = whitelist mode (only matching senders can POST)
- Inbox with **DENY rules** = blacklist mode (matching senders are blocked)

**Rule Priority:**
- DENY rules **always take precedence** over ALLOW rules
- If a sender matches both ALLOW and DENY, they are **denied**

### Match Kinds

ACL rules support five different matching strategies:

**1. Auth Token (`auth_token`)**
- Matches against `X-Auth-Token` HTTP header
- Example: `my-secret-token-123`
- Use case: API clients with pre-shared keys

**2. Exact Actor (`exact_actor`)**
- Matches exact actor IRI from notification JSON
- Example: `https://alice.example/profile`
- Use case: Allow/deny specific known actors

**3. Actor IRI Prefix (`actor_iri_prefix`)**
- Matches actor IRIs starting with specified prefix
- Example: `https://university.edu/users/`
- Use case: Allow all actors from specific system path

**4. Domain Suffix (`domain_suffix`)**
- Matches actor IRIs ending with specified domain
- Example: `orcid.org` matches `https://orcid.org/0000-0001-2345-6789`
- Use case: Trust all actors from specific domain (e.g., `*.orcid.org`)


### Common Access Patterns

**Pattern 1: Fully Public (Default)**
```
No ACL rules → Anyone can POST
```

**Pattern 2: Token-Based Access**
```
ALLOW rule:
  Match Kind: Auth Token
  Match Value: SECRET-TOKEN-123

→ Only requests with X-Auth-Token: SECRET-TOKEN-123 can POST
```

**Pattern 3: Domain Whitelist**
```
ALLOW rule:
  Match Kind: Domain Suffix
  Match Value: trusted.edu

→ Only actors from *.trusted.edu can POST
```

**Pattern 4: Actor Blacklist**
```
DENY rule:
  Match Kind: Exact Actor
  Match Value: https://spammer.example/bot

→ Block this specific actor, allow all others
```

**Pattern 5: Whitelist with Exceptions**
```
ALLOW rule:
  Match Kind: Domain Suffix
  Match Value: university.edu

DENY rule:
  Match Kind: Exact Actor
  Match Value: https://university.edu/suspended-user

→ Allow all *.university.edu EXCEPT suspended-user (deny wins)
```

### ACL Validation Logic

When a notification is POSTed to an inbox:

1. **Check if inbox exists** (404 if not)
2. **Query ACL rules** for this inbox
3. **If no rules exist** → Accept (public inbox)
4. **If rules exist:**
   - Extract actor IRI from JSON: `body_jsonld->>'$.actor.id'`
   - Extract auth token from header: `$_SERVER['HTTP_X_AUTH_TOKEN']`
   - **Check DENY rules first** → If match, reject (403 Forbidden)
   - **Check ALLOW rules** → If match, accept
   - **If ALLOW rules exist but no match** → reject (403 Forbidden)
   - **If only DENY rules exist and no match** → accept
5. **Insert notification** into database (201 Created)

### Managing ACLs via UI

**Step-by-step workflow:**

1. Navigate to `/?p=acl_manage`
2. Find your inbox in the list
3. Review existing rules (if any)
4. To add a new rule:
   - Select **Rule Type** (ALLOW or DENY)
   - Choose **Match Kind** from dropdown
   - Enter **Match Value** (token, IRI, domain, etc.)
   - Click "Add" button
5. To remove a rule, click the trash icon (**delete confirmation required**)

**Example: Restrict inbox to ORCID users only**

```
1. Create inbox at /?p=new_inbox
2. Go to /?p=acl_manage
3. Find your new inbox
4. Add ALLOW rule:
   - Rule Type: ALLOW
   - Match Kind: Domain Suffix
   - Match Value: orcid.org
5. Click Add
```

Now only actors with IRIs like `https://orcid.org/0000-0001-2345-6789` can POST to this inbox.

### Database Schema

ACL rules are stored in the `i_inbox_acls` table:

```sql
CREATE TABLE i_inbox_acls (
  id INT PRIMARY KEY AUTO_INCREMENT,
  inbox_id INT NOT NULL,
  rule_type ENUM('allow', 'deny') NOT NULL DEFAULT 'allow',
  match_kind VARCHAR(50) NOT NULL,  -- auth_token, exact_actor, etc.
  match_value VARCHAR(500) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (inbox_id) REFERENCES i_inboxes(id) ON DELETE CASCADE
);
```

## Configuration

### Environment Variables

Edit `src/config.php` or set environment variables in Docker:

```bash
# Database connection
DB_HOST=ldn_mysql          # MySQL hostname (use service name in Docker)
DB_NAME=ldn_inbox          # Database name
DB_USER=ldn_user           # Database username
DB_PASSWORD=ldn_password   # Database password (CHANGE IN PRODUCTION!)

# Application settings
BASE_URL=http://localhost:8081   # Public-facing URL (no trailing slash)
```

### Docker Compose Configuration

The `docker-compose.yml` file configures:
- PHP 8.2-Apache container (`ldn_php`)
- MySQL 8.0 container (`ldn_mysql`)
- Persistent volume for MySQL data
- Network configuration
- Port mappings (8081:80 for web, 3307:3306 for MySQL)


## MySQL JSON Query Examples

Leverage native JSON functions for powerful queries:

```sql
-- Find all Offer notifications
SELECT * FROM i_notifications
WHERE body_jsonld->>'$.type' = 'Offer';

-- Count notifications by type
SELECT
  body_jsonld->>'$.type' AS notification_type,
  COUNT(*) as total
FROM i_notifications
GROUP BY notification_type;

-- Find notifications from specific actor
SELECT * FROM i_notifications
WHERE body_jsonld->>'$.actor' = 'https://sender.example/alice';

-- Search in nested objects
SELECT * FROM i_notifications
WHERE body_jsonld->>'$.object.type' = 'Article';

-- Find notifications with inReplyTo field
SELECT * FROM i_notifications
WHERE JSON_CONTAINS_PATH(body_jsonld, 'one', '$.inReplyTo');

-- Extract multiple fields efficiently
SELECT
  id,
  body_jsonld->>'$.type' AS type,
  body_jsonld->>'$.actor' AS actor,
  body_jsonld->>'$.object' AS object,
  JSON_LENGTH(body_jsonld->'$.to') AS recipient_count
FROM i_notifications;

-- Check if JSON array contains value
SELECT * FROM i_notifications
WHERE JSON_CONTAINS(
  body_jsonld->'$.cc',
  '"https://www.w3.org/ns/activitystreams#Public"'
);

-- Update JSON field partially
UPDATE i_notifications
SET body_jsonld = JSON_SET(body_jsonld, '$.processed', TRUE, '$.processedAt', NOW())
WHERE id = 42;
```

## Development Guide

### View Logs

```bash
# All services
docker compose logs -f

# Just PHP
docker compose logs -f ldn_php

# Just MySQL
docker compose logs -f ldn_mysql
```

### Access MySQL Console

```bash
# Via docker exec
docker exec -it ldn_mysql mysql -uldn_user -pldn_password ldn_inbox

# Via local MySQL client (if installed)
mysql -h127.0.0.1 -P3307 -uldn_user -pldn_password ldn_inbox
```

### Access PHP Container Shell

```bash
docker exec -it ldn_php bash

# Inside container, you can:
cd /var/www/html
php -v
tail -f /var/log/apache2/error.log
```

### Run Migrations

```bash
# Apply specific migration
docker exec -i ldn_mysql mysql -uldn_user -pldn_password ldn_inbox \
  < database/migrations/003_add_resources_table.sql

# Re-run all migrations (destructive!)
docker exec -i ldn_mysql mysql -uldn_user -pldn_password ldn_inbox \
  < database/schema.sql
```

### Reload Demo Data

```bash
docker exec -i ldn_mysql mysql -uldn_user -pldn_password ldn_inbox \
  < database/seeds/demo_data.sql
```

### Stop Services

```bash
docker compose down
```

### Fresh Start (Delete All Data)

```bash
# Stop and remove volumes
docker compose down -v

# Restart (will reinitialize database)
docker compose up -d
```

### Generate Password Hashes

```bash
# In PHP container
docker exec -it ldn_php php -r "echo password_hash('mypassword', PASSWORD_DEFAULT) . PHP_EOL;"
```

## Testing


### Manual Testing via Web UI

1. Login as admin (`admin` / `admin123`)
2. Configure Origin Settings (admin menu)
3. Create a new user with actor metadata (name, type)
4. Create a new inbox and mark it as primary
5. (Optional) Configure ACL rules at `/?p=acl_manage` to restrict inbox access
6. Use "Form Builder" to send Event Notifications compliant JSON
   - Select target user → inbox auto-fills automatically (UX enhancement)
   - Live JSON preview updates in real-time
7. View the inbox to see the received notification
8. Click on notification → use context-aware reply builder with smart suggestions


## Troubleshooting

### Database Connection Errors

```bash
# Check if MySQL is running
docker compose ps

# Check MySQL logs
docker compose logs ldn_mysql

# Verify credentials in config.php match docker-compose.yml
```

### Permission Errors

```bash
# Fix file permissions (if needed on Linux)
sudo chown -R $USER:$USER .
```

### Port Conflicts

If ports 8081 or 3307 are already in use, edit `docker-compose.yml`:

```yaml
ports:
  - "9090:80"  # Change 8081 to 9090
```

### JSON Validation Errors

MySQL will reject invalid JSON. Ensure:
- Proper JSON syntax (use JSON validator)
- Required fields present (@context, type)
- No trailing commas
- Proper string escaping


---

**Built with:** PHP 8.2 • MySQL 8.0 • Docker • Bootstrap 5 • ActivityStreams 2.0

**Key Features:** Event Notifications compliant • Native JSON columns • W3C LDN compliant • Role-based access • Enhanced UX with auto-fill • Context-aware replies • Educational codebase • Web GUI • **ACL Management UI** • Deduplication • Audit trail

## Commentary & Future Improvements

