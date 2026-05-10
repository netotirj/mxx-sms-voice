<?php

namespace App\Model\Entity;

use App\Service\AuthContext;
use App\Service\PlanRuntimeService;
use App\Support\RequestCache;
use PDOStatement;
use WilliamCosta\DatabaseManager\Database;

class RegisterTenancies
{
    private static array $tableExistsCache = [];
    private static array $columnExistsCache = [];

    public string $id;
    public string $name;
    public string $tenancy_phone;
    public string $status;
    public string $created_at;
    public string $updated_at;
    public string $account_code;


    public function insertUserTenancy(): bool
    {
        (new Database('tenancies'))->insert([
            'id'            => $this->id,
            'name'          => $this->name,
            'tenancy_phone' => $this->tenancy_phone,
            'account_code'  => $this->account_code, // ✅ NOVO
            'status'        => $this->status,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at
        ]);

        return true;
    }

    public static function accountCodeExists(string $accountCode): bool
    {
        $db = new Database();

        $query = "SELECT 1 FROM tenancies WHERE account_code = :code LIMIT 1";
        $params = [':code' => $accountCode];

        return (bool) $db->execute($query, $params)->fetchColumn();
    }

    public static function phoneExists(string $phone): bool
    {
        $phone = preg_replace('/\D+/', '', $phone) ?: '';
        if ($phone === '') {
            return false;
        }

        $db = new Database();

        $query = "SELECT 1 FROM tenancies WHERE tenancy_phone = :phone LIMIT 1";
        $params = [':phone' => $phone];

        return (bool) $db->execute($query, $params)->fetchColumn();
    }

    /**
     * Atualiza o plano ativo da tenancy
     *
     * @param string $tenancyId
     * @param int $userPlanId
     * @return bool
     */
    public static function updateActivePlan(string $tenancyId, int $userPlanId): bool
    {
        $query = "UPDATE tenancies SET active_plan_id = :plan_id WHERE id = :tenancy_id";
        $params = [
            ':plan_id'     => $userPlanId,
            ':tenancy_id'  => $tenancyId
        ];

        $updated = (new Database())->execute($query, $params) != false;
        if ($updated) {
            PlanRuntimeService::invalidatePlanCache($tenancyId);
            AuthContext::invalidate($tenancyId);
        }

        return $updated;
    }

    /**
     * Retorna o ID do plano ativo da tenancy
     *
     * @param string $tenancyId
     * @return int|null
     */
    public static function getActivePlanId(string $tenancyId): ?int
    {
        return RequestCache::remember('tenancy.active_plan.' . $tenancyId, static function () use ($tenancyId): ?int {
            $query = "SELECT active_plan_id FROM tenancies WHERE id = :id";
            $params = [':id' => $tenancyId];
            $result = (new Database())->execute($query, $params)->fetchObject();

            return $result && $result->active_plan_id ? (int)$result->active_plan_id : null;
        });
    }

    public static function getTenancyByAdminEmail(string $email): ?object
    {
        $db = new Database();

        $query = "SELECT u.id as user_id,
                     u.email,
                     u.user_function,
                     u.tenancy_id,
                     u.name,
                     t.tenancy_phone,
                     t.account_code
              FROM users u
              INNER JOIN tenancies t ON t.id = u.tenancy_id
              WHERE u.email = :email
                AND u.user_function = 'admin'
              LIMIT 1";

        $params = [':email' => $email];

        $result = $db->execute($query, $params)->fetchObject();

        if (!$result) {
            // E-mail não existe ou não é admin → retorna null
            return null;
        }

        // Mascarar telefone antes de retornar
        $phone = $result->tenancy_phone;
        $result->maskedPhone = substr($phone, 0, 2) . "*****" . substr($phone, -2);

        return $result;
    }

    public static function getTenancyOwnerUserId(string $tenancyId): ?int
    {
        $db = new Database();

        $query = "SELECT u.id
              FROM users u
              WHERE u.tenancy_id = :tenancy_id
                AND u.user_function = 'admin'
              LIMIT 1";

        $params = [':tenancy_id' => $tenancyId];

        $result = $db->execute($query, $params)->fetchObject();

        return $result ? (int)$result->id : null;
    }


    /**
     * Salva o código de reset na tabela password_resets
     *
     * @param string $tenancyId
     * @param int $userId
     * @param string $code
     * @param int $expireSeconds
     * @return PDOStatement
     */
    public static function save(string $tenancyId, int $userId, string $code, int $expireSeconds): PDOStatement
    {
        $db = new Database();

        $expiresAt = date('Y-m-d H:i:s', time() + $expireSeconds);

        $query = "INSERT INTO password_resets (user_id, tenancy_id, code, expires_at) 
                  VALUES (:user_id, :tenancy_id, :code, :expires_at)";

        $params = [
            ':user_id'    => $userId,
            ':tenancy_id' => $tenancyId,
            ':code'       => $code,
            ':expires_at' => $expiresAt
        ];

        return $db->execute($query, $params);
    }

