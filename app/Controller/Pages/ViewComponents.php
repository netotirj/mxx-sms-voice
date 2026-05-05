<?php

namespace App\Controller\Pages;

use App\Model\Entity\PermissionsRules;
use App\Model\Entity\UserPlans;
use App\Model\Entity\UserSearch;
use App\Model\Entity\RegisterTenancies;
use App\Utils\View;
use App\Session\User as SessionUser;

/**
 * Classe ViewComponents
 *
 * Responsável por encapsular renderizações de componentes com View,
 * padronizando retorno JSON e estrutura das páginas.
 */
class ViewComponents
{

    /**
     * Renderiza o menu lateral.
     *
     * @return string
     */

    private static function getMenu(): string
    {
        $obUser = SessionUser::getLogged();
        $roleId = (int)($obUser['role_id'] ?? 0);

        $userPerms = self::isSuperAdmin($obUser)
            ? ['SUPERADMIN']
            : PermissionsRules::getRolePermissionsNames($roleId);

        return View::render('pages/menu', [
            'URL'             => URL,
            'item_dashboard'  => self::getDashboardHtml($userPerms),
            'item_mensageria' => self::getMensageriaHtml($userPerms),
            'item_whatsapp'   => self::getWhatsAppHtml($userPerms),
            'item_voz'        => self::getVozHtml($userPerms),
            'item_administrativo' => self::getAdministrativoHtml($userPerms),
            'item_recargas'   => self::getRecargasHtml($userPerms),
            'item_relatorios' => self::getRelatoriosHtml($userPerms),
            'item_usuarios'   => self::getUsuariosHtml($userPerms),
        ]);
    }

    private static function hasPerm(array $userPerms, array $needles): bool
    {
        // 1. Se for SUPERADMIN, ignora qualquer outra checagem e libera
        if (in_array('SUPERADMIN', $userPerms, true)) {
            return true;
        }

        // 2. Garantimos que os dados estão limpos antes de comparar.
        // Fazemos isso apenas uma vez fora do loop para ganhar performance.
        $userPerms = array_map(fn($p) => trim((string)$p), $userPerms);

        foreach ($needles as $needle) {
            $cleanNeedle = trim((string)$needle);

            // 3. Comparação direta: se a rota solicitada está no array de permissões do banco
            if (in_array($cleanNeedle, $userPerms, true)) {
                return true;
            }
        }

        return false;
    }

    private static function buildMenuItem(
        string $href,
        string $label,
        string $iconClass,
        string $iconColor = 'text-blue-500',
        string $extraSpanClass = '',
        string $extraLinkClass = 'dark:text-white opacity-80 hover:opacity-100'
    ): string {
        return '
    <li>
        <a href="' . URL . $href . '" class="nav-link relative flex items-center py-2 pl-12 pr-4 text-xs ' . $extraLinkClass . '">
            <div class="mr-2 flex h-6 w-6 items-center justify-center">
                <i class="text-[10px] ' . $iconColor . ' ' . $iconClass . '"></i>
            </div>
            <span class="' . $extraSpanClass . '">' . $label . '</span>
        </a>
    </li>';
    }

    private static function buildSectionTitle(string $title, string $pt = 'pt-3'): string
    {
        return '<li class="px-6 ' . $pt . ' text-[10px] uppercase text-slate-400">' . $title . '</li>';
    }

    private static function renderSubmenu(
        string $title,
        string $iconClass,
        string $iconColor,
        string $content
    ): string {
        if (trim($content) === '') {
            return '';
        }

        return '
    <li class="w-full mt-0.5 submenu">
        <div class="submenu-header relative flex cursor-pointer items-center justify-between rounded-lg px-4 py-2.5 mx-2 text-sm transition-all dark:text-white dark:opacity-80">
            <div class="flex items-center">
                <div class="mr-2 flex h-8 w-8 items-center justify-center ' . $iconColor . '">
                    <i class="' . $iconClass . '"></i>
                </div>
                <span>' . $title . '</span>
            </div>
            <i class="submenu-toggle fas fa-chevron-down text-[10px] transition-transform duration-300"></i>
        </div>
        <ul class="submenu-items flex flex-col overflow-hidden max-h-0 transition-all duration-300 ease-in-out list-none">
            ' . $content . '
        </ul>
    </li>';
    }

