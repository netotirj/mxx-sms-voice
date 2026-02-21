<?php

namespace App\Model\Entity;
use WilliamCosta\DatabaseManager\Database;

class RefillsResellers
{
    public int $id;
    public int $user_id;
    public string $tenancy_id;
    public string $email;
    public string $balance;
    public string $notes;
    public string $type;
    public string $transaction_id;
    public string $client_ip;
    public string $status;
    public string $created_at;
    public string $updated_at;

    /**
     * Inserir recarga na tabela refills
     */
    public function insertRefill(): int
    {

        $now = date('Y-m-d H:i:s');

        $fields = [
            'user_id'        => $this->user_id,
            'tenancy_id'     => $this->tenancy_id,
            'email'          => $this->email,
            'balance'        => $this->balance,
            'notes'          => $this->notes,
            'type'           => $this->type,
            'transaction_id' => $this->transaction_id,
            'client_ip'      => $this->client_ip,
            'status'         => $this->status ?? 'pending',
            'created_at'      => $this->createdAt ?? $now,
            'updated_at'      => $this->updatedAt ?? $now,
        ];

        $this->id = (new Database('refills'))->insert($fields);

        return $this->id;
    }

    /**
     * Buscar último registro de recarga por tenancy e usuário opcional
     */
    public static function getLastRefill(?int $userId, string $tenancyId): ?self
    {
        $where = "tenancy_id = :tenancy_id AND user_id = :user_id";
        $params = [
            ':tenancy_id' => $tenancyId,
            ':user_id' => $userId
        ];



        $data = (new Database('refills'))->select(
            "$where ORDER BY created_at DESC LIMIT 1",
            $params
        )->fetch();

        if (!$data) {
            return null;
        }

        $obj = new self();

        foreach ($data as $field => $value) {
            if (property_exists($obj, $field)) {
                $obj->$field = $value;
            }
        }

        return $obj;
    }

    public static function getValuesRefillCurrentMonth(?int $userId, ?string $tenancyId): array
    {
        $query = "
        SELECT 
            id,
            user_id,
            tenancy_id,
            balance,
            created_at
        FROM refills
        WHERE status = 'completed'
          AND YEAR(created_at) = YEAR(CURDATE())
          AND MONTH(created_at) = MONTH(CURDATE())
    ";

        $params = [];

        // Filtro por tenancy
        if (!empty($tenancyId)) {
            $query .= " AND tenancy_id = :tenancy_id";
            $params[':tenancy_id'] = $tenancyId;
        }

        // Filtro por user
        if (!empty($userId)) {
            $query .= " AND user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        // Ordenação
        $query .= " ORDER BY created_at ASC";

        return (new Database())->execute($query, $params)->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }


    /**
     * Contagem de transações do reseller
     */
    /*public static function getTransactionsCount(int $resellerId, ?string $searchValue = null): int
    {
        $query = "SELECT COUNT(*) as qtd FROM refills WHERE user_id = :user_id";
        $params = [':user_id' => $resellerId];

        if (!empty($searchValue)) {
            $query .= " AND (
                email LIKE :search OR 
                balance LIKE :search OR 
                notes LIKE :search OR 
                type LIKE :search OR 
                transaction_id LIKE :search OR 
                client_ip LIKE :search OR 
                status LIKE :search OR 
                created_at LIKE :search
            )";
            $params[':search'] = '%' . $searchValue . '%';
        }

        return (new Database)->execute($query, $params)
            ->fetchObject()
            ->qtd ?? 0;
    }*/

    public static function getTransactionsCount(?int $resellerId = null, ?string $searchValue = null, ?string $tenancyId = null): int
    {
        $query = "SELECT COUNT(*) as qtd FROM refills WHERE 1=1";
        $params = [];

        // 🔹 Condição para reseller
        if (!is_null($resellerId)) {
            $query .= " AND user_id = :user_id";
            $params[':user_id'] = $resellerId;
        }

        // 🔹 Condição para admin com tenancy
        if (is_null($resellerId) && !is_null($tenancyId)) {
            $query .= " AND tenancy_id = :tenancy_id";
            $params[':tenancy_id'] = $tenancyId;
        }

        if (!empty($searchValue)) {
            $query .= " AND (
            email LIKE :search OR 
            balance LIKE :search OR 
            notes LIKE :search OR 
            type LIKE :search OR 
            transaction_id LIKE :search OR 
            client_ip LIKE :search OR 
            status LIKE :search OR 
            created_at LIKE :search
        )";
            $params[':search'] = '%' . $searchValue . '%';
        }

        return (new Database)->execute($query, $params)
            ->fetchObject()
            ->qtd ?? 0;
    }