    public static function validateResetCode(string $code, int $userId): ?object
    {
        $db = new Database();

        $query = "SELECT * FROM password_resets
              WHERE user_id = :user_id
                AND code = :code
                AND expires_at >= NOW()
                AND used = 0
              LIMIT 1";

        $params = [
            ':user_id' => $userId,
            ':code'    => $code
        ];

        $result = $db->execute($query, $params)->fetchObject();

        return $result ?: null; // se não encontrar, código inválido ou expirado
    }

    public static function markCodeUsed(int $id): void
    {
        $db = new Database();
        $query = "UPDATE password_resets SET used = 1 WHERE id = :id";
        $db->execute($query, [':id' => $id]);
    }

    /**
     * Inicializa a estrutura de permissões para uma nova Tenancy
     */
    public function setupInitialPermissions(): void
    {
        // 1. Criar os papéis padrão para esta nova Tenancy
        // Usando a nova tabela 'sys_roles'
        $roleAdminId = \App\Model\Entity\PermissionsRules::createRole(
            $this->id,
            'admin',
            'Administrador'
        );

        \App\Model\Entity\PermissionsRules::createRole(
            $this->id,
            'operator',
            'Operador'
        );

        // 2. Liberar apenas as rotas padrão da tenancy para o Admin deste novo cliente.
        // Rotas reservadas ao super administrador ficam fora desse papel inicial.
        \App\Model\Entity\PermissionsRules::initAdminPermissions($this->id, $roleAdminId);
    }

