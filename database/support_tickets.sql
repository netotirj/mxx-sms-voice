CREATE TABLE IF NOT EXISTS support_tickets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenancy_id VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    requester_name VARCHAR(160) NULL,
    requester_phone VARCHAR(32) NULL,
    department ENUM('support', 'commercial', 'sales', 'finance') NOT NULL DEFAULT 'support',
    subject VARCHAR(160) NOT NULL,
    status ENUM('open', 'pending', 'closed') NOT NULL DEFAULT 'open',
    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    assigned_user_id INT UNSIGNED NULL,
    last_message TEXT NULL,
    last_message_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_support_tickets_tenancy (tenancy_id),
    KEY idx_support_tickets_user (user_id),
    KEY idx_support_tickets_status (status),
    KEY idx_support_tickets_last_message (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_ticket_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id INT UNSIGNED NOT NULL,
    sender_user_id INT UNSIGNED NULL,
    sender_type ENUM('customer', 'agent', 'bot', 'system') NOT NULL DEFAULT 'customer',
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_support_ticket_messages_ticket (ticket_id),
    CONSTRAINT fk_support_ticket_messages_ticket
        FOREIGN KEY (ticket_id) REFERENCES support_tickets(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
