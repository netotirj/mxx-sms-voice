<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Model\Entity\RegisterTenancies;
use App\Model\Entity\UserSearch;
use App\Utils\View;
use Random\RandomException;

class PassReset extends ViewComponents
{
    public static function getResetPass($request): array|bool|string
    {
        $content = View::render('', []);
        return parent::getComponentsResetPass('Maxx Solutions - SMS | Resetar Senha', $content);
    }

    /**
     * Reset Password
     * @throws RandomException
     */

    public static function setResetPass($request): Response
    {
        header('Content-Type: application/json; charset=utf-8');

        $postVars = $request->getPostVars();
        $email = strtolower(trim($postVars['email'] ?? ''));
        // Busca tenancy pelo admin


        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new Response(400, [
                'status' => 'ERROR',
                'message' => 'E-mail inválido.'
            ], 'application/json');
        }

        // Busca tenancy pelo admin
        $dataEmail = RegisterTenancies::getTenancyByAdminEmail($email);

        if (!$dataEmail) {
            return new Response(404, [
                'status' => 'ERROR',
                'message' => 'E-mail não encontrado.'
            ], 'application/json');
        }

        $_SESSION['maskedPhone'] = $dataEmail->maskedPhone;
        $_SESSION['user_id'] = $dataEmail->user_id;
        $_SESSION['email'] = $dataEmail->email;

        // Gera código de 6 dígitos
        $code = random_int(100000, 999999);
        RegisterTenancies::save($dataEmail->tenancy_id, $dataEmail->user_id, $code, 5 * 60);

        // Envia SMS diretamente usando SmsService
        $result = SentSmsUnique::send($dataEmail->tenancy_phone, "Seu código de Verificacao e: $code");

        if ($result !== true) {
            return new Response(500, [
                'status' => '500',
                'message' => 'Erro ao enviar SMS: ' . $result
            ], 'application/json');
        }

        // Retorna JSON com telefone mascarado
        return new Response(200, [
            'status' => '200',
            'message' => 'Código enviado com sucesso!'
        ], 'application/json');
    }

    public static function setResetPassCode($request): string
    {
        $maskedPhone = $_SESSION['maskedPhone'] ?? null;
        $content = View::render('login/code-pass-content', [
            'phone' => $maskedPhone
        ]);

        return parent::getComponentsResetPassCode('Maxx Solutions - SMS | Resetar Senha', $content);
    }

    public static function setConfirmPassCode($request): Response
    {
        header('Content-Type: application/json; charset=utf-8');

        // Pega o user_id da sessão
        $user_id = $_SESSION['user_id'] ?? null;
        $email = $_SESSION['email'] ?? null;

        if (!$user_id) {
            return new Response(401, [
                'status'  => 'ERROR',
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $postVars = $request->getPostVars();
        $codeConfirm = trim($postVars['code'] ?? '');

        if (strlen($codeConfirm) !== 6 || !ctype_digit($codeConfirm)) {
            return new Response(400, [
                'status'  => 'ERROR',
                'message' => 'Código inválido. Deve conter 6 números.'
            ], 'application/json');
        }

        // Valida se existe um código válido, não expirado e não usado
        $reset = RegisterTenancies::validateResetCode($codeConfirm, $user_id);

        if (!$reset) {
            return new Response(403, [
                'status'  => 'ERROR',
                'message' => 'Código inválido ou expirado.'
            ], 'application/json');
        }

        // Marca o código como usado
        RegisterTenancies::markCodeUsed($reset->id);

        // Retorna sucesso
        return new Response(200, [
            'status'  => 'SUCCESS',
            'message' => 'Código confirmado. Você pode redefinir sua senha agora.'
        ], 'application/json');
    }

    public static function getConfirmPassView($request): array|bool|string
    {
        $content = View::render('', []);
        return parent::getComponentsResetPassConfirmed('Maxx Solutions - SMS | Resetar Senha', $content);
    }

    public static function getConfirmPassNew($request): Response
    {

        $user_id = $_SESSION['user_id'] ?? null;
        $email =   $_SESSION['email'] ?? null;
        $dataEmail = RegisterTenancies::getTenancyByAdminEmail($email);

        $postVars = $request->getPostVars();
        $newPass     = $postVars['new_password'] ?? '';
        $confirmPass = $postVars['confirm_password'] ?? '';


        if (empty($newPass) || empty($confirmPass)) {
            return new Response(400, [
                'status' => 400,
                'message' => 'Todos os campos de senha são obrigatórios.'
            ], 'application/json');
        }


        if ($newPass !== $confirmPass) {
            return new Response(400, [
                'status' => 400,
                'message' => 'A nova senha e a confirmação não coincidem.'
            ], 'application/json');
        }


        $userData = UserSearch::getUserById($dataEmail->tenancy_id, $user_id);
        if (!$userData) {
            return new Response(404, [
                'status' => 404,
                'message' => 'Usuário não encontrado.'
            ], 'application/json');
        }

        $obUserPass = new UserSearch();
        $obUserPass->id = $user_id;
        $obUserPass->tenancy_id = $dataEmail->tenancy_id;
        $obUserPass->password = password_hash($newPass, PASSWORD_DEFAULT);


        if ($obUserPass->updatePassword()) {

            // Depois que a senha foi redefinida com sucesso
            unset($_SESSION['user_id']);
            unset($_SESSION['email']);
            unset($_SESSION['maskedPhone']);

            // ou se só usa sessão para isso, destruir tudo
            session_destroy();
            // Sucesso

            return new Response(200, [
                'success' => true,
                'message' => 'Senha atualizada com sucesso!'
            ], 'application/json');
        } else {
            // Erro ao atualizar
            return new Response(500, [
                'success' => false,
                'message' => 'Erro ao atualizar a senha.'
            ], 'application/json');
        }
    }


    /**
     * @throws RandomException
     */
    public static function resendResetCode($request): Response
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!isset($_SESSION['user_id'], $_SESSION['email'])) {
            return new Response(400, [
                'status' => 'ERROR',
                'message' => 'Sessão inválida. Solicite o reset novamente.'
            ], 'application/json');
        }

        // Reaproveita o mesmo fluxo do setResetPass
        $dataEmail = $_SESSION['email'];

        $dataEmailTenancy = RegisterTenancies::getTenancyByAdminEmail($dataEmail);

        $code = random_int(100000, 999999);
        RegisterTenancies::save($dataEmailTenancy->tenancy_id, $dataEmailTenancy->user_id, $code, 5 * 60);

        $result = SentSmsUnique::send($dataEmailTenancy->tenancy_phone, "Seu codigo de Verificacao e: $code");

        if ($result !== true) {
            return new Response(500, [
                'status' => '500',
                'message' => 'Erro ao reenviar SMS: ' . $result
            ], 'application/json');
        }

        return new Response(200, [
            'status' => '200',
            'message' => 'Novo código enviado com sucesso!'
        ], 'application/json');
    }


}