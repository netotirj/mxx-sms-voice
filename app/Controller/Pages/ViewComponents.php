<?php

namespace App\Controller\Pages;

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
        return View::render('pages/menu');
    }

    /**
     * Renderiza o header/topbar.
     *
     * @return string
     */
    private static function getHeader(): string
    {
        $obUser = SessionUser::getLogged();
        $usersImage = UserSearch::getUsers($obUser['tenancy_id'], $obUser['id']);
        $user = $usersImage[0] ?? null;

        $userAvatar = $user['image']
            ? URL . '/resources/assets/img/' . $user['image']
            : URL . '/resources/assets/img/default-avatar.png';

        $isAdmin = strtolower(trim($user['user_function'] ?? '')) === 'admin';

        $optionsHTML = '';
        if ($isAdmin) {
            // Pega apenas planos ativos e confirmados
            $userPlans   = UserPlans::getAllActivePlansByUser($obUser['id'], $obUser['tenancy_id']);
            $currentPlan = RegisterTenancies::getActivePlanId($obUser['tenancy_id']);

            foreach ($userPlans as $plan) {
                $selected = $plan->plan_id == $currentPlan ? 'selected' : '';
                $optionsHTML .= sprintf(
                    '<option value="%d" %s>%s</option>',
                    $plan->plan_id,
                    $selected,
                    htmlspecialchars($plan->name_plan)
                );
            }
        }

        return View::render('pages/header', [
            'name'                 => $obUser['name'] ?? 'User',
            'avatar'               => $userAvatar,
            'planOptions'          => $optionsHTML,       // vazio se não for admin
            'planoSwitcherHidden'  => $isAdmin ? '' : 'invisible',
            'isAdmin'              => $isAdmin ? 'true' : 'false'
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
        return View::render('login/index', [
            'title'   => $title,
            'content' => $content,
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
        return View::render('register/index', [
            'title'   => $title,
            'content' => $content,
        ]);
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
