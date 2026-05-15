<?php

namespace App\Controller\Pages;

use App\Model\Entity\PixSearch;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\Notifications;

class PaymentProcessor
{
    /**
     * Processa um pagamento estornado (refund)
     */
    /*public static function processPaymentRefunded(array $payment): bool
    {
        $webA = new PixSearch();
        $webA->payment_status         = 'PAYMENT_REFUNDED';
        $webA->value                  = $payment['value'] ?? 0;
        $webA->pixQrCodeId            = null; // 👈 força NULL
        $webA->billingType            = $payment['billingType'] ?? null;
        $webA->external_Reference     = $payment['externalReference'] ?? null;
        $webA->invoiceNumber          = $payment['invoiceNumber'];
        $webA->transactionReceiptUrl  = $payment['transactionReceiptUrl'] ?? null;
        $webA->confirmed_date         = $payment['confirmedDate'] ?? date('Y-m-d H:i:s');

        if (!$webA->updatePaymentRefunded()) {
            return false;
        }

        // 🔎 Buscar registro de saldo pelo invoice
        $balanceInfo = BalanceSms::getByInvoice($webA->invoiceNumber);
        $obPix       = PixSearch::getByInvoice($webA->invoiceNumber);

        if ($balanceInfo && $obPix) {
            // 🔻 Decrementar saldo
            BalanceSms::decrementBalance(
                $balanceInfo->user_id,
                $balanceInfo->tenancy_id,
                $obPix->value,
                $obPix->invoiceNumber
            );

            // 🔔 Enviar notificação
            Notifications::insertNotifications(
                $balanceInfo->tenancy_id,
                $balanceInfo->user_id,
                "Pagamento estornado",
                "O pagamento referente à fatura <b>#{$obPix->invoiceNumber}</b> foi estornado. 
             O valor foi debitado do seu saldo.",
                'alert'
            );
        }

        return true;
    }*/

    public static function processPaymentRefunded(array $payment): bool
    {

        // ✅ Idempotência: se já está REFUNDED, não debita de novo
        $current = PixSearch::getByInvoice($payment['invoiceNumber'] ?? '');
        if ($current && ($current->payment_status ?? null) === 'PAYMENT_REFUNDED') {
            return true;
        }

        $webA = new PixSearch();
        $webA->payment_status = 'PAYMENT_REFUNDED';
        $webA->value = $payment['value'] ?? 0;
        $webA->pixQrCodeId = null;
        $webA->billingType = $payment['billingType'] ?? null;
        $webA->external_Reference = $payment['externalReference'] ?? null;
        $webA->invoiceNumber = $payment['invoiceNumber'];
        $webA->transactionReceiptUrl = $payment['transactionReceiptUrl'] ?? null;
        $webA->confirmed_date = $payment['confirmedDate'] ?? date('Y-m-d H:i:s');

        if (!$webA->updatePaymentRefunded()) {
            return false;
        }

        // 🔎 Registros do invoice
        $balanceInfo = BalanceSms::getByInvoice($webA->invoiceNumber);
        $obPix = PixSearch::getByInvoice($webA->invoiceNumber);

        if ($balanceInfo && $obPix) {

            $tenantId = $balanceInfo->tenancy_id;
            $adminId = $balanceInfo->user_id;   // ✅ como você disse: quem faz pix é o admin
            $value = (float)$obPix->value;

            // =====================================================
            // 1) 🔻 Estorno no painel (BalanceSms) = retirar saldo
            // =====================================================
            BalanceSms::decrementBalance(
                $adminId,
                $tenantId,
                $value,
                $obPix->invoiceNumber
            );

            // =====================================================
            // 2) 🔔 Notificação
            // =====================================================
            Notifications::insertNotifications(
                $tenantId,
                $adminId,
                "Pagamento estornado",
                "O pagamento referente à fatura <b>#{$obPix->invoiceNumber}</b> foi estornado.
             O valor foi debitado do seu saldo.",
                'alert'
            );
        }

        return true;
    }


}
