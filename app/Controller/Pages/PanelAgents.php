<?php

namespace App\Controller\Pages;

use App\Config\TelephonyConfig;
use App\Http\Response;
use App\Model\Entity\CampaignVoice;
use App\Model\Entity\PauseConfig;
use App\Model\Entity\PauseLog;
use App\Model\Entity\RegisterTenancies;
use App\Service\AgentManager;
use App\Session\User as SessionUser;
use App\Utils\AsteriskEnv;
use App\Utils\View;
use App\Model\Entity\AgentsPortal as EntityAgent;


class PanelAgents extends ViewComponents
{
    private static function resolveAgentOnlineState(AgentManager $agentManager, string $ramal, array $currentData): bool
    {
        $checked = $agentManager->checkEndpointOnline($ramal);
        if ($checked === null) {
            $cached = $currentData['online'] ?? ($currentData['registered'] ?? false);
            return $cached === true || $cached === 1 || $cached === '1';
        }

        return $checked;
    }

    /**
     * Renderiza a página do painel
     */
    public static function getComponentsPanelAgents($request): Response|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            $request->getRouter()->redirect('/login');
        }

        //echo password_hash('0+hAwCV0', PASSWORD_DEFAULT);

        $content = View::render('/callcenter/painel', [
            'ASTERISK_WS_HOST' => AsteriskEnv::wsHost(),
            'ASTERISK_WS_PORT' => AsteriskEnv::wsPort(),
        ]);
        return parent::getComponentsUsers('Maxx Solutions | Painel do Agente', $content);
    }

    /**
     * Realiza o check-in do agente (Login do Ramal) via POST JSON
     */
    public static function setLoginAgent($request): Response
    {
        $sessaoAtiva = SessionUser::getLogged();
        $tenancyId   = $sessaoAtiva['tenancy_id'] ?? null;
        $userId      = $sessaoAtiva['id'] ?? null;

        $input = file_get_contents('php://input');
        $postVars = json_decode($input, true) ?? [];

        $extension = trim($postVars['ramal'] ?? '');
        $password  = $postVars['password'] ?? '';

        if (empty($extension) || empty($password)) {
            return new Response(200, json_encode(['status' => 'ERROR', 'message' => 'Ramal e senha são obrigatórios!']), 'application/json');
        }

        if (!$tenancyId) {
            return new Response(200, json_encode(['status' => 'ERROR', 'message' => 'Sessão inválida. Relogue no sistema.']), 'application/json');
        }

        $obAgent = EntityAgent::getAgentByExtension($extension, $tenancyId);

        // Validação de existência e senha
        if (!$obAgent instanceof EntityAgent || !password_verify($password, $obAgent->password ?? '')) {
            return new Response(200, json_encode(['status' => 'ERROR', 'message' => 'Ramal ou senha incorretos.']), 'application/json');
        }

        if (($obAgent->status ?? 'n') === 'n') {
            return new Response(200, json_encode(['status' => 'ERROR', 'message' => 'Este ramal está desativado.']), 'application/json');
        }

        $obAgent->user_id       = $userId;
        $obAgent->last_activity = date('Y-m-d H:i:s');
        $obAgent->updatedAt     = date('Y-m-d H:i:s');
        $obAgent->atualizar();

        try {
            $agentManager = new AgentManager([
                'ari_host' => TelephonyConfig::ariHost(),
                'ari_auth' => TelephonyConfig::ariAuth()
            ]);

            $redis = $agentManager->getRedis();
            $rawCurrent = $redis->hGet('discador:agentes', (string)$obAgent->extension);
            $currentData = $rawCurrent ? json_decode($rawCurrent, true) : [];
            $currentData = is_array($currentData) ? $currentData : [];
            $isOnline = self::resolveAgentOnlineState($agentManager, (string)$obAgent->extension, $currentData);

            $currentData['ramal'] = (string)$obAgent->extension;
            $currentData['name'] = $obAgent->name ?? 'Agente';
            $currentData['user_id'] = (int)$userId;
            $currentData['tenancy_id'] = (string)$tenancyId;
            $currentData['status'] = 'LIVRE';
            $currentData['status_name'] = null;
            $currentData['color'] = 'emerald';
            $currentData['online'] = $isOnline;
            $currentData['last_activity'] = $obAgent->last_activity;
            $currentData['status_since'] = time();
            $currentData['updated'] = time();
            $currentData['last_update_by'] = 'setLoginAgent';

            $redis->hSet('discador:agentes', (string)$obAgent->extension, json_encode($currentData, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
        }

        // RETORNO COM A SENHA PLANA PARA O WEBRTC
        return new Response(200, [
            'status'  => 'OK',
            'message' => 'Olá ' . ($obAgent->name ?? 'Agente') . '!',
            'data'    => [
                'ramal' => $obAgent->extension,
                'nome'  => $obAgent->name,
                'password_sip' => $password // <--- ENVIAMOS A SENHA QUE ACABOU DE SER VALIDADA
            ]
        ], 'application/json');
    }



    /**
     * Rota: /callcenter/breaks-list
     */
    public static function getComponentsListBreaks($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) return new Response(401, json_encode(['message' => "Não autenticado"]), 'application/json');

        $userId   = (int)$obUser['id'];
        $role     = strtolower($obUser['function'] ?? '');
        $tenancy  = (string)($obUser['tenancy_id'] ?? '');

        // 🛡️ LÓGICA DE PERMISSÕES MANTIDA
        // Se for admin ou super_admin, filterUserId é null (vê tudo da empresa)
        // Caso contrário, filtra pelo ID do usuário logado
        $filterUserId = in_array($role, ['super_admin', 'admin']) ? null : $userId;

        try {
            // Chama a Model passando os filtros de segurança
            $pausas = PauseConfig::getBreaksList($tenancy, $filterUserId);

            return new Response(200,([
                'success' => true,
                'total'   => count($pausas),
                'data'    => $pausas
            ]), 'application/json');

        } catch (\Throwable $e) {
            return new Response(500, json_encode(['success' => false, 'error' => $e->getMessage()]), 'application/json');
        }
    }

    public static function getActiveBreaksForPanel($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) return new Response(401, json_encode(['success' => false]), 'application/json');

        $tenancy = (string)($obUser['tenancy_id'] ?? '');
        $role    = strtolower($obUser['function'] ?? '');
        $myId    = (int)($obUser['id'] ?? 0);

        try {
            // --- LÓGICA DE HIERARQUIA COM A NOVA FUNÇÃO ---

            if ($role === 'super_admin' || $role === 'admin' || $role === 'reseller') {
                // Se eu sou o "Dono" (Admin ou Revendedor), eu vejo as pausas criadas por MIM.
                $filterId = $myId;
            } else {
                // Se eu sou Operador, Financeiro, Agente, etc:
                // Eu herdo as pausas do Administrador da minha Tenancy.
                // Usamos a sua nova função para achar o ID desse Administrador.
                $filterId = RegisterTenancies::getTenancyOwnerUserId($tenancy);

                // Fallback de segurança: se não achar o admin, tenta o próprio ID
                if (!$filterId) $filterId = $myId;
            }

            // Agora chamamos a sua Model original que você não queria mexer
            $pausas = PauseConfig::getBreaksList($tenancy, $filterId, 'active');

            return new Response(200, json_encode([
                'success' => true,
                'data'    => $pausas
            ]), 'application/json');

        } catch (\Throwable $e) {
            return new Response(500, json_encode(['success' => false, 'error' => $e->getMessage()]), 'application/json');
        }
    }

    public static function SetNewsBreaks($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) return new Response(401, json_encode(['success' => false, 'message' => "Não autenticado"]), 'application/json');

        $postVars = $request->getPostVars();
        $tenancy  = (string)($obUser['tenancy_id'] ?? '');
        $userId   = (int)$obUser['id'];

        try {
            // 1. Validar e formatar o Tempo Máximo
            $maxTime = $postVars['max_time'] ?? '00:15:00';
            if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $maxTime)) {
                // Garante o formato HH:MM:SS para o MySQL
                $maxTime = date('H:i:s', strtotime($maxTime));
            }

            // 2. Mapeamento de Cor -> Tipo (Perfeito como você fez)
            $color = $postVars['color_theme'] ?? 'orange';
            $type = match ($color) {
                'blue'    => 'Produtiva',
                'emerald' => 'Feedback',
                'purple'  => 'Sistemas',
                'red'     => 'Logoff',
                'slate'   => 'Treinamento',
                default   => 'Improdutiva',
            };

            // 3. Preparar dados
            $data = [
                'id'           => !empty($postVars['id']) ? (int)$postVars['id'] : null,
                'tenancy_id'   => $tenancy,
                'user_id'      => $userId, // O criador é o dono
                'name'         => trim($postVars['name'] ?? ''),
                'description'  => trim($postVars['description'] ?? ''),
                'type'         => $type,
                'color_theme'  => $color,
                'max_time'     => $maxTime,
                'icon'         => $postVars['icon'] ?? 'ni-button-pause',
                'status'       => $postVars['status'] ?? 'active'
            ];

            if (empty($data['name'])) {
                return new Response(400, json_encode(['success' => false, 'message' => "O nome da pausa é obrigatório"]), 'application/json');
            }

            // --- TRAVA DE SEGURANÇA (Opcional mas recomendado) ---
            // Se for edição (ID presente), você pode validar na Model se
            // aquele ID pertence mesmo ao $userId ou ao $tenancy antes de salvar.

            // 4. Salvar via Model
            $result = PauseConfig::saveBreak($data);

            return new Response(200, json_encode([ // Adicionado json_encode
                'success' => true,
                'message' => "Pausa salva com sucesso!",
                'data'    => $result
            ]), 'application/json');

        } catch (\Throwable $e) {
            return new Response(500, json_encode([ // Adicionado json_encode
                'success' => false,
                'message' => $e->getMessage()
            ]), 'application/json');
        }
    }

    public static function DeleteBreaks($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) return new Response(401, json_encode(['success' => false, 'message' => "Não autenticado"]), 'application/json');

        $postVars = $request->getPostVars();
        $id = (int)($postVars['id'] ?? 0);

        if ($id <= 0) {
            return new Response(400, json_encode(['success' => false, 'message' => "ID inválido para exclusão"]), 'application/json');
        }

        try {
            // Chame o método de delete da sua Model (ajuste o nome se for diferente)
            $success = PauseConfig::deleteBreak($id);

            if ($success) {
                return new Response(200, json_encode([
                    'success' => true,
                    'message' => "Pausa excluída com sucesso!"
                ]), 'application/json');
            } else {
                return new Response(500, json_encode([
                    'success' => false,
                    'message' => "Não foi possível excluir o registro no banco."
                ]), 'application/json');
            }

        } catch (\Throwable $e) {
            return new Response(500, json_encode(['success' => false, 'message' => $e->getMessage()]), 'application/json');
        }
    }

    public static function toggleStatusBreak($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) return new Response(401, json_encode(['success' => false]), 'application/json');

        // Captura o JSON enviado pelo fetch (id e status)
        $json = json_decode(file_get_contents('php://input'), true);
        $id = $json['id'] ?? null;
        $newStatus = $json['status'] ?? 'inactive';
        $tenancy = (string)($obUser['tenancy_id'] ?? '');

        try {
            if (!$id) {
                return new Response(400, json_encode(['success' => false, 'message' => "ID não informado"]), 'application/json');
            }

            // Criamos os dados para a model respeitando a tenancy por segurança
            $data = [
                'id'     => (int)$id,
                'status' => $newStatus === 'active' ? 'active' : 'inactive'
            ];

            // Usamos a sua model saveBreak que já trata o update
            // IMPORTANTE: Sua model deve permitir update parcial ou você
            // deve buscar o registro antes se o seu Database exigir todos os campos.
            PauseConfig::saveBreak($data);

            return new Response(200,(['success' => true]), 'application/json');

        } catch (\Throwable $e) {
            return new Response(500,(['success' => false, 'message' => $e->getMessage()]), 'application/json');
        }
    }

    public static function setAgentStatus($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) return new Response(401, json_encode(['success' => false]), 'application/json');

        $json = json_decode(file_get_contents('php://input'), true);
        $ramal    = $json['ramal'] ?? null;
        $status   = $json['status'] ?? 'disponivel';
        $pausaId  = $json['pausa_id'] ?? null;
        $tenancy  = (string)($obUser['tenancy_id'] ?? '');

        try {
            PauseLog::finalizarPausaAtiva($obUser['id'], $tenancy);

            $agentManager = new AgentManager([
                'ari_host' => TelephonyConfig::ariHost(),
                'ari_auth' => TelephonyConfig::ariAuth()
            ]);
            $redis = $agentManager->getRedis();

            $rawCurrent = $redis->hGet('discador:agentes', (string)$ramal);
            $currentData = $rawCurrent ? json_decode($rawCurrent, true) : [];
            $currentData = is_array($currentData) ? $currentData : [];
            $isOnline = self::resolveAgentOnlineState($agentManager, (string)$ramal, $currentData);

            if ($status === 'pausa' && $pausaId) {
                $config = PauseConfig::getBreakById($pausaId, $tenancy);

                $log = new PauseLog();
                $log->tenancy_id = $tenancy;
                $log->user_id = $obUser['id'];
                $log->pausa_config_id = $pausaId;
                $log->iniciarPausa();

                $currentData['status']       = 'PAUSA';
                $currentData['status_name']  = strtoupper($config['name'] ?? 'PAUSA');
                $currentData['color']        = $config['color_theme'] ?? 'orange';
            } else {
                // ✅ CORREÇÃO AQUI: Para sair da pausa, status_name DEVE ser null
                // Isso avisa ao AgentManager que a regra de negócio acabou.
                $currentData['status']       = 'LIVRE';
                $currentData['status_name']  = null; // 👈 Mude de 'DISPONÍVEL' para null
                $currentData['color']        = 'emerald';
            }

            $currentData['ramal'] = (string)$ramal;
            $currentData['user_id'] = (int)$obUser['id'];
            $currentData['tenancy_id'] = (string)$tenancy;
            $currentData['status_since'] = time();
            $currentData['online']       = $isOnline;
            $currentData['updated']      = time();
            $currentData['last_update_by'] = 'setAgentStatus_manual';

            $redis->hSet('discador:agentes', (string)$ramal, json_encode($currentData, JSON_UNESCAPED_UNICODE));

            return new Response(200, json_encode([
                'success' => true,
                'message' => "Status atualizado com sucesso"
            ]), 'application/json');

        } catch (\Throwable $e) {
            return new Response(500, json_encode(['success' => false, 'message' => $e->getMessage()]), 'application/json');
        }
    }

    public static function getContactDataForPanel($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) return new Response(401, json_encode(['success' => false]), 'application/json');

        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        $listId = (int)($data['id'] ?? 0);
        $phone  = (string)($data['telefone'] ?? '');

        // Agora passamos o tenant_id e o user_id (que estão na sua sessão)
        $contato = CampaignVoice::getContactDetailsByListAndPhone(
            $listId,
            $phone,
            $obUser['tenancy_id'],
            (int)$obUser['id'] // Ou 'user_id', dependendo de como está na sua SessionUser
        );

        //echo "<pre>";
        //print_r($postVars);
        //echo "</pre>";exit();

        if (!$contato) {
            return new Response(404, json_encode([
                'success' => false,
                'message' => 'Contato não localizado ou você não tem permissão para esta lista'
            ]), 'application/json');
        }


        return new Response(200, json_encode([
            'success' => true,
            'cliente' => $contato
        ]),'application/json');

    }

    public static function getLookupClient($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(
                401,
                json_encode([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ]),
                'application/json'
            );
        }

        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        $cpf      = (string)($data['cpf'] ?? '');
        $telefone = (string)($data['telefone'] ?? '');
        $nome     = (string)($data['nome'] ?? '');

        $cpf      = preg_replace('/\D/', '', $cpf);
        $telefone = preg_replace('/\D/', '', $telefone);
        $nome     = trim(preg_replace('/\s+/', ' ', $nome));

        if (empty($cpf) && empty($telefone) && empty($nome)) {
            return new Response(
                400,
                json_encode([
                    'success' => false,
                    'message' => 'Informe CPF, telefone ou nome'
                ]),
                'application/json'
            );
        }

        try {
            $lookup = new LookupClient();
            $clienteBruto = null;
            $tipoBusca = '';

            if (!empty($cpf)) {
                $tipoBusca = 'cpf';
                $clienteBruto = $lookup->lookupCpf($cpf);
            } elseif (!empty($telefone)) {
                $tipoBusca = 'telefone';
                $clienteBruto = $lookup->lookupPhone($telefone);
            } elseif (!empty($nome)) {
                $tipoBusca = 'nome';
                $clienteBruto = $lookup->lookupName($nome);
            }

            if (!$clienteBruto || empty($clienteBruto['dados'])) {
                return new Response(
                    404,
                    json_encode([
                        'success' => false,
                        'message' => 'Cliente não localizado',
                        'tipo_busca' => $tipoBusca
                    ]),
                    'application/json'
                );
            }

            $dados            = $clienteBruto['dados'];
            $dadosPrincipais  = $dados['DADOS'] ?? [];
            $drops            = $dados['DROPS'] ?? [];
            $telefonesList    = $dados['TELEFONE_LIST'] ?? [];
            $telefonesDetalhe = $dados['TELEFONE'] ?? [];
            $emails           = $dados['Email'] ?? [];
            $score            = $dados['SCORE'] ?? [];
            $parentes         = $dados['PARENTES'] ?? [];
            $irpf             = $dados['IRPF'] ?? [];
            $poder            = $dados['Poder'] ?? [];
            $pis              = $dados['PIS'] ?? [];
            $tse              = $dados['TSE'] ?? [];
            $outrasBases      = $dados['CONSULTAS_OUTRAS_DBS'] ?? [];

            $enderecoPrincipal = [];
            if (!empty($drops) && is_array($drops)) {
                $enderecoPrincipal = $drops[0];
            }

            $phonePrincipal = '';
            if (!empty($telefonesList) && is_array($telefonesList)) {
                $phonePrincipal = preg_replace('/\D/', '', (string)$telefonesList[0]);
            } elseif (!empty($telefone)) {
                $phonePrincipal = $telefone;
            }

            $clienteFormatado = [
                'name' => (string)($dadosPrincipais['NOME'] ?? ''),
                'phone' => $phonePrincipal,
                'cpf' => (string)($dadosPrincipais['CPF'] ?? $cpf),
                'campaign_name' => 'BUSCA MANUAL',
                'extra_data' => [
                    'nome_mae'   => (string)($dadosPrincipais['NOME_MAE'] ?? ''),
                    'nascimento' => (string)($dadosPrincipais['NASC'] ?? ''),
                    'sexo'       => (string)($dadosPrincipais['SEXO'] ?? ''),
                    'renda'      => (string)($dadosPrincipais['RENDA'] ?? ''),
                    'cidade'     => (string)($enderecoPrincipal['CIDADE'] ?? ''),
                    'bairro'     => (string)($enderecoPrincipal['BAIRRO'] ?? ''),
                    'uf'         => (string)($enderecoPrincipal['UF'] ?? ''),
                    'cep'        => (string)($enderecoPrincipal['CEP'] ?? ''),
                    'telefones'  => !empty($telefonesList) && is_array($telefonesList)
                        ? implode(' | ', $telefonesList)
                        : ''
                ]
            ];

            $resumo = [
                [
                    'label' => 'Nome da Mãe',
                    'value' => (string)($dadosPrincipais['NOME_MAE'] ?? '')
                ],
                [
                    'label' => 'Nascimento',
                    'value' => (string)($dadosPrincipais['NASC'] ?? '')
                ],
                [
                    'label' => 'Sexo',
                    'value' => (string)($dadosPrincipais['SEXO'] ?? '')
                ],
                [
                    'label' => 'Renda',
                    'value' => (string)($dadosPrincipais['RENDA'] ?? '')
                ],
                [
                    'label' => 'Cidade',
                    'value' => (string)($enderecoPrincipal['CIDADE'] ?? '')
                ],
                [
                    'label' => 'Bairro',
                    'value' => (string)($enderecoPrincipal['BAIRRO'] ?? '')
                ],
                [
                    'label' => 'UF',
                    'value' => (string)($enderecoPrincipal['UF'] ?? '')
                ],
                [
                    'label' => 'CEP',
                    'value' => (string)($enderecoPrincipal['CEP'] ?? '')
                ]
            ];

            $telefonesFormatados = [];
            if (!empty($telefonesDetalhe) && is_array($telefonesDetalhe)) {
                foreach ($telefonesDetalhe as $tel) {
                    $ddd    = (string)($tel['DDD'] ?? '');
                    $numero = (string)($tel['TELEFONE'] ?? '');
                    $full   = preg_replace('/\D/', '', $ddd . $numero);

                    $tipoTelefone = match ((string)($tel['TIPO_TELEFONE'] ?? '')) {
                        '1' => 'Fixo',
                        '2' => 'Comercial',
                        '3' => 'Celular',
                        default => 'Não informado'
                    };

                    $telefonesFormatados[] = [
                        'numero' => $full,
                        'tipo' => $tipoTelefone,
                        'classificacao' => (string)($tel['CLASSIFICACAO'] ?? ''),
                        'informacao' => (string)($tel['DT_INFORMACAO'] ?? '')
                    ];
                }
            } elseif (!empty($telefonesList) && is_array($telefonesList)) {
                foreach ($telefonesList as $tel) {
                    $telefonesFormatados[] = [
                        'numero' => preg_replace('/\D/', '', (string)$tel),
                        'tipo' => 'Não informado',
                        'classificacao' => '',
                        'informacao' => ''
                    ];
                }
            }

            $enderecosFormatados = [];
            if (!empty($drops) && is_array($drops)) {
                foreach ($drops as $end) {
                    $logradouro = trim(
                        (string)($end['LOGR_TIPO'] ?? '') . ' ' .
                        (string)($end['LOGR_NOME'] ?? '')
                    );

                    $enderecosFormatados[] = [
                        'logradouro' => trim($logradouro),
                        'numero' => (string)($end['LOGR_NUMERO'] ?? ''),
                        'complemento' => (string)($end['LOGR_COMPLEMENTO'] ?? ''),
                        'bairro' => (string)($end['BAIRRO'] ?? ''),
                        'cidade' => (string)($end['CIDADE'] ?? ''),
                        'uf' => (string)($end['UF'] ?? ''),
                        'cep' => (string)($end['CEP'] ?? ''),
                        'informacao' => (string)($end['DT_ATUALIZACAO'] ?? '')
                    ];
                }
            }

            $emailsFormatados = [];
            if (!empty($emails) && is_array($emails)) {
                foreach ($emails as $email) {
                    $emailsFormatados[] = [
                        'email' => (string)($email['EMAIL'] ?? ''),
                        'score' => (string)($email['EMAIL_SCORE'] ?? ''),
                        'estrutura' => (string)($email['ESTRUTURA'] ?? ''),
                        'status' => (string)($email['STATUS_VT'] ?? '')
                    ];
                }
            }

            $scorePerfil = [];

            if (!empty($score[0])) {
                $scorePerfil[] = [
                    'label' => 'Score CSB8',
                    'value' => (string)($score[0]['CSB8'] ?? '')
                ];
                $scorePerfil[] = [
                    'label' => 'Faixa CSB8',
                    'value' => (string)($score[0]['CSB8_FAIXA'] ?? '')
                ];
                $scorePerfil[] = [
                    'label' => 'Score CSBA',
                    'value' => (string)($score[0]['CSBA'] ?? '')
                ];
                $scorePerfil[] = [
                    'label' => 'Faixa CSBA',
                    'value' => (string)($score[0]['CSBA_FAIXA'] ?? '')
                ];
            }

            if (!empty($poder[0])) {
                $scorePerfil[] = [
                    'label' => 'Poder Aquisitivo',
                    'value' => (string)($poder[0]['PODER_AQUISITIVO'] ?? '')
                ];
                $scorePerfil[] = [
                    'label' => 'Faixa Poder',
                    'value' => (string)($poder[0]['FX_PODER_AQUISITIVO'] ?? '')
                ];
                $scorePerfil[] = [
                    'label' => 'Renda Poder',
                    'value' => (string)($poder[0]['RENDA_PODER_AQUISITIVO'] ?? '')
                ];
            }

            $vinculos = [
                'parentes' => [],
                'pis' => [],
                'tse' => [],
                'irpf' => []
            ];

            if (!empty($parentes) && is_array($parentes)) {
                foreach ($parentes as $parente) {
                    $vinculos['parentes'][] = [
                        'nome' => (string)($parente['NOME_VINCULO'] ?? ''),
                        'vinculo' => (string)($parente['VINCULO'] ?? ''),
                        'cpf' => (string)($parente['CPF_VINCULO'] ?? '')
                    ];
                }
            }

            if (!empty($pis) && is_array($pis)) {
                foreach ($pis as $pisItem) {
                    $vinculos['pis'][] = [
                        'pis' => (string)($pisItem['PIS'] ?? '')
                    ];
                }
            }

            if (!empty($tse) && is_array($tse)) {
                foreach ($tse as $tseItem) {
                    $vinculos['tse'][] = [
                        'titulo' => (string)($tseItem['TITULO_ELEITOR'] ?? ''),
                        'zona' => (string)($tseItem['ZONA'] ?? ''),
                        'secao' => (string)($tseItem['SECAO'] ?? '')
                    ];
                }
            }

            if (!empty($irpf) && is_array($irpf)) {
                foreach ($irpf as $irpfItem) {
                    $vinculos['irpf'][] = [
                        'ano' => (string)($irpfItem['Ano_Referencia'] ?? ''),
                        'banco' => (string)($irpfItem['Instituicao_Bancaria'] ?? ''),
                        'lote' => (string)($irpfItem['Lote'] ?? ''),
                        'situacao' => (string)($irpfItem['Sit_Receita_Federal'] ?? '')
                    ];
                }
            }

            $detalhes = [
                'resumo' => $resumo,
                'telefones' => $telefonesFormatados,
                'enderecos' => $enderecosFormatados,
                'emails' => $emailsFormatados,
                'score_perfil' => $scorePerfil,
                'vinculos' => $vinculos,
                'outras_bases' => $outrasBases
            ];

            return new Response(
                200,
                json_encode([
                    'success' => true,
                    'tipo_busca' => $tipoBusca,
                    'cliente' => $clienteFormatado,
                    'detalhes' => $detalhes
                ]),
                'application/json'
            );

        } catch (\Throwable $e) {
            error_log('Erro getLookupClient: ' . $e->getMessage());

            return new Response(
                500,
                json_encode([
                    'success' => false,
                    'message' => 'Erro ao consultar cliente'
                ]),
                'application/json'
            );
        }
    }

    //echo "<pre>";
    //print_r($channels);
    //echo "</pre>";
    public static function playAudioARI($request): Response
    {
        $params = json_decode(file_get_contents('php://input'), true);
        $ramal = $params['ramal'] ?? null;
        $audioPath = $params['audio_path'] ?? null;
        $action = $params['action'] ?? 'play';

        if (!$ramal) {
            return new Response(400, json_encode([
                'success' => false,
                'error' => 'Ramal não informado'
            ]), 'application/json');
        }

        try {
            $client = new \GuzzleHttp\Client([
                'auth' => TelephonyConfig::ariAuth(),
                'timeout' => 5
            ]);

            $ariBase = TelephonyConfig::ariBaseUrl();

            // 1. Localiza o canal do ramal
            $channels = json_decode($client->get("{$ariBase}channels")->getBody(), true);
            $targetId = null;

            foreach ($channels as $c) {
                if (isset($c['name']) && str_contains($c['name'], (string)$ramal)) {
                    $targetId = $c['id'];
                    break;
                }
            }

            if (!$targetId) {
                return new Response(404, json_encode([
                    'success' => false,
                    'error' => 'Offline'
                ]), 'application/json');
            }

            // STOP
            if ($action === 'stop') {
                $allChannels = json_decode($client->get("{$ariBase}channels")->getBody(), true);
                $stopped = false;

                foreach ($allChannels as $c) {
                    if (
                        isset($c['name']) &&
                        str_contains($c['name'], 'Snoop') &&
                        str_contains($c['name'], $targetId)
                    ) {
                        try {
                            $client->delete("{$ariBase}channels/{$c['id']}");
                            $stopped = true;
                        } catch (\Throwable $e) {
                            // ignora erro se o canal já caiu
                        }
                    }
                }

                return new Response(200, json_encode([
                    'success' => $stopped,
                    'action' => 'stopped',
                    'target' => $targetId
                ]), 'application/json');
            }

            // PLAY
            if (!$audioPath) {
                return new Response(400, json_encode([
                    'success' => false,
                    'error' => 'Caminho do áudio não informado'
                ]), 'application/json');
            }

            $asteriskSip = new AsteriskExtensionsSip();
            $resultAudios = $asteriskSip->listAudios(['path' => $audioPath]);
            $duration = 0;
            $displayName = 'Audio';

            if (!empty($resultAudios['ok']) && !empty($resultAudios['data'])) {
                foreach ($resultAudios['data'] as $audio) {
                    if (($audio['path'] ?? null) === $audioPath) {
                        $duration = (float)($audio['duration_seconds'] ?? 0);
                        $displayName = $audio['name'] ?? 'Audio';
                        break;
                    }
                }
            }

            $mediaARI = str_replace(
                ['/var/lib/asterisk/sounds/', '.wav', '.mp3'],
                '',
                $audioPath
            );
            $mediaARI = ltrim($mediaARI, '/');

            $snoopResp = $client->post("{$ariBase}channels/{$targetId}/snoop", [
                'json' => [
                    'app' => 'app-asterisk',
                    'spy' => 'both',
                    'whisper' => 'both'
                ]
            ]);

            $snoop = json_decode($snoopResp->getBody(), true);

            if (!isset($snoop['id'])) {
                return new Response(500, json_encode([
                    'success' => false,
                    'error' => 'Falha ao criar canal snoop'
                ]), 'application/json');
            }

            $client->post("{$ariBase}channels/{$snoop['id']}/play", [
                'query' => [
                    'media' => "sound:$mediaARI"
                ]
            ]);

            return new Response(200, json_encode([
                'success' => true,
                'duration' => $duration,
                'name' => $displayName,
                'playbackId' => $snoop['id']
            ]), 'application/json');

        } catch (\Throwable $e) {
            return new Response(500, json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]), 'application/json');
        }
    }




}
