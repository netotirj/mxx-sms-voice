<?php

namespace App\Controller\Pages;

use App\Service\PlanRuntimeService;
use App\Utils\View;
use App\Model\Entity\CampaignSearch;
use App\Model\Entity\ContactsSearch;
use App\Session\User as SessionUser;
use App\Http\Response;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Utils\CampaignNameCode;


class Campaign extends ViewComponents
{
    private static function currentSmsRate(string $tenancyId): float
    {
        $summary = PlanRuntimeService::getDisplaySummary($tenancyId);
        return (float)($summary->value_sms ?? 0);
    }

    public static function getCampaign($request): array|bool|string
    {
        $content = View::render('/campaign/index', []);
        return parent::getComponentsCampaign('Maxx Solutions - SMS | Campaign', $content);
    }
    public static function getNewCampaign($request): string
    {

        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status'  => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $valueSms = self::currentSmsRate((string)$obUser['tenancy_id']);

        // 3️⃣ Renderiza view com valor default
        $content = View::render('/campaign/new', [
            'value_sms' => $valueSms,
        ]);

        return parent::getComponentsCampaign('Maxx Solutions - SMS | Campaign', $content);

    }

    public static function getCampaignRealtime($request): Response
    {
        try {
            // Obtém o usuário logado
            $user = SessionUser::getLogged();
            $tenancyId = $user['tenancy_id'] ?? null;
            $userId    = $user['id'] ?? null;
            $userFunc  = $user['function'] ?? null;

            if (!$tenancyId) {
                throw new \Exception('Tenancy não definido.');
            }

            $params = $request->getQueryParams();

            $draw        = isset($params['draw']) ? (int)$params['draw'] : 0;
            $start       = isset($params['start']) ? (int)$params['start'] : 0;
            $length      = isset($params['length']) ? (int)$params['length'] : 5000;
            $searchValue = $params['search']['value'] ?? null;

            $orderColumnIndex = $params['order'][0]['column'] ?? 0;
            $orderDir         = $params['order'][0]['dir'] ?? 'desc';

            $columns = [
                0 => 'id',
                1 => 'name',
                2 => 'message',
                3 => 'qtdRows',
                4 => 'status',
                5 => 'created_at'
            ];

            $orderColumn = $columns[$orderColumnIndex] ?? 'id';

            // 🔎 Se for reseller, aplica filtro por user_id
            $filterUserId = ($userFunc === 'reseller') ? $userId : null;

            // Total de registros com ou sem filtro
            $totalRecords = CampaignSearch::getCampaignsCount(
                $tenancyId,
                $searchValue,
                $filterUserId
            );

            // Registros paginados
            $campaigns = CampaignSearch::getCampaignsPaginated(
                $tenancyId,
                $searchValue,
                $start,
                $length,
                $orderColumn,
                strtoupper($orderDir),
                $filterUserId
            );

            $data = [];

            foreach ($campaigns as $campaign) {
                $statusText = match ($campaign->status) {
                    'y' => 'Ativa',
                    'n' => 'Inativa',
                    'f' => 'Finalizada',
                    default => 'Desconhecido'
                };

                $data[] = [
                    'id'         => $campaign->id,
                    'name'       => $campaign->name,
                    'message'    => $campaign->message,
                    'status'     => $campaign->status,
                    'qtdRows'    => $campaign->qtd_contacts ?? 0,
                    'statusCamp' => $statusText,
                    'created'    => $campaign->created_at
                ];
            }

            return new Response(200, [
                'draw'            => $draw,
                'recordsTotal'    => $totalRecords,
                'recordsFiltered' => $totalRecords,
                'data'            => $data
            ], 'application/json');
        } catch (\Exception $e) {
            return new Response(500, [
                'error'   => true,
                'message' => $e->getMessage()
            ], 'application/json');
        }
    }