    /**
     * Lista transações paginadas do reseller
     */
    /*public static function getTransactionsPaginated(
        int     $resellerId,
        ?string $searchValue,
        int     $start,
        int     $length,
        string  $orderColumn = 'id',
        string  $orderDir = 'DESC'
    ): array {
        $params = [':user_id' => $resellerId];
        $where = "user_id = :user_id";

        if (!empty($searchValue)) {
            $where .= " AND (
                email LIKE :search OR 
                balance LIKE :search OR 
                notes LIKE :search OR 
                type LIKE :search OR 
                transaction_id LIKE :search OR 
                client_ip LIKE :search OR 
                status LIKE :search OR 
                created_at LIKE :search
            )";
            $params[':search'] = '%' . $searchValue . '%';
        }

        $order = "{$orderColumn} {$orderDir}";
        $limit = "{$start}, {$length}";

        $query = "
            SELECT 
                id,
                email,
                balance,
                notes,
                type,
                transaction_id,
                client_ip,
                status,
                created_at,
                updated_at
            FROM refills
            WHERE {$where}
            ORDER BY {$order}
            LIMIT {$limit}
        ";

        return (new Database)->execute($query, $params)
            ->fetchAll(\PDO::FETCH_OBJ);
    }*/
    public static function getTransactionsPaginated(
        ?int    $resellerId,
        ?string $searchValue,
        int     $start,
        int     $length,
        string  $orderColumn = 'id',
        string  $orderDir = 'DESC',
        ?string    $tenancyId = null
    ): array {
        $params = [];
        $where = "1=1";

        if (!is_null($resellerId)) {
            $where .= " AND user_id = :user_id";
            $params[':user_id'] = $resellerId;
        }

        if (is_null($resellerId) && !is_null($tenancyId)) {
            $where .= " AND tenancy_id = :tenancy_id";
            $params[':tenancy_id'] = $tenancyId;
        }

        if (!empty($searchValue)) {
            $where .= " AND (
            email LIKE :search OR 
            balance LIKE :search OR 
            notes LIKE :search OR 
            type LIKE :search OR 
            transaction_id LIKE :search OR 
            client_ip LIKE :search OR 
            status LIKE :search OR 
            created_at LIKE :search
        )";
            $params[':search'] = '%' . $searchValue . '%';
        }

        $order = "{$orderColumn} {$orderDir}";
        $limit = "{$start}, {$length}";

        $query = "
        SELECT 
            id,
            email,
            balance,
            notes,
            type,
            transaction_id,
            client_ip,
            status,
            created_at,
            updated_at
        FROM refills
        WHERE {$where}
        ORDER BY {$order}
        LIMIT {$limit}
    ";

        return (new Database)->execute($query, $params)
            ->fetchAll(\PDO::FETCH_OBJ);
    }

    public static function getById(int $id): ?\stdClass
    {
        $query = "
        SELECT 
            id,
            user_id,
            email,
            balance,
            notes,
            type,
            transaction_id,
            client_ip,
            status,
            created_at,
            updated_at
        FROM refills
        WHERE id = :id
        LIMIT 1
    ";

        $result = (new Database)->execute($query, [':id' => $id])->fetchObject();

        return $result ?: null;
    }





}