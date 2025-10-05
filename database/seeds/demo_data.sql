-- Internal Seed Data - All IRIs within localhost system
-- This seed creates a fully self-contained demo where all actors, inboxes, and resources
-- exist within the system, enabling complete end-to-end testing and interaction flows
-- Passwords: 'password' for all users, 'admin123' for admin

-- ========================================
-- ORIGIN (Application Identity)
-- ========================================
INSERT INTO i_origin (id_iri, name, type) VALUES
('http://localhost:8081', 'LDN Inbox Demo System', 'Application');

-- ========================================
-- USERS with Actor Information
-- ========================================
INSERT INTO u_users(username, password_hash, role, webid_iri, actor_name, actor_type) VALUES
('admin', '$2y$10$9lrdxHjQBNBB8dhWLAz03OBxd3Im5Wgyt93V9.dENRvgdCBaWYLNy', 'admin', 'http://localhost:8081/users/admin', 'System Administrator', 'Person'),
('alice', '$2y$10$jl1nyHoT2QMsthL.RTO8DetZ0njfVwEk3cFP1IpYzOA7jcLF45TmW', 'user', 'http://localhost:8081/users/alice', 'Alice Johnson', 'Person'),
('bob', '$2y$10$jl1nyHoT2QMsthL.RTO8DetZ0njfVwEk3cFP1IpYzOA7jcLF45TmW', 'user', 'http://localhost:8081/users/bob', 'Bob Smith', 'Person'),
('charlie', '$2y$10$jl1nyHoT2QMsthL.RTO8DetZ0njfVwEk3cFP1IpYzOA7jcLF45TmW', 'user', 'http://localhost:8081/users/charlie', 'Charlie Chen', 'Person'),
('diana', '$2y$10$jl1nyHoT2QMsthL.RTO8DetZ0njfVwEk3cFP1IpYzOA7jcLF45TmW', 'user', 'http://localhost:8081/users/diana', 'Diana Rodriguez', 'Person'),
('eve', '$2y$10$jl1nyHoT2QMsthL.RTO8DetZ0njfVwEk3cFP1IpYzOA7jcLF45TmW', 'user', 'http://localhost:8081/users/eve', 'Eve Martinez', 'Organization');

SET @admin_id = (SELECT id FROM u_users WHERE username = 'admin');
SET @alice_id = (SELECT id FROM u_users WHERE username = 'alice');
SET @bob_id = (SELECT id FROM u_users WHERE username = 'bob');
SET @charlie_id = (SELECT id FROM u_users WHERE username = 'charlie');
SET @diana_id = (SELECT id FROM u_users WHERE username = 'diana');
SET @eve_id = (SELECT id FROM u_users WHERE username = 'eve');

-- ========================================
-- INBOXES with Primary Flag
-- ========================================
# Each user gets a primary inbox
# All inboxes are public to allow easy testing
INSERT INTO i_inboxes (owner_user_id, inbox_iri, resource_iri, visibility, is_primary) VALUES
(@alice_id, 'http://localhost:8081/api/inbox_receive.php?inbox_id=1', 'http://localhost:8081/resources/1', 'public', 1),
(@bob_id, 'http://localhost:8081/api/inbox_receive.php?inbox_id=2', 'http://localhost:8081/resources/2', 'public', 1),
(@charlie_id, 'http://localhost:8081/api/inbox_receive.php?inbox_id=3', 'http://localhost:8081/resources/3', 'public', 1),
(@diana_id, 'http://localhost:8081/api/inbox_receive.php?inbox_id=4', 'http://localhost:8081/resources/4', 'public', 1),
(@eve_id, 'http://localhost:8081/api/inbox_receive.php?inbox_id=5', 'http://localhost:8081/resources/5', 'public', 1),
# Secondary inboxes for some users
(@alice_id, 'http://localhost:8081/api/inbox_receive.php?inbox_id=6', NULL, 'public', 0),
(@bob_id, 'http://localhost:8081/api/inbox_receive.php?inbox_id=7', 'http://localhost:8081/resources/7', 'public', 0);