    public static function setNewCampaign($request): Response
    {


        try {
            $obUser = SessionUser::getLogged();
            if (!$obUser) {
                return new Response(401, [
                    'success' => false,
                    'status' => 401,
                    'message' => 'Usuário não autenticado.'
                ], 'application/json');
            }

            $postVars = $request->getPostVars();

            $baseName = trim($postVars['campaignName'] ?? '');
            $message = trim($postVars['messageInput'] ?? '');
            $status  = trim($postVars['status'] ?? 'y');
            $type_msg = trim($postVars['sender_type'] ?? '');
            $charset_msg = trim($postVars['charset'] ?? '');

            if (!$baseName || !$message || !$status) {
                return new Response(422, [
                    'success' => false,
                    'status'  => 422,
                    'message' => 'Preencha todos os campos obrigatórios.'
                ], 'application/json');
            }

            $name = CampaignNameCode::generate(
                $baseName,
                static function (string $candidate) use ($obUser): bool {
                    return (bool) (new \WilliamCosta\DatabaseManager\Database('campaign'))
                        ->select(
                            'tenancy_id = :tenancy_id AND name = :name',
                            [
                                ':tenancy_id' => (string)$obUser['tenancy_id'],
                                ':name' => $candidate,
                            ],
                            '',
                            '1',
                            ['id']
                        )
                        ->fetch(\PDO::FETCH_ASSOC);
                }
            );



            $obCampaign = new CampaignSearch();
            $obCampaign->tenancy_id = $obUser['tenancy_id'];
            $obCampaign->user_id = $obUser['id'];
            $obCampaign->name = $name;
            $obCampaign->message = $message;
            $obCampaign->status = $status;
            $obCampaign->charset_msg = $charset_msg;
            $obCampaign->type_msg = $type_msg;
            $obCampaign->created_at = date('Y-m-d H:i:s');
            $obCampaign->updated_at = date('Y-m-d H:i:s');
            $obCampaign->register();

            return new Response(200, [
                'success' => true,
                'status' => 200,
                'message' => 'Campanha criada com sucesso!',
                'campaign_id' => $obCampaign->id,
                'name' => $name,
            ], 'application/json');

        } catch (\Exception $e) {
            return new Response(500, [
                'success' => false,
                'status' => 500,
                'message' => $e->getMessage()
            ], 'application/json');
        }

    }

