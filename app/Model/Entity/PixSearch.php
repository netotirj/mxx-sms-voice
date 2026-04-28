<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;

class PixSearch
{
    public int $webhook_id;
    public int $user_id;
    public string $tenancy_id;
    public int $user_plain_id;
    public string $payment_status;
    public string $value;
    public ?string $pixQrCodeId = null;

    public ?string $external_Reference = null;
    public ?string $billingType = null;
    public int $invoiceNumber;
    public ?string $transactionReceiptUrl = null;
    public ?string $dateCreated = null;
    public ?string $confirmed_date = null;

    public function createPix(): bool
    {
        $this->webhook_id = (new Database('webhook_pix'))->insert([
            'user_id' => $this->user_id,
            'tenancy_id' => $this->tenancy_id,
            'user_plain_id' => $this->user_plain_id,
            'payment_status' => $this->payment_status,
            'value' => $this->value,
            'pixQrCodeId' => $this->pixQrCodeId,
            'external_Reference' => $this->external_Reference,
            'billingType' => $this->billingType,
            'invoiceNumber' => $this->invoiceNumber,
            'transactionReceiptUrl' => $this->transactionReceiptUrl,
            'dateCreated' => $this->dateCreated,
            'confirmed_date' => null,
        ]);

        return true;
    }


	public static function getPixByQrCode(string $pixQrCodeId, string $tenancyId, int $userId): ?self
    {
    $stmt = (new Database('webhook_pix'))->select(
        'pixQrCodeId = :pixQrCodeId AND tenancy_id = :tenancy_id AND user_id = :user_id',
        [
            ':pixQrCodeId' => $pixQrCodeId,
            ':tenancy_id'  => $tenancyId,
            ':user_id'     => $userId,
        ]
    );

    // Garante array associativo independente do default do PDO
    $dados = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$dados) {
        return null;
    }

    $obj = new self();

    // Normaliza snake_case / lowercased -> camelCase
    $toCamel = static function (string $key): string {
        // troca caracteres não alfanuméricos por underscore
        $k = preg_replace('/[^A-Za-z0-9_]+/', '_', $key);
        // tudo minúsculo para padronizar
        $k = strtolower($k);
        // snake_case -> CamelCase
        $k = str_replace(' ', '', ucwords(str_replace('_', ' ', $k)));
        // camelCase
        return lcfirst($k);
    };

    foreach ($dados as $campo => $valor) {
        // nomes candidatos para achar a propriedade
        $candidatos = [
            $campo,                 // nome como veio do fetch
            $toCamel($campo),       // versão camelCase
        ];

        $atribuido = false;

        foreach ($candidatos as $prop) {
            if (property_exists($obj, $prop)) {
                try {
                    $obj->{$prop} = $valor; // se tiver typed property, PHP fará a coerção básica
                    $atribuido = true;
                    break;
                } catch (\TypeError $e) {
                    // tentativa simples de coerção para tipos comuns
                    if (is_numeric($valor)) {
                        $obj->{$prop} = (str_contains((string)$valor, '.')) ? (float)$valor : (int)$valor;
                        $atribuido = true;
                        break;
                    }
                    if (is_string($valor) && ($valor === '0' || $valor === '1')) {
                        $obj->{$prop} = ($valor === '1');
                        $atribuido = true;
                        break;
                    }
                    // se ainda assim der ruim, apenas não atribui este campo
                }
            }
        }

        // Correção pontual para chaves “estranhas” que às vezes vêm do banco
        if (!$atribuido) {
            if (strtolower($campo) === 'external_reference' && property_exists($obj, 'externalReference')) {
                $obj->externalReference = $valor;
            } elseif (strtolower($campo) === 'confirmed_date' && property_exists($obj, 'confirmedDate')) {
                $obj->confirmedDate = $valor;
            } elseif (strtolower($campo) === 'webhook_id' && property_exists($obj, 'webhookId')) {
                $obj->webhookId = (int)$valor;
            }
        }
    }