    private static function getDashboardHtml(array $userPerms): string
    {
        // Rota física do dashboard.php
        if (!self::hasPerm($userPerms, ['/dashboard'])) {
            return '';
        }

        return '
<li class="w-full mt-0.5">
    <a class="nav-link relative flex items-center px-4 py-2.5 mx-2 text-sm transition-all dark:text-white" href="' . URL . '/dashboard">
        <div class="mr-2 flex h-8 w-8 items-center justify-center rounded-lg text-center">
            <i class="text-sm text-blue-500 ni ni-tv-2"></i>
        </div>
        <span>Dashboard</span>
    </a>
</li>';
    }

    private static function getMensageriaHtml(array $userPerms): string
    {
        $items = '';

        // Verifica pela ROTA física
        if (self::hasPerm($userPerms, ['/campaign'])) {
            $items .= self::buildMenuItem('/campaign', 'Listar Campanhas', 'ni ni-bullet-list-67', 'text-blue-500');
            $items .= self::buildMenuItem('/campaign/new', 'Criar Campanha', 'ni ni-fat-add', 'text-emerald-500');
        }

        if (self::hasPerm($userPerms, ['/campaign/single-shot'])) {
            $items .= self::buildMenuItem('/campaign/single-shot', 'Disparo Simples', 'ni ni-send', 'text-indigo-900');
        }

        return self::renderSubmenu('SMS', 'ni ni-archive-2', 'text-orange-500', $items);
    }

    private static function getWhatsAppHtml(array $userPerms): string
    {
        if (!self::hasPerm($userPerms, ['/campaign/whatsapp'])) {
            return '';
        }

        $items = '';
        $items .= self::buildMenuItem('/campaign/whatsapp', 'Central', 'ni ni-chat-round', 'text-emerald-500');
        $items .= self::buildMenuItem('/campaign/whatsapp?wa=conversations', 'Atendimento', 'ni ni-chat-round', 'text-cyan-500');
        $items .= self::buildMenuItem('/campaign/whatsapp?wa=campaigns', 'Campanhas', 'ni ni-send', 'text-orange-500');
        $items .= self::buildMenuItem('/campaign/whatsapp?wa=templates', 'Templates', 'ni ni-single-copy-04', 'text-violet-500');
        $items .= self::buildMenuItem('/campaign/whatsapp?wa=accounts', 'Contas Meta', 'ni ni-settings-gear-65', 'text-slate-500');

        return self::renderSubmenu('WhatsApp', 'fa-brands fa-whatsapp', 'text-green-500', $items);
    }

    private static function getVozHtml(array $userPerms): string
    {
        $operacao = '';
        $recursos = '';
        $config   = '';

        // Operação - Rotas de voice.php e callcenter.php
        if (self::hasPerm($userPerms, ['/campaign/voice'])) {
            $operacao .= self::buildMenuItem('/campaign/voice', 'Disparo de Voz', 'ni ni-send', 'text-fuchsia-700');
        }

        if (self::hasPerm($userPerms, ['/callcenter/monitoring'])) {
            $operacao .= self::buildMenuItem('/callcenter/monitoring', 'Monitoramento', 'ni ni-tv-2', 'text-emerald-500');
            $operacao .= self::buildMenuItem('/campaign/voice/calls-view', 'Live Calls', 'ni ni-headphones', 'text-cyan-500');
        }

        if (self::hasPerm($userPerms, ['/callcenter/agent-panel'])) {
            $operacao .= self::buildMenuItem('/callcenter/agent-panel', 'Meu Painel', 'ni ni-button-play', 'text-blue-500', 'font-bold text-slate-700 dark:text-white');
        }

        // Recursos
        if (self::hasPerm($userPerms, ['/campaign/voice/list'])) {
            $recursos .= self::buildMenuItem('/campaign/voice/list', 'Listas', 'ni ni-bullet-list-67', 'text-blue-500', 'font-semibold');
        }

        if (self::hasPerm($userPerms, ['/campaign/voice/audios'])) {
            $recursos .= self::buildMenuItem('/campaign/voice/audios', 'Áudios', 'ni ni-note-03', 'text-emerald-500');
        }

        // Configuração
        if (self::hasPerm($userPerms, ['/callcenter/agents'])) {
            $config .= self::buildMenuItem('/callcenter/agents', 'Agentes', 'ni ni-single-02', 'text-cyan-500');
        }

        if (self::hasPerm($userPerms, ['/callcenter/queues'])) {
            $config .= self::buildMenuItem('/callcenter/queues', 'Filas de Espera', 'ni ni-bullet-list-67', 'text-orange-500');
        }

        if (self::hasPerm($userPerms, ['/callcenter/breaks'])) {
            $config .= self::buildMenuItem('/callcenter/breaks', 'Gerenciar Pausas', 'ni ni-button-pause', 'text-red-500');
        }

        if (self::hasPerm($userPerms, ['/campaign/voice/trunks'])) {
            $config .= self::buildMenuItem('/campaign/voice/trunks', 'Trunks', 'ni ni-world', 'text-purple-500');
        }

        if (self::hasPerm($userPerms, ['/campaign/voice/sip'])) {
            $config .= self::buildMenuItem('/campaign/voice/sip', 'SIP Devices', 'ni ni-mobile-button', 'text-orange-500');
        }

        $content = '';
        if ($operacao !== '') $content .= self::buildSectionTitle('Operação', 'pt-2') . $operacao;
        if ($recursos !== '') $content .= self::buildSectionTitle('Recursos') . $recursos;
        if ($config !== '')   $content .= self::buildSectionTitle('Configuração') . $config;

        return self::renderSubmenu('Voz & Call Center', 'ni ni-headphones', 'text-yellow-500', $content);
    }

