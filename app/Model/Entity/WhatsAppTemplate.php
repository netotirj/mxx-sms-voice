<?php

namespace App\Model\Entity;

use PDO;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppTemplate
{
    private const SYSTEM_TEMPLATE = 'system';
    private const TENANT_TEMPLATE = 'tenant';

    public static function create(array $data): int
    {
        $componentsJson = isset($data['components'])
            ? json_encode($data['components'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        $values = [
            'tenancy_id' => $data['tenancy_id'],
            'user_id' => (int)$data['user_id'],
            'account_id' => $data['account_id'] ?? null,
            'waba_id' => $data['waba_id'] ?? null,
            'meta_template_id' => $data['meta_template_id'] ?? null,
            'name' => $data['name'],
            'language' => $data['language'] ?? 'pt_BR',
            'category' => $data['category'] ?? 'MARKETING',
            'body' => $data['body'] ?? null,
            'components' => $componentsJson,
            'variable_map' => isset($data['variable_map'])
                ? json_encode($data['variable_map'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'is_system_template' => !empty($data['is_system_template']) ? 1 : 0,
            'template_type' => self::normalizeTemplateType($data['template_type'] ?? null, !empty($data['is_system_template'])),
            'status' => $data['status'] ?? 'pending',
            'template_submitted_at' => $data['template_submitted_at'] ?? null,
            'template_approved_at' => $data['template_approved_at'] ?? null,
            'template_rejected_at' => $data['template_rejected_at'] ?? null,
            'template_last_sync_at' => $data['template_last_sync_at'] ?? null,
            'template_last_error' => $data['template_last_error'] ?? null,
            'meta_payload' => isset($data['meta_payload'])
                ? json_encode($data['meta_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if (self::hasColumn('components_json')) {
            $values['components_json'] = $componentsJson;
        }
        if (self::hasColumn('rejected_reason')) {
            $values['rejected_reason'] = $data['rejected_reason'] ?? null;
        }
        if (self::hasColumn('created_by')) {
            $values['created_by'] = (int)($data['created_by'] ?? $data['user_id']);
        }
        if (self::hasColumn('updated_by')) {
            $values['updated_by'] = (int)($data['updated_by'] ?? $data['user_id']);
        }

        return (int)(new Database('whatsapp_templates'))->insert($values);
    }

    public static function listForUser(array $user): array
    {
        $where = self::scopeForUser($user);

        $rows = (new Database('whatsapp_templates'))
            ->select($where, [], 'id DESC')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row) use ($user): array {
            $row['template_type'] = self::templateTypeOf($row);
            $row['is_system_template'] = self::isSystemTemplate($row) ? 1 : 0;
            $row['can_edit'] = self::canMutate($row, $user);
            $row['can_delete'] = self::canDelete($row, $user);
            $row['can_sync_status'] = self::canMutate($row, $user);
            return $row;
        }, $rows);
    }

    public static function getForUser(int $id, array $user): ?array
    {
        $where = 'id = :id AND (' . self::scopeForUser($user) . ')';
        $row = (new Database('whatsapp_templates'))
            ->select($where, [':id' => $id], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function getById(int $id): ?array
    {
        $row = (new Database('whatsapp_templates'))
            ->select('id = :id', [':id' => $id], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function getByNameForUser(string $name, string $language, array $user): ?array
    {
        $where = 'name = :name AND language = :language AND (' . self::scopeForUser($user) . ')';

        $row = (new Database('whatsapp_templates'))
            ->select($where, [
                ':name' => $name,
                ':language' => $language,
            ], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function getByNameForTenant(string $name, string $language, string $tenancyId): ?array
    {
        $row = (new Database('whatsapp_templates'))
            ->select(
                'tenancy_id = :tenancy_id AND name = :name AND language = :language',
                [
                    ':tenancy_id' => $tenancyId,
                    ':name' => $name,
                    ':language' => $language,
                ],
                '',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function findByMetaIdentity(string $tenancyId, ?string $wabaId, string $name, string $language): ?array
    {
        $where = 'tenancy_id = :tenancy_id AND name = :name AND language = :language';
        $params = [
            ':tenancy_id' => $tenancyId,
            ':name' => $name,
            ':language' => $language,
        ];

        if ($wabaId !== null && $wabaId !== '') {
            $where .= ' AND (waba_id = :waba_id OR waba_id IS NULL)';
            $params[':waba_id'] = $wabaId;
        }

        $row = (new Database('whatsapp_templates'))
            ->select($where, $params, '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function updateForUser(int $id, array $user, array $values): bool
    {
        $template = self::getForUser($id, $user);
        if (!$template || !self::canMutate($template, $user)) {
            return false;
        }

        $values['updated_at'] = date('Y-m-d H:i:s');
        unset($values['tenancy_id'], $values['user_id'], $values['is_system_template'], $values['template_type']);
        $ok = (new Database('whatsapp_templates'))->update(
            'id = :id AND tenancy_id = :tenancy_id',
            $values,
            [':id' => $id, ':tenancy_id' => (string)$template['tenancy_id']]
        );
        self::audit($id, $user, 'update', $template);
        return $ok;
    }

    public static function updateMetaStatus(int $id, string $status, array $meta = [], ?string $error = null): bool
    {
        return self::updateMetaStatusForUser($id, null, $status, $meta, $error);
    }

    public static function updateMetaStatusForUser(int $id, ?array $user, string $status, array $meta = [], ?string $error = null): bool
    {
        $template = self::getById($id);
        if (!$template) {
            return false;
        }
        if ($user !== null && (!self::canView($template, $user) || !self::canMutate($template, $user))) {
            return false;
        }

        $status = self::normalizeMetaStatus($status);
        $values = [
            'status' => $status,
            'template_last_sync_at' => date('Y-m-d H:i:s'),
            'template_last_error' => $error,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if (!empty($meta['id'])) {
            $values['meta_template_id'] = (string)$meta['id'];
        }
        if (!empty($meta['category'])) {
            $values['category'] = strtoupper((string)$meta['category']);
        }
        if (isset($meta['components']) && self::hasColumn('components_json')) {
            $values['components_json'] = json_encode($meta['components'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (isset($meta['components'])) {
            $values['components'] = json_encode($meta['components'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $values['body'] = self::extractBodyFromMeta($meta);
        }
        if (self::hasColumn('rejected_reason')) {
            $values['rejected_reason'] = self::metaRejectedReason($meta);
        }
        if ($user !== null && self::hasColumn('updated_by')) {
            $values['updated_by'] = (int)($user['id'] ?? 0);
        }
        if (!empty($meta)) {
            $values['meta_payload'] = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($status === 'approved') {
            $values['template_approved_at'] = date('Y-m-d H:i:s');
        }
        if ($status === 'rejected') {
            $values['template_rejected_at'] = date('Y-m-d H:i:s');
        }

        $ok = (new Database('whatsapp_templates'))->update(
            'id = :id AND tenancy_id = :tenancy_id',
            $values,
            [':id' => $id, ':tenancy_id' => (string)$template['tenancy_id']]
        );
        if ($user !== null) {
            self::audit($id, $user, 'update', $template);
        }
        return $ok;
    }

    public static function upsertFromMeta(array $account, array $template, ?array $actor = null): void
    {
        $name = (string)($template['name'] ?? '');
        $language = (string)($template['language'] ?? '');
        if ($name === '' || $language === '') {
            return;
        }

        $existing = self::findByMetaIdentity((string)$account['tenancy_id'], (string)($account['waba_id'] ?? ''), $name, $language);
        if ($existing) {
            if ($actor === null || self::canMutate($existing, $actor)) {
                self::updateMetaStatusForUser((int)$existing['id'], $actor, (string)($template['status'] ?? 'pending'), $template);
            }
            return;
        }

        $id = self::create([
            'tenancy_id' => $account['tenancy_id'],
            'user_id' => (int)$account['user_id'],
            'account_id' => (int)$account['id'],
            'waba_id' => $account['waba_id'] ?? null,
            'meta_template_id' => $template['id'] ?? null,
            'name' => $name,
            'language' => $language,
            'category' => strtoupper((string)($template['category'] ?? 'UTILITY')),
            'body' => self::extractBodyFromMeta($template),
            'components' => $template['components'] ?? null,
            'variable_map' => self::extractVariableMapFromMeta($template),
            'status' => self::normalizeMetaStatus((string)($template['status'] ?? 'pending')),
            'rejected_reason' => self::metaRejectedReason($template),
            'is_system_template' => false,
            'template_type' => self::TENANT_TEMPLATE,
            'template_last_sync_at' => date('Y-m-d H:i:s'),
            'meta_payload' => $template,
            'created_by' => (int)($actor['id'] ?? $account['user_id']),
            'updated_by' => (int)($actor['id'] ?? $account['user_id']),
        ]);
        if ($actor !== null) {
            self::audit($id, $actor, 'create', [
                'tenancy_id' => $account['tenancy_id'],
                'is_system_template' => 0,
                'template_type' => self::TENANT_TEMPLATE,
            ]);
        }
    }

    public static function disableMissingFromMeta(array $account, array $metaTemplates, ?array $actor = null): int
    {
        $seen = [];
        foreach ($metaTemplates as $template) {
            if (!is_array($template)) {
                continue;
            }

            $name = (string)($template['name'] ?? '');
            $language = (string)($template['language'] ?? '');
            if ($name !== '' && $language !== '') {
                $seen[$name . "\n" . $language] = true;
            }
        }

        $rows = (new Database('whatsapp_templates'))
            ->select(
                'account_id = :account_id AND tenancy_id = :tenancy_id AND is_system_template = 0 AND template_type <> :system_type',
                [
                    ':account_id' => (int)$account['id'],
                    ':tenancy_id' => (string)$account['tenancy_id'],
                    ':system_type' => self::SYSTEM_TEMPLATE,
                ]
            )
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $disabled = 0;
        foreach ($rows as $row) {
            if ($actor !== null && !self::canMutate($row, $actor)) {
                continue;
            }

            $key = (string)$row['name'] . "\n" . (string)$row['language'];
            if (isset($seen[$key])) {
                continue;
            }

            if ((string)($row['status'] ?? '') === 'disabled') {
                continue;
            }

            (new Database('whatsapp_templates'))->update(
                'id = :id AND tenancy_id = :tenancy_id',
                [
                    'status' => 'disabled',
                    'template_last_sync_at' => date('Y-m-d H:i:s'),
                    'template_last_error' => 'Template não retornado pela Meta nesta WABA.',
                    'updated_at' => date('Y-m-d H:i:s'),
                ],
                [
                    ':id' => (int)$row['id'],
                    ':tenancy_id' => (string)$row['tenancy_id'],
                ]
            );

            if ($actor !== null) {
                self::audit((int)$row['id'], $actor, 'update', $row);
            }
            $disabled++;
        }

        return $disabled;
    }

    public static function normalizeMetaStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === 'ready_to_submit') {
            return 'READY_TO_SUBMIT';
        }
        if ($status === 'review_manual') {
            return 'REVIEW_MANUAL';
        }

        return in_array($status, ['draft', 'pending', 'approved', 'rejected', 'paused', 'disabled'], true)
            ? $status
            : 'pending';
    }

    public static function approvedForUser(array $user, ?int $accountId = null, ?string $wabaId = null): array
    {
        $where = "status = 'approved' AND (" . self::scopeForUser($user) . ')';
        $params = [];
        if ($accountId !== null && $accountId > 0) {
            $where .= ' AND (account_id = :account_id OR account_id IS NULL)';
            $params[':account_id'] = $accountId;
        }
        if ($wabaId !== null && $wabaId !== '') {
            $where .= ' AND (waba_id = :waba_id OR waba_id IS NULL)';
            $params[':waba_id'] = $wabaId;
        }

        return (new Database('whatsapp_templates'))
            ->select($where, $params, 'name ASC, language ASC')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function deleteForUser(int $id, array $user): bool
    {
        $template = self::getForUser($id, $user);
        if (!$template || !self::canDelete($template, $user)) {
            return false;
        }

        $ok = (new Database('whatsapp_templates'))->delete(
            'id = :id AND tenancy_id = :tenancy_id',
            [':id' => $id, ':tenancy_id' => (string)$template['tenancy_id']]
        );
        self::audit($id, $user, 'delete', $template);
        return $ok;
    }

    public static function audit(int $templateId, array $user, string $action, array $template = []): void
    {
        try {
            $db = new Database('whatsapp_template_audit_logs');
            self::ensureAuditTable($db);
            $db->insert([
                'template_id' => $templateId,
                'user_id' => (int)($user['id'] ?? 0),
                'tenancy_id' => (string)($template['tenancy_id'] ?? $user['tenancy_id'] ?? ''),
                'action' => $action,
                'template_type' => self::templateTypeOf($template),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[whatsapp_template_audit] ' . $e->getMessage());
        }
    }

    private static function ensureAuditTable(Database $db): void
    {
        $db->execute("
            CREATE TABLE IF NOT EXISTS whatsapp_template_audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                template_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                tenancy_id VARCHAR(64) NOT NULL,
                action VARCHAR(32) NOT NULL,
                template_type ENUM('system', 'tenant') NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_whatsapp_template_audit_template (template_id, created_at),
                KEY idx_whatsapp_template_audit_user (user_id, created_at),
                KEY idx_whatsapp_template_audit_tenancy (tenancy_id, created_at),
                KEY idx_whatsapp_template_audit_action (action, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public static function canMutate(array $template, array $user): bool
    {
        if (self::isSuperAdmin($user)) {
            return true;
        }

        if (self::isSystemTemplate($template)) {
            return false;
        }

        return (string)($template['tenancy_id'] ?? '') !== ''
            && (string)($template['tenancy_id'] ?? '') === (string)($user['tenancy_id'] ?? '');
    }

    public static function canDelete(array $template, array $user): bool
    {
        return self::isSuperAdmin($user);
    }

    private static function canView(array $template, array $user): bool
    {
        return self::isSuperAdmin($user)
            || (
                (string)($template['tenancy_id'] ?? '') !== ''
                && (string)($template['tenancy_id'] ?? '') === (string)($user['tenancy_id'] ?? '')
            );
    }

    private static function scopeForUser(array $user): string
    {
        if (self::isSuperAdmin($user)) {
            return '1=1';
        }

        $tenancyId = "'" . addslashes((string)($user['tenancy_id'] ?? '')) . "'";
        $userId = (int)($user['id'] ?? 0);
        $role = strtolower((string)($user['user_function'] ?? $user['function'] ?? ''));
        $system = '(is_system_template = 1 OR template_type = ' . "'system'" . ')';
        $tenant = "tenancy_id = {$tenancyId}";

        if (in_array($role, ['admin', 'rh', 'financial', 'reception', 'manager', 'supervisor', 'monitor', 'support_l2'], true)) {
            return $tenant;
        }

        if ($role === 'reseller') {
            return "{$tenant} AND ({$system} OR user_id = {$userId} OR user_id IN (
                SELECT id FROM users WHERE user_id = {$userId} AND tenancy_id = {$tenancyId}
            ))";
        }

        return "{$tenant} AND ({$system} OR user_id = {$userId})";
    }

    private static function isSuperAdmin(array $user): bool
    {
        return strtolower((string)($user['user_function'] ?? $user['function'] ?? '')) === 'super_admin';
    }

    private static function isSystemTemplate(array $template): bool
    {
        return (int)($template['is_system_template'] ?? 0) === 1
            || strtolower((string)($template['template_type'] ?? '')) === self::SYSTEM_TEMPLATE;
    }

    private static function templateTypeOf(array $template): string
    {
        return self::isSystemTemplate($template) ? self::SYSTEM_TEMPLATE : self::TENANT_TEMPLATE;
    }

    private static function normalizeTemplateType(?string $type, bool $isSystem): string
    {
        $type = strtolower(trim((string)$type));
        if (in_array($type, [self::SYSTEM_TEMPLATE, self::TENANT_TEMPLATE], true)) {
            return $type;
        }

        return $isSystem ? self::SYSTEM_TEMPLATE : self::TENANT_TEMPLATE;
    }

    private static function extractBodyFromMeta(array $template): ?string
    {
        foreach (($template['components'] ?? []) as $component) {
            if (strtoupper((string)($component['type'] ?? '')) === 'BODY') {
                return (string)($component['text'] ?? '') ?: null;
            }
        }

        return null;
    }

    private static function extractVariableMapFromMeta(array $template): ?array
    {
        $payloadMap = $template['variable_map'] ?? $template['semantic_variable_map'] ?? null;
        if (is_array($payloadMap)) {
            return $payloadMap;
        }

        $body = self::extractBodyFromMeta($template);
        if (!$body) {
            return null;
        }

        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $matches);
        $numbers = array_values(array_unique(array_map('intval', $matches[1] ?? [])));
        sort($numbers);
        if ($numbers === []) {
            return null;
        }

        $map = [];
        foreach ($numbers as $number) {
            $map[(string)$number] = [
                'key' => 'variavel_' . $number,
                'description' => 'Variavel posicional importada da Meta.',
            ];
        }

        return $map;
    }

    private static function metaRejectedReason(array $meta): ?string
    {
        foreach (['rejected_reason', 'rejection_reason', 'reason'] as $key) {
            $value = trim((string)($meta[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function hasColumn(string $column): bool
    {
        static $columns = null;
        if ($columns === null) {
            try {
                $rows = (new Database())->execute('SHOW COLUMNS FROM whatsapp_templates')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $columns = array_fill_keys(array_map(static fn ($row) => (string)$row['Field'], $rows), true);
            } catch (\Throwable $e) {
                $columns = [];
            }
        }

        return isset($columns[$column]);
    }
}