SET @inbox_alice = (SELECT id FROM i_inboxes WHERE inbox_iri = 'http://localhost:8081/api/inbox_receive.php?inbox_id=1');
SET @inbox_bob = (SELECT id FROM i_inboxes WHERE inbox_iri = 'http://localhost:8081/api/inbox_receive.php?inbox_id=2');
SET @inbox_charlie = (SELECT id FROM i_inboxes WHERE inbox_iri = 'http://localhost:8081/api/inbox_receive.php?inbox_id=3');
SET @inbox_diana = (SELECT id FROM i_inboxes WHERE inbox_iri = 'http://localhost:8081/api/inbox_receive.php?inbox_id=4');
SET @inbox_eve = (SELECT id FROM i_inboxes WHERE inbox_iri = 'http://localhost:8081/api/inbox_receive.php?inbox_id=5');

-- ========================================
-- RESOURCES (All Internal)
-- ========================================
INSERT INTO r_resources (owner_user_id, resource_iri, title, type, description, content_url) VALUES
(@alice_id, 'http://localhost:8081/resources/1', 'Introduction to Linked Data Notifications', 'Article', 'A comprehensive guide to implementing LDN in modern web applications', NULL),
(@bob_id, 'http://localhost:8081/resources/2', 'Climate Change Dataset 2024', 'Document', 'Annual climate data from weather stations worldwide', NULL),
(@charlie_id, 'http://localhost:8081/resources/3', 'Weekly Dev Update #42', 'Note', 'Progress report on the new API features and bug fixes', NULL),
(@diana_id, 'http://localhost:8081/resources/4', 'How to Build Decentralized Social Networks', 'Article', 'Exploring ActivityPub and federation protocols', NULL),
(@eve_id, 'http://localhost:8081/resources/5', 'Open Source Project: LDN Toolkit', 'Page', 'Documentation and tools for LDN implementation', NULL),
(@alice_id, 'http://localhost:8081/resources/6', 'Research Paper: Semantic Web Standards', 'Document', 'Analysis of RDF, JSON-LD, and Linked Data adoption rates', NULL),
(@bob_id, 'http://localhost:8081/resources/7', 'Data Visualization: Temperature Trends', 'Image', 'Interactive chart showing global temperature changes', NULL),
(@charlie_id, 'http://localhost:8081/resources/8', 'Podcast Episode: Web3 and Federation', 'Audio', 'Interview with experts on decentralized protocols', NULL),
(@diana_id, 'http://localhost:8081/resources/9', 'Workshop: ActivityStreams Deep Dive', 'Event', 'Hands-on workshop on AS2.0 vocabulary and JSON-LD', NULL),
(@eve_id, 'http://localhost:8081/resources/10', 'Code Repository: Inbox Server', 'Place', 'Source code for production-ready LDN inbox implementation', NULL);

-- ========================================
-- SENDERS (All Internal Users)
-- ========================================
# Since all notifications are from internal users, we register them as senders
INSERT INTO s_senders (actor_iri, verified) VALUES
('http://localhost:8081/users/alice', 1),
('http://localhost:8081/users/bob', 1),
('http://localhost:8081/users/charlie', 1),
('http://localhost:8081/users/diana', 1),
('http://localhost:8081/users/eve', 1),
('http://localhost:8081/users/admin', 1);

SET @sender_alice = (SELECT id FROM s_senders WHERE actor_iri = 'http://localhost:8081/users/alice');
SET @sender_bob = (SELECT id FROM s_senders WHERE actor_iri = 'http://localhost:8081/users/bob');
SET @sender_charlie = (SELECT id FROM s_senders WHERE actor_iri = 'http://localhost:8081/users/charlie');
SET @sender_diana = (SELECT id FROM s_senders WHERE actor_iri = 'http://localhost:8081/users/diana');
SET @sender_eve = (SELECT id FROM s_senders WHERE actor_iri = 'http://localhost:8081/users/eve');
SET @sender_admin = (SELECT id FROM s_senders WHERE actor_iri = 'http://localhost:8081/users/admin');