    private static function getAdministrativoHtml(array $userPerms): string
    {
        $gestao = '';
        $comunicacao = '';
        $config = '';

        if (self::hasPerm($userPerms, ['/users'])) {
            $gestao .= self::buildMenuItem('/users', 'Lista de Usuários', 'ni ni-circle-08', 'text-blue-500');
        }

        if (self::hasPerm($userPerms, ['/support'])) {
            $gestao .= self::buildMenuItem('/support', 'Tickets de Suporte', 'ni ni-support-16', 'text-emerald-500');
        }

        if (in_array('SUPERADMIN', $userPerms, true) || self::hasPerm($userPerms, ['/system-updates'])) {
            $comunicacao .= self::buildMenuItem('/system-updates', 'Atualizações', 'fa fa-bullhorn', 'text-cyan-500');
        }

        if (self::hasPerm($userPerms, ['/reports/notifications'])) {
            $comunicacao .= self::buildMenuItem('/reports/notifications', 'Notificações', 'ni ni-notification-70', 'text-yellow-500');
        }

        if (self::hasPerm($userPerms, ['/permissions'])) {
            $config .= self::buildMenuItem('/permissions', 'Permissões', 'ni ni-key-25', 'text-cyan-500');
        }

        $content = '';
        if ($gestao !== '') $content .= self::buildSectionTitle('Gestão', 'pt-2') . $gestao;
        if ($comunicacao !== '') $content .= self::buildSectionTitle('Comunicação') . $comunicacao;
        if ($config !== '') $content .= self::buildSectionTitle('Configuração') . $config;

        return self::renderSubmenu('Administrativo', 'ni ni-settings-gear-65', 'text-slate-600', $content);
    }

    private static function getRecargasHtml(array $userPerms): string
    {
        // Agora verificamos pela ROTA física '/refills'
        if (!self::hasPerm($userPerms, ['/refills'])) {
            return '';
        }

        return '
        <li class="w-full mt-0.5">
            <a href="' . URL . '/refills" class="nav-link relative flex items-center px-4 py-2.5 mx-2 text-sm dark:text-white dark:opacity-80">
                <div class="mr-2 flex h-8 w-8 items-center justify-center text-cyan-500">
                    <i class="ni ni-credit-card"></i>
                </div>
                <span>Planos e Recargas</span>
            </a>
        </li>';
    }