    public static function deleteTenancyTree(string $tenancyId): array
    {
        $tenancyId = trim($tenancyId);
        if ($tenancyId === '') {
            throw new \InvalidArgumentException('Tenancy inválida para exclusão.');
        }

        $db = new Database();
        $tenancy = (new Database('tenancies'))
            ->select('id = :id', [':id' => $tenancyId], '', '1')
            ->fetchObject();

        if (!$tenancy) {
            throw new \RuntimeException('Tenancy não encontrada.');
        }

        $summary = [
            'tenancy_id' => $tenancyId,
            'deleted' => [],
        ];

        $context = self::collectDeleteContext($db, $tenancyId);

        $db->beginTransaction();
        try {
            self::deleteByIds($db, $summary, 'support_ticket_messages', 'ticket_id', $context['support_ticket_ids']);
            self::deleteByIds($db, $summary, 'voice_list_contacts', 'voice_list_id', $context['voice_list_ids']);
            self::deleteByIds($db, $summary, 'whatsapp_display_name_approval_events', 'whatsapp_number_id', $context['whatsapp_number_ids']);
            self::deleteByIds($db, $summary, 'whatsapp_number_quality_events', 'account_id', $context['whatsapp_account_ids']);
            self::deleteByIds($db, $summary, 'whatsapp_marketing_opt_outs', 'account_id', $context['whatsapp_account_ids']);
            self::deleteByIds($db, $summary, 'whatsapp_campaign_recipients', 'campaign_id', $context['whatsapp_campaign_ids']);

            self::deleteByColumn($db, $summary, 'whatsapp_outbox', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'whatsapp_support_sessions', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'whatsapp_support_queue_history', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'whatsapp_support_queue_agents', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'whatsapp_support_events', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'whatsapp_template_event_logs', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'whatsapp_message_cdr', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'whatsapp_number_health', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'whatsapp_templates', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'number_requests', 'company_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'whatsapp_number_requests', 'company_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'whatsapp_campaigns', 'tenancy_id', $tenancyId);
            self::deleteByIds($db, $summary, 'whatsapp_messages', 'conversation_id', $context['whatsapp_conversation_ids']);
            self::deleteByIds($db, $summary, 'whatsapp_messages', 'account_id', $context['whatsapp_account_ids']);
            self::deleteByColumn($db, $summary, 'whatsapp_conversations', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'whatsapp_support_queues', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'whatsapp_numbers', 'company_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'whatsapp_accounts', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'whatsapp_meta_rate_limit_logs', 'tenant_id', $tenancyId);

            self::deleteByColumn($db, $summary, 'contacts', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'campaign_batches', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'campaign', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'campaign_voice_schedules', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'campaign_voice', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'voice_list', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'queue_members', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'queues_config', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'pausas_logs', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'pausas_config', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'support_ticket_audit_logs', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'support_tickets', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'notifications', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'password_resets', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'callback', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'cdr', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'webhook_pix', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'refills', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'reseller_rates', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'reseller_whatsapp_pricing', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'mxx_user_plans', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'tenancy_balance_logs', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'tenancy_balance', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'system_updates', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'agentes_portal', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'address', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'sys_role_permissions', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'user_roles', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'sys_roles', 'tenancy_id', $tenancyId);
            self::deleteByColumn($db, $summary, 'users', 'tenancy_id', $tenancyId);

            self::deleteByIds($db, $summary, 'whatsapp_pricing_margins', 'customer_id', $context['user_ids']);
            self::deleteByIds($db, $summary, 'whatsapp_pricing_price_floors', 'customer_id', $context['user_ids']);

            $stmt = $db->run('DELETE FROM tenancies WHERE id = :tenancy_id', [
                ':tenancy_id' => $tenancyId,
            ]);
            $summary['deleted']['tenancies'] = $stmt->rowCount();

            $db->commit();

            error_log(json_encode([
                'event' => 'tenancy_tree_deleted',
                'tenancy_id' => $tenancyId,
                'deleted' => $summary['deleted'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $summary;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log(json_encode([
                'event' => 'tenancy_tree_delete_failed',
                'tenancy_id' => $tenancyId,
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            throw $e;
        }
    }

    private static function collectDeleteContext(Database $db, string $tenancyId): array
    {
        return [
            'user_ids' => self::fetchIds($db, 'users', 'id', 'tenancy_id = :tenancy_id', [':tenancy_id' => $tenancyId]),
            'support_ticket_ids' => self::fetchIds($db, 'support_tickets', 'id', 'tenancy_id = :tenancy_id', [':tenancy_id' => $tenancyId]),
            'voice_list_ids' => self::fetchIds($db, 'voice_list', 'id', 'tenancy_id = :tenancy_id', [':tenancy_id' => $tenancyId]),
            'whatsapp_account_ids' => self::fetchIds($db, 'whatsapp_accounts', 'id', 'tenancy_id = :tenancy_id', [':tenancy_id' => $tenancyId]),
            'whatsapp_campaign_ids' => self::fetchIds($db, 'whatsapp_campaigns', 'id', 'tenancy_id = :tenancy_id', [':tenancy_id' => $tenancyId]),
            'whatsapp_conversation_ids' => self::fetchIds($db, 'whatsapp_conversations', 'id', 'tenancy_id = :tenancy_id', [':tenancy_id' => $tenancyId]),
            'whatsapp_number_ids' => self::fetchIds($db, 'whatsapp_numbers', 'id', 'company_id = :tenancy_id', [':tenancy_id' => $tenancyId]),
        ];
    }

    private static function fetchIds(
        Database $db,
        string $table,
        string $idColumn,
        string $where,
        array $params
    ): array {
        if (!self::tableExists($db, $table) || !self::columnExists($db, $table, $idColumn)) {
            return [];
        }

        $rows = $db->run(
            sprintf('SELECT %s FROM %s WHERE %s', self::quoteIdentifier($idColumn), self::quoteIdentifier($table), $where),
            $params
        )->fetchAll(\PDO::FETCH_COLUMN);

        if ($rows === false) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static function ($value) {
            if ($value === null || $value === '') {
                return null;
            }
            return ctype_digit((string)$value) ? (int)$value : (string)$value;
        }, $rows), static fn ($value) => $value !== null)));
    }

    private static function deleteByColumn(
        Database $db,
        array &$summary,
        string $table,
        string $column,
        string $value
    ): void {
        if (!self::tableExists($db, $table) || !self::columnExists($db, $table, $column)) {
            return;
        }

        $stmt = $db->run(
            sprintf('DELETE FROM %s WHERE %s = :value', self::quoteIdentifier($table), self::quoteIdentifier($column)),
            [':value' => $value]
        );
        $summary['deleted'][$table] = ($summary['deleted'][$table] ?? 0) + $stmt->rowCount();
    }

    private static function deleteByIds(
        Database $db,
        array &$summary,
        string $table,
        string $column,
        array $ids
    ): void {
        if ($ids === [] || !self::tableExists($db, $table) || !self::columnExists($db, $table, $column)) {
            return;
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $key = ':id_' . $index;
            $placeholders[] = $key;
            $params[$key] = $id;
        }

        $stmt = $db->run(
            sprintf(
                'DELETE FROM %s WHERE %s IN (%s)',
                self::quoteIdentifier($table),
                self::quoteIdentifier($column),
                implode(', ', $placeholders)
            ),
            $params
        );
        $summary['deleted'][$table] = ($summary['deleted'][$table] ?? 0) + $stmt->rowCount();
    }

    private static function tableExists(Database $db, string $table): bool
    {
        $cacheKey = strtolower($table);
        if (array_key_exists($cacheKey, self::$tableExistsCache)) {
            return self::$tableExistsCache[$cacheKey];
        }

        $exists = (bool)$db->run(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1',
            [':table' => $table]
        )->fetchColumn();

        self::$tableExistsCache[$cacheKey] = $exists;
        return $exists;
    }

    private static function columnExists(Database $db, string $table, string $column): bool
    {
        $cacheKey = strtolower($table . '.' . $column);
        if (array_key_exists($cacheKey, self::$columnExistsCache)) {
            return self::$columnExistsCache[$cacheKey];
        }

        $exists = (bool)$db->run(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column LIMIT 1',
            [
                ':table' => $table,
                ':column' => $column,
            ]
        )->fetchColumn();

        self::$columnExistsCache[$cacheKey] = $exists;
        return $exists;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }


}
