<?php

namespace App\Controller\Pages;


use App\Http\Request;
use App\Http\Response;
use App\Model\Entity\CallbackSms;
use App\Model\Entity\CdrVoice;
use App\Model\Entity\NotificationsUsers;
use App\Model\Entity\PixSearch;
use App\Model\Entity\Rates;
use App\Model\Entity\RefillsResellers;
use App\Model\Entity\UserSearch;
use App\Session\User as SessionUser;
use App\Utils\View;
use DateTime;
use Exception;
use JetBrains\PhpStorm\NoReturn;
use App\Controller\Pages\ResellerRechargePDF;

class Reports extends ViewComponents
{
    // ======================= Auxiliares =======================

    /**
     * @throws Exception
     */
    private static function getUserTenancy(): string
    {
        $user = SessionUser::getLogged();
        $tenancyId = $user['tenancy_id'] ?? null;
        if (!$tenancyId) {
            throw new Exception('Tenancy não definido.');
        }
        return $tenancyId;
    }

    private static function getDataTableParams(Request $request): array
    {
        $params = $request->getQueryParams();
        return [
            'draw' => isset($params['draw']) ? (int)$params['draw'] : 0,
            'start' => isset($params['start']) ? (int)$params['start'] : 0,
            'length' => isset($params['length']) ? (int)$params['length'] : 10,
            'search' => $params['search']['value'] ?? null,
            'orderColumnIndex' => $params['order'][0]['column'] ?? 0,
            'orderDir' => $params['order'][0]['dir'] ?? 'desc'
        ];
    }

