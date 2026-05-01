<?php
global $obRouter;

use App\Http\Response;

$siteResponse = function () {
    $path = dirname(__DIR__) . '/database/site/index.html';
    if (!is_file($path)) {
        return new Response(404, 'Site não encontrado.');
    }

    $baseUrl = rtrim(defined('VIEW_URL') ? VIEW_URL : (defined('URL') ? URL : ''), '/');
    $html = (string)file_get_contents($path);

    $turnstileSiteKey = trim((string)(getenv('PUBLIC_DEMO_TURNSTILE_SITE_KEY') ?: getenv('TURNSTILE_SITE_KEY') ?: ''));

    if ($baseUrl !== '') {
        $html = str_replace('href="/login"', 'href="' . $baseUrl . '/login"', $html);
        $html = str_replace('href="/register"', 'href="' . $baseUrl . '/register"', $html);
        $html = str_replace('src="/logo.png"', 'src="' . $baseUrl . '/site/logo.png"', $html);
        $html = str_replace('data-demo-api-base="/sms"', 'data-demo-api-base="' . $baseUrl . '"', $html);
    } else {
        $html = str_replace('src="/logo.png"', 'src="/site/logo.png"', $html);
    }

    if ($turnstileSiteKey === '') {
        $html = str_replace(
            '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>',
            '',
            $html
        );

        $html = preg_replace(
            '/<div class="mt-4 cf-turnstile" data-sitekey="\{\{PUBLIC_DEMO_TURNSTILE_SITE_KEY\}\}" data-theme="dark"><\/div>/',
            '<div class="mt-4 rounded-2xl border border-amber-400/30 bg-amber-500/10 p-3 text-sm text-amber-100">Configure o Cloudflare Turnstile para liberar este teste.</div>',
            $html
        ) ?? $html;
    }

    $html = str_replace('{{PUBLIC_DEMO_TURNSTILE_SITE_KEY}}', htmlspecialchars($turnstileSiteKey, ENT_QUOTES, 'UTF-8'), $html);

    return new Response(200, $html, 'text/html');
};

$logoResponse = function () {
    $path = dirname(__DIR__) . '/branding_kit/logo_white.png';
    if (!is_file($path)) {
        return new Response(404, 'Logo não encontrado.');
    }

    return new Response(200, file_get_contents($path), 'image/png');
};

$obRouter->get('/', [
    'name' => '/',
    'middlewares' => [],
    $siteResponse
]);

$obRouter->get('/site', [
    'name' => '/site',
    'middlewares' => [],
    $siteResponse
]);

$obRouter->get('/site/logo.png', [
    'name' => '/site/logo.png',
    'middlewares' => [],
    $logoResponse
]);

$obRouter->get('/logo.png', [
    'name' => '/logo.png',
    'middlewares' => [],
    $logoResponse
]);
