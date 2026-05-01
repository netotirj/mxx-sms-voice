<?php

namespace App\Model\Entity;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class BalanceSms
{
    public int $id;
    public string $tenancy_id;
    public ?int $user_id = null;
    public ?int $plan_id = null;
    public float $balance = 0.0;
    public float $value_sms = 0.0;
    public float $value_voice  = 0.0;
    public float $value_torpedo = 0.0;
    public float $value_whatsapp = 0.0;
    public float $service_fee = 0.0;
    public ?string $payment_invoice = null;
    public ?string $created_at = null;

    public ?string $updated_at = null;


    public static function getBalanceSms(?int $userId, ?string $tenancyId, ?int $planId = null): ?self
    {
        $db = new Database();

        // 1. Super Admin
        if ($userId === null && empty($tenancyId)) {
            $query = "SELECT * FROM tenancy_balance ORDER BY updated_at DESC LIMIT 1";
            return $db->execute($query)->fetchObject(self::class) ?: null;
        }

        // 2. Tenancy Total (Agregado)
        if ($userId === null && !empty($tenancyId)) {
            $query = "SELECT tenancy_id, SUM(balance) as balance, MAX(value_sms) as value_sms, MAX(updated_at) as updated_at 
                  FROM tenancy_balance WHERE tenancy_id = :tenancy_id";
            $params = [':tenancy_id' => $tenancyId];

            if ($planId) {
                $query .= " AND plan_id = :plan_id";
                $params[':plan_id'] = $planId;
            }
            $query .= " GROUP BY tenancy_id";
            return $db->execute($query, $params)->fetchObject(self::class) ?: null;
        }

        // 3. Usuário Específico
        $query = "SELECT * FROM tenancy_balance WHERE user_id = :user_id";
        $params = [':user_id' => $userId];

        if (!empty($tenancyId)) {
            $query .= " AND tenancy_id = :tenancy_id";
            $params[':tenancy_id'] = $tenancyId;
        }

        $result = $db->execute($query . " ORDER BY updated_at DESC LIMIT 1", $params)->fetchObject(self::class);

        // Fallback para tabela Users (Saldo de revendedor)
        if (!$result && $userId) {
            $reseller = $db->execute("SELECT id as user_id, reseller_balance as balance FROM users WHERE id = :id", [':id' => $userId])->fetchObject(self::class);
            if ($reseller) {
                $reseller->tenancy_id = $tenancyId;
                return $reseller;
            }
        }

        return $result;
    }