    private static function getRelatoriosHtml(array $userPerms): string
    {
        $items = '';

        // Verificação pela rota pai ou rotas específicas
        if (self::hasPerm($userPerms, ['/reports'])) {
            $items .= self::buildMenuItem('/reports/sms-view', 'Sms', 'ni ni-archive-2', 'text-pink-600');
            $items .= self::buildMenuItem('/reports/whatsapp', 'WhatsApp', 'fa-brands fa-whatsapp', 'text-emerald-500');
            $items .= self::buildMenuItem('/reports/recharge-transactions', 'Recargas', 'ni ni-credit-card', 'text-violet-600');
        }

        if (!self::hasPerm($userPerms, ['/reports']) && self::hasPerm($userPerms, ['/reports/whatsapp'])) {
            $items .= self::buildMenuItem('/reports/whatsapp', 'WhatsApp', 'fa-brands fa-whatsapp', 'text-emerald-500');
        }

        // --- AQUI ESTAVA FALTANDO ---
        // Relatório de Call Center
        if (self::hasPerm($userPerms, ['/callcenter/reports'])) {
            $items .= self::buildMenuItem('/callcenter/reports', 'Call Center', 'ni ni-folder-17', 'text-purple-500');
        }

        // CDR (Voz)
        if (self::hasPerm($userPerms, ['/reports/cdr'])) {
            $items .= self::buildMenuItem('/reports/cdr', 'CDR (Voz)', 'ni ni-collection', 'text-cyan-500');
        }

        // Pix
        if (self::hasPerm($userPerms, ['/reports/transactions'])) {
            $items .= self::buildMenuItem('/reports/transactions', 'Pix', 'fa-brands fa-pix', 'text-emerald-500');
        }

        if (in_array('SUPERADMIN', $userPerms, true)) {
            $items .= self::buildMenuItem('/site-tests', 'Testes do Site', 'ni ni-world', 'text-blue-600');
        }

        return self::renderSubmenu('Relatórios', 'ni ni-chart-bar-32', 'text-red-500', $items);
    }

    private static function getUsuariosHtml(array $userPerms): string
    {
        $items = '';

        if (self::hasPerm($userPerms, ['/rates'])) {
            $items .= self::buildMenuItem('/rates', 'Tarifas', 'ni ni-money-coins', 'text-yellow-500');
        }

        return self::renderSubmenu('Tarifas', 'ni ni-money-coins', 'text-indigo-500', $items);
    }



    /**
     * Centraliza a descoberta da função/cargo do usuário (Sessão ou Banco)
     */
    private static function getUserFunction(array $user): string
    {
        return strtolower(trim((string)($user['user_function'] ?? $user['function'] ?? '')));
    }

    /**
     * Verifica se é Super Admin (Usa a lógica inteligente acima)
     */
    private static function isSuperAdmin(array $user): bool
    {
        return self::getUserFunction($user) === 'super_admin';
    }

    /**
     * Verifica se é Admin comum
     */
    private static function isAdmin(array $user): bool
    {
        return self::getUserFunction($user) === 'admin';
    }

    private static function isReseller(array $user): bool
    {
        return self::getUserFunction($user) === 'reseller';
    }

    private static function canSeeSupportTicketsHeader(array $user): bool
    {
        return self::isSuperAdmin($user) || self::isAdmin($user) || self::isReseller($user);
    }

    private static function getSupportTicketsHeaderHtml(array $user): string
    {
        if (!self::canSeeSupportTicketsHeader($user)) {
            return '';
        }

        return '
                <div class="relative mr-6">
                    <a href="javascript:void(0);" id="supportTicketsButton" class="relative flex items-center justify-center cursor-pointer" title="Tickets de suporte">
                        <i class="fa fa-question-circle text-xl text-white"></i>
                        <span id="supportTicketsBadge" class="absolute -top-2 -right-2 hidden min-w-4 h-4 px-1 rounded-full bg-emerald-500 text-white text-xxs font-bold leading-4 text-center">0</span>
                    </a>

                    <div id="supportTicketsMenu"
                         class="absolute right-0 mt-2 w-96 max-w-[calc(100vw-2rem)] max-h-100 overflow-y-auto rounded-xl bg-white shadow-2xl opacity-0 pointer-events-none transition-all duration-200 border border-slate-100"
                         style="z-index:9999; top: 100%;">
                        <div class="px-4 py-3 border-b border-slate-100 bg-slate-50 rounded-t-xl">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm text-slate-800 font-bold leading-tight">Suporte</p>
                                    <p id="supportTicketsSummary" class="text-xs text-slate-500 mt-0.5">Acompanhando seus tickets abertos</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <a href="' . URL . '/support" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-white text-emerald-600 border border-slate-200 hover:bg-emerald-50" title="Abrir painel de suporte">
                                        <i class="fa fa-arrow-up-right-from-square text-xs"></i>
                                    </a>
                                    <button id="supportTicketsRefresh" type="button" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-white text-blue-600 border border-slate-200 hover:bg-blue-50" title="Atualizar tickets">
                                        <i class="fa fa-rotate-right text-xs"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div id="supportTicketsList" class="p-3">
                            <div class="text-center text-gray-500 p-4">Carregando tickets...</div>
                        </div>
                    </div>
                </div>';
    }