-- ========================================
-- NOTIFICATIONS (All Internal - Ready for Interaction)
-- ========================================

# Notification 1: Alice creates a resource and notifies Bob
INSERT INTO i_notifications (
  inbox_id, sender_id, content_type, body_jsonld,
  as_type, as_object_iri, as_target_iri, digest_sha256, status
) VALUES (
  @inbox_bob, @sender_alice, 'application/ld+json',
  '{"@context":"https://www.w3.org/ns/activitystreams","id":"http://localhost:8081/notifications/n1","type":"Create","actor":{"id":"http://localhost:8081/users/alice","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=1","name":"Alice Johnson","type":"Person"},"origin":{"id":"http://localhost:8081","name":"LDN Inbox Demo System","type":"Application"},"object":{"id":"http://localhost:8081/resources/1","type":"Article","name":"Introduction to Linked Data Notifications"},"target":{"id":"http://localhost:8081/users/bob","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=2","name":"Bob Smith","type":"Person"},"published":"2025-10-01T10:30:00Z"}',
  'Create',
  'http://localhost:8081/resources/1',
  'http://localhost:8081/users/bob',
  UNHEX(SHA2('internal-notif-create-1', 256)),
  'accepted'
);

# Notification 2: Bob updates his dataset and notifies Alice
INSERT INTO i_notifications (
  inbox_id, sender_id, content_type, body_jsonld,
  as_type, as_object_iri, as_target_iri, digest_sha256, status
) VALUES (
  @inbox_alice, @sender_bob, 'application/ld+json',
  '{"@context":"https://www.w3.org/ns/activitystreams","id":"http://localhost:8081/notifications/n2","type":"Update","actor":{"id":"http://localhost:8081/users/bob","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=2","name":"Bob Smith","type":"Person"},"origin":{"id":"http://localhost:8081","name":"LDN Inbox Demo System","type":"Application"},"object":{"id":"http://localhost:8081/resources/2","type":"Document","name":"Climate Change Dataset 2024"},"target":{"id":"http://localhost:8081/users/alice","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=1","name":"Alice Johnson","type":"Person"},"published":"2025-10-01T14:20:00Z"}',
  'Update',
  'http://localhost:8081/resources/2',
  'http://localhost:8081/users/alice',
  UNHEX(SHA2('internal-notif-update-2', 256)),
  'accepted'
);

# Notification 3: Charlie announces his weekly update to Diana
INSERT INTO i_notifications (
  inbox_id, sender_id, content_type, body_jsonld,
  as_type, as_object_iri, as_target_iri, digest_sha256, status
) VALUES (
  @inbox_diana, @sender_charlie, 'application/ld+json',
  '{"@context":"https://www.w3.org/ns/activitystreams","id":"http://localhost:8081/notifications/n3","type":"Announce","actor":{"id":"http://localhost:8081/users/charlie","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=3","name":"Charlie Chen","type":"Person"},"origin":{"id":"http://localhost:8081","name":"LDN Inbox Demo System","type":"Application"},"object":{"id":"http://localhost:8081/resources/3","type":"Note","name":"Weekly Dev Update #42"},"target":{"id":"http://localhost:8081/users/diana","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=4","name":"Diana Rodriguez","type":"Person"},"published":"2025-10-02T09:15:00Z"}',
  'Announce',
  'http://localhost:8081/resources/3',
  'http://localhost:8081/users/diana',
  UNHEX(SHA2('internal-notif-announce-3', 256)),
  'accepted'
);

