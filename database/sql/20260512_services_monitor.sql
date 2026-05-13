CREATE TABLE IF NOT EXISTS worker_heartbeats (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_name VARCHAR(160) NOT NULL,
    worker_type VARCHAR(80) NOT NULL,
    last_seen_at DATETIME NOT NULL,
    last_status VARCHAR(120) NOT NULL DEFAULT 'unknown',
    last_message VARCHAR(1000) NULL,
    processed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    failed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    memory_mb DECIMAL(10,2) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_worker_heartbeats_service (service_name),
    KEY idx_worker_heartbeats_worker_type (worker_type),
    KEY idx_worker_heartbeats_seen (last_seen_at),
    KEY idx_worker_heartbeats_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_monitor_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    tenancy_id VARCHAR(64) NOT NULL,
    action VARCHAR(80) NOT NULL,
    service_name VARCHAR(160) NOT NULL,
    ip_address VARCHAR(64) NULL,
    context_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_service_monitor_audit_user (user_id, created_at),
    KEY idx_service_monitor_audit_tenancy (tenancy_id, created_at),
    KEY idx_service_monitor_audit_service (service_name, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sys_routes (module_name, route_path)
VALUES
    ('Administrativo: Monitoramento de Serviços', '/admin/services-monitor'),
    ('Administrativo: Monitoramento de Serviços', '/admin/services-monitor/status'),
    ('Administrativo: Monitoramento de Serviços', '/admin/services-monitor/logs');
