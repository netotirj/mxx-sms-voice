<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Model\Entity\SiteServiceTest;
use App\Session\User as SessionUser;
use App\Utils\Privacy;
use App\Utils\View;

class SiteTests
{
    public static function index($request): Response
    {
        $user = SessionUser::getLogged();
        if (!$user) {
            return new Response(401, 'Usuário não autenticado.');
        }

        $role = strtolower((string)($user['user_function'] ?? $user['function'] ?? ''));
        if ($role !== 'super_admin') {
            return new Response(403, 'Acesso restrito ao super admin.');
        }

        $query = method_exists($request, 'getQueryParams') ? $request->getQueryParams() : ($_GET ?? []);
        $filters = [
            'service_type' => self::cleanEnum((string)($query['service_type'] ?? ''), ['voice', 'sms', 'whatsapp']),
            'status' => self::cleanStatus((string)($query['status'] ?? '')),
            'email' => trim((string)($query['email'] ?? '')),
            'destination' => trim((string)($query['destination'] ?? '')),
            'date_from' => self::cleanDate((string)($query['date_from'] ?? '')),
            'date_to' => self::cleanDate((string)($query['date_to'] ?? '')),
        ];

        $rows = SiteServiceTest::list(array_filter($filters, static fn($v) => $v !== '' && $v !== null), 500);

        $content = View::render('site-tests/index', [
            'filters' => self::renderFilters($filters),
            'rows' => self::renderRows($rows),
            'total' => count($rows),
        ]);

        return new Response(200, ViewComponents::getComponentsReports('Testes do Site', $content));
    }