# Notification 4: Eve offers collaboration to Diana (can be accepted/rejected)
INSERT INTO i_notifications (
  inbox_id, sender_id, content_type, body_jsonld,
  as_type, as_object_iri, as_target_iri, digest_sha256, corr_token, status
) VALUES (
  @inbox_diana, @sender_eve, 'application/ld+json',
  '{"@context":"https://www.w3.org/ns/activitystreams","id":"http://localhost:8081/notifications/n4","type":"Offer","actor":{"id":"http://localhost:8081/users/eve","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=5","name":"Eve Martinez","type":"Organization"},"origin":{"id":"http://localhost:8081","name":"LDN Inbox Demo System","type":"Application"},"object":{"id":"http://localhost:8081/resources/5","type":"Page","name":"Open Source Project: LDN Toolkit"},"target":{"id":"http://localhost:8081/users/diana","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=4","name":"Diana Rodriguez","type":"Person"},"summary":"Invitation to collaborate on LDN Toolkit project","published":"2025-10-02T11:45:00Z","correlationId":"collab-offer-001"}',
  'Offer',
  'http://localhost:8081/resources/5',
  'http://localhost:8081/users/diana',
  UNHEX(SHA2('internal-notif-offer-4', 256)),
  'collab-offer-001',
  'accepted'
);

# Notification 5: Diana accepts Eve's offer (reply to notification 4)
INSERT INTO i_notifications (
  inbox_id, sender_id, content_type, body_jsonld,
  as_type, as_object_iri, as_target_iri, digest_sha256, corr_token, status
) VALUES (
  @inbox_eve, @sender_diana, 'application/ld+json',
  '{"@context":"https://www.w3.org/ns/activitystreams","id":"http://localhost:8081/notifications/n5","type":"Accept","actor":{"id":"http://localhost:8081/users/diana","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=4","name":"Diana Rodriguez","type":"Person"},"origin":{"id":"http://localhost:8081","name":"LDN Inbox Demo System","type":"Application"},"object":"http://localhost:8081/notifications/n4","target":{"id":"http://localhost:8081/users/eve","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=5","name":"Eve Martinez","type":"Organization"},"summary":"Accepted collaboration offer","published":"2025-10-02T17:00:00Z","correlationId":"collab-offer-001"}',
  'Accept',
  'http://localhost:8081/notifications/n4',
  'http://localhost:8081/users/eve',
  UNHEX(SHA2('internal-notif-accept-5', 256)),
  'collab-offer-001',
  'accepted'
);

# Notification 6: Alice offers to review Bob's dataset (can be accepted/rejected)
INSERT INTO i_notifications (
  inbox_id, sender_id, content_type, body_jsonld,
  as_type, as_object_iri, as_target_iri, digest_sha256, corr_token, status
) VALUES (
  @inbox_bob, @sender_alice, 'application/ld+json',
  '{"@context":"https://www.w3.org/ns/activitystreams","id":"http://localhost:8081/notifications/n6","type":"Offer","actor":{"id":"http://localhost:8081/users/alice","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=1","name":"Alice Johnson","type":"Person"},"origin":{"id":"http://localhost:8081","name":"LDN Inbox Demo System","type":"Application"},"object":{"id":"http://localhost:8081/resources/6","type":"Document","name":"Peer Review Service"},"target":{"id":"http://localhost:8081/users/bob","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=2","name":"Bob Smith","type":"Person"},"summary":"Offering to peer review your climate dataset","published":"2025-10-03T08:00:00Z","correlationId":"review-offer-002"}',
  'Offer',
  'http://localhost:8081/resources/6',
  'http://localhost:8081/users/bob',
  UNHEX(SHA2('internal-notif-offer-6', 256)),
  'review-offer-002',
  'accepted'
);

# Notification 7: Bob rejects Alice's review offer
INSERT INTO i_notifications (
  inbox_id, sender_id, content_type, body_jsonld,
  as_type, as_object_iri, as_target_iri, digest_sha256, corr_token, status
) VALUES (
  @inbox_alice, @sender_bob, 'application/ld+json',
  '{"@context":"https://www.w3.org/ns/activitystreams","id":"http://localhost:8081/notifications/n7","type":"Reject","actor":{"id":"http://localhost:8081/users/bob","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=2","name":"Bob Smith","type":"Person"},"origin":{"id":"http://localhost:8081","name":"LDN Inbox Demo System","type":"Application"},"object":"http://localhost:8081/notifications/n6","target":{"id":"http://localhost:8081/users/alice","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=1","name":"Alice Johnson","type":"Person"},"summary":"Dataset already under review by another team","published":"2025-10-03T10:00:00Z","correlationId":"review-offer-002"}',
  'Reject',
  'http://localhost:8081/notifications/n6',
  'http://localhost:8081/users/alice',
  UNHEX(SHA2('internal-notif-reject-7', 256)),
  'review-offer-002',
  'accepted'
);

