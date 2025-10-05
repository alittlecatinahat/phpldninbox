# Tiny API - Simplified LDN Inbox

A minimal implementation of the LDN (Linked Data Notifications) inbox API for command-line CURL usage.

## Overview

The Tiny API provides basic POST and GET functionality for LDN notifications without ACL validation, inbox checking, or other security features. It is designed for development, testing, and command-line interaction only.

## Endpoints

### POST - Send a Notification

Accepts JSON-LD notifications and stores them in the database.

**Endpoint:** `tinyapi/post.php`

**Method:** `POST`

**Parameters:**
- `inbox_id` (required): The ID of the inbox to receive the notification

**Request Body:** Valid JSON (preferably JSON-LD)

**Example:**

```bash
curl -X POST http://localhost:8081/tinyapi/post.php?inbox_id=1 \
  -H "Content-Type: application/ld+json" \
  -d '{
    "@context": "https://www.w3.org/ns/activitystreams",
    "type": "Offer",
    "actor": "https://example.org/user/1",
    "object": "https://example.org/resource/1"
  }'
```

**Response (201 Created):**

```json
{
  "id": 42,
  "iri": "http://localhost:8081/notification.php?id=42",
  "status": "accepted"
}
```

### GET - Retrieve a Notification

Retrieves the full JSON-LD content of a notification by ID.

**Endpoint:** `tinyapi/get.php`

**Method:** `GET`

**Parameters:**
- `id` (required): The database ID of the notification

**Example:**

```bash
curl http://localhost:8081/tinyapi/get.php?id=42
```

**Response (200 OK):**

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "type": "Offer",
  "actor": "https://example.org/user/1",
  "object": "https://example.org/resource/1"
}
```

## Supported Activity Types

The API accepts all Activity Streams 2.0 types, including:

### State the artifact
- Create
- Update
- Remove
- Announce

### Pertain activities
- Offer
- Accept
- Reject
- Announce
- Undo

## JSON-LD Structure

All notifications should follow the Activity Streams 2.0 structure:

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "type": "Activity",
  "actor": {
    "id": "https://example.org/user/1",
    "inbox": "https://example.org/inbox/1",
    "name": "User Name",
    "type": "Person"
  },
  "object": "https://example.org/resource/1",
  "target": {
    "id": "https://example.org/target/1",
    "inbox": "https://example.org/inbox/2",
    "name": "Target Name",
    "type": "Resource"
  },
  "origin": {
    "id": "https://example.org/origin/1",
    "name": "Origin Name",
    "type": "Application"
  }
}
```

## Error Responses

### 400 Bad Request
```json
{
  "error": "Missing or invalid inbox_id"
}
```

```json
{
  "error": "Empty request body"
}
```

```json
{
  "error": "Invalid JSON"
}
```

### 404 Not Found
```json
{
  "error": "Not found"
}
```

### 500 Internal Server Error
```json
{
  "error": "Server error",
  "detail": "Error message details"
}
```

## Important Notes

- This API has NO security features (no ACL validation, no authentication)
- Use only for development, testing, and command-line interaction
- All notifications are stored in the same database as the full API
- Deduplication is handled via SHA-256 digest
- Notifications are idempotent - sending the same notification twice will not create duplicates

## Examples

### Create Notification

```bash
curl -X POST http://localhost:8081/tinyapi/post.php?inbox_id=1 \
  -H "Content-Type: application/ld+json" \
  -d '{
    "@context": "https://www.w3.org/ns/activitystreams",
    "type": "Create",
    "actor": "https://alice.example/profile",
    "object": {
      "type": "Note",
      "content": "Hello World"
    }
  }'
```

### Offer Notification

```bash
curl -X POST http://localhost:8081/tinyapi/post.php?inbox_id=1 \
  -H "Content-Type: application/ld+json" \
  -d '{
    "@context": "https://www.w3.org/ns/activitystreams",
    "type": "Offer",
    "actor": "https://bob.example/profile",
    "object": "https://example.org/resource/123",
    "target": "https://alice.example/inbox"
  }'
```

### Accept Notification

```bash
curl -X POST http://localhost:8081/tinyapi/post.php?inbox_id=1 \
  -H "Content-Type: application/ld+json" \
  -d '{
    "@context": "https://www.w3.org/ns/activitystreams",
    "type": "Accept",
    "actor": "https://alice.example/profile",
    "object": "https://example.org/offer/456",
    "inReplyTo": "https://example.org/notification/123"
  }'
```

### Retrieve Notification

```bash
curl http://localhost:8081/tinyapi/get.php?id=42
```

### Pretty Print Response

```bash
curl http://localhost:8081/tinyapi/get.php?id=42 | jq .
```
