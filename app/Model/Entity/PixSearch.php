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
    public $invoiceNumber = null;
    public ?string $transactionReceiptUrl = null;
    public ?string $asaas_payment_id = null;
    public ?string $asaas_customer_id = null;
    public ?string $invoice_url = null;
    public ?string $pix_payload = null;
    public ?string $pix_encoded_image = null;
    public ?string $due_date = null;
    public ?string $payment_date = null;
    public ?string $last_webhook_event = null;
    public ?string $last_webhook_payload = null;
    public ?string $dateCreated = null;
    public ?string $confirmed_date = null;
    public ?string $updated_at = null;

    public static function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        $db = new Database();
        $columns = [
            'asaas_payment_id' => "ALTER TABLE webhook_pix ADD COLUMN asaas_payment_id VARCHAR(64) NULL AFTER transactionReceiptUrl",
            'asaas_customer_id' => "ALTER TABLE webhook_pix ADD COLUMN asaas_customer_id VARCHAR(64) NULL AFTER asaas_payment_id",
            'invoice_url' => "ALTER TABLE webhook_pix ADD COLUMN invoice_url VARCHAR(255) NULL AFTER asaas_customer_id",
            'pix_payload' => "ALTER TABLE webhook_pix ADD COLUMN pix_payload LONGTEXT NULL AFTER invoice_url",
            'pix_encoded_image' => "ALTER TABLE webhook_pix ADD COLUMN pix_encoded_image LONGTEXT NULL AFTER pix_payload",
            'due_date' => "ALTER TABLE webhook_pix ADD COLUMN due_date DATE NULL AFTER pix_encoded_image",
            'payment_date' => "ALTER TABLE webhook_pix ADD COLUMN payment_date DATETIME NULL AFTER due_date",
            'last_webhook_event' => "ALTER TABLE webhook_pix ADD COLUMN last_webhook_event VARCHAR(64) NULL AFTER payment_date",
            'last_webhook_payload' => "ALTER TABLE webhook_pix ADD COLUMN last_webhook_payload LONGTEXT NULL AFTER last_webhook_event",
            'updated_at' => "ALTER TABLE webhook_pix ADD COLUMN updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER confirmed_date",
        ];

        foreach ($columns as $column => $sql) {
            if (!self::columnExists('webhook_pix', $column)) {
                $db->execute($sql);
            }
        }

        if (!self::tableExists('pix_api_logs')) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS pix_api_logs (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    webhook_id INT NULL,
                    user_id INT UNSIGNED NULL,
                    tenancy_id CHAR(36) NULL,
                    event_name VARCHAR(64) NOT NULL,
                    level VARCHAR(16) NOT NULL DEFAULT 'info',
                    payment_id VARCHAR(64) NULL,
                    invoice_number VARCHAR(64) NULL,
                    pix_qr_code_id VARCHAR(64) NULL,
                    message TEXT NULL,
                    context_json LONGTEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_pix_api_logs_webhook (webhook_id),
                    KEY idx_pix_api_logs_tenancy_user (tenancy_id, user_id),
                    KEY idx_pix_api_logs_payment (payment_id, invoice_number, pix_qr_code_id),
                    KEY idx_pix_api_logs_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        $checked = true;
    }

    private static function hydrate(array|object $data): self
    {
        $row = is_object($data) ? get_object_vars($data) : $data;
        $obj = new self();

        foreach ($row as $field => $value) {
            if (property_exists($obj, $field)) {
                $obj->$field = $value;
            }
        }

        return $obj;
    }

    private static function normalizeInvoice(mixed $invoice): ?string
    {
        $invoice = trim((string)($invoice ?? ''));
        if ($invoice === '' || $invoice === '0') {
            return null;
        }

        return ctype_digit($invoice) ? $invoice : null;
    }

    private static function normalizeDate(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d', $timestamp) : null;
    }

    private static function normalizeDateTime(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private static function providerPaymentId(array $payment): ?string
    {
        $value = trim((string)($payment['id'] ?? $payment['paymentId'] ?? $payment['asaas_payment_id'] ?? ''));
        return $value !== '' ? $value : null;
    }

    private static function providerCustomerId(array $payment): ?string
    {
        $value = trim((string)($payment['customer'] ?? $payment['customerId'] ?? $payment['asaas_customer_id'] ?? ''));
        return $value !== '' ? $value : null;
    }

    private static function providerInvoiceUrl(array $payment): ?string
    {
        $value = trim((string)($payment['invoiceUrl'] ?? $payment['transactionReceiptUrl'] ?? $payment['invoice_url'] ?? ''));
        return $value !== '' ? $value : null;
    }

    private static function providerPayloadJson(array $payment): ?string
    {
        return json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    private static function tableExists(string $table): bool
    {
        return (bool)(new Database())->execute(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1',
            [':table' => $table]
        )->fetchColumn();
    }

    private static function columnExists(string $table, string $column): bool
    {
        return (bool)(new Database())->execute(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column LIMIT 1',
            [
                ':table' => $table,
                ':column' => $column,
            ]
        )->fetchColumn();
    }

    public function createPix(): bool
    {
        self::ensureSchema();
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
            'asaas_payment_id' => $this->asaas_payment_id,
            'asaas_customer_id' => $this->asaas_customer_id,
            'invoice_url' => $this->invoice_url,
            'pix_payload' => $this->pix_payload,
            'pix_encoded_image' => $this->pix_encoded_image,
            'due_date' => $this->due_date,
            'payment_date' => $this->payment_date,
            'last_webhook_event' => $this->last_webhook_event,
            'last_webhook_payload' => $this->last_webhook_payload,
            'dateCreated' => $this->dateCreated,
            'confirmed_date' => null,
        ]);

        return true;
    }

    public static function getPixByQrCode(string $pixQrCodeId, string $tenancyId, int $userId): ?self
    {
        self::ensureSchema();
        $data = (new Database('webhook_pix'))->select(
            'pixQrCodeId = :pixQrCodeId AND tenancy_id = :tenancy_id AND user_id = :user_id',
            [
                ':pixQrCodeId' => $pixQrCodeId,
                ':tenancy_id' => $tenancyId,
                ':user_id' => $userId,
            ]
        )->fetch(\PDO::FETCH_ASSOC);

        return $data ? self::hydrate($data) : null;
    }

    public function updatePayment(): bool
    {
        self::ensureSchema();
        $this->confirmed_date = date('Y-m-d H:i:s');

        $query = "
            UPDATE webhook_pix
            SET
                payment_status = :payment_status,
                value = :value,
                pixQrCodeId = :pixQrCodeId,
                billingType = :billingType,
                transactionReceiptUrl = :transactionReceiptUrl,
                invoice_url = :invoice_url,
                payment_date = :payment_date,
                confirmed_date = :confirmed_date,
                invoiceNumber = :invoiceNumber
            WHERE pixQrCodeId = :pixQrCodeId
        ";

        $params = [
            ':payment_status' => $this->payment_status,
            ':value' => $this->value,
            ':pixQrCodeId' => $this->pixQrCodeId,
            ':billingType' => $this->billingType,
            ':transactionReceiptUrl' => $this->transactionReceiptUrl,
            ':invoice_url' => $this->invoice_url,
            ':payment_date' => $this->payment_date,
            ':confirmed_date' => $this->confirmed_date,
            ':invoiceNumber' => $this->invoiceNumber,
        ];

        return (new Database())->execute($query, $params)->rowCount() > 0;
    }

    public static function updateProviderStatus(string $invoiceNumber, string $status, array $payment, bool $confirmed): bool
    {
        self::ensureSchema();
        $confirmedDate = $confirmed
            ? ($payment['confirmedDate'] ?? $payment['paymentDate'] ?? date('Y-m-d H:i:s'))
            : null;

        $query = "
            UPDATE webhook_pix
            SET
                payment_status = :payment_status,
                value = :value,
                pixQrCodeId = COALESCE(:pixQrCodeId, pixQrCodeId),
                asaas_payment_id = COALESCE(:asaas_payment_id, asaas_payment_id),
                asaas_customer_id = COALESCE(:asaas_customer_id, asaas_customer_id),
                external_Reference = COALESCE(:externalReference, external_Reference),
                billingType = COALESCE(:billingType, billingType),
                transactionReceiptUrl = COALESCE(:transactionReceiptUrl, transactionReceiptUrl),
                invoice_url = COALESCE(:invoice_url, invoice_url),
                due_date = COALESCE(:due_date, due_date),
                payment_date = COALESCE(:payment_date, payment_date),
                last_webhook_event = :last_webhook_event,
                last_webhook_payload = :last_webhook_payload,
                pix_payload = COALESCE(:pix_payload, pix_payload),
                pix_encoded_image = COALESCE(:pix_encoded_image, pix_encoded_image)
                " . ($confirmedDate !== null ? ", confirmed_date = :confirmed_date" : "") . "
            WHERE invoiceNumber = :invoice
        ";

        $params = [
            ':payment_status' => $status,
            ':value' => (float)($payment['value'] ?? 0),
            ':pixQrCodeId' => $payment['pixQrCodeId'] ?? null,
            ':asaas_payment_id' => self::providerPaymentId($payment),
            ':asaas_customer_id' => self::providerCustomerId($payment),
            ':externalReference' => $payment['externalReference'] ?? null,
            ':billingType' => $payment['billingType'] ?? null,
            ':transactionReceiptUrl' => $payment['transactionReceiptUrl'] ?? null,
            ':invoice_url' => self::providerInvoiceUrl($payment),
            ':due_date' => self::normalizeDate($payment['dueDate'] ?? null),
            ':payment_date' => self::normalizeDateTime($payment['paymentDate'] ?? $payment['clientPaymentDate'] ?? $payment['confirmedDate'] ?? null),
            ':last_webhook_event' => $status,
            ':last_webhook_payload' => self::providerPayloadJson($payment),
            ':pix_payload' => $payment['payload'] ?? null,
            ':pix_encoded_image' => $payment['encodedImage'] ?? null,
            ':invoice' => $invoiceNumber,
        ];

        if ($confirmedDate !== null) {
            $params[':confirmed_date'] = $confirmedDate;
        }

        return (new Database())->execute($query, $params)->rowCount() > 0;
    }

    public static function updateProviderStatusForPix(self $pix, string $status, array $payment, bool $confirmed): bool
    {
        self::ensureSchema();
        $confirmedDate = $confirmed
            ? ($payment['confirmedDate'] ?? $payment['paymentDate'] ?? date('Y-m-d H:i:s'))
            : null;

        $query = "
            UPDATE webhook_pix
            SET
                payment_status = :payment_status,
                value = :value,
                invoiceNumber = COALESCE(:invoiceNumber, invoiceNumber),
                asaas_payment_id = COALESCE(:asaas_payment_id, asaas_payment_id),
                asaas_customer_id = COALESCE(:asaas_customer_id, asaas_customer_id),
                external_Reference = COALESCE(:externalReference, external_Reference),
                billingType = COALESCE(:billingType, billingType),
                transactionReceiptUrl = COALESCE(:transactionReceiptUrl, transactionReceiptUrl),
                invoice_url = COALESCE(:invoice_url, invoice_url),
                due_date = COALESCE(:due_date, due_date),
                payment_date = COALESCE(:payment_date, payment_date),
                last_webhook_event = :last_webhook_event,
                last_webhook_payload = :last_webhook_payload,
                pix_payload = COALESCE(:pix_payload, pix_payload),
                pix_encoded_image = COALESCE(:pix_encoded_image, pix_encoded_image)
                " . ($confirmedDate !== null ? ", confirmed_date = :confirmed_date" : "") . "
            WHERE webhook_id = :webhook_id
        ";

        $params = [
            ':payment_status' => $status,
            ':value' => (float)($payment['value'] ?? $pix->value ?? 0),
            ':invoiceNumber' => self::normalizeInvoice($payment['invoiceNumber'] ?? null),
            ':asaas_payment_id' => self::providerPaymentId($payment),
            ':asaas_customer_id' => self::providerCustomerId($payment),
            ':externalReference' => $payment['externalReference'] ?? null,
            ':billingType' => $payment['billingType'] ?? null,
            ':transactionReceiptUrl' => $payment['transactionReceiptUrl'] ?? null,
            ':invoice_url' => self::providerInvoiceUrl($payment),
            ':due_date' => self::normalizeDate($payment['dueDate'] ?? null),
            ':payment_date' => self::normalizeDateTime($payment['paymentDate'] ?? $payment['clientPaymentDate'] ?? $payment['confirmedDate'] ?? null),
            ':last_webhook_event' => $status,
            ':last_webhook_payload' => self::providerPayloadJson($payment),
            ':pix_payload' => $payment['payload'] ?? null,
            ':pix_encoded_image' => $payment['encodedImage'] ?? null,
            ':webhook_id' => (int)$pix->webhook_id,
        ];

        if ($confirmedDate !== null) {
            $params[':confirmed_date'] = $confirmedDate;
        }

        return (new Database())->execute($query, $params)->rowCount() > 0;
    }

    public static function markPaidOnceForPix(self $pix, string $status, array $payment = []): bool
    {
        self::ensureSchema();
        $query = "
            UPDATE webhook_pix
            SET
                payment_status = :payment_status,
                value = :value,
                pixQrCodeId = COALESCE(:pixQrCodeId, pixQrCodeId),
                asaas_payment_id = COALESCE(:asaas_payment_id, asaas_payment_id),
                asaas_customer_id = COALESCE(:asaas_customer_id, asaas_customer_id),
                external_Reference = COALESCE(:externalReference, external_Reference),
                billingType = COALESCE(:billingType, billingType),
                invoiceNumber = COALESCE(:invoiceNumber, invoiceNumber),
                transactionReceiptUrl = COALESCE(:transactionReceiptUrl, transactionReceiptUrl),
                invoice_url = COALESCE(:invoice_url, invoice_url),
                due_date = COALESCE(:due_date, due_date),
                payment_date = COALESCE(:payment_date, payment_date),
                last_webhook_event = :last_webhook_event,
                last_webhook_payload = :last_webhook_payload,
                pix_payload = COALESCE(:pix_payload, pix_payload),
                pix_encoded_image = COALESCE(:pix_encoded_image, pix_encoded_image),
                confirmed_date = :confirmed_date
            WHERE webhook_id = :webhook_id
              AND payment_status NOT IN ('PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED')
        ";

        $params = [
            ':payment_status' => $status,
            ':value' => (float)($payment['value'] ?? $pix->value ?? 0),
            ':pixQrCodeId' => $payment['pixQrCodeId'] ?? $pix->pixQrCodeId ?? null,
            ':asaas_payment_id' => self::providerPaymentId($payment),
            ':asaas_customer_id' => self::providerCustomerId($payment),
            ':externalReference' => $payment['externalReference'] ?? null,
            ':billingType' => $payment['billingType'] ?? null,
            ':invoiceNumber' => self::normalizeInvoice($payment['invoiceNumber'] ?? null),
            ':transactionReceiptUrl' => $payment['transactionReceiptUrl'] ?? null,
            ':invoice_url' => self::providerInvoiceUrl($payment),
            ':due_date' => self::normalizeDate($payment['dueDate'] ?? null),
            ':payment_date' => self::normalizeDateTime($payment['paymentDate'] ?? $payment['clientPaymentDate'] ?? $payment['confirmedDate'] ?? null),
            ':last_webhook_event' => $status,
            ':last_webhook_payload' => self::providerPayloadJson($payment),
            ':pix_payload' => $payment['payload'] ?? null,
            ':pix_encoded_image' => $payment['encodedImage'] ?? null,
            ':confirmed_date' => $payment['confirmedDate'] ?? $payment['paymentDate'] ?? date('Y-m-d H:i:s'),
            ':webhook_id' => (int)$pix->webhook_id,
        ];

        return (new Database())->execute($query, $params)->rowCount() > 0;
    }

    public static function markPaidOnce(string $invoiceNumber, string $status, array $payment = []): bool
    {
        $pix = self::getByInvoice($invoiceNumber);
        return $pix ? self::markPaidOnceForPix($pix, $status, $payment) : false;
    }

    public static function markRefundedOnceForPix(self $pix, array $payment = []): bool
    {
        self::ensureSchema();
        $query = "
            UPDATE webhook_pix
            SET
                payment_status = 'PAYMENT_REFUNDED',
                value = :value,
                pixQrCodeId = COALESCE(:pixQrCodeId, pixQrCodeId),
                asaas_payment_id = COALESCE(:asaas_payment_id, asaas_payment_id),
                asaas_customer_id = COALESCE(:asaas_customer_id, asaas_customer_id),
                external_Reference = COALESCE(:externalReference, external_Reference),
                billingType = COALESCE(:billingType, billingType),
                invoiceNumber = COALESCE(:invoiceNumber, invoiceNumber),
                transactionReceiptUrl = COALESCE(:transactionReceiptUrl, transactionReceiptUrl),
                invoice_url = COALESCE(:invoice_url, invoice_url),
                due_date = COALESCE(:due_date, due_date),
                payment_date = COALESCE(:payment_date, payment_date),
                last_webhook_event = :last_webhook_event,
                last_webhook_payload = :last_webhook_payload,
                pix_payload = COALESCE(:pix_payload, pix_payload),
                pix_encoded_image = COALESCE(:pix_encoded_image, pix_encoded_image),
                confirmed_date = :confirmed_date
            WHERE webhook_id = :webhook_id
              AND payment_status != 'PAYMENT_REFUNDED'
        ";

        $params = [
            ':value' => (float)($payment['value'] ?? $pix->value ?? 0),
            ':pixQrCodeId' => $payment['pixQrCodeId'] ?? $pix->pixQrCodeId ?? null,
            ':asaas_payment_id' => self::providerPaymentId($payment),
            ':asaas_customer_id' => self::providerCustomerId($payment),
            ':externalReference' => $payment['externalReference'] ?? null,
            ':billingType' => $payment['billingType'] ?? null,
            ':invoiceNumber' => self::normalizeInvoice($payment['invoiceNumber'] ?? null),
            ':transactionReceiptUrl' => $payment['transactionReceiptUrl'] ?? null,
            ':invoice_url' => self::providerInvoiceUrl($payment),
            ':due_date' => self::normalizeDate($payment['dueDate'] ?? null),
            ':payment_date' => self::normalizeDateTime($payment['paymentDate'] ?? $payment['clientPaymentDate'] ?? $payment['confirmedDate'] ?? null),
            ':last_webhook_event' => 'PAYMENT_REFUNDED',
            ':last_webhook_payload' => self::providerPayloadJson($payment),
            ':pix_payload' => $payment['payload'] ?? null,
            ':pix_encoded_image' => $payment['encodedImage'] ?? null,
            ':confirmed_date' => $payment['confirmedDate'] ?? $payment['paymentDate'] ?? date('Y-m-d H:i:s'),
            ':webhook_id' => (int)$pix->webhook_id,
        ];

        return (new Database())->execute($query, $params)->rowCount() > 0;
    }

    public static function markRefundedOnce(string $invoiceNumber, array $payment = []): bool
    {
        $pix = self::getByInvoice($invoiceNumber);
        return $pix ? self::markRefundedOnceForPix($pix, $payment) : false;
    }

    public static function getPixLast(?int $userId, ?string $tenancyId): ?self
    {
        self::ensureSchema();
        $where = 'confirmed_date IS NOT NULL AND payment_status IN ("PAYMENT_RECEIVED", "PAYMENT_CONFIRMED")';
        $params = [];

        if (!empty($tenancyId)) {
            $where .= ' AND tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $tenancyId;
        }

        if ($userId !== null) {
            $where .= ' AND user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $data = (new Database('webhook_pix'))->select(
            "$where ORDER BY confirmed_date DESC LIMIT 1",
            $params
        )->fetch(\PDO::FETCH_ASSOC);

        return $data ? self::hydrate($data) : null;
    }

    public static function getValuesPixCurrentMonth(?int $userId, ?string $tenancyId): array
    {
        self::ensureSchema();
        $query = "
            SELECT webhook_id, tenancy_id, user_id, value, confirmed_date
            FROM webhook_pix
            WHERE confirmed_date IS NOT NULL
              AND payment_status IN ('PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED')
              AND YEAR(confirmed_date) = YEAR(CURDATE())
              AND MONTH(confirmed_date) = MONTH(CURDATE())
        ";

        $params = [];

        if (!empty($tenancyId)) {
            $query .= ' AND tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $tenancyId;
        }

        if ($userId !== null) {
            $query .= ' AND user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $query .= ' ORDER BY confirmed_date ASC';

        return (new Database())->execute($query, $params)->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    public function updatePaymentRefunded(): bool
    {
        self::ensureSchema();
        $this->confirmed_date = date('Y-m-d H:i:s');

        if ($this->payment_status !== 'PAYMENT_REFUNDED') {
            return true;
        }

        return (new Database('webhook_pix'))->update(
            'invoiceNumber = :invoice',
            [
                'payment_status' => $this->payment_status,
                'value' => $this->value,
                'pixQrCodeId' => $this->pixQrCodeId,
                'external_Reference' => $this->external_Reference,
                'billingType' => $this->billingType,
                'invoiceNumber' => $this->invoiceNumber,
                'transactionReceiptUrl' => $this->transactionReceiptUrl,
                'invoice_url' => $this->invoice_url,
                'payment_date' => $this->payment_date,
                'confirmed_date' => $this->confirmed_date,
            ],
            [':invoice' => $this->invoiceNumber]
        );
    }

    public static function getByInvoice(string $invoiceNumber): ?self
    {
        self::ensureSchema();
        $data = (new Database('webhook_pix'))
            ->select('invoiceNumber = :invoice', [':invoice' => $invoiceNumber])
            ->fetch(\PDO::FETCH_ASSOC);

        return $data ? self::hydrate($data) : null;
    }

    public static function findByProviderPayload(array $payment): ?self
    {
        self::ensureSchema();

        $paymentId = self::providerPaymentId($payment);
        if ($paymentId !== null) {
            $data = (new Database('webhook_pix'))
                ->select('asaas_payment_id = :payment_id', [':payment_id' => $paymentId])
                ->fetch(\PDO::FETCH_ASSOC);

            if ($data) {
                return self::hydrate($data);
            }
        }

        $pixQrCodeId = trim((string)($payment['pixQrCodeId'] ?? ''));
        if ($pixQrCodeId !== '') {
            $data = (new Database('webhook_pix'))
                ->select('pixQrCodeId = :pixQrCodeId', [':pixQrCodeId' => $pixQrCodeId])
                ->fetch(\PDO::FETCH_ASSOC);

            if ($data) {
                return self::hydrate($data);
            }
        }

        $externalReference = trim((string)($payment['externalReference'] ?? ''));
        if ($externalReference !== '') {
            $data = (new Database('webhook_pix'))
                ->select('external_Reference = :externalReference', [':externalReference' => $externalReference])
                ->fetch(\PDO::FETCH_ASSOC);

            if ($data) {
                return self::hydrate($data);
            }
        }

        $invoice = self::normalizeInvoice($payment['invoiceNumber'] ?? null);
        if ($invoice !== null) {
            return self::getByInvoice($invoice);
        }

        return null;
    }

    public static function getWebhookAsaasCount(string $tenancyId, ?string $searchValue = null): int
    {
        self::ensureSchema();
        $query = 'SELECT COUNT(*) as qtd FROM webhook_pix WHERE tenancy_id = :tenancy_id';
        $params = [':tenancy_id' => $tenancyId];

        if (!empty($searchValue)) {
            $query .= " AND (
                webhook_id LIKE :search OR
                payment_status LIKE :search OR
                value LIKE :search OR
                asaas_payment_id LIKE :search OR
                invoiceNumber LIKE :search OR
                transactionReceiptUrl LIKE :search OR
                invoice_url LIKE :search OR
                confirmed_date LIKE :search
            )";
            $params[':search'] = '%' . $searchValue . '%';
        }

        return (new Database())->execute($query, $params)->fetchObject()->qtd ?? 0;
    }

    public static function getWebhookAsaas(array $filters = [], string $order = 'confirmed_date DESC'): array
    {
        self::ensureSchema();
        $params = [];
        $where = '1=1';

        if (isset($filters['tenancy_id'])) {
            $where .= ' AND tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenancy_id'];
        }

        if (isset($filters['user_id'])) {
            $where .= ' AND user_id = :user_id';
            $params[':user_id'] = $filters['user_id'];
        }

        $query = "
            SELECT
                webhook_id,
                payment_status,
                value,
                asaas_payment_id,
                asaas_customer_id,
                invoiceNumber,
                invoice_url,
                transactionReceiptUrl,
                due_date,
                payment_date,
                confirmed_date
            FROM webhook_pix
            WHERE {$where}
            ORDER BY {$order}
        ";

        return (new Database())->execute($query, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function appendApiLog(string $eventName, array $context = [], string $level = 'info'): void
    {
        self::ensureSchema();

        try {
            (new Database('pix_api_logs'))->insert([
                'webhook_id' => isset($context['webhook_id']) ? (int)$context['webhook_id'] : null,
                'user_id' => isset($context['user_id']) ? (int)$context['user_id'] : null,
                'tenancy_id' => isset($context['tenancy_id']) ? (string)$context['tenancy_id'] : null,
                'event_name' => mb_substr($eventName, 0, 64),
                'level' => mb_substr($level, 0, 16),
                'payment_id' => self::providerPaymentId($context),
                'invoice_number' => trim((string)($context['invoiceNumber'] ?? '')) ?: null,
                'pix_qr_code_id' => trim((string)($context['pixQrCodeId'] ?? '')) ?: null,
                'message' => isset($context['message']) ? (string)$context['message'] : null,
                'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable) {
        }
    }
}