    private static function renderFilters(array $filters): string
    {
        return '
            <form method="get" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4">
                ' . self::select('service_type', 'Serviço', [
                    '' => 'Todos',
                    'sms' => 'SMS',
                    'voice' => 'Voz',
                    'whatsapp' => 'WhatsApp',
                ], (string)($filters['service_type'] ?? '')) . '
                ' . self::select('status', 'Status', [
                    '' => 'Todos',
                    'pending' => 'Pendente',
                    'sent' => 'Enviado',
                    'failed' => 'Falhou',
                ], (string)($filters['status'] ?? '')) . '
                ' . self::input('email', 'E-mail', (string)($filters['email'] ?? '')) . '
                ' . self::input('destination', 'Telefone', (string)($filters['destination'] ?? '')) . '
                ' . self::input('date_from', 'De', (string)($filters['date_from'] ?? ''), 'date') . '
                ' . self::input('date_to', 'Até', (string)($filters['date_to'] ?? ''), 'date') . '
                <div class="lg:col-span-6 flex flex-wrap gap-2">
                    <button class="inline-flex items-center gap-2 bg-gradient-to-tr from-slate-800 to-slate-700 text-white text-xs font-bold px-4 py-2.5 rounded-lg shadow uppercase transition-all hover:scale-[1.02]" type="submit">
                        <i class="fa fa-search text-xs"></i> Filtrar
                    </button>
                    <a class="inline-flex items-center gap-2 border border-slate-200 dark:border-slate-600 text-slate-500 dark:text-slate-300 text-xs font-bold px-4 py-2.5 rounded-lg uppercase transition-all hover:bg-slate-50 dark:hover:bg-slate-800" href="' . URL . '/site-tests">
                        <i class="fa fa-eraser text-xs"></i> Limpar
                    </a>
                </div>
            </form>';
    }

    private static function renderRows(array $rows): string
    {
        if ($rows === []) {
            return '<tr><td colspan="9" class="px-6 py-8 text-center italic text-slate-400 dark:text-slate-500">Nenhum teste encontrado.</td></tr>';
        }

        $html = '';
        foreach ($rows as $row) {
            $provider = self::prettyJson($row['provider_response'] ?? null);
            $payload = self::prettyJson($row['request_payload'] ?? null);
            $fullIp = (string)($row['ip_address'] ?? '');
            $maskedIp = Privacy::maskIp($fullIp);
            $ipDisclosureId = 'site-test-ip-' . (int)$row['id'];
            $html .= '<tr class="hover:bg-slate-50 dark:hover:bg-slate-800/60 border-b border-slate-100 dark:border-slate-700 align-top">
                <td class="px-6 py-4 text-xs font-bold text-slate-400">#' . self::e((string)$row['id']) . '</td>
                <td class="px-6 py-4 text-sm font-semibold text-slate-800 dark:text-white">' . self::e((string)$row['email']) . '</td>
                <td class="px-6 py-4">' . self::badge((string)$row['service_type']) . '</td>
                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">' . self::e((string)($row['destination'] ?? '')) . '</td>
                <td class="px-6 py-4">' . self::statusBadge((string)$row['status']) . '</td>
                <td class="px-6 py-4 text-xs text-slate-500 dark:text-slate-400">' . self::e($maskedIp) . '</td>
                <td class="px-6 py-4 text-xs text-slate-500 dark:text-slate-400">' . self::e((string)($row['provider'] ?? '')) . '<br><span class="text-xxs text-slate-400">' . self::e((string)($row['provider_message_id'] ?? '')) . '</span></td>
                <td class="px-6 py-4 text-xs text-slate-500 dark:text-slate-400">' . self::e((string)($row['created_at'] ?? '')) . '<br>' . self::e((string)($row['sent_at'] ?? '')) . '</td>
                <td class="px-6 py-4 text-center">
                    <details class="inline-block text-left">
                        <summary class="cursor-pointer rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-600 dark:bg-blue-900/40 dark:text-blue-200">Ver</summary>
                        <div class="mt-3 grid gap-3 md:grid-cols-2">
                            <pre class="max-h-72 w-[min(36rem,80vw)] overflow-auto rounded-xl bg-slate-950 p-3 text-xs text-slate-100">' . self::e($provider) . '</pre>
                            <div class="max-h-72 w-[min(36rem,80vw)] overflow-auto rounded-xl bg-slate-950 p-3 text-xs text-slate-100">
                                <p><strong>IP mascarado:</strong> ' . self::e($maskedIp) . '</p>
                                <button type="button" onclick="(function(){var el=document.getElementById(\'' . self::e($ipDisclosureId) . '\'); if(el){el.classList.toggle(\'hidden\');}})()" class="mt-2 rounded-md bg-slate-800 px-2 py-1 text-[11px] font-bold text-slate-100">Visualizar IP completo</button>
                                <p id="' . self::e($ipDisclosureId) . '" class="mt-2 hidden"><strong>IP completo:</strong> ' . self::e($fullIp) . '</p>
                                <pre class="mt-3 whitespace-pre-wrap">' . self::e($payload . "\n\nErro:\n" . (string)($row['error_message'] ?? '')) . '</pre>
                            </div>
                        </div>
                    </details>
                </td>
            </tr>';
        }

        return $html;
    }

    private static function input(string $name, string $label, string $value, string $type = 'text'): string
    {
        return '<label class="block text-xs font-medium text-slate-500">' . self::e($label) . '<input class="mt-1 w-full px-2 py-1.5 border border-slate-200 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 dark:text-white outline-none" type="' . self::e($type) . '" name="' . self::e($name) . '" value="' . self::e($value) . '"></label>';
    }

    private static function select(string $name, string $label, array $options, string $selected): string
    {
        $html = '<label class="block text-xs font-medium text-slate-500">' . self::e($label) . '<select class="mt-1 w-full px-2 py-1.5 border border-slate-200 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-700 dark:text-white outline-none" name="' . self::e($name) . '">';
        foreach ($options as $value => $text) {
            $html .= '<option value="' . self::e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . self::e($text) . '</option>';
        }
        return $html . '</select></label>';
    }

    private static function badge(string $service): string
    {
        $label = ['sms' => 'SMS', 'voice' => 'Voz', 'whatsapp' => 'WhatsApp'][$service] ?? $service;
        return '<span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">' . self::e($label) . '</span>';
    }

    private static function statusBadge(string $status): string
    {
        $color = match ($status) {
            'sent' => 'bg-emerald-50 text-emerald-700',
            'failed' => 'bg-rose-50 text-rose-700',
            default => 'bg-amber-50 text-amber-700',
        };
        return '<span class="rounded-full px-3 py-1 text-xs font-bold ' . $color . '">' . self::e($status) . '</span>';
    }

    private static function prettyJson(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE
            ? (string)json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $value;
    }

    private static function cleanEnum(string $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : '';
    }

    private static function cleanStatus(string $value): string
    {
        return self::cleanEnum($value, ['pending', 'sent', 'failed']);
    }

    private static function cleanDate(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
