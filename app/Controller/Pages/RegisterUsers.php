<?php

namespace App\Controller\Pages;

use App\Utils\View;
use App\Model\Entity\UserAuthentication;
use App\Model\Entity\PermissionsRules;
use App\Model\Entity\RegisterTenancies;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\Notifications;
use App\Model\Entity\UserPlans;
use App\Session\User as SessionUser;
use Ramsey\Uuid\Uuid;
use Random\RandomException;
use Throwable;
use WilliamCosta\DatabaseManager\Database;

class RegisterUsers extends ViewComponents
{
    private const int BOOTSTRAP_PLAN_ID = 37;

    public static function getRegister($request): array|bool|string
    {
        $content = View::render('', []);
        return parent::getComponentsRegister('Maxx Solutions - SMS | Register', $content);
    }

    /**
     * Gera account_code único
     * @throws RandomException
     */
    public static function generateUniqueAccountCode(int $attempts = 30): string
    {
        for ($i = 0; $i < $attempts; $i++) {
            $code = (string) random_int(1000000000, 9999999999);
            if (!RegisterTenancies::accountCodeExists($code)) {
                return $code;
            }
        }
        throw new \RuntimeException("Falha ao gerar account_code único.");
    }

    /**
     * Realiza o registro do novo Tenancy e Usuário Admin
     * @throws RandomException
     */
    public static function setRegister($request): void
    {
        header('Content-Type: application/json; charset=utf-8');
        SessionUser::ensureSessionStarted();

        $postVars = $request->getPostVars();
        $socialProfile = self::getPendingSocialRegistration();
        $requestedSocialRegistration = ($postVars['social_registration'] ?? '') === '1';
        $isSocialRegistration = $requestedSocialRegistration && $socialProfile !== [];

        $name     = trim($postVars['name'] ?? '');
        $lastname = trim($postVars['lastname'] ?? '');
        $email    = strtolower(trim($postVars['email'] ?? ''));
        $password = trim($postVars['password'] ?? '');
        $phone    = self::normalizePhone((string)($postVars['phone'] ?? ''));
        $acceptTerms = isset($postVars['accept_terms']) && $postVars['accept_terms'] === 'on';
        $captcha = trim($postVars['cf-turnstile-response'] ?? '');

        if (Recaptcha::isTurnstileEnabled() && !Recaptcha::verifyTurnstile($captcha)) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'Verificação de segurança inválida.']);
        }

        if ($requestedSocialRegistration && $socialProfile === []) {
            self::jsonResponse([
                'status' => 'ERROR',
                'message' => 'Sua sessão de cadastro social expirou. Clique novamente no botão da rede social.'
            ]);
        }

        if ($isSocialRegistration) {
            $socialFirstName = trim((string)($socialProfile['first_name'] ?? ''));
            $socialLastName = trim((string)($socialProfile['last_name'] ?? ''));

            if ($socialFirstName !== '') {
                $name = $socialFirstName;
            }

            if ($socialLastName !== '') {
                $lastname = $socialLastName;
            }

            $email = strtolower(trim((string)($socialProfile['email'] ?? $email)));
            $password = bin2hex(random_bytes(24));
        }

        // Validações
        if ($name === '' || $lastname === '' || $phone === '' || $email === '' || (!$isSocialRegistration && $password === '')) {
            $missingFields = [];

            if ($name === '') {
                $missingFields[] = 'nome';
            }
            if ($lastname === '') {
                $missingFields[] = 'sobrenome';
            }
            if ($phone === '') {
                $missingFields[] = 'telefone';
            }
            if ($email === '') {
                $missingFields[] = 'e-mail';
            }
            if (!$isSocialRegistration && $password === '') {
                $missingFields[] = 'senha';
            }

            self::jsonResponse([
                'status' => 'ERROR',
                'message' => 'Preencha: ' . implode(', ', $missingFields) . '.'
            ]);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'E-mail inválido.']);
        }

        if (!self::isValidPhone($phone)) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'Telefone inválido. Informe um celular válido com DDD.']);
        }

        if (!$acceptTerms) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'Você deve aceitar os Termos e Condições.']);
        }

        if (UserAuthentication::getUserByEmail($email) instanceof UserAuthentication) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'E-mail já cadastrado.']);
        }

        if (RegisterTenancies::phoneExists($phone)) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'Telefone já cadastrado.']);
        }

        $result = self::createAccount([
            'name' => $name,
            'lastname' => $lastname,
            'email' => $email,
            'phone' => $phone,
            'password' => $password,
        ]);

        if (($result['status'] ?? 'ERROR') === 'OK') {
            unset($_SESSION['social_register_profile']);
            self::jsonResponse([
                'status' => 'OK',
                'message' => $isSocialRegistration
                    ? 'Conta social cadastrada com sucesso. Você já pode entrar pela rede social.'
                    : 'Usuário cadastrado com sucesso.'
            ]);
        }

        self::jsonResponse(['status' => 'ERROR', 'message' => $result['message'] ?? 'Erro ao finalizar cadastro.']);
    }

    public static function createAccount(array $data): array
    {
        $name = trim((string)($data['name'] ?? ''));
        $lastname = trim((string)($data['lastname'] ?? ''));
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $password = trim((string)($data['password'] ?? ''));
        $phone = self::normalizePhone((string)($data['phone'] ?? ''));

        if ($name === '' || $email === '' || $password === '' || $phone === '') {
            return ['status' => 'ERROR', 'message' => 'Nome, e-mail, telefone e senha são obrigatórios.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'ERROR', 'message' => 'E-mail inválido.'];
        }

        if (!self::isValidPhone($phone)) {
            return ['status' => 'ERROR', 'message' => 'Telefone inválido. Informe um celular válido com DDD.'];
        }

        if (UserAuthentication::getUserByEmail($email) instanceof UserAuthentication) {
            return ['status' => 'ERROR', 'message' => 'E-mail já cadastrado.'];
        }

        if (RegisterTenancies::phoneExists($phone)) {
            return ['status' => 'ERROR', 'message' => 'Telefone já cadastrado.'];
        }

        $tenancyId   = Uuid::uuid4()->toString();
        $accountCode = self::generateUniqueAccountCode();
        $db = new Database();

        try {
            self::ensureRegistrationSchema();
            $db->beginTransaction();

            // 1️⃣ Criar Tenancy
            $obTenancy = new RegisterTenancies();
            $obTenancy->id = $tenancyId;
            $obTenancy->name = $name;
            $obTenancy->tenancy_phone = $phone;
            $obTenancy->account_code = $accountCode;
            $obTenancy->status = 'active';
            $obTenancy->created_at = date('Y-m-d H:i:s');
            $obTenancy->updated_at = date('Y-m-d H:i:s');

            if (!$obTenancy->insertUserTenancy()) {
                throw new \RuntimeException('Erro ao criar tenancy.');
            }

            // 2️⃣ Criar papel admin global da tenancy
            $roleId = PermissionsRules::registerRole(
                'admin',
                'Administrador',
                'y',
                $tenancyId,
                null,
                null
            );

            if ($roleId <= 0) {
                throw new \RuntimeException('Erro ao criar papel administrativo.');
            }

            PermissionsRules::initAdminPermissions($tenancyId, $roleId);

            // 3️⃣ Criar Usuário admin
            $obUser = new UserAuthentication();
            $obUser->name = $name;
            $obUser->account_code = $accountCode;
            $obUser->last_name = $lastname;
            $obUser->email = $email;
            $obUser->password = password_hash($password, PASSWORD_DEFAULT);
            $obUser->status_account = 'active';
            $obUser->status = 'n';
            $obUser->tenancy_id = $tenancyId;
            $obUser->user_function = 'admin';
            $obUser->role_id = $roleId;
            $obUser->createdAt = date('Y-m-d H:i:s');
            $obUser->updatedAt = date('Y-m-d H:i:s');

            $newUserId = (int)$obUser->RegisterUsers();
            if ($newUserId <= 0) {
                throw new \RuntimeException('Erro ao criar usuário administrador.');
            }

            // 4️⃣ Vincular o usuário ao papel criado
            PermissionsRules::assignRoleToUser($newUserId, $tenancyId, $roleId);

            // 5️⃣ Configurações iniciais de plano e saldo
            $userPlanId = UserPlans::createUserPlan([
                'user_id'    => $newUserId,
                'plan_id'    => self::BOOTSTRAP_PLAN_ID,
                'tenancy_id' => $tenancyId,
                'status'     => 'confirmed'
            ]);

            if ($userPlanId <= 0) {
                throw new \RuntimeException('Erro ao vincular plano inicial da conta.');
            }

            $invoiceNumber = str_pad(mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);
            $balanceInserted = BalanceSms::insertBalance(
                $newUserId,
                self::BOOTSTRAP_PLAN_ID,
                $tenancyId,
                3,
                0.20,
                0.35,
                0.35,
                $invoiceNumber,
                0.85,
                0.35,
                0.70,
                null,
                null,
                null,
                null,
                'bootstrap:' . $invoiceNumber
            );

            if (!$balanceInserted) {
                throw new \RuntimeException('Erro ao aplicar crédito inicial da conta.');
            }

            if (!RegisterTenancies::updateActivePlan($tenancyId, $userPlanId)) {
                throw new \RuntimeException('Erro ao definir plano ativo inicial da tenancy.');
            }

            // 6️⃣ Notificação de boas-vindas
            $notificationId = Notifications::insertNotifications(
                $tenancyId,
                $newUserId,
                "🎉 Bem-vindo(a)!",
                "Conta criada! Você recebeu R$ 3,00 de crédito inicial.",
                'notice'
            );

            if ($notificationId === false) {
                throw new \RuntimeException('Erro ao finalizar cadastro da notificação inicial.');
            }

            $db->commit();

            $obUser->id = $newUserId;

            return [
                'status' => 'OK',
                'message' => 'Usuário cadastrado com sucesso.',
                'user' => $obUser,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log(json_encode([
                'event' => 'register_account_failed',
                'tenancy_id' => $tenancyId,
                'email' => $email,
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return ['status' => 'ERROR', 'message' => 'Erro ao finalizar cadastro.'];
        }
    }

    private static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if ($digits !== '' && !str_starts_with($digits, '55')) {
            $digits = '55' . $digits;
        }

        return $digits;
    }

    private static function isValidPhone(string $phone): bool
    {
        return str_starts_with($phone, '55') && strlen($phone) >= 12 && strlen($phone) <= 13;
    }

    private static function getPendingSocialRegistration(): array
    {
        $profile = $_SESSION['social_register_profile'] ?? [];

        if (!is_array($profile) || empty($profile['email']) || empty($profile['created_at'])) {
            return [];
        }

        if ((time() - (int)$profile['created_at']) > 900) {
            unset($_SESSION['social_register_profile']);
            return [];
        }

        return $profile;
    }

    private static function ensureRegistrationSchema(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $checks = [
            'users' => 'INT(10) UNSIGNED NOT NULL AUTO_INCREMENT',
            'sys_roles' => 'INT(11) NOT NULL AUTO_INCREMENT',
            'mxx_user_plans' => 'INT(10) UNSIGNED NOT NULL AUTO_INCREMENT',
            'tenancy_balance' => 'INT(10) UNSIGNED NOT NULL AUTO_INCREMENT',
            'notifications' => 'INT(10) UNSIGNED NOT NULL AUTO_INCREMENT',
        ];

        foreach ($checks as $table => $definition) {
            self::ensureAutoIncrementId($table, $definition);
        }

        $ensured = true;
    }

    private static function ensureAutoIncrementId(string $table, string $definition): void
    {
        $db = new Database();
        $column = $db->execute(
            "SELECT COLUMN_NAME, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = 'id'
             LIMIT 1",
            [':table' => $table]
        )->fetchObject();

        if (!$column) {
            return;
        }

        $extra = strtolower(trim((string)($column->EXTRA ?? '')));
        if (str_contains($extra, 'auto_increment')) {
            return;
        }

        $db->execute(sprintf(
            'ALTER TABLE `%s` MODIFY `id` %s',
            $table,
            $definition
        ));
    }
}
