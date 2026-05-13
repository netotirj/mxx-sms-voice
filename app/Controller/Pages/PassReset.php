<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Model\Entity\RegisterTenancies;
use App\Model\Entity\UserSearch;
use App\Utils\View;
use Random\RandomException;

class PassReset extends ViewComponents
{
    private const int RESET_CODE_TTL = 300;

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
        $channel = self::normalizeResetChannel((string)($postVars['channel'] ?? 'sms'));

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
        $_SESSION['reset_channel'] = $channel;
        $_SESSION['reset_destination'] = self::describeResetDestination($channel, $dataEmail);

        // Gera código de 6 dígitos
        $code = random_int(100000, 999999);
        RegisterTenancies::save($dataEmail->tenancy_id, $dataEmail->user_id, $code, self::RESET_CODE_TTL);

        $delivery = self::sendResetCode($dataEmail, $code, $channel);
        if (!$delivery['ok']) {
            return new Response(500, [
                'status' => 'ERROR',
                'message' => $delivery['message'],
            ], 'application/json');
        }

        // Retorna JSON com telefone mascarado
        return new Response(200, [
            'status' => 'OK',
            'message' => $delivery['message'],
            'channel' => $channel,
            'destination' => $_SESSION['reset_destination'],
        ], 'application/json');
    }

    public static function setResetPassCode($request): string
    {
        $destination = $_SESSION['reset_destination'] ?? ($_SESSION['maskedPhone'] ?? 'seu contato cadastrado');
        $content = View::render('login/code-pass-content', [
            'destination' => $destination,
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
            unset($_SESSION['reset_channel']);
            unset($_SESSION['reset_destination']);

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
        if (!$dataEmailTenancy) {
            return new Response(404, [
                'status' => 'ERROR',
                'message' => 'Conta não encontrada. Solicite o reset novamente.'
            ], 'application/json');
        }

        $code = random_int(100000, 999999);
        RegisterTenancies::save($dataEmailTenancy->tenancy_id, $dataEmailTenancy->user_id, $code, self::RESET_CODE_TTL);

        $channel = self::normalizeResetChannel((string)($_SESSION['reset_channel'] ?? 'sms'));
        $delivery = self::sendResetCode($dataEmailTenancy, $code, $channel);

        if (!$delivery['ok']) {
            return new Response(500, [
                'status' => 'ERROR',
                'message' => $delivery['message']
            ], 'application/json');
        }

        return new Response(200, [
            'status' => 'OK',
            'message' => $delivery['message']
        ], 'application/json');
    }

    private static function normalizeResetChannel(string $channel): string
    {
        return in_array($channel, ['sms', 'email', 'both'], true) ? $channel : 'sms';
    }

    private static function sendResetCode(object $account, int $code, string $channel): array
    {
        $errors = [];
        $sent = [];
        $message = "Seu codigo de verificacao Maxx Solutions e: {$code}. Ele expira em 5 minutos.";

        if (in_array($channel, ['sms', 'both'], true)) {
            $smsResult = SentSmsUnique::send($account->tenancy_phone, $message);
            if ($smsResult === true) {
                $sent[] = 'SMS';
            } else {
                $errors[] = 'SMS: ' . $smsResult;
            }
        }

        if (in_array($channel, ['email', 'both'], true)) {
            if (self::sendResetEmail((string)$account->email, $code)) {
                $sent[] = 'e-mail';
            } else {
                $errors[] = 'e-mail: envio indisponível no servidor.';
            }
        }

        if ($sent === []) {
            return [
                'ok' => false,
                'message' => 'Não foi possível enviar o código. ' . implode(' ', $errors),
            ];
        }

        $sentLabel = implode(' e ', $sent);
        return [
            'ok' => true,
            'message' => 'Código enviado por ' . $sentLabel . '.',
        ];
    }

    private static function sendResetEmail(string $email, int $code): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (trim((string)getenv('SMTP_HOST')) !== '') {
            return self::sendResetEmailViaSmtp($email, $code);
        }

        $from = self::systemMailFrom();
        $subject = 'Código de recuperação de senha - Maxx Solutions';
        $body = "Olá,\n\nSeu código de recuperação de senha é: {$code}\n\nEle expira em 5 minutos. Se você não solicitou essa recuperação, ignore esta mensagem.\n\nEm caso de dúvida, fale com " . self::systemContactEmail() . ".\n\nMaxx Solutions";
        $headers = [
            'From: Maxx Solutions <' . $from . '>',
            'Reply-To: ' . self::systemContactEmail(),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: PHP/' . phpversion(),
        ];

        return @mail($email, $subject, $body, implode("\r\n", $headers));
    }

    private static function sendResetEmailViaSmtp(string $email, int $code): bool
    {
        $host = trim((string)getenv('SMTP_HOST'));
        $port = (int)(getenv('SMTP_PORT') ?: 587);
        $secure = strtolower(trim((string)getenv('SMTP_SECURE')));
        $user = trim((string)getenv('SMTP_USER'));
        $pass = (string)getenv('SMTP_PASS');
        $from = trim((string)(getenv('MAIL_FROM') ?: self::systemContactEmail()));
        $fromName = trim((string)(getenv('MAIL_FROM_NAME') ?: 'Maxx Solutions'));

        if ($host === '' || $from === '') {
            return false;
        }

        $transportHost = $secure === 'ssl' ? 'ssl://' . $host : $host;
        $socket = @stream_socket_client($transportHost . ':' . $port, $errno, $errstr, 20);
        if (!$socket) {
            return false;
        }

        stream_set_timeout($socket, 20);

        $ok = self::smtpExpect($socket, [220])
            && self::smtpCommand($socket, 'EHLO ' . (parse_url((string)(getenv('URL') ?: URL), PHP_URL_HOST) ?: 'localhost'), [250]);

        if ($ok && $secure === 'tls') {
            $ok = self::smtpCommand($socket, 'STARTTLS', [220])
                && stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
                && self::smtpCommand($socket, 'EHLO ' . (parse_url((string)(getenv('URL') ?: URL), PHP_URL_HOST) ?: 'localhost'), [250]);
        }

        if ($ok && $user !== '') {
            $ok = self::smtpCommand($socket, 'AUTH LOGIN', [334])
                && self::smtpCommand($socket, base64_encode($user), [334])
                && self::smtpCommand($socket, base64_encode($pass), [235]);
        }

        $subject = 'Código de recuperação de senha - Maxx Solutions';
        $body = "Olá,\r\n\r\nSeu código de recuperação de senha é: {$code}\r\n\r\nEle expira em 5 minutos. Se você não solicitou essa recuperação, ignore esta mensagem.\r\n\r\nEm caso de dúvida, fale com " . self::systemContactEmail() . ".\r\n\r\nMaxx Solutions";
        $headers = [
            'From: ' . self::formatEmailHeader($fromName, $from),
            'Reply-To: <' . self::systemContactEmail() . '>',
            'To: <' . $email . '>',
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";

        $ok = $ok
            && self::smtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250])
            && self::smtpCommand($socket, 'RCPT TO:<' . $email . '>', [250, 251])
            && self::smtpCommand($socket, 'DATA', [354])
            && self::smtpCommand($socket, $payload, [250]);

        self::smtpCommand($socket, 'QUIT', [221]);
        fclose($socket);

        return $ok;
    }

    private static function smtpCommand($socket, string $command, array $expectedCodes): bool
    {
        fwrite($socket, $command . "\r\n");
        return self::smtpExpect($socket, $expectedCodes);
    }

    private static function smtpExpect($socket, array $expectedCodes): bool
    {
        $line = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) {
                return false;
            }
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int)substr($line, 0, 3);
        return in_array($code, $expectedCodes, true);
    }

    private static function formatEmailHeader(string $name, string $email): string
    {
        $name = trim(str_replace(["\r", "\n"], '', $name));
        $email = trim(str_replace(["\r", "\n"], '', $email));

        return $name !== '' ? sprintf('"%s" <%s>', addcslashes($name, '"\\'), $email) : '<' . $email . '>';
    }

    private static function systemContactEmail(): string
    {
        $supportEmail = trim((string)getenv('SUPPORT_EMAIL'));
        if (filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
            return $supportEmail;
        }

        $mailFrom = trim((string)getenv('MAIL_FROM'));
        if (filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
            return $mailFrom;
        }

        return 'sac@maxxsolutions.com.br';
    }

    private static function systemMailFrom(): string
    {
        $mailFrom = trim((string)getenv('MAIL_FROM'));
        if (filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
            return $mailFrom;
        }

        return self::systemContactEmail();
    }

    private static function describeResetDestination(string $channel, object $account): string
    {
        $maskedEmail = self::maskEmail((string)$account->email);
        $maskedPhone = (string)($account->maskedPhone ?? 'telefone cadastrado');

        return match ($channel) {
            'email' => $maskedEmail,
            'both' => $maskedPhone . ' e ' . $maskedEmail,
            default => $maskedPhone,
        };
    }

    private static function maskEmail(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($user === '' || $domain === '') {
            return 'e-mail cadastrado';
        }

        $prefix = substr($user, 0, 2);
        return $prefix . '***@' . $domain;
    }

}
