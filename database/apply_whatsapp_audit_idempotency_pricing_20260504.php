<?php

require __DIR__ . '/../bootstrap/cli.php';

use WilliamCosta\DatabaseManager\Database;

$db = new Database();

$columnExists = static function (string $table, string $column) use ($db): bool {
    return (bool)$db
        ->execute("SHOW COLUMNS FROM {$table} LIKE :column", [':column' => $column])
        ->fetch(PDO::FETCH_ASSOC);
};

$indexExists = static function (string $table, string $index) use ($db): bool {
    return (bool)$db
        ->execute("SHOW INDEX FROM {$table} WHERE Key_name = :index", [':index' => $index])
        ->fetch(PDO::FETCH_ASSOC);
};

$columns = [
    ['whatsapp_outbox', 'idempotency_key', 'ALTER TABLE whatsapp_outbox ADD COLUMN idempotency_key CHAR(64) NULL AFTER id'],
    ['whatsapp_outbox', 'preview_body', 'ALTER TABLE whatsapp_outbox ADD COLUMN preview_body TEXT NULL AFTER body'],
    ['whatsapp_outbox', 'template_variables', 'ALTER TABLE whatsapp_outbox ADD COLUMN template_variables JSON NULL AFTER template_components'],
    ['whatsapp_outbox', 'pricing_snapshot', 'ALTER TABLE whatsapp_outbox ADD COLUMN pricing_snapshot JSON NULL AFTER price_brl'],
    ['whatsapp_messages', 'preview_body', 'ALTER TABLE whatsapp_messages ADD COLUMN preview_body TEXT NULL AFTER body'],
    ['whatsapp_messages', 'template_variables', 'ALTER TABLE whatsapp_messages ADD COLUMN template_variables JSON NULL AFTER payload'],
    ['whatsapp_messages', 'pricing_snapshot', 'ALTER TABLE whatsapp_messages ADD COLUMN pricing_snapshot JSON NULL AFTER template_variables'],
    ['whatsapp_campaign_recipients', 'template_variables', 'ALTER TABLE whatsapp_campaign_recipients ADD COLUMN template_variables JSON NULL AFTER name'],
    ['whatsapp_message_cdr', 'country_code', 'ALTER TABLE whatsapp_message_cdr ADD COLUMN country_code CHAR(2) NULL AFTER message_category'],
    ['whatsapp_message_cdr', 'cost_usd', 'ALTER TABLE whatsapp_message_cdr ADD COLUMN cost_usd DECIMAL(12, 6) NULL AFTER country_code'],
    ['whatsapp_message_cdr', 'exchange_rate', 'ALTER TABLE whatsapp_message_cdr ADD COLUMN exchange_rate DECIMAL(14, 6) NULL AFTER cost_usd'],
    ['whatsapp_message_cdr', 'effective_rate', 'ALTER TABLE whatsapp_message_cdr ADD COLUMN effective_rate DECIMAL(14, 6) NULL AFTER exchange_rate'],
    ['whatsapp_message_cdr', 'cost_brl', 'ALTER TABLE whatsapp_message_cdr ADD COLUMN cost_brl DECIMAL(12, 6) NULL AFTER effective_rate'],
    ['whatsapp_message_cdr', 'margin_percent', 'ALTER TABLE whatsapp_message_cdr ADD COLUMN margin_percent DECIMAL(8, 4) NULL AFTER cost_brl'],
    ['whatsapp_message_cdr', 'final_price_brl', 'ALTER TABLE whatsapp_message_cdr ADD COLUMN final_price_brl DECIMAL(10, 4) NULL AFTER margin_percent'],
    ['whatsapp_message_cdr', 'delivered_at', 'ALTER TABLE whatsapp_message_cdr ADD COLUMN delivered_at DATETIME NULL AFTER final_price_brl'],
    ['whatsapp_message_cdr', 'pricing_payload', 'ALTER TABLE whatsapp_message_cdr ADD COLUMN pricing_payload JSON NULL AFTER delivered_at'],
];

foreach ($columns as [$table, $column, $sql]) {
    if (!$columnExists($table, $column)) {
        $db->execute($sql);
    }
}

$db->execute("ALTER TABLE whatsapp_campaign_recipients MODIFY status ENUM('pending', 'queued', 'sent', 'failed') NOT NULL DEFAULT 'pending'");
$db->execute("ALTER TABLE whatsapp_message_cdr MODIFY message_category ENUM('marketing', 'utility', 'authentication', 'service') NOT NULL");

if (!$indexExists('whatsapp_outbox', 'uq_whatsapp_outbox_idempotency')) {
    $db->execute('ALTER TABLE whatsapp_outbox ADD UNIQUE KEY uq_whatsapp_outbox_idempotency (idempotency_key)');
}

if (!$indexExists('whatsapp_outbox', 'idx_whatsapp_outbox_template_dedupe')) {
    $db->execute('ALTER TABLE whatsapp_outbox ADD INDEX idx_whatsapp_outbox_template_dedupe (tenancy_id, account_id, contact_phone, template_name, status, created_at)');
}

if (!$indexExists('whatsapp_message_cdr', 'idx_whatsapp_cdr_pricing_country')) {
    $db->execute('ALTER TABLE whatsapp_message_cdr ADD INDEX idx_whatsapp_cdr_pricing_country (country_code, message_category, timestamp)');
}

echo "migration_ok\n";
