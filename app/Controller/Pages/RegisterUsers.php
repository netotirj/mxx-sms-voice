<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\Notifications;
use App\Model\Entity\UserPlans;
use App\Utils\View;
use App\Model\Entity\UserAuthentication;
use App\Model\Entity\PermissionsRules;
use App\Model\Entity\RegisterTenancies;
use App\Controller\Pages\Recaptcha;
use Ramsey\Uuid\Uuid;
use Random\RandomException;


class RegisterUsers extends ViewComponents
{
    public static function getRegister($request): array|bool|string
    {
        $content = View::render('', []);
        return parent::getComponentsRegister('Maxx Solutions - SMS | Register', $content);
    }

    /**
     * @throws RandomException
     */
    public static function generateUniqueAccountCode(int $attempts = 30): string
    {
        for ($i = 0; $i < $attempts; $i++) {

            // 10 dígitos, não começa com 0
            $code = (string) random_int(1000000000, 9999999999);

            if (!RegisterTenancies::accountCodeExists($code)) {
                return $code;
            }
        }

        throw new \RuntimeException(
            "Falha ao gerar account_code único após {$attempts} tentativas."
        );
    }


    /**
     * @throws RandomException
     */
    public static function setRegister($request): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $postVars = $request->getPostVars();

        $name = trim($postVars['name'] ?? '');
        $lastname = trim($postVars['lastname'] ?? '');
        $phone = trim($postVars['phone'] ?? '');
        // 🔹 Remove tudo que não for número (espaços, +, (), - etc.)
        $phone = preg_replace('/\D/', '', $phone);
        // 🔹 Se não começa com 55, adiciona
        if (!str_starts_with($phone, '55')) {
            $phone = '55' . $phone;
        }

        $email = strtolower(trim($postVars['email'] ?? ''));
        $password = trim($postVars['password'] ?? '');
        $captcha = trim($postVars['g-recaptcha-response'] ?? '');
        $acceptTerms = isset($postVars['accept_terms']) && $postVars['accept_terms'] === 'on';

        // Validações básicas
        if ($name === '' || $lastname === '' || $phone === '' || $email === '' || $password === '') {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'Todos os campos são obrigatórios.']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'E-mail inválido.']);
        }

        /*if (!Recaptcha::verify($captcha, $_SERVER['REMOTE_ADDR'] ?? null)) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'Falha na verificação do reCAPTCHA!']);
        }*/

        if (!$acceptTerms) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'Você deve aceitar os Termos e Condições.']);
        }

        // Verifica se e-mail já está cadastrado
        if (UserAuthentication::getUserByEmail($email) instanceof UserAuthentication) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'E-mail já cadastrado.']);
        }

        // Gera tenancy_id único
        $tenancyId = Uuid::uuid4()->toString();

        // ✅ gera account_code único (10 dígitos)
        $accountCode = self::generateUniqueAccountCode();

        // 1️⃣ Criar tenancy
        $obTenancy = new RegisterTenancies();
        $obTenancy->id = $tenancyId;
        $obTenancy->name = $name . ' ' . $lastname;
        $obTenancy->tenancy_phone = $phone;
        $obTenancy->account_code = $accountCode; // ✅ NOVO
        $obTenancy->status = 'active';
        $obTenancy->created_at = date('Y-m-d H:i:s');
        $obTenancy->updated_at = date('Y-m-d H:i:s');

        $newTenancyId = $obTenancy->insertUserTenancy();

        if (!$newTenancyId) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'Erro ao criar tenancy.']);
        }


        // 2️⃣ Cria roles padrão e associa todas as permissões existentes ao admin
        PermissionsRules::createDefaultRolesAndPermissions($tenancyId);

        // Cria usuário
        $obUser = new UserAuthentication();
        $obUser->name = $name;
        $obUser->last_name = $lastname;
        $obUser->email = $email;
        $obUser->password = password_hash($password, PASSWORD_DEFAULT);
        $obUser->status = 'n';
        $obUser->tenancy_id = $tenancyId;
        $obUser->user_function = 'admin';
        $obUser->createdAt = date('Y-m-d H:i:s');
        $obUser->updatedAt = date('Y-m-d H:i:s');
        $obUser->last_activity = date('Y-m-d H:i:s');

        if ($newUserId = $obUser->RegisterUsers()) {

            // 4️⃣ Pega a role admin do tenancy
            $roleId = PermissionsRules::getAdminRoleId($tenancyId);

            if ($roleId) {
                PermissionsRules::assignRoleToUser($newUserId, $tenancyId, $roleId);
            }

            // 5️⃣ Cria plano padrão para o usuário
            $userPlanId = UserPlans::createUserPlan([
                'user_id'    => $newUserId,
                'plan_id'    => 4, // Plano inicial
                'tenancy_id' => $tenancyId,
                'status'     => 'confirmed'
            ]);

            // 🔹 Gerar invoiceNumber aleatório (9 dígitos)
            $invoiceNumber = str_pad(mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);

            // 6️⃣ Inserir créditos iniciais (R$ 3,00)
            BalanceSms::insertBalance(
                $newUserId,
                4,
                $tenancyId,
                3,
                0.30,
                0.05,
                0.10,
                $invoiceNumber
            );

            // 7️⃣ Atualizar plano ativo do tenancy
            RegisterTenancies::updateActivePlan($tenancyId, 1);

            // 8️⃣ Notificação de boas-vindas
            Notifications::insertNotifications(
                $tenancyId,
                $newUserId,
                "🎉 Bem-vindo(a)!",
                "Sua conta foi criada com sucesso! Você recebeu <b>R$ 3,00 de crédito</b> para testar o envio de SMS.<br>
         Fatura gerada: <b>#{$invoiceNumber}</b>",
                'notice'
            );

            self::jsonResponse(['status' => 'OK', 'message' => 'Usuário cadastrado com sucesso.']);
        } else {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'Erro ao cadastrar usuário.']);
        }

    }


}