    return $obj;
}

    public function updatePayment(): bool
    {
        $this->confirmed_date = date('Y-m-d H:i:s');

        $query = "
       UPDATE webhook_pix
    SET 
        payment_status        = :payment_status,
        value                 = :value,
        pixQrCodeId           = :pixQrCodeId,
        billingType           = :billingType,
        transactionReceiptUrl = :transactionReceiptUrl,
        confirmed_date        = :confirmed_date,
        invoiceNumber         = :invoiceNumber
    WHERE 
        pixQrCodeId = :pixQrCodeId
            
    ";

        $params = [
            ':payment_status' => $this->payment_status,
            ':value' => $this->value,
            ':pixQrCodeId' => $this->pixQrCodeId,
            ':billingType' => $this->billingType,
            ':transactionReceiptUrl' => $this->transactionReceiptUrl,
            ':confirmed_date' => $this->confirmed_date,
            ':invoiceNumber' => $this->invoiceNumber

        ];

        return (new Database())->execute($query, $params)->rowCount() > 0;
    }

    public static function getPixLast(?int $userId, ?string $tenancyId): ?self
    {
        $where = "1=1";
        $params = [];

        // 🔹 Filtros opcionais
        if (!empty($tenancyId)) {
            $where .= " AND tenancy_id = :tenancy_id";
            $params[':tenancy_id'] = $tenancyId;
        }

        if ($userId !== null) {
            $where .= " AND user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        $data = (new Database('webhook_pix'))->select(
            "$where ORDER BY confirmed_date DESC LIMIT 1",
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


    public static function getValuesPixCurrentMonth(?int $userId, ?string $tenancyId): array
    {
        $query = "
        SELECT 
            webhook_id,
            tenancy_id,
            user_id,
            value,
            confirmed_date
        FROM webhook_pix
        WHERE 1=1
          AND YEAR(confirmed_date) = YEAR(CURDATE())
          AND MONTH(confirmed_date) = MONTH(CURDATE())
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

        $query .= " ORDER BY confirmed_date ASC";

        return (new Database())->execute($query, $params)->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }



    public function updatePaymentRefunded(): bool
    {
        $this->confirmed_date = date('Y-m-d H:i:s');

        $db = new Database('webhook_pix');

        // 🔹 Se for reembolso, tratamos diferente
        if ($this->payment_status === 'PAYMENT_REFUNDED') {
            return $db->update(
                'invoiceNumber = ' . "'$this->invoiceNumber'",
                [
                    'payment_status'        => $this->payment_status,
                    'value'                 => $this->value, // pode salvar valor reembolsado
                    'pixQrCodeId'           => $this->pixQrCodeId,
                    'external_Reference'    => $this->external_Reference,
                    'billingType'           => $this->billingType,
                    'invoiceNumber'         => $this->invoiceNumber,
                    'transactionReceiptUrl' => $this->transactionReceiptUrl,
                    'confirmed_date'        => $this->confirmed_date, // 👈 cria coluna refund_date no banco
                ]
            );
        }
        return true;
    }

    public static function getByInvoice(string $invoiceNumber): ?self
    {
        $db = new Database('webhook_pix');
        $data = $db->select("invoiceNumber = '$invoiceNumber'")->fetchObject(self::class);

        return $data ?: null;
    }

    public static function getWebhookAsaasCount(string $tenancyId, ?string $searchValue = null): int
    {
        $query = "SELECT COUNT(*) as qtd FROM webhook_pix WHERE tenancy_id = :tenancy_id";
        $params = [':tenancy_id' => $tenancyId];

        if (!empty($searchValue)) {
            $query .= " AND (
            webhook_id LIKE :search OR 
            payment_status LIKE :search OR 
            value LIKE :search OR 
            invoiceNumber LIKE :search OR 
            transactionReceiptUrl LIKE :search OR 
            confirmed_date LIKE :search
        )";
            $params[':search'] = '%' . $searchValue . '%';
        }

        return (new Database)->execute($query, $params)
            ->fetchObject()
            ->qtd ?? 0;
    }

    /**
     * Método para buscar transações Pix (Padrão CDR)
     * Removemos o Start/Length para permitir paginação fluida no Front-end
     */
    public static function getWebhookAsaas(array $filters = [], string $order = 'confirmed_date DESC'): array
    {
        $params = [];
        $where = "1=1"; // Facilita a concatenação dos filtros

        // Filtro de Tenancy (Obrigatório por segurança)
        if (isset($filters['tenancy_id'])) {
            $where .= " AND tenancy_id = :tenancy_id";
            $params[':tenancy_id'] = $filters['tenancy_id'];
        }

        // Filtro de Usuário (Se houver)
        if (isset($filters['user_id'])) {
            $where .= " AND user_id = :user_id";
            $params[':user_id'] = $filters['user_id'];
        }

        // Note que não aplicamos o LIMIT aqui para seguir o padrão CDR
        $query = "
        SELECT 
            webhook_id,
            payment_status,
            value,
            invoiceNumber,
            transactionReceiptUrl,
            confirmed_date
        FROM webhook_pix
        WHERE {$where}
        ORDER BY {$order}
    ";

        return (new Database)->execute($query, $params)
            ->fetchAll(\PDO::FETCH_ASSOC); // Usando ASSOC para facilitar o array_map no Controller
    }


}
