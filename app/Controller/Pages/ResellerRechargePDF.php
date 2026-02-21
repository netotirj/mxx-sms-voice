<?php

namespace App\Controller\Pages;

use DateTime;
use Dompdf\Dompdf;
use Dompdf\Options;
use Exception;

class ResellerRechargePDF
{
    private Dompdf $dompdf;

    public function __construct()
    {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $this->dompdf = new Dompdf($options);
    }

    /**
     * Gera o PDF da recarga
     *
     * @param array $reseller Dados do reseller (id, name, email)
     * @param array $recharge Dados da recarga (invoice_number, amount, date, due_date, created_by)
     * @param bool $download  Se true, força download. Se false, abre no navegador.
     */
    public function generate(array $reseller, array $recharge, bool $download = false): void
    {
        $html = $this->buildTemplate($reseller, $recharge);

        $this->dompdf->loadHtml($html);
        $this->dompdf->setPaper('A4', 'portrait');
        $this->dompdf->render();

        $filename = "Recarga_".$recharge['invoice_number'].".pdf";

        $this->dompdf->stream($filename, ["Attachment" => $download]);

        // 🔚 Garante que nada além do PDF seja renderizado
        exit;
    }

    /**
     * @throws Exception
     */
    private function buildTemplate(array $reseller, array $recharge): string
    {


        $invoiceNumber = htmlspecialchars($recharge['invoice_number']);

        // Formata datas para Brasil
        $invoiceDateObj = !empty($recharge['date']) ? new DateTime($recharge['date']) : null;
        $dueDateObj     = !empty($recharge['due_date']) ? new DateTime($recharge['due_date']) : null;

        $invoiceDate = $invoiceDateObj ? $invoiceDateObj->format('d/m/Y H:i') : '';
        $dueDate     = $dueDateObj ? $dueDateObj->format('d/m/Y H:i') : '';

        // Quantidade de SMS
        $total_sms = isset($recharge['total_sms']) ? (int)$recharge['total_sms'] : 0;

        // Valor formatado (4 casas decimais)
        $amount = isset($recharge['amount']) ? number_format($recharge['amount'], 4, ',', '') : '0,0000';

        $createdBy   = htmlspecialchars($recharge['created_by'] ?? '');
        $resellerId  = htmlspecialchars($reseller['id'] ?? '');
        $generatedAt = date('d/m/Y H:i');
        $notes       = htmlspecialchars($recharge['notes'] ?? '');
        $serviceItems = htmlspecialchars($recharge['service_items'] ?? 'Compra de SMS');


        $path = URL . '/resources/assets/img/carousel-3.jpg';
        $type = pathinfo($path, PATHINFO_EXTENSION);
        $data = file_get_contents($path);
        $base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="pt-BR">
            <head>
                <meta charset="UTF-8">
                <title>Fatura {$invoiceNumber}</title>
                <style>
                    body {
                        font-family: 'DejaVu Sans', sans-serif;
                        font-size: 12px;
                        color: #333;
                        margin: 0;
                        padding: 0;
                    }
                    .container {
                        width: 90%;
                        margin: 20px auto;
                    }
                    /* Cabeçalho */
                    .header {
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        margin-bottom: 20px;
                    }
                   .header img {
                        max-height: 100px;
                        max-width: 250px;  /* Aumentado aqui */
                        height: auto;
                        width: auto;
                    }


                    .header h2 {
                        margin: 0;
                    }
                    /* Empresa e Cliente */
                    .company-client {
                        display: flex;
                        justify-content: space-between;
                        margin-bottom: 20px;
                    }
                    .company, .client {
                        width: 48%;
                        vertical-align: top;
                    }
                    .client {
                        text-align: right;
                    }
                    /* Informações da fatura */
                    .invoice-info {
                        display: flex;
                        justify-content: space-between;
                        margin-bottom: 20px;
                    }
                    .invoice-left, .invoice-right {
                        width: 48%;
                    }
                    /* Tabela de detalhes */
                    table {
                        width: 100%;
                        border-collapse: collapse;
                        margin-bottom: 20px;
                    }
                    th {
                        background: #2c3e50;
                        color: #fff;
                        font-weight: bold;
                        padding: 8px;
                        text-align: left;
                    }
                    td {
                        border: 1px solid #ddd;
                        padding: 8px;
                    }
                    tbody tr:nth-child(even) {
                        background-color: #f9f9f9;
                    }
                    .right {
                        text-align: right;
                    }
                    /* Notas e Service Items */
                    .notes, .service-items {
                        margin-bottom: 20px;
                    }
                    /* Totais */
                    .totals {
                        margin-bottom: 20px;
                        text-align: right;
                    }
                    /* Rodapé */
                    .footer {
                        font-size: 11px;
                        color: #666;
                        text-align: center;
                        margin-top: 40px;
                    }
                </style>
            </head>
            <body>
            <div class="container">
            
               <!-- Cabeçalho (layout sem linhas visíveis) -->
                <table style="width: 100%; margin-bottom: 20px; border-collapse: collapse;">
                    <tr>
                        <td style="width: 50%; vertical-align: middle; border: none;">
                           <img src="{$base64}" alt="Logo3" style="max-width: 250px; max-height: 100px; width: auto; height: auto;">

                        </td>
                        <td style="width: 50%; text-align: right; vertical-align: middle; border: none;">
                            <h2 style="margin: 0;">Fatura Nº: {$invoiceNumber}</h2>
                        </td>
                    </tr>
                </table>
            
               <!-- Empresa e Cliente lado a lado sem linhas visíveis -->
                    <div style="width:100%; margin-bottom:20px; overflow:hidden;">
                        <!-- Empresa -->
                        <div style="width:48%; display:inline-block; vertical-align:top;">
                            <strong>Voip Telecomunicações Ltda.</strong><br>
                            Rua Da Quitanda 187,<br>
                            RIO DE JANEIRO - 20091005<br>
                            RIO DE JANEIRO , Brasil<br>
                            Telefone: 21 96894-3160<br>
                            CNPJ: 40.214.225./0001-65
                        </div>
                    
                        <!-- Cliente -->
                        <div style="width:48%; display:inline-block; vertical-align:top; text-align:right;">
                            <strong>Recarga feita:</strong><br>
                            ID Revendedor: {$resellerId}<br>
                            BRASIL
                        </div>
                    </div>
            
               <!-- Informações da fatura divididas -->
                <table style="width:100%; margin-bottom:20px;">
                    <tr>
                        <td style="width:50%; vertical-align:top;">
                            <p><strong>Data da Fatura:</strong> {$invoiceDate}</p>
                            <p><strong>Créditos do Mês:</strong> {$amount}</p>
                            <p><strong>Total SMS:</strong> {$total_sms}</p>
                        </td>
                        <td style="width:50%; vertical-align:top;">
                            <p><strong>Data de Vencimento:</strong> {$dueDate}</p>
                            <p><strong>Taxa de Serviço:</strong> 0,0000</p>
                            <p><strong>Total (com impostos):</strong> {$amount}</p>
                        </td>
                    </tr>
                </table>

            
                <!-- Detalhes -->
                <table>
                    <thead>
                    <tr>
                        <th>Data</th>
                        <th>Descrição</th>
                        <th class="right">Valor (BRL)</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td>{$invoiceDate}</td>
                        <td>Conta recarregada pelo {$createdBy} da conta</td>
                        <td class="right">{$amount}</td>
                    </tr>
                    </tbody>
                </table>
            
                <!-- Notas -->
                <div class="notes">
                    <strong>Notas:</strong> {$notes}
                </div>
            
                <!-- Itens de serviço -->
                <div class="service-items">
                    <strong>Service Items:</strong> {$serviceItems}
                </div>
            
                <!-- Totais -->
                <div class="totals">
                    <p><strong>Subtotal:</strong> {$amount} BRL</p>
                    <p><strong>Total:</strong> {$amount} BRL</p>
                </div>
            
                <!-- Rodapé -->
                <div class="footer">
                    Documento gerado eletronicamente em {$generatedAt}
                </div>
            
            </div>
            </body>
            </html>
            HTML;
    }


}