    /*public static function setUploadCampaign($request): Response
    {
        $postVars = $request->getPostVars();
        $campaignId = $postVars['campaignId'] ?? null;

        try {
            $obUser = SessionUser::getLogged();
            if (!$obUser) {
                return new Response(401, json_encode([
                    'status' => 401,
                    'message' => 'Usuário não autenticado.'
                ]), 'application/json');
            }

            if (!isset($_FILES['fileInput']) || empty($_FILES['fileInput']['tmp_name'])) {
                return new Response(422, json_encode([
                    'status' => 422,
                    'message' => 'Nenhum arquivo enviado.'
                ]), 'application/json');
            }

            $file = $_FILES['fileInput'];
            $filename = $file['name'];
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            $allowedExtensions = ['csv', 'xlsx', 'xls'];
            if (!in_array($extension, $allowedExtensions)) {
                return new Response(422, json_encode([
                    'status' => 422,
                    'message' => 'Tipo de arquivo não suportado. Envie um CSV ou Excel (.xlsx).'
                ]), 'application/json');
            }

            $inserted = 0;

            // Definir aliases e localização das colunas
            $nameAliases = ['name', 'nome', 'cliente', 'full_name'];
            $phoneAliases = ['phone', 'telefone', 'celular'];

            function findColumnIndex(array $headerMap, array $aliases): ?int
            {
                foreach ($aliases as $alias) {
                    if (isset($headerMap[$alias])) {
                        return $headerMap[$alias];
                    }
                }
                return null;
            }

            function normalizePhoneNumber($input): string
            {
                // Se vier com vírgula (notação científica no Excel em pt-BR), converte para ponto
                $input = str_replace(',', '.', $input);

                // Se for notação científica, converte corretamente para inteiro
                if (stripos($input, 'e') !== false) {
                    $input = number_format((float)$input, 0, '', '');
                }

                // Remove qualquer caractere não numérico
                $digits = preg_replace('/\D+/', '', $input);

                // Se tiver só 11 dígitos (ex: 21999999999), adiciona o DDI 55
                if (strlen($digits) === 11) {
                    $digits = '55' . $digits;
                }

                // Se tiver 13 dígitos e não começar com 55, adiciona o DDI
                if (strlen($digits) === 13 && !str_starts_with($digits, '55')) {
                    $digits = '55' . substr($digits, -11);
                }

                return $digits;
            }


            // === CSV ===
            if ($extension === 'csv') {
                if (($handle = fopen($file['tmp_name'], "r")) !== false) {
                    $header = fgetcsv($handle, 1000, ";", '"', "\\");
                    if (!$header) {
                        fclose($handle);
                        throw new \Exception('Não foi possível ler o cabeçalho do CSV.');
                    }

                    $headerLower = array_map(fn($h) => strtolower(trim($h)), $header);
                    $headerMap = array_flip($headerLower);

                    $nameIndex = findColumnIndex($headerMap, $nameAliases);
                    $phoneIndex = findColumnIndex($headerMap, $phoneAliases);

                    if ($nameIndex === null || $phoneIndex === null) {
                        fclose($handle);
                        throw new \Exception('CSV inválido. As colunas Nome e Telefone são obrigatórias.');
                    }

                    while (($data = fgetcsv($handle, 1000, ";", '"', "\\")) !== false) {
                        if (count($data) < max($nameIndex, $phoneIndex) + 1)
                            continue;


                        $contactName = trim($data[$nameIndex]);
                        $contactPhone = normalizePhoneNumber($data[$phoneIndex]);;

                        $isValid = strlen($contactPhone) >= 13 && str_starts_with($contactPhone, '55');
                        if ($contactName && $isValid) {
                            $obContact = new ContactsSearch();
                            $obContact->tenancy_id = (string) $obUser['tenancy_id'];
                            $obContact->user_id = (int) $obUser['id'];
                            $obContact->name = $contactName;
                            $obContact->phone = $contactPhone;
                            $obContact->campaign_id = (int) $campaignId;
                            $obContact->created_at = date('Y-m-d H:i:s');
                            $obContact->updated_at = date('Y-m-d H:i:s');
                            $obContact->register();
                            $inserted++;
                        }
                    }
                    fclose($handle);
                }

                // === XLSX/XLS ===
            } else {
                $spreadsheet = IOFactory::load($file['tmp_name']);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();

                if (empty($rows)) {
                    throw new \Exception('Planilha Excel vazia ou inválida.');
                }

                $header = array_map(fn($h) => strtolower(trim((string) $h)), $rows[0]);
                unset($rows[0]);

                $headerMap = array_flip($header);
                $nameIndex = findColumnIndex($headerMap, $nameAliases);
                $phoneIndex = findColumnIndex($headerMap, $phoneAliases);

                if ($nameIndex === null || $phoneIndex === null) {
                    throw new \Exception('Excel inválido. As colunas Nome e Telefone são obrigatórias.');
                }

                foreach ($rows as $row) {
                    $contactName = trim((string) $row[$nameIndex]);
                    $contactPhoneCsv = normalizePhoneNumber($row[$phoneIndex]);

                    $isValid = strlen($contactPhoneCsv) >= 13 && str_starts_with($contactPhoneCsv, '55');
                    if ($contactName && $isValid) {
                        $obContact = new ContactsSearch();
                        $obContact->tenancy_id = (string) $obUser['tenancy_id'];
                        $obContact->user_id = (int) $obUser['id'];
                        $obContact->name = $contactName;
                        $obContact->phone = $contactPhoneCsv;
                        $obContact->campaign_id = (int) $campaignId;
                        $obContact->created_at = date('Y-m-d H:i:s');
                        $obContact->updated_at = date('Y-m-d H:i:s');
                        $obContact->register();
                        $inserted++;
                    }
                }
            }

            return new Response(200, json_encode([
                'status' => 200,
                'message' => "{$inserted} contatos adicionados com sucesso!"
            ]), 'application/json');

        } catch (\Exception $e) {
            return new Response(500, json_encode([
                'status' => 500,
                'message' => $e->getMessage()
            ]), 'application/json');
        }
    }


    public static function getEditCampaign($request, $id): Response|string
    {
    try {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, json_encode([
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ]), 'application/json');
        }

        // Buscar campanha pelo id e tenancy_id
        $obCampaign = CampaignSearch::getCampaignByIdAndTenancy($id, $obUser['tenancy_id']);

        if (!$obCampaign instanceof CampaignSearch) {
            // Caso não encontre
            return new Response(404, json_encode([
                'status' => 404,
                'message' => 'Campanha não encontrada ou não pertence a este tenant.'
            ]), 'application/json');
        }

        $valueSms = self::currentSmsRate((string)$obUser['tenancy_id']);

        // Renderizar a view passando os dados da campanha para o formulário
        $content = View::render('/campaign/edit', [
            'id' => $obCampaign->id,
            'name' => $obCampaign->name,
            'message' => $obCampaign->message,
            'status' => $obCampaign->status,
            'value_sms' => $valueSms,
        ]);

        return parent::getComponentsCampaign('Maxx Solutions - SMS | Editar Campanha', $content);

    } catch (\Exception $e) {
        return new Response(500, json_encode([
            'status' => 500,
            'message' => $e->getMessage()
        ]), 'application/json');
    }
    }*/

    public static function getEditCampaign($request, $id): Response|string
    {
        try {
            $obUser = SessionUser::getLogged();
            if (!$obUser) {
                return new Response(401, [
                    'status' => 401,
                    'message' => 'Usuário não autenticado.'
                ], 'application/json');
            }

            $obCampaign = CampaignSearch::getCampaignByIdAndTenancy((int)$id, $obUser['tenancy_id']);
            if (!$obCampaign instanceof CampaignSearch) {
                return new Response(404, [
                    'status' => 404,
                    'message' => 'Campanha não encontrada ou não pertence a este tenant.'
                ], 'application/json');
            }

            $valueSms = self::currentSmsRate((string)$obUser['tenancy_id']);

            $content = View::render('/campaign/edit', [
                'id' => $obCampaign->id,
                'name' => $obCampaign->name,
                'message' => $obCampaign->message,
                'status' => $obCampaign->status,
                'type_msg' => $obCampaign->type_msg ?: 'short',
                'charset_msg' => $obCampaign->charset_msg ?? 0,
                'value_sms' => $valueSms,
            ]);

            return parent::getComponentsCampaign('Maxx Solutions - SMS | Editar Campanha', $content);
        } catch (\Exception $e) {
            return new Response(500, [
                'status' => 500,
                'message' => $e->getMessage()
            ], 'application/json');
        }
    }