    private static function getProductUpdatesHeaderHtml(): string
    {
        return '
                <div class="relative mr-6">
                    <a href="javascript:void(0);" id="productUpdatesButton" class="relative flex items-center justify-center cursor-pointer" title="Novidades e melhorias">
                        <i class="fa fa-bullhorn text-xl text-white"></i>
                        <span id="productUpdatesBadge" class="absolute -top-2 -right-2 hidden min-w-4 h-4 px-1 rounded-full bg-cyan-500 text-white text-xxs font-bold leading-4 text-center">0</span>
                    </a>

                    <div id="productUpdatesMenu"
                         class="absolute right-0 mt-2 w-96 max-w-[calc(100vw-2rem)] max-h-100 overflow-y-auto rounded-xl bg-white shadow-2xl opacity-0 pointer-events-none transition-all duration-200 border border-slate-100"
                         style="z-index:9999; top: 100%;">
                        <div class="px-4 py-3 border-b border-slate-100 bg-slate-50 rounded-t-xl">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm text-slate-800 font-bold leading-tight">Novidades</p>
                                    <p class="text-xs text-slate-500 mt-0.5">Melhorias em andamento e entregas recentes</p>
                                </div>
                                <span class="inline-flex items-center gap-1 rounded-full bg-cyan-50 px-2.5 py-1 text-xxs font-bold text-cyan-700">
                                    <i class="fa fa-bullhorn"></i> Atualizações
                                </span>
                            </div>
                        </div>
                        <div id="productUpdatesList" class="p-3 space-y-2">
                            <div class="text-center text-gray-500 p-4">Carregando novidades...</div>
                        </div>
                    </div>
                </div>';
    }

    /**
     * Regra para trocar de plano (Admin ou Super Admin)
     */
    private static function canSwitchPlan(array $user): bool
    {
        return self::isAdmin($user) || self::isSuperAdmin($user);
    }

    /**
     * Renderiza o header/topbar.
     *
     * @return string
     */
    private static function getHeader(): string
    {
        $obUser = SessionUser::getLogged();
        $user = UserSearch::getUserById($obUser['tenancy_id'], (int)$obUser['id']) ?? [];

        $userBase = !empty($user) ? $user : $obUser;

        $userAvatar = !empty($user['image'])
            ? URL . '/resources/assets/img/' . $user['image']
            : URL . '/resources/assets/img/default-avatar.png';

        $canSwitchPlan = self::canSwitchPlan($userBase);

        $planSwitcherHtml = '';

        if ($canSwitchPlan) {
            $userPlans   = UserPlans::getAllActivePlansByUser($obUser['id'], $obUser['tenancy_id']);
            $currentPlan = RegisterTenancies::getActivePlanId($obUser['tenancy_id']);

            $optionsHTML = '';

            foreach ((array)$userPlans as $plan) {
                $planId = 0;
                $planName = '';

                if (is_object($plan)) {
                    $planId   = (int)($plan->plan_id ?? 0);
                    $planName = (string)($plan->name_plan ?? '');
                } elseif (is_array($plan)) {
                    $planId   = (int)($plan['plan_id'] ?? 0);
                    $planName = (string)($plan['name_plan'] ?? '');
                }

                if ($planId <= 0 || $planName === '') {
                    continue;
                }

                $selected = ($planId === (int)$currentPlan) ? ' selected' : '';

                $optionsHTML .= '<option value="' . $planId . '"' . $selected . '>'
                    . htmlspecialchars($planName, ENT_QUOTES, 'UTF-8')
                    . '</option>';
            }

            $planSwitcherHtml = '
                <div id="planoSwitcher" class="flex justify-center flex-1">
                    <div class="flex items-center space-x-2 bg-white text-slate-700 px-4 py-1.5 rounded-lg shadow-md">
                        <i class="fa fa-layer-group text-blue-500"></i>
                        <label for="planoSelect" class="text-sm font-medium">Plano Atual:</label>
                        <select id="planoSelect"
                                class="text-sm bg-white text-slate-800 border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                onchange="swapPlan(this.value)">
                            ' . $optionsHTML . '
                        </select>
                    </div>
                </div>';
        }

        return View::render('pages/header', [
            'name'             => $obUser['name'] ?? 'User',
            'avatar'           => $userAvatar,
            'planSwitcherHtml' => $planSwitcherHtml,
            'productUpdatesHeader' => self::getProductUpdatesHeaderHtml(),
            'supportTicketsHeader' => self::getSupportTicketsHeaderHtml($userBase)
        ]);
    }