# Notification 8: Charlie announces a workshop to all (sent to Alice)
INSERT INTO i_notifications (
  inbox_id, sender_id, content_type, body_jsonld,
  as_type, as_object_iri, as_target_iri, digest_sha256, status
) VALUES (
  @inbox_alice, @sender_charlie, 'application/ld+json',
  '{"@context":"https://www.w3.org/ns/activitystreams","id":"http://localhost:8081/notifications/n8","type":"Announce","actor":{"id":"http://localhost:8081/users/charlie","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=3","name":"Charlie Chen","type":"Person"},"origin":{"id":"http://localhost:8081","name":"LDN Inbox Demo System","type":"Application"},"object":{"id":"http://localhost:8081/resources/9","type":"Event","name":"Workshop: ActivityStreams Deep Dive"},"target":{"id":"http://localhost:8081/users/alice","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=1","name":"Alice Johnson","type":"Person"},"summary":"Upcoming workshop on ActivityStreams 2.0","published":"2025-10-03T11:30:00Z"}',
  'Announce',
  'http://localhost:8081/resources/9',
  'http://localhost:8081/users/alice',
  UNHEX(SHA2('internal-notif-announce-8', 256)),
  'accepted'
);

# Notification 9: Bob removes an old resource and notifies Charlie
INSERT INTO i_notifications (
  inbox_id, sender_id, content_type, body_jsonld,
  as_type, as_object_iri, as_target_iri, digest_sha256, status
) VALUES (
  @inbox_charlie, @sender_bob, 'application/ld+json',
  '{"@context":"https://www.w3.org/ns/activitystreams","id":"http://localhost:8081/notifications/n9","type":"Remove","actor":{"id":"http://localhost:8081/users/bob","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=2","name":"Bob Smith","type":"Person"},"origin":{"id":"http://localhost:8081","name":"LDN Inbox Demo System","type":"Application"},"object":{"id":"http://localhost:8081/resources/7","type":"Image","name":"Old Temperature Visualization"},"target":{"id":"http://localhost:8081/users/charlie","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=3","name":"Charlie Chen","type":"Person"},"summary":"Removed deprecated visualization","published":"2025-10-03T14:00:00Z"}',
  'Remove',
  'http://localhost:8081/resources/7',
  'http://localhost:8081/users/charlie',
  UNHEX(SHA2('internal-notif-remove-9', 256)),
  'accepted'
);

# Notification 10: Admin creates a new system resource and notifies Eve
INSERT INTO i_notifications (
  inbox_id, sender_id, content_type, body_jsonld,
  as_type, as_object_iri, as_target_iri, digest_sha256, status
) VALUES (
  @inbox_eve, @sender_admin, 'application/ld+json',
  '{"@context":"https://www.w3.org/ns/activitystreams","id":"http://localhost:8081/notifications/n10","type":"Create","actor":{"id":"http://localhost:8081/users/admin","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=1","name":"System Administrator","type":"Person"},"origin":{"id":"http://localhost:8081","name":"LDN Inbox Demo System","type":"Application"},"object":{"id":"http://localhost:8081/resources/10","type":"Place","name":"Code Repository: Inbox Server"},"target":{"id":"http://localhost:8081/users/eve","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=5","name":"Eve Martinez","type":"Organization"},"summary":"New repository created for your organization","published":"2025-10-04T09:00:00Z"}',
  'Create',
  'http://localhost:8081/resources/10',
  'http://localhost:8081/users/eve',
  UNHEX(SHA2('internal-notif-create-10', 256)),
  'accepted'
);