    public static function getSumBalanceSms(?int $userId, ?string $tenancyId): float
    {
        $query = "
        SELECT SUM(balance) as total_balance
        FROM tenancy_balance
        WHERE 1=1
    ";

        $params = [];

        // 🔹 Se tiver tenancy_id, aplica o filtro
        if (!empty($tenancyId)) {
            $query .= " AND tenancy_id = :tenancy_id";
            $params[':tenancy_id'] = $tenancyId;
        }

        // 🔹 Se tiver user_id, aplica o filtro
        if ($userId !== null) {
            $query .= " AND user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        $result = (new Database())->execute($query, $params)->fetchObject();

        return (float)($result->total_balance ?? 0);
    }


    public static function getBalanceSmsForPreviousMonths(?int $userId, ?string $tenancyId): array
    {
        $query = "
        SELECT
            DATE_FORMAT(created_at, '%Y-%m') AS mes,
            SUM(amount) AS gasto_mes
        FROM tenancy_balance_logs
        WHERE 1=1
    ";

        $params = [];

        // 🔹 Filtros opcionais
        if (!empty($tenancyId)) {
            $query .= " AND tenancy_id = :tenancy_id";
            $params[':tenancy_id'] = $tenancyId;
        }

        if ($userId !== null) {
            $query .= " AND user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        $query .= "
        GROUP BY mes
        ORDER BY mes DESC
        LIMIT 6
    ";

        $results = (new Database())->execute($query, $params)->fetchAll(\PDO::FETCH_OBJ);

        return $results ?: [];
    }


    public static function updateBalance(int $userId, string $tenancyId, int $planId, float $newBalance): bool
    {

        $query = "
        UPDATE tenancy_balance 
        SET 
            balance = :balance,
            updated_at = NOW()
        WHERE 
            user_id = :user_id 
            AND tenancy_id = :tenancy_id
            AND plan_id = :plan_id
    ";

        $params = [
            ':balance' => $newBalance,
            ':user_id' => $userId,
            ':tenancy_id' => $tenancyId,
            ':plan_id' => $planId
        ];

        return (new Database())->execute($query, $params)->rowCount() > 0;
    }

    public static function insertBalance(
        int $userId,
        int $planId,
        string $tenancyId,
        float $amountPlan,
        float $valueSms,
        float $valueVoice,
        float $valueTorpedo,
        ?string $paymentInvoice = null
    ): bool {
        // Se tiver invoice, verificar se já foi inserido
        if (!empty($paymentInvoice)) {
            $exists = (new Database('tenancy_balance'))
                ->select('payment_invoice = :invoice', [':invoice' => $paymentInvoice])
                ->fetch();

            if ($exists) {
                // Já existe crédito para esse pagamento
                return false;
            }
        }

        // Inserir novo saldo
        return (new Database('tenancy_balance'))->insert([
            'user_id'         => $userId,
            'plan_id'         => $planId,
            'tenancy_id'      => $tenancyId,
            'balance'         => $amountPlan,
            'value_sms'       => $valueSms,
            'value_voice'     => $valueVoice,
            'value_torpedo'   => $valueTorpedo,
            'payment_invoice' => $paymentInvoice,
            'updated_at'      => date('Y-m-d H:i:s')
        ]);
    }

    public static function insertBalanceLog(array $data): bool
    {

        $query = "
        INSERT INTO tenancy_balance_logs (user_id, tenancy_id, amount, description, created_at)
        VALUES (:user_id, :tenancy_id, :amount, :description, NOW())
    ";

        $params = [
            ':user_id' => $data['user_id'],
            ':tenancy_id' => $data['tenancy_id'],
            ':amount' => $data['amount'],
            ':description' => $data['description']
        ];

        return (new Database())->execute($query, $params)->rowCount() > 0;
    }

    public static function decrementBalance(int $userId, string $tenancyId, float $value, string $invoiceNumber): bool
    {
        $db = new Database();

        // 🔎 Buscar registro pelo invoiceNumber, user_id e tenancy_id
        $balanceData = $db->execute(
            "SELECT * FROM tenancy_balance 
         WHERE payment_invoice = :invoice 
           AND user_id = :user_id 
           AND tenancy_id = :tenancy_id
         LIMIT 1",
            [
                ':invoice'    => $invoiceNumber,
                ':user_id'    => $userId,
                ':tenancy_id' => $tenancyId
            ]
        )->fetch(PDO::FETCH_OBJ);

        if (!$balanceData) {
            return false; // invoice não encontrado
        }

        // 🔻 Calcula novo saldo (não permite saldo negativo)
        $newBalance = max(0, $balanceData->balance - $value);

        // 🔄 Atualiza saldo
        $updated = $db->execute(
            "UPDATE tenancy_balance 
         SET balance = :balance, updated_at = :updated_at 
         WHERE payment_invoice = :invoice
           AND user_id = :user_id
           AND tenancy_id = :tenancy_id",
            [
                ':balance'    => $newBalance,
                ':updated_at' => date('Y-m-d H:i:s'),
                ':invoice'    => $invoiceNumber,
                ':user_id'    => $userId,
                ':tenancy_id' => $tenancyId
            ]
        );

        return ($updated > 0);
    }

    public static function getByInvoice(string $invoiceNumber): ?self
    {
        $db = new Database('tenancy_balance');
        $data = $db->select("payment_invoice = '$invoiceNumber'")->fetchObject(self::class);
        return $data ?: null;
    }

    public static function decrementResellerBalance(float $amount, int $userId, string $tenancyId): bool
    {
        $query = "UPDATE users 
                  SET reseller_balance = reseller_balance - :amount
                  WHERE id = :id AND tenancy_id = :tenancy_id
                  AND reseller_balance >= :amount"; // evita saldo negativo

        $params = [
            ':amount'     => $amount,
            ':id'         => $userId,
            ':tenancy_id' => $tenancyId
        ];

        $db = new Database();
        $stmt = $db->execute($query, $params);

        return $stmt->rowCount() > 0;
    }

    public static function sumAdminServiceFeeFromLogs(string $tenancyId, int $adminUserId): float
{
    $sql = "
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM tenancy_balance_logs
        WHERE tenancy_id = :tenancy_id
          AND user_id    = :user_id
          AND description LIKE 'SERVICE_FEE_ADMIN%'
    ";

    $row = (new Database('tenancy_balance_logs'))
        ->execute($sql, [
            ':tenancy_id' => $tenancyId,
            ':user_id'    => $adminUserId,
        ])
        ->fetchObject();

    return (float)($row->total ?? 0);
}



}
