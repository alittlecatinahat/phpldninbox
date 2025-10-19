-- Database schema for LDN Inbox
USE ldn_inbox;


-- --------
-- --------
-- ORIGIN
-- Authentication and authorization with role-based access
CREATE TABLE i_origin (
  id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  id_iri        VARCHAR(1000) NOT NULL,
  name          VARCHAR(1000) NULL,
  type          ENUM('application') NOT NULL DEFAULT 'application',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_origin_iri (id_iri(768))
) ENGINE=InnoDB;
-- --------
-- --------
-- USERS
-- Authentication and authorization with role-based access
CREATE TABLE u_users (
  id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  username      VARCHAR(300) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  role          ENUM('admin', 'user') NOT NULL DEFAULT 'user',
  last_login    TIMESTAMP NULL,
  webid_iri     VARCHAR(3000) NULL,
  actor_name    VARCHAR(500) NULL,
  actor_type    VARCHAR(255) NULL DEFAULT 'Person',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE INDEX idx_users_role ON u_users(role);

-- --------
-- --------
-- INBOXES
-- per user, per resource
CREATE TABLE i_inboxes (
  id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  inbox_iri     VARCHAR(1024) NOT NULL,
  resource_iri  VARCHAR(2048) NULL,                      
  visibility    ENUM('public','private') NOT NULL DEFAULT 'public', 
  is_primary    TINYINT(1) NOT NULL DEFAULT 0,          
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inboxes_owner FOREIGN KEY (owner_user_id) REFERENCES u_users(id),
  UNIQUE KEY uq_inbox_iri (inbox_iri(768))
) ENGINE=InnoDB;

CREATE INDEX idx_inboxes_owner ON i_inboxes(owner_user_id);
CREATE INDEX idx_inboxes_primary ON i_inboxes(owner_user_id, is_primary);

-- --------
-- --------
-- Mockup resources
CREATE TABLE IF NOT EXISTS r_resources (
  id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  resource_iri  VARCHAR(2000) NOT NULL,                    
  owner_user_id BIGINT UNSIGNED NOT NULL,               
  title         VARCHAR(500) NULL,                         
  type          VARCHAR(300) NULL,                         
  description   TEXT NULL,                                 
  content_url   VARCHAR(2000) NULL,                        
  metadata      JSON NULL,                                 
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_resources_owner FOREIGN KEY (owner_user_id) REFERENCES u_users(id),
  UNIQUE KEY uq_resource_iri (resource_iri(768))
) ENGINE=InnoDB;

CREATE INDEX idx_resources_owner ON r_resources(owner_user_id);
CREATE INDEX idx_resources_type ON r_resources(type);



-- ------
-- ------
-- SENDERS
-- extracetd ex post
-- @verified
CREATE TABLE s_senders (
  id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  actor_iri     VARCHAR(3000) NOT NULL,                  
  verified      TINYINT(1) NOT NULL DEFAULT 0,           
  first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_senders_actor (actor_iri(512))
) ENGINE=InnoDB;

-- ------
-- ------
-- NOTIFICATIONS, MAIN JSON-LD PAYLOAD table

CREATE TABLE i_notifications (
  id               BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  inbox_id         BIGINT UNSIGNED NOT NULL,
  notification_iri VARCHAR(2000) NULL,                   
  sender_id        BIGINT UNSIGNED NULL,                
  content_type     VARCHAR(300) NOT NULL,                
  body_jsonld      JSON NOT NULL,                        
  compacted_jsonld JSON NULL,                            
  as_type          VARCHAR(300) NULL,                     
  as_object_iri    VARCHAR(2000) NULL,                    
  as_target_iri    VARCHAR(2000) NULL,                  
  digest_sha256    BINARY(32) NOT NULL,                   
  status           ENUM('accepted','rejected','deleted') NOT NULL DEFAULT 'accepted',
  received_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  corr_request_id  BIGINT UNSIGNED NULL,                  
  corr_token       VARCHAR(300) NULL,                     
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_notif_inbox   FOREIGN KEY (inbox_id)  REFERENCES i_inboxes(id),
  CONSTRAINT fk_notif_sender  FOREIGN KEY (sender_id) REFERENCES s_senders(id),
  CONSTRAINT fk_notif_corr    FOREIGN KEY (corr_request_id) REFERENCES i_notifications(id)
) ENGINE=InnoDB;

CREATE UNIQUE INDEX uq_notif_dedup ON i_notifications(inbox_id, digest_sha256);
CREATE INDEX idx_notif_inbox_time ON i_notifications(inbox_id, received_at);
CREATE INDEX idx_notif_as_type ON i_notifications(as_type);
CREATE INDEX idx_notif_obj ON i_notifications(as_object_iri(300));
CREATE UNIQUE INDEX uq_notif_iri ON i_notifications(notification_iri(500));

-- ------
-- ------
-- HTTP REQ METADATA
CREATE TABLE i_notification_http_meta (
  id               BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  notification_id  BIGINT UNSIGNED NOT NULL,
  method           ENUM('POST','PUT') NOT NULL,
  origin_ip        VARBINARY(16) NULL,                   
  user_agent       VARCHAR(500) NULL,
  header_host      VARCHAR(300) NULL,
  header_signature TEXT NULL,                            
  status_code      INT NOT NULL,                        
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_httpmeta_notif FOREIGN KEY (notification_id) REFERENCES i_notifications(id)
) ENGINE=InnoDB;

-- ------
-- ------
-- ACL BASE TABLE
CREATE TABLE i_inbox_acls (
  id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  inbox_id      BIGINT UNSIGNED NOT NULL,
  rule_type     ENUM('allow','deny') NOT NULL,
  match_kind    ENUM('actor_iri_prefix','domain_suffix','exact_actor','auth_token','mtls_dn') NOT NULL,
  match_value   VARCHAR(200) NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_acl_inbox FOREIGN KEY (inbox_id) REFERENCES i_inboxes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------
-- ------
-- OUTGOING NOTIFICATION
CREATE TABLE o_outgoing_notifications (
  id               BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  from_user_id     BIGINT UNSIGNED NULL,                
  to_inbox_iri     VARCHAR(2048) NOT NULL,
  body_jsonld      JSON NOT NULL,                   
  content_type     VARCHAR(255) NOT NULL DEFAULT 'application/ld+json',
  as_type          VARCHAR(255) NULL,
  corr_token       VARCHAR(255) NULL,               
  reply_to_notification_id BIGINT UNSIGNED NULL,         
  delivery_status  ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending',
  last_error       TEXT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_outgoing_user FOREIGN KEY (from_user_id) REFERENCES u_users(id)
) ENGINE=InnoDB;

CREATE INDEX idx_outgoing_status ON o_outgoing_notifications(delivery_status, created_at);
ALTER TABLE o_outgoing_notifications
  ADD CONSTRAINT fk_outgoing_reply_to FOREIGN KEY (reply_to_notification_id) REFERENCES i_notifications(id);

-- ------
-- ------
-- DELIVERY ATTEPTS, not future proof
CREATE TABLE o_delivery_attempts (
  id                      BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  outgoing_notification_id BIGINT UNSIGNED NOT NULL,
  attempt_no              INT NOT NULL,
  response_status         INT NULL,
  response_headers        TEXT NULL,
  response_body           MEDIUMTEXT NULL,
  attempted_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_deliv_out FOREIGN KEY (outgoing_notification_id) REFERENCES o_outgoing_notifications(id)
) ENGINE=InnoDB;