    public static function setUploadCampaign($request): Response
    {
        $ObUser = SessionUser::getLogged();
        if (!$ObUser) {
            return new Response(401, [
                'success' => false,
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $file = $_FILES['fileInput'] ?? null;
        $campaignId = $request->getPostVars()['campaignId'] ?? null;

        if (!$file || empty($file['tmp_name'])) {
            return new Response(422, [
                'success' => false,
                'status' => 422,
                'message' => 'Nenhum arquivo enviado.'
            ], 'application/json');
        }

        try {
            $service = new ImportContactsService($ObUser, (int)$campaignId);
            $count = $service->import($file);

            return new Response(200, [
                'success' => true,
                'status' => 200,
                'message' => "{$count} contatos importados com sucesso!"
            ], 'application/json');

        } catch (\Exception $e) {
            return new Response(500, [
                'success' => false,
                'status' => 500,
                'message' => $e->getMessage()
            ], 'application/json');
        }
    }


    public static function setEditCampaign($request, $id): Response
    {

        try {
            $userOb = SessionUser::getLogged();
            if (!$userOb) {
                return new Response(401, [
                    'success' => false,
                    'status' => 401,
                    'message' => 'Usuário não autenticado.'
                ], 'application/json');
            }



            // Busca a campanha
            $obCampaign = CampaignSearch::getCampaignByIdAndTenancy($id, $userOb['tenancy_id']);

            if (!$obCampaign instanceof CampaignSearch) {
                return new Response(404, [
                    'success' => false,
                    'status' => 404,
                    'message' => 'Campanha não encontrada ou não pertence a este tenant.'
                ], 'application/json');
            }

            // Dados do formulário
            $postVars = $request->getPostVars();
            // Validação simples
            if (empty($postVars['campaignName']) || empty($postVars['messageInput'])) {
                return new Response(400, [
                    'success' => false,
                    'status' => 400,
                    'message' => 'Nome e mensagem são obrigatórios.'
                ], 'application/json');
            }

            // Atualiza os dados
            $obCampaign->name = trim($postVars['campaignName']);
            $obCampaign->message = trim($postVars['messageInput']);
            $obCampaign->status = $postVars['status'] ?? 'active';
            $obCampaign->charset_msg = trim($postVars['charset'] ?? '0');
            $obCampaign->type_msg = trim($postVars['sender'] ?? 'short');
            $obCampaign->updated_at = date('Y-m-d H:i:s');

            // Salva no banco
            if (!$obCampaign->update()) {
                return new Response(500, [
                    'success' => false,
                    'status' => 500,
                    'message' => 'Erro ao atualizar campanha.'
                ], 'application/json');
            }

            return new Response(200, [
                'success' => true,
                'status' => 200,
                'message' => 'Campanha atualizada com sucesso.',
                'campaign_id' => $obCampaign->id
            ], 'application/json');

        } catch (\Exception $e) {
            return new Response(500, [
                'success' => false,
                'status' => 500,
                'message' => $e->getMessage()
            ], 'application/json');
        }
    }

    public static function setDeleteCampaign($request, $id): Response
    {
        // Obtém o usuário logado
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'success' => false,
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        // Valida o ID da campanha
        if (!is_numeric($id)) {
            return new Response(400, [
                'success' => false,
                'status' => 400,
                'message' => 'ID da campanha inválido.'
            ], 'application/json');
        }

        // Busca a campanha pelo ID e tenancy
        $obCampaign = CampaignSearch::getCampaignByIdAndTenancy($id, $obUser['tenancy_id']);


        if (!$obCampaign) {
            return new Response(404, [
                'success' => false,
                'status' => 404,
                'message' => 'Campanha não encontrada.'
            ], 'application/json');
        }

        $success = CampaignSearch::deleteByIdAndTenancy($id, $obUser['tenancy_id']);

        if (!$success) {
            return new Response(500, [
                'success' => false,
                'status' => 500,
                'message' => 'Erro ao excluir a campanha.'
            ], 'application/json');
        }

        return new Response(200, [
            'success' => true,
            'status' => 200,
            'message' => 'Campanha excluída com sucesso.'
        ], 'application/json');

    }
}
