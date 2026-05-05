CREATE TABLE IF NOT EXISTS system_updates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenancy_id VARCHAR(64) NOT NULL DEFAULT 'global',
    created_by INT UNSIGNED NULL,
    target_user_id INT UNSIGNED NULL,
    module VARCHAR(120) NOT NULL DEFAULT 'Sistema',
    category ENUM('bug', 'update', 'correction', 'improvement', 'incident', 'maintenance') NOT NULL DEFAULT 'update',
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    status ENUM('planned', 'in_progress', 'done', 'cancelled') NOT NULL DEFAULT 'planned',
    visibility ENUM('public', 'internal') NOT NULL DEFAULT 'public',
    steps_json JSON NULL,
    cancellation_reason TEXT NULL,
    cancelled_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_system_updates_tenancy (tenancy_id),
    KEY idx_system_updates_target_user (target_user_id),
    KEY idx_system_updates_category (category),
    KEY idx_system_updates_status (status, visibility),
    KEY idx_system_updates_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE system_updates
SET tenancy_id = 'global'
WHERE tenancy_id IS NULL OR tenancy_id = '' OR tenancy_id <> 'global';

SET @add_system_updates_target_user = (
    SELECT IF(
        NOT EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'system_updates'
              AND COLUMN_NAME = 'target_user_id'
        ),
        'ALTER TABLE system_updates ADD COLUMN target_user_id INT UNSIGNED NULL AFTER created_by',
        'SELECT 1'
    )
);
PREPARE stmt FROM @add_system_updates_target_user;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_system_updates_target_user_idx = (
    SELECT IF(
        NOT EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'system_updates'
              AND INDEX_NAME = 'idx_system_updates_target_user'
        ),
        'ALTER TABLE system_updates ADD KEY idx_system_updates_target_user (target_user_id)',
        'SELECT 1'
    )
);
PREPARE stmt FROM @add_system_updates_target_user_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_system_updates_category := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'system_updates'
              AND COLUMN_NAME = 'category'
        ),
        'ALTER TABLE system_updates ADD COLUMN category ENUM(''bug'', ''update'', ''correction'', ''improvement'', ''incident'', ''maintenance'') NOT NULL DEFAULT ''update'' AFTER module',
        'SELECT 1'
    )
);
PREPARE stmt FROM @add_system_updates_category;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_system_updates_category_idx = (
    SELECT IF(
        NOT EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'system_updates'
              AND INDEX_NAME = 'idx_system_updates_category'
        ),
        'ALTER TABLE system_updates ADD KEY idx_system_updates_category (category)',
        'SELECT 1'
    )
);
PREPARE stmt FROM @add_system_updates_category_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_system_updates_cancellation_reason := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'system_updates'
              AND COLUMN_NAME = 'cancellation_reason'
        ),
        'ALTER TABLE system_updates ADD COLUMN cancellation_reason TEXT NULL AFTER steps_json',
        'SELECT 1'
    )
);
PREPARE stmt FROM @add_system_updates_cancellation_reason;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_system_updates_cancelled_at := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'system_updates'
              AND COLUMN_NAME = 'cancelled_at'
        ),
        'ALTER TABLE system_updates ADD COLUMN cancelled_at DATETIME NULL AFTER cancellation_reason',
        'SELECT 1'
    )
);
PREPARE stmt FROM @add_system_updates_cancelled_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Atualizações do Sistema', '/system-updates'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/system-updates');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Atualizações do Sistema', '/system-updates/list'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/system-updates/list');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Atualizações do Sistema', '/system-updates/users'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/system-updates/users');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Atualizações do Sistema', '/system-updates/create'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/system-updates/create');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Atualizações do Sistema', '/system-updates/update'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/system-updates/update');