    /**
     * Renderiza o footer.
     *
     * @return string
     */
    private static function getFooter(): string
    {
        return View::render('pages/footer');
    }

    /**
     * Renderiza o layout do Dashboard com título e conteúdo injetados.
     *
     * @param string $title
     * @param string|array|bool $content
     * @return string
     */


    /**
     * Retorna resposta JSON padronizada e encerra execução.
     *
     * @param array $data
     * @param int $code
     * @return void
     */
    protected static function jsonResponse(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Renderiza o layout de Login com título e conteúdo injetados.
     *
     * @param string $title
     * @param string|array|bool $content
     * @return string
     */
    public static function getComponentsLogin(string $title, string|array|bool $content): string
    {
        $captcha = self::getTurnstileViewVars();

        return View::render('login/index', [
            'title'              => $title,
            'content'            => $content,
            'captchaEnabled'     => $captcha['enabled'],
            'captchaScriptUrl'   => $captcha['scriptUrl'],
            'captchaSiteKey'     => $captcha['siteKey'],
            'captchaWidgetClass' => $captcha['widgetClass'],
        ]);
    }

    public static function getComponentsResetPass(string $title, string|array|bool $content): string
    {
        return View::render('login/reset-pass', [
            'title'   => $title,
            'content' => $content,
        ]);
    }

    public static function getComponentsResetPassCode(string $title, string|array|bool $content): string
    {

        return View::render('login/code-pass', [
            'title'   => $title,
            'content' => $content,

        ]);
    }

    public static function getComponentsResetPassConfirmed(string $title, string|array|bool $content): string
    {

        return View::render('login/confirmed-pass', [
            'title'   => $title,
            'content' => $content,

        ]);
    }


    public static function getComponentsRegister(string $title, string|array|bool $content): string
    {
        $captcha = self::getTurnstileViewVars();
        $socialProfile = $_SESSION['social_register_profile'] ?? [];
        $isSocialRegister = is_array($socialProfile)
            && !empty($socialProfile['email'])
            && !empty($socialProfile['created_at'])
            && (time() - (int)$socialProfile['created_at']) <= 900;

        return View::render('register/index', [
            'title'                 => $title,
            'content'               => $content,
            'captchaEnabled'        => $captcha['enabled'],
            'captchaScriptUrl'      => $captcha['scriptUrl'],
            'captchaSiteKey'        => $captcha['siteKey'],
            'captchaWidgetClass'    => $captcha['widgetClass'],
            'registerSocialButtons' => self::buildSocialButtons('register', 'social-register-btn'),
            'registerSocialMode'    => $isSocialRegister ? 'true' : 'false',
            'registerSocialInput'   => $isSocialRegister ? '1' : '0',
            'registerFirstName'     => htmlspecialchars((string)($socialProfile['first_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'registerLastName'      => htmlspecialchars((string)($socialProfile['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'registerEmail'         => htmlspecialchars((string)($socialProfile['email'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'registerEmailReadonly' => $isSocialRegister ? 'readonly' : '',
            'registerPasswordClass' => $isSocialRegister ? 'hidden' : '',
            'registerPasswordRequired' => $isSocialRegister ? '' : 'required',
        ]);
    }

    private static function getTurnstileViewVars(): array
    {
        $configured = Recaptcha::isTurnstileConfigured();
        $enabled = Recaptcha::isTurnstileEnabled();

        return [
            'enabled' => $enabled ? 'true' : 'false',
            'scriptUrl' => ($configured && $enabled) ? 'https://challenges.cloudflare.com/turnstile/v0/api.js' : '',
            'siteKey' => Recaptcha::getTurnstileSiteKey(),
            'widgetClass' => ($configured && $enabled) ? '' : 'hidden',
        ];
    }

    private static function buildSocialButtons(string $intent, string $className): string
    {
        $providers = [
            'google' => ['label' => 'Google', 'icon' => self::getGoogleIcon()],
            'apple' => ['label' => 'Apple', 'icon' => self::getAppleIcon()],
            'facebook' => ['label' => 'Facebook', 'icon' => self::getFacebookIcon()],
        ];

        $buttons = '';

        foreach ($providers as $provider => $meta) {
            $url = URL . '/auth/social/' . $provider . '?intent=' . rawurlencode($intent);
            $buttons .= '
              <button type="button" data-social-provider="' . $provider . '" data-social-url="' . $url . '" class="' . $className . ' flex w-full items-center justify-center gap-2 rounded-xl border border-gray-600 bg-transparent px-4 py-2.5 text-sm font-medium text-white transition-all duration-200 hover:bg-gray-700">
                ' . $meta['icon'] . '
                <span>' . $meta['label'] . '</span>
              </button>';
        }

        return $buttons;
    }

    private static function getGoogleIcon(): string
    {
        return '<svg class="h-5 w-5" viewBox="0 0 24 24" aria-hidden="true">
                  <path fill="#EA4335" d="M12 10.2v3.9h5.5c-.2 1.3-1.6 3.9-5.5 3.9-3.3 0-6-2.7-6-6s2.7-6 6-6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.8 3.4 14.6 2.5 12 2.5A9.5 9.5 0 1 0 12 21.5c5.5 0 9.1-3.8 9.1-9.2 0-.6-.1-1.1-.2-1.6H12Z"/>
                  <path fill="#FBBC05" d="M3.6 7.9 6.8 10.2A5.9 5.9 0 0 1 12 6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.8 3.4 14.6 2.5 12 2.5c-3.7 0-7 2.1-8.4 5.4Z"/>
                  <path fill="#34A853" d="M12 21.5c2.5 0 4.7-.8 6.3-2.3l-3-2.5c-.8.6-1.9 1.1-3.3 1.1-3.8 0-5.1-2.5-5.4-3.7l-3.2 2.5c1.4 3.3 4.8 4.9 8.6 4.9Z"/>
                  <path fill="#4285F4" d="M21.1 12.3c0-.6-.1-1.1-.2-1.6H12v3.9h5.5c-.3 1.3-1.1 2.3-2.2 3.1l3 2.5c1.8-1.7 2.8-4.2 2.8-7.9Z"/>
                </svg>';
    }

    private static function getAppleIcon(): string
    {
        return '<svg class="h-5 w-5 fill-current" viewBox="0 0 24 24" aria-hidden="true"><path d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zM15.53 3.83c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.702z"/></svg>';
    }

    private static function getFacebookIcon(): string
    {
        return '<svg class="h-5 w-5" viewBox="0 0 24 24" aria-hidden="true"><path fill="#1877F2" d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06C2 17.08 5.66 21.24 10.44 22v-7.03H7.9v-2.91h2.54V9.84c0-2.52 1.49-3.91 3.77-3.91 1.09 0 2.23.2 2.23.2v2.46h-1.26c-1.24 0-1.63.78-1.63 1.57v1.9h2.78l-.44 2.91h-2.34V22C18.34 21.24 22 17.08 22 12.06Z"/></svg>';
    }

    public static function getComponentsDashboard(string $title, string|array|bool $content): string
    {
        return View::render('pages/page', [
            'title'   => $title,
            'menu'    => self::getMenu(),
            'header'  => self::getHeader(),
            'content' => $content,
            'footer'  => self::getFooter(),
        ]);
    }

    public static function getComponentsCampaign(string $title, string|array|bool $content): string
    {
        return View::render('pages/page', [
            'title'   => $title,
            'menu'    => self::getMenu(),
            'header'  => self::getHeader(),
            'content' => $content,
            'footer'  => self::getFooter(),
        ]);
    }


    public static function getComponentsRefills(string $title, string|array|bool $content): string
    {
        return View::render('pages/page', [
            'title'   => $title,
            'menu'    => self::getMenu(),
            'header'  => self::getHeader(),
            'content' => $content,
            'footer'  => self::getFooter(),
        ]);
    }

    public static function getComponentsReports(string $title, string|array|bool $content): string
    {
        return View::render('pages/page', [
            'title'   => $title,
            'menu'    => self::getMenu(),
            'header'  => self::getHeader(),
            'content' => $content,
            'footer'  => self::getFooter(),
        ]);
    }

    public static function getComponentsUsers(string $title, string|array|bool $content): string
    {
        return View::render('pages/page', [
            'title'   => $title,
            'menu'    => self::getMenu(),
            'header'  => self::getHeader(),
            'content' => $content,
            'footer'  => self::getFooter(),
        ]);
    }
    
}