# Notification 11: Diana offers a workshop slot to Charlie (awaiting response)
INSERT INTO i_notifications (
  inbox_id, sender_id, content_type, body_jsonld,
  as_type, as_object_iri, as_target_iri, digest_sha256, corr_token, status
) VALUES (
  @inbox_charlie, @sender_diana, 'application/ld+json',
  '{"@context":"https://www.w3.org/ns/activitystreams","id":"http://localhost:8081/notifications/n11","type":"Offer","actor":{"id":"http://localhost:8081/users/diana","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=4","name":"Diana Rodriguez","type":"Person"},"origin":{"id":"http://localhost:8081","name":"LDN Inbox Demo System","type":"Application"},"object":{"id":"http://localhost:8081/resources/9","type":"Event","name":"Workshop Speaker Slot"},"target":{"id":"http://localhost:8081/users/charlie","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=3","name":"Charlie Chen","type":"Person"},"summary":"Invitation to speak at ActivityStreams workshop","published":"2025-10-04T10:30:00Z","correlationId":"workshop-speaker-003"}',
  'Offer',
  'http://localhost:8081/resources/9',
  'http://localhost:8081/users/charlie',
  UNHEX(SHA2('internal-notif-offer-11', 256)),
  'workshop-speaker-003',
  'accepted'
);

# Notification 12: Alice updates her article and notifies Charlie
INSERT INTO i_notifications (
  inbox_id, sender_id, content_type, body_jsonld,
  as_type, as_object_iri, as_target_iri, digest_sha256, status
) VALUES (
  @inbox_charlie, @sender_alice, 'application/ld+json',
  '{"@context":"https://www.w3.org/ns/activitystreams","id":"http://localhost:8081/notifications/n12","type":"Update","actor":{"id":"http://localhost:8081/users/alice","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=1","name":"Alice Johnson","type":"Person"},"origin":{"id":"http://localhost:8081","name":"LDN Inbox Demo System","type":"Application"},"object":{"id":"http://localhost:8081/resources/1","type":"Article","name":"Introduction to Linked Data Notifications (v2)"},"target":{"id":"http://localhost:8081/users/charlie","inbox":"http://localhost:8081/api/inbox_receive.php?inbox_id=3","name":"Charlie Chen","type":"Person"},"summary":"Updated article with new examples","published":"2025-10-04T15:00:00Z"}',
  'Update',
  'http://localhost:8081/resources/1',
  'http://localhost:8081/users/charlie',
  UNHEX(SHA2('internal-notif-update-12', 256)),
  'accepted'
);

-- ========================================
-- Update notification IRIs
-- ========================================
UPDATE i_notifications SET notification_iri = CONCAT('http://localhost:8081/notification.php?id=', id)
WHERE notification_iri IS NULL;

-- ========================================
-- HTTP Metadata
-- ========================================
INSERT INTO i_notification_http_meta (notification_id, method, origin_ip, user_agent, header_host, status_code)
SELECT
  id,
  'POST',
  INET6_ATON('127.0.0.1'),
  CASE (id % 3)
    WHEN 0 THEN 'Mozilla/5.0 (LDN Client/1.0)'
    WHEN 1 THEN 'curl/7.68.0'
    ELSE 'ActivityPub-Client/2.0'
  END,
  'localhost:8081',
  201
FROM i_notifications
WHERE id NOT IN (SELECT notification_id FROM i_notification_http_meta);

-- ========================================
-- Inbox ACL Rules
-- ========================================
# No ACL rules - all inboxes are fully public (default behavior)
# When no ACL rules exist, any actor can POST to the inbox

-- ========================================
-- Display Summary
-- ========================================
SELECT
  'Internal seed data loaded successfully!' AS status,
  'All actors, inboxes, and resources are within http://localhost:8081' AS note,
  'All inboxes are public with no ACL restrictions' AS acl_note,
  (SELECT COUNT(*) FROM i_origin) AS total_origins,
  (SELECT COUNT(*) FROM u_users) AS total_users,
  (SELECT COUNT(*) FROM i_inboxes) AS total_inboxes,
  (SELECT COUNT(*) FROM i_inboxes WHERE is_primary = 1) AS primary_inboxes,
  (SELECT COUNT(*) FROM r_resources) AS total_resources,
  (SELECT COUNT(*) FROM i_notifications) AS total_notifications,
  (SELECT COUNT(*) FROM s_senders) AS total_senders;