    #[NoReturn] private static function respondDataTable(array $data, int $draw, int $totalRecords): void
    {
        $response = [
            'draw' => $draw,
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalRecords,
            'data' => $data
        ];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response);
        exit;
    }

    private static function handleRealtime(Request $request, callable $dataFetcher, array $columns): void
    {
        try {
            $tenancyId = self::getUserTenancy();
            $dtParams = self::getDataTableParams($request);

            $orderColumn = $columns[$dtParams['orderColumnIndex']] ?? $columns[0];

            $totalRecords = $dataFetcher('count', $tenancyId, $dtParams['search']);
            $records = $dataFetcher('list', $tenancyId, $dtParams['search'], $dtParams['start'], $dtParams['length'], $orderColumn, strtoupper($dtParams['orderDir']));

            $data = [];
            foreach ($records as $record) {
                $data[] = (array)$record;
            }

            self::respondDataTable($data, $dtParams['draw'], $totalRecords);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => true,
                'message' => $e->getMessage()
            ]);
            exit;
        }
    }

    // ======================= Views =======================

    public static function getReportsStatus($request): array|bool|string
    {
        $content = View::render('/reports/index', []);
        return parent::getComponentsReports('Maxx Solutions - SMS | Reports', $content);
    }

    public static function getNotificationsStatus($request): array|bool|string
    {
        $content = View::render('/reports/notifications', []);
        return parent::getComponentsReports('Maxx Solutions - SMS | Reports', $content);
    }

    public static function getTransactionsPix($request): array|bool|string
    {
        $content = View::render('/reports/pix', []);
        return parent::getComponentsReports('Maxx Solutions - SMS | Reports', $content);
    }

    public static function getTransactionsResellers($request): array|bool|string
    {
        $content = View::render('/reports/recharge', []);
        return parent::getComponentsReports('Maxx Solutions - SMS | Reports', $content);
    }

    public static function getStatusSmsView($request): array|bool|string
    {
        $content = View::render('/reports/sms', []);
        return parent::getComponentsReports('Maxx Solutions - SMS | Reports', $content);
    }

    public static function getCdrComponents($request): array|bool|string
    {
        $content = View::render('/reports/cdr', []);
        return parent::getComponentsReports('Maxx Solutions - SMS | Reports', $content);
    }

    // ======================= Realtime =======================

    public static function getReportsStatusRealtime($request): void
    {
        $columns = ['id', 'phone', 'camp_name', 'operator', 'status', 'update_date'];

        // 🔑 Usuário logado
        $obUser = SessionUser::getLogged();
        $userFunc = strtolower(trim($obUser['function'] ?? ''));
        $userId = $obUser['id'] ?? null;
        $tenancyId = $obUser['tenancy_id'] ?? null;

        // 🧩 Define o escopo de acesso
        $isReseller = ($userFunc === 'reseller');
        $isSuperAdmin = ($userFunc === 'super_admin');

        // 🔧 Filtro de usuário — somente se reseller
        $filterUserId = $isReseller ? $userId : null;

        // 🔧 Tenancy — nulo para super_admin (vê tudo)
        $filterTenancyId = $isSuperAdmin ? null : $tenancyId;

        self::handleRealtime(
            $request,
            function (
                $type,
                $tenancyId,
                $search,
                $start = 0,
                $length = 10,
                $orderColumn = 'id',
                $orderDir = 'DESC'
            ) use ($filterUserId, $filterTenancyId, $isSuperAdmin) {

                // 🔹 Se for super_admin → ignora todos os filtros
                if ($isSuperAdmin) {
                    if ($type === 'count') {
                        return CallbackSms::getCallbackSmsCount(null, $search, null);
                    }
                    return CallbackSms::getStatusSmsPaginated(
                        null,  // tenancy
                        $search,
                        $start,
                        $length,
                        $orderColumn,
                        $orderDir,
                        null   // userId
                    );
                }

                // 🔹 Demais perfis (reseller / tenant user)
                if ($type === 'count') {
                    return CallbackSms::getCallbackSmsCount($filterTenancyId, $search, $filterUserId);
                }

                return CallbackSms::getStatusSmsPaginated(
                    $filterTenancyId,
                    $search,
                    $start,
                    $length,
                    $orderColumn,
                    $orderDir,
                    $filterUserId
                );
            },
            $columns
        );
    }



    public static function getNotificationsStatusRealtime($request): void
    {
        $columns = ['id', 'title', 'message', 'type', 'read_at', 'created_at'];

        // 🔑 Usuário logado
        $obUser = SessionUser::getLogged();
        $userFunc = strtolower(trim($obUser['function'] ?? ''));
        $isReseller = ($userFunc === 'reseller');
        $filterUserId = $isReseller ? $obUser['id'] : null;
        $tenancyId = $obUser['tenancy_id'];

        self::handleRealtime(
            $request,
            function ($type, $tenancyId, $search, $start = 0, $length = 10, $orderColumn = 'id', $orderDir = 'DESC') use ($filterUserId) {
                if ($type === 'count') {
                    return NotificationsUsers::getNotificationCount(
                        $tenancyId,
                        $search,
                        $filterUserId
                    );
                }
                return NotificationsUsers::getNotificationsPaginated(
                    $tenancyId,
                    $search,
                    $start,
                    $length,
                    $orderColumn,
                    $orderDir,
                    $filterUserId
                );
            },
            $columns
        );
    }


    #[NoReturn] public static function getTransactionsPixRealtime($request): void
    {
        $obUser = SessionUser::getLogged();
        $userFunc = strtolower(trim($obUser['function'] ?? ''));
        $isReseller = ($userFunc === 'reseller');
        $tenancyId = $obUser['tenancy_id'];
        $resellerId = $isReseller ? $obUser['id'] : null;

        $columns = $isReseller
            ? ['id', 'transaction_id', 'email', 'balance', 'type', 'status', 'created_at']
            : ['webhook_id', 'payment_status', 'value', 'invoiceNumber', 'confirmed_date', 'transactionReceiptUrl'];

        $start = intval($_GET['start'] ?? 0);
        $length = intval($_GET['length'] ?? 10);
        $search = $_GET['search']['value'] ?? '';
        $draw = intval($_GET['draw'] ?? 1);

        // 🔹 Ajusta orderColumn e orderDir de forma segura
        $orderColumnIndex = $_GET['order'][0]['column'] ?? 0;
        $orderDir = $_GET['order'][0]['dir'] ?? 'DESC';
        $orderColumn = $columns[$orderColumnIndex] ?? $columns[0];

        if (!in_array($orderColumn, $columns, true)) {
            $orderColumn = $columns[0];
        }

        if ($isReseller) {
            $recordsTotal = RefillsResellers::getTransactionsCount($resellerId, null);
            $recordsFiltered = RefillsResellers::getTransactionsCount($resellerId, $search);
            $data = RefillsResellers::getTransactionsPaginated($resellerId, $search, $start, $length, $orderColumn, $orderDir);
            $userType = 'reseller';
        } else {
            $recordsTotal = PixSearch::getWebhookAsaasCount($tenancyId, null);
            $recordsFiltered = PixSearch::getWebhookAsaasCount($tenancyId, $search);
            $data = PixSearch::getWebhookAsaasPaginated($tenancyId, $search, $start, $length, $orderColumn, $orderDir);
            $userType = 'admin';
        }

        header('Content-Type: application/json');
        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
            'user_type' => $userType
        ]);
        exit;
    }


    #[NoReturn] public static function getRechargeResellers($request): void
    {
        $obUser = SessionUser::getLogged();
        $userFunc = strtolower(trim($obUser['function'] ?? ''));

        $tenancyId = $obUser['tenancy_id'];

        // 🔹 Colunas exibidas no painel do admin
        $columns = ['id', 'name', 'email', 'balance', 'last_refill', 'status', 'transaction_id', 'client_ip', 'type', 'notes', 'created_at', 'updated_at'];

        $start = intval($_GET['start'] ?? 0);
        $length = intval($_GET['length'] ?? 10);
        $search = $_GET['search']['value'] ?? '';
        $draw = intval($_GET['draw'] ?? 1);

        $orderColumnIndex = $_GET['order'][0]['column'] ?? 0;
        $orderDir = $_GET['order'][0]['dir'] ?? 'DESC';
        $orderColumn = $columns[$orderColumnIndex] ?? $columns[0];

        if (!in_array($orderColumn, $columns, true)) {
            $orderColumn = $columns[0];
        }

        // 🔹 Total e filtrado
        $recordsTotal = RefillsResellers::getTransactionsCount(null, null, $tenancyId);
        $recordsFiltered = RefillsResellers::getTransactionsCount(null, $search, $tenancyId);

        $data = RefillsResellers::getTransactionsPaginated(
            null,          // null = todos resellers
            $search,
            $start,
            $length,
            $orderColumn,
            $orderDir,
            $tenancyId     // filtra por tenancy
        );

        header('Content-Type: application/json');
        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
            'user_type' => 'admin'
        ]);
        exit;
    }

    public static function getStatusSmsViewRealtime($request): void
    {
        $columns = ['id', 'phone_sms', 'value_sms', 'operator', 'status_sms', 'update_date'];

        $obUser = SessionUser::getLogged();
        $userFunc = strtolower(trim($obUser['function'] ?? ''));
        $isReseller = ($userFunc === 'reseller');
        $filterUserId = $isReseller ? $obUser['id'] : null;
        $tenancyId = $obUser['tenancy_id'];

        self::handleRealtime($request, function ($type, $tenancyId, $search, $start = 0, $length = 10, $orderColumn = 'id', $orderDir = 'DESC') use ($filterUserId) {
            if ($type === 'count') return CallbackSms::getCallbackSmsCount($tenancyId, $search, $filterUserId);
            return CallbackSms::getStatusSmsPaginated($tenancyId, $search, $start, $length, $orderColumn, $orderDir, $filterUserId);
        }, $columns);
    }


    public static function downloadRechargePDF($request, int $id): void
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            http_response_code(401);
            echo json_encode([
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ]);
            exit;
        }

        // 🔹 Busca dados da recarga
        $recharge = RefillsResellers::getById($id);
        if (!$recharge) {
            http_response_code(404);
            echo "Comprovante não encontrado";
            exit;
        }

        // 🔹 Busca dados do reseller (usuário dono da recarga)
        $resellers = UserSearch::getResellers($obUser['tenancy_id'], $recharge->user_id);
        $resellersRates = Rates::getLatestActiveRate($obUser['tenancy_id'], $recharge->user_id);
        $rate = floatval($resellersRates['rate']);
        $balance = floatval($recharge->balance);

        if ($rate > 0 && $balance > 0) {
            // Calcula quantidade de SMS
            $total_sms = (int)($balance / $rate);
        } else {
            $total_sms = 0;
        }

        // Formata para exibição
        $total = number_format($total_sms, 0, ',', '.');
        $reseller = $resellers[0] ?? null;

        if (!$reseller) {
            http_response_code(404);
            echo "Reseller não encontrado";
            exit;
        }

        // 🔹 Dados combinados para o PDF
        $dataReseller = [
            'id' => $reseller['id'],
            'tenancy_d' => $obUser['tenancy_id'],
            'name' => $reseller['name'] . ' ' . ($reseller['last_name'] ?? ''),
            'email' => $reseller['email']
        ];

        $dataRecharge = [
            'invoice_number' => $recharge->transaction_id,
            'amount' => $recharge->balance,
            'total_sms' => $total_sms,
            'date' => $recharge->created_at,
            'due_date' => $recharge->created_at,
            'created_by' => 'Administrador'
        ];

        // 🔹 Gera PDF (uma única vez)
        $pdf = new ResellerRechargePDF();
        $pdf->generate($dataReseller, $dataRecharge, false); // false = abre no navegador
    }

    #[NoReturn] public static function getStatusSmsStream($request): void
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-transform');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // desativa buffering no Nginx

            @ini_set('zlib.output_compression', '0');
            @ini_set('implicit_flush', '1');
            ob_implicit_flush(true);
        }


        // envia ping inicial para abrir a conexão imediatamente
        echo "event: ping\n";
        echo "data: connected\n\n";
        @ob_flush();
        @flush();


        // Usuário logado
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            $payload = [
                'status' => 401,
                'message' => 'Usuário não autenticado'
            ];
            echo "event: auth\n";
            echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            @ob_flush();
            @flush();
            exit;
        }

        $userFunc = strtolower(trim($obUser['function'] ?? ''));
        $isReseller = ($userFunc === 'reseller');
        $filterUserId = $isReseller ? $obUser['id'] : null;
        $tenancyId = $obUser['tenancy_id'];

        // Busca últimos SMS
        $smsData = CallbackSms::getStatusSmsPaginated(
            $tenancyId,
            null,
            0,
            10,
            'id',
            'DESC',
            $filterUserId
        );

        $count = CallbackSms::getCallbackSmsCount($tenancyId, null, $filterUserId);

        $payload = [
            'status' => 200,
            'total' => $count,
            'data' => $smsData
        ];

        // Envia SSE e fecha conexão
        echo "event: sms-status\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @ob_flush();
        @flush();
        exit;
    }


    function cleanAndDetectDestination(string $dest): array
    {
        $original = preg_replace('/\D/', '', $dest);

        // Verifica se tinha prefixo de rota (externo)
        $isExternal = preg_match('/^(55|65|45|0|00\d{2}|0\d{2})/', $original);

        // Remove prefixos
        $clean = preg_replace('/^(55|65|45|0|00\d{2}|0\d{2})/', '', $original);

        // Detecta RAMAL pela sua regra (8 - 10 dígitos)
        if (!$isExternal && strlen($clean) >= 8 && strlen($clean) <= 10) {
            $type = "ramal";
        } else {
            $type = "externo";
        }

        return [
            "destination" => $clean,
            "destination_type" => $type
        ];
    }

    public static function getCdrCallsAnalysis(): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, ['message' => "Usuário não autenticado"], 'application/json');
        }


        $userId   = $obUser['id'];
        $role     = strtolower($obUser['function']);
        $tenancy  = $obUser['tenancy_id'];

        // ==============================
        // 🔎 MONTAR FILTROS
        // ==============================
        $filters = [];

        // SUPER ADMIN → pode ver tudo, sem filtros
        if ($role === 'super_admin') {
            $filters = [];

            // ADMIN → vê somente tudo da tenancy
        } elseif ($role === 'admin') {
            $filters['tenancy_id'] = $tenancy;

            // RESELLER → vê somente as chamadas dele
        } elseif ($role === 'reseller') {
            $filters['tenancy_id'] = $tenancy;
            $filters['user_id']    = $userId;

            // USUÁRIO NORMAL → vê APENAS suas chamadas
        } else {
            $filters['tenancy_id'] = $tenancy;
            $filters['user_id']    = $userId;
        }

        Voice::processCdrFromRedis();



        // ==============================
        // 🔽 BUSCA NO BANCO (Model)
        // ==============================
        try {
            $cdr = CdrVoice::getCdrVoice($filters, "created_at DESC");
        } catch (\Exception $e) {
            return new Response(500, [
                'message' => "Erro ao consultar CDR",
                'error'   => $e->getMessage()
            ], 'application/json');
        }

        // ==============================
        // 🔄 Formatando os dados
        // ==============================
        $formatted = array_map(/**
         * @throws Exception
         */ function ($row) {

            return [
                'id'          => $row['id'],
                'channel_id'  => $row['channel_id'],
                'tenancy_id'  => $row['tenancy_id'],
                'user_id'     => $row['user_id'],
                'number'      => $row['number'],
                'destination' => (strlen($row['destination']) === 13)
                    ? substr($row['destination'], 2)
                    : $row['destination'],

                //'destination' => $row['destination'],
                //'destination' => substr($row['destination'], 2),
                'channelNumber' => $row['number'], //$row['channel_number'],
                'type'        => $row['type'],
                'dialstatus'  => $row['dialstatus'],
                'cause'       => $row['cause'],
                'cause_txt'   => $row['cause_txt'],
                'taxa'        => $row['taxa_of_service'],
                'duration'    => (int)$row['duration'],
                'value'       => (float)$row['value'],
                'started'     => (new DateTime($row['started']))->format('d-m-Y H:i:s'),
                'answered'    => $row['answered'],
                'ended'       => $row['ended'],
                'created_at'  => $row['created_at'],
            ];
        }, $cdr);

        // ==============================
        // ✅ RETORNO PARA O FRONTEND
        // ==============================
        return new Response(200, [
            'success' => true,
            'total'   => count($formatted),
            'data'    => $formatted
        ], 'application/json');
    }

}
