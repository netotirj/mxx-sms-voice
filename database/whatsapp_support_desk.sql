CREATE TABLE IF NOT EXISTS whatsapp_support_queues (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenancy_id VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    priority INT NOT NULL DEFAULT 0,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wasq_tenancy (tenancy_id),
    KEY idx_wasq_account (account_id),
    KEY idx_wasq_priority (status, priority),
    CONSTRAINT fk_wasq_account
        FOREIGN KEY (account_id) REFERENCES whatsapp_accounts(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_support_queue_agents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenancy_id VARCHAR(64) NOT NULL,
    queue_id INT UNSIGNED NOT NULL,
    agent_user_id INT UNSIGNED NOT NULL,
    max_simultaneous INT UNSIGNED NOT NULL DEFAULT 3,
    status ENUM('online', 'busy', 'offline') NOT NULL DEFAULT 'offline',
    last_assigned_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wasqa_queue_agent (queue_id, agent_user_id),
    KEY idx_wasqa_tenancy (tenancy_id),
    KEY idx_wasqa_agent_status (agent_user_id, status),
    KEY idx_wasqa_queue_status (queue_id, status),
    CONSTRAINT fk_wasqa_queue
        FOREIGN KEY (queue_id) REFERENCES whatsapp_support_queues(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_support_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenancy_id VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    conversation_id INT UNSIGNED NOT NULL,
    queue_id INT UNSIGNED NOT NULL,
    assigned_agent_user_id INT UNSIGNED NULL,
    state ENUM('waiting', 'active', 'finished') NOT NULL DEFAULT 'waiting',
    priority INT NOT NULL DEFAULT 0,
    is_vip TINYINT(1) NOT NULL DEFAULT 0,
    active_key VARCHAR(16) NULL,
    queued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    last_customer_message_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wass_conversation_open (conversation_id, active_key),
    KEY idx_wass_tenancy_state (tenancy_id, state),
    KEY idx_wass_queue_state_order (queue_id, state, priority, is_vip, queued_at),
    KEY idx_wass_agent_state (assigned_agent_user_id, state),
    KEY idx_wass_account (account_id),
    CONSTRAINT fk_wass_account
        FOREIGN KEY (account_id) REFERENCES whatsapp_accounts(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_wass_conversation
        FOREIGN KEY (conversation_id) REFERENCES whatsapp_conversations(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_wass_queue
        FOREIGN KEY (queue_id) REFERENCES whatsapp_support_queues(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_support_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenancy_id VARCHAR(64) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wase_tenancy_id (tenancy_id, id),
    KEY idx_wase_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
