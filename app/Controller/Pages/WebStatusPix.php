<?php

namespace App\Controller\Pages;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\PixSearch;
use App\Controller\Pages\PaymentProcessor;
use App\Model\Entity\UserSearch;
use JetBrains\PhpStorm\NoReturn;

class WebStatusPix
{
    #[NoReturn] public static function getCallbackAsaas($request): void
    {
        $headers = getallheaders();
	
        $secretToken = getenv('ASAAS_WEBHOOK_SECRET');

		
        // 🔒 Verifica autenticação
        if (!isset($headers['Asaas-Access-Token']) || $headers['Asaas-Access-Token'] !== $secretToken) {
            http_response_code(403);
           echo 'Acesso não autorizado.';
            exit;
        }

        // 📩 Lê e decodifica o corpo JSON
        $body = file_get_contents('php://input');
        $bodyArray = json_decode($body, true);

        if (!is_array($bodyArray) || !isset($bodyArray['event']) || !isset($bodyArray['payment'])) {
            http_response_code(400);
            echo 'Webhook inválido.';
            exit;
        }

        $event   = strtoupper($bodyArray['event']);
        $payment = $bodyArray['payment'];

        if (empty($payment['invoiceNumber'])) {
            http_response_code(422);
            echo 'invoiceNumber ausente.';
            exit;
        }

        // 🎯 Roteamento de eventos
        switch ($event) {
            case 'PAYMENT_REFUNDED':
                if (PaymentProcessor::processPaymentRefunded($payment)) {
                    http_response_code(200);
                } else {
                    http_response_code(500);
                    echo 'Erro ao processar refund.';
                }
                exit;

            /*case 'PAYMENT_CREATED':
            case 'PAYMENT_RECEIVED':
                $webA = new PixSearch();
                $webA->value                 = $payment['value'] ?? 0;
                $webA->pixQrCodeId           = $payment['pixQrCodeId'] ?? null;
                $webA->billingType           = $payment['billingType'] ?? null;
                $webA->external_Reference    = $payment['externalReference'] ?? null;
                $webA->invoiceNumber         = $payment['invoiceNumber'];
                $webA->transactionReceiptUrl = $payment['transactionReceiptUrl'] ?? null;
                $webA->confirmed_date        = $payment['confirmedDate'] ?? date('Y-m-d H:i:s');
                $webA->payment_status        = $event;

                if ($webA->updatePayment()) {
                    http_response_code(200);
                } else {
                    http_response_code(500);
                    echo 'Erro ao atualizar pagamento.';
                }
                exit;

            default:
                http_response_code(204); // Evento ignorado
                exit;*/

            case 'PAYMENT_CREATED':
            case 'PAYMENT_RECEIVED':

                // ✅ pega status atual antes de atualizar (idempotência)
                $old = PixSearch::getByInvoice($payment['invoiceNumber']);
                $oldStatus = $old->payment_status ?? null;

                $webA = new PixSearch();
                $webA->value                 = $payment['value'] ?? 0;
                $webA->pixQrCodeId           = $payment['pixQrCodeId'] ?? null;
                $webA->billingType           = $payment['billingType'] ?? null;
                $webA->external_Reference    = $payment['externalReference'] ?? null;
                $webA->invoiceNumber         = $payment['invoiceNumber'];
                $webA->transactionReceiptUrl = $payment['transactionReceiptUrl'] ?? null;
                $webA->confirmed_date        = $payment['confirmedDate'] ?? date('Y-m-d H:i:s');
                $webA->payment_status        = $event;

                if ($webA->updatePayment()) {

                    // 🔺 Só credita SIP se estava diferente e agora virou RECEIVED
                    if ($event === 'PAYMENT_RECEIVED' && $oldStatus !== 'PAYMENT_RECEIVED') {

                        $balanceInfo = BalanceSms::getByInvoice($webA->invoiceNumber);
                        if ($balanceInfo) {
                            $adminId  = $balanceInfo->user_id;
                            $tenantId = $balanceInfo->tenancy_id;
                            $value    = (float)$webA->value;

                            $adminAsterisk = UserSearch::getUserById($tenantId, $adminId);
                            $adminRole     = $adminAsterisk['user_function'] ?? 'admin';

                            (new AsteriskExtensionsSip())->updateBalance(
                                ['user_id' => $adminId, 'tenant_id' => $tenantId],
                                [
                                    'user_id'       => $adminId,
                                    'tenant_id'     => $tenantId,
                                    'balance_admin' => +$value, // ✅ crédito no received
                                    'role'          => $adminRole
                                ]
                            );
                        }
                    }

                    http_response_code(200);
                } else {
                    http_response_code(500);
                    echo 'Erro ao atualizar pagamento.';
                }
                exit;

        }
    }



}
