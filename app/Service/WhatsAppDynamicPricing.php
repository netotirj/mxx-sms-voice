<?php

namespace App\Service;

use App\Config\TelephonyConfig;
use App\RedisConn;
use GuzzleHttp\Client;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppDynamicPricing
{
    private const DEFAULT_COUNTRY = 'BR';
    private const DEFAULT_CURRENCY = 'USD';
    private const CACHE_TTL_SECONDS = 600;

    private const DEFAULT_USD_PRICES = [
        'marketing' => 0.071880,
        'utility' => 0.007820,
        'authentication' => 0.007820,
        'voice' => 0.010800,
    ];

    private const DEFAULT_MARGINS = [
        'marketing' => 36.00,
        'utility' => 140.00,
        'authentication' => 140.00,
        'voice' => 80.00,
    ];

    public static function calculatePrice(string $messageType, string $countryCode = self::DEFAULT_COUNTRY, ?int $customerId = null): array
    {
        $messageType = self::normalizeMessageType($messageType);
        if ($messageType === 'service') {
            return self::freePrice($countryCode, $customerId);
        }

        $countryCode = self::normalizeCountryCode($countryCode);
        self::seedDefaults();
        $exchange = self::currentExchangeRate();
        $metaPrice = self::activeMetaPrice($messageType, $countryCode);
        $margin = self::marginPercent($messageType, $customerId);
        $cacheKey = 'whatsapp:pricing:'
            . strtolower($countryCode)
            . ':' . $messageType
            . ':' . (int)($customerId ?? 0)
            . ':' . (string)($exchange['updated_at'] ?? '')
            . ':' . (string)($exchange['effective_rate'] ?? '')
            . ':' . (string)($metaPrice['price_usd'] ?? '')
            . ':' . (string)$margin;
        $cached = self::cacheGet($cacheKey);
        if (is_array($cached)) {
            return $cached + ['cache' => 'hit'];
        }

        $extraMargin = self::automaticExtraMargin($exchange);
        $appliedMargin = $margin + $extraMargin;
        $costBrl = round((float)$metaPrice['price_usd'] * (float)$exchange['effective_rate'], 6);
        $rawFinal = $costBrl * (1 + ($appliedMargin / 100));
        $finalPrice = self::commercialRound($rawFinal);
        $finalPrice = self::applyPriceFloor($countryCode, $messageType, $customerId, $finalPrice, (float)($exchange['daily_change_percent'] ?? 0));

        $result = [
            'message_type' => $messageType,
            'country_code' => $countryCode,
            'customer_id' => $customerId,
            'cost_usd' => round((float)$metaPrice['price_usd'], 6),
            'exchange_rate' => round((float)$exchange['rate_brl'], 6),
            'safety_margin_percent' => round((float)$exchange['safety_margin_percent'], 4),
            'effective_rate' => round((float)$exchange['effective_rate'], 6),
            'cost_brl' => $costBrl,
            'margin_percent' => round($appliedMargin, 4),
            'base_margin_percent' => round($margin, 4),
            'extra_margin_percent' => round($extraMargin, 4),
            'final_price_brl' => $finalPrice,
            'profit_brl' => round($finalPrice - $costBrl, 6),
            'exchange_alert' => !empty($exchange['alert_flag']),
            'pricing_source' => (string)($metaPrice['source'] ?? 'database'),
            'exchange_updated_at' => $exchange['updated_at'] ?? null,
            'cache' => 'miss',
        ];

        self::cacheSet($cacheKey, $result, self::cacheTtl());
        return $result;
    }

    public static function salePriceBrl(string $messageType, string $countryCode = self::DEFAULT_COUNTRY, ?int $customerId = null): float
    {
        return (float)self::calculatePrice($messageType, $countryCode, $customerId)['final_price_brl'];
    }

    public static function pricingForLedger(string $messageType, string $countryCode, ?int $customerId = null): array
    {
        $pricing = self::calculatePrice($messageType, $countryCode, $customerId);

        return [
            'country_code' => $pricing['country_code'],
            'cost_usd' => $pricing['cost_usd'],
            'exchange_rate' => $pricing['exchange_rate'],
            'effective_rate' => $pricing['effective_rate'],
            'cost_brl' => $pricing['cost_brl'],
            'margin_percent' => $pricing['margin_percent'],
            'final_price_brl' => $pricing['final_price_brl'],
            'pricing_payload' => $pricing,
        ];
    }

    public static function updateUsdRate(?float $manualRate = null, string $source = 'manual'): array
    {
        self::seedDefaults();
        $currency = self::DEFAULT_CURRENCY;
        $previous = self::storedExchangeRate();
        $rate = $manualRate !== null && $manualRate > 0 ? $manualRate : self::fetchUsdBrlRate();
        if ($rate <= 0) {
            return [
                'success' => false,
                'message' => 'API de cambio falhou. Nenhuma nova cotacao USD/BRL foi confirmada.',
                'data' => $previous,
            ];
        }

        $safetyMargin = (float)($previous['safety_margin_percent'] ?? self::exchangeSafetyMarginPercent());
        $effectiveRate = self::effectiveRate($rate, $safetyMargin);
        $previousRate = (float)($previous['rate_brl'] ?? 0);
        $dailyChange = $previousRate > 0 ? (($rate - $previousRate) / $previousRate) * 100 : 0.0;
        $alert = abs($dailyChange) >= self::exchangeAlertThresholdPercent();

        (new Database())->execute(
            "INSERT INTO currency_exchange_rates
                (currency, rate_brl, safety_margin_percent, effective_rate, daily_change_percent, alert_flag, source, updated_at)
             VALUES (:currency, :rate_brl, :safety_margin, :effective_rate, :daily_change, :alert, :source, NOW())
             ON DUPLICATE KEY UPDATE
                rate_brl = VALUES(rate_brl),
                safety_margin_percent = VALUES(safety_margin_percent),
                effective_rate = VALUES(effective_rate),
                daily_change_percent = VALUES(daily_change_percent),
                alert_flag = VALUES(alert_flag),
                source = VALUES(source),
                updated_at = NOW()",
            [
                ':currency' => $currency,
                ':rate_brl' => $rate,
                ':safety_margin' => $safetyMargin,
                ':effective_rate' => $effectiveRate,
                ':daily_change' => $dailyChange,
                ':alert' => $alert ? 1 : 0,
                ':source' => $source,
            ]
        );

        if ($alert) {
            (new Database('currency_exchange_alerts'))->insert([
                'currency' => $currency,
                'previous_rate_brl' => $previousRate ?: null,
                'new_rate_brl' => $rate,
                'change_percent' => $dailyChange,
                'threshold_percent' => self::exchangeAlertThresholdPercent(),
                'message' => 'Variação cambial acima do limite configurado.',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        self::cacheDelete('whatsapp:exchange:' . strtolower($currency));
        $planSync = self::syncPlanWhatsappPricing();

        return [
            'success' => true,
            'message' => $alert ? 'Cotação atualizada com alerta de variação.' : 'Cotação atualizada.',
            'data' => self::currentExchangeRate(false),
            'plan_pricing_sync' => $planSync,
        ];
    }

    public static function syncPlanWhatsappPricing(string $countryCode = self::DEFAULT_COUNTRY): array
    {
        $countryCode = self::normalizeCountryCode($countryCode);
        $categories = ['marketing', 'utility', 'authentication', 'voice'];
        $prices = [];

        try {
            foreach ($categories as $category) {
                $prices[$category] = self::calculatePrice($category, $countryCode, 0);
            }

            foreach ($prices as $category => $pricing) {
                $priceBrl = round((float)($pricing['final_price_brl'] ?? 0), 4);
                if ($priceBrl <= 0) {
                    continue;
                }

                (new Database())->execute(
                    "INSERT INTO plan_whatsapp_pricing (plan_id, category, price_brl, created_at, updated_at)
                     SELECT id, :category, :price_brl, NOW(), NOW()
                     FROM mxx_plans
                     WHERE status = 'active'
                     ON DUPLICATE KEY UPDATE
                        price_brl = VALUES(price_brl),
                        updated_at = NOW()",
                    [
                        ':category' => $category,
                        ':price_brl' => $priceBrl,
                    ]
                );
            }

            self::syncMxxPlanWhatsappColumns($prices);

            return [
                'success' => true,
                'country_code' => $countryCode,
                'prices' => [
                    'marketing' => round((float)($prices['marketing']['final_price_brl'] ?? 0), 4),
                    'utility' => round((float)($prices['utility']['final_price_brl'] ?? 0), 4),
                    'authentication' => round((float)($prices['authentication']['final_price_brl'] ?? 0), 4),
                    'voice' => round((float)($prices['voice']['final_price_brl'] ?? 0), 4),
                ],
                'synced_at' => date('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            error_log('[whatsapp_plan_pricing_sync_dynamic] ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public static function refreshUsdRateIfStale(int $maxAgeSeconds = self::CACHE_TTL_SECONDS): array
    {
        $current = self::storedExchangeRate();
        $updatedAt = strtotime((string)($current['updated_at'] ?? '')) ?: 0;
        if (is_array($current) && $updatedAt > 0 && (time() - $updatedAt) <= max(60, $maxAgeSeconds)) {
            return [
                'success' => true,
                'message' => 'Cotação ainda dentro da validade.',
                'data' => $current,
                'cache' => 'fresh',
            ];
        }

        $result = self::updateUsdRate(null, 'exchange_api_before_send');
        if (!($result['success'] ?? false)) {
            throw new \RuntimeException('Cotacao USD/BRL indisponivel. O envio/cobranca nao deve continuar sem confirmar a taxa atual.');
        }

        return $result;
    }

    public static function diagnoseExchangeApis(): array
    {
        $diagnostics = [];

        foreach (self::exchangeApiUrls() as $url) {
            $diagnostics[] = self::diagnoseExchangeUrl($url);
        }

        return [
            'php' => PHP_VERSION,
            'curl_loaded' => extension_loaded('curl'),
            'force_ipv4' => self::forceIpv4Exchange(),
            'proxy' => self::exchangeProxy() !== '' ? self::maskProxy(self::exchangeProxy()) : null,
            'curl_binary_fallback' => self::curlBinaryFallbackEnabled(),
            'curl_binary' => self::curlBinaryCommand(),
            'ca_info' => self::exchangeCaInfo() ?: ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: null,
            'urls' => $diagnostics,
        ];
    }

    public static function countryCodeFromPhone(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone) ?: '';
        if (str_starts_with($phone, '55')) {
            return 'BR';
        }

        return self::normalizeCountryCode((string)TelephonyConfig::env('WHATSAPP_DEFAULT_COUNTRY_CODE', self::DEFAULT_COUNTRY));
    }

    private static function normalizeMessageType(string $messageType): string
    {
        $messageType = strtolower(trim($messageType));
        return match ($messageType) {
            'marketing' => 'marketing',
            'utility', 'utilidade' => 'utility',
            'authentication', 'auth', 'autenticacao', 'autenticação' => 'authentication',
            'voice', 'voz' => 'voice',
            'service' => 'service',
            default => 'marketing',
        };
    }

    private static function normalizeCountryCode(string $countryCode): string
    {
        $countryCode = strtoupper(preg_replace('/[^A-Z]/i', '', $countryCode) ?: '');
        return $countryCode !== '' ? substr($countryCode, 0, 2) : self::DEFAULT_COUNTRY;
    }

    private static function freePrice(string $countryCode, ?int $customerId): array
    {
        return [
            'message_type' => 'service',
            'country_code' => self::normalizeCountryCode($countryCode),
            'customer_id' => $customerId,
            'cost_usd' => 0.0,
            'exchange_rate' => 0.0,
            'safety_margin_percent' => 0.0,
            'effective_rate' => 0.0,
            'cost_brl' => 0.0,
            'margin_percent' => 0.0,
            'base_margin_percent' => 0.0,
            'extra_margin_percent' => 0.0,
            'final_price_brl' => 0.0,
            'profit_brl' => 0.0,
            'exchange_alert' => false,
            'pricing_source' => 'service_window',
            'exchange_updated_at' => null,
            'cache' => 'none',
        ];
    }

    private static function activeMetaPrice(string $messageType, string $countryCode): array
    {
        $row = (new Database('whatsapp_meta_message_prices'))
            ->select(
                'country_code = :country_code AND message_type = :message_type AND active = 1 AND valid_from <= CURDATE()',
                [
                    ':country_code' => $countryCode,
                    ':message_type' => $messageType,
                ],
                'valid_from DESC, id DESC',
                '1'
            )
            ->fetch(\PDO::FETCH_ASSOC);

        if ($row) {
            $row['source'] = 'database';
            return $row;
        }

        return [
            'country_code' => $countryCode,
            'message_type' => $messageType,
            'price_usd' => self::DEFAULT_USD_PRICES[$messageType] ?? self::DEFAULT_USD_PRICES['marketing'],
            'source' => 'fallback',
        ];
    }

    private static function currentExchangeRate(bool $cache = true): array
    {
        $cacheKey = 'whatsapp:exchange:usd';
        if ($cache) {
            $cached = self::cacheGet($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        self::seedDefaults();
        $row = self::storedExchangeRate();
        if (!$row) {
            throw new \RuntimeException('Nenhuma cotacao USD/BRL armazenada. Atualize a cotacao antes de calcular precos.');
        }

        if ($cache) {
            self::cacheSet($cacheKey, $row, self::cacheTtl());
        }

        return $row;
    }

    private static function marginPercent(string $messageType, ?int $customerId): float
    {
        $params = [
            ':message_type' => $messageType,
        ];
        $where = 'message_type = :message_type AND active = 1 AND customer_id = 0';
        if ($customerId !== null && $customerId > 0) {
            $where = 'message_type = :message_type AND active = 1 AND customer_id IN (:customer_id, 0)';
            $params[':customer_id'] = $customerId;
        }

        $row = (new Database('whatsapp_pricing_margins'))
            ->select($where, $params, 'customer_id DESC, id DESC', '1')
            ->fetch(\PDO::FETCH_ASSOC);

        return $row ? (float)$row['margin_percent'] : (self::DEFAULT_MARGINS[$messageType] ?? 85.0);
    }

    private static function fetchUsdBrlRate(): float
    {
        $urls = self::exchangeApiUrls();
        $lastError = null;

        foreach ($urls as $url) {
            $rate = self::fetchUsdBrlRateFromUrl($url, $lastError);
            if ($rate > 0) {
                return $rate;
            }
        }

        if (self::curlBinaryFallbackEnabled()) {
            foreach ($urls as $url) {
                $rate = self::fetchUsdBrlRateFromCurlBinary($url, $lastError);
                if ($rate > 0) {
                    return $rate;
                }
            }
        }

        if ($lastError !== null) {
            error_log('[whatsapp_exchange_update] ' . $lastError);
        }

        return 0.0;
    }

    private static function fetchUsdBrlRateFromUrl(string $url, ?string &$lastError = null): float
    {
        try {
            $client = new Client([
                'timeout' => 10,
                'connect_timeout' => 12,
                'http_errors' => false,
                'force_ip_resolve' => self::forceIpv4Exchange() ? 'v4' : null,
                'proxy' => self::exchangeProxy() !== '' ? self::exchangeProxy() : null,
                'verify' => self::exchangeCaInfo() ?: true,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'MaxxWhatsAppPricing/1.0',
                ],
            ]);
            $response = $client->get($url);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                $lastError = sprintf(
                    'API de câmbio retornou HTTP %s em %s: %s',
                    $response->getStatusCode(),
                    $url,
                    mb_substr((string)$response->getBody(), 0, 300)
                );
                return 0.0;
            }

            $data = json_decode((string)$response->getBody(), true);
            if (!is_array($data)) {
                return 0.0;
            }

            foreach (['USDBRL.bid', 'rates.BRL', 'conversion_rates.BRL', 'value.0.cotacaoVenda', 'brl', 'rate', 'bid'] as $path) {
                $value = self::valueByPath($data, $path);
                if (is_numeric($value) && (float)$value > 0) {
                    return (float)$value;
                }
            }
            $lastError = 'API de câmbio sem campo de cotação reconhecido em ' . $url;
        } catch (\Throwable $e) {
            $lastError = $url . ': ' . $e->getMessage();
        }

        return 0.0;
    }

    private static function exchangeApiUrls(): array
    {
        $primary = trim((string)TelephonyConfig::env('WHATSAPP_EXCHANGE_API_URL', 'https://economia.awesomeapi.com.br/json/last/USD-BRL'));
        $fallbacks = trim((string)TelephonyConfig::env(
            'WHATSAPP_EXCHANGE_FALLBACK_API_URLS',
            'https://api.frankfurter.app/latest?from=USD&to=BRL,https://open.er-api.com/v6/latest/USD'
        ));

        $urls = array_merge(
            [$primary],
            preg_split('/[\r\n,;]+/', $fallbacks) ?: [],
            self::includeBcbPtaxFallback() ? self::bcbPtaxUrls() : []
        );

        return array_values(array_unique(array_filter(array_map('trim', $urls))));
    }

    private static function bcbPtaxUrls(): array
    {
        $urls = [];
        for ($daysAgo = 0; $daysAgo < 7; $daysAgo++) {
            $date = date('m-d-Y', strtotime('-' . $daysAgo . ' days'));
            $urls[] = "https://olinda.bcb.gov.br/olinda/servico/PTAX/versao/v1/odata/CotacaoMoedaAberturaOuIntermediario(moeda=@moeda,dataCotacao=@dataCotacao)?@moeda='USD'&@dataCotacao='{$date}'&\$top=1&\$format=json";
        }

        return $urls;
    }

    private static function includeBcbPtaxFallback(): bool
    {
        $value = strtolower((string)TelephonyConfig::env('WHATSAPP_EXCHANGE_INCLUDE_BCB', 'false'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private static function fetchUsdBrlRateFromCurlBinary(string $url, ?string &$lastError = null): float
    {
        if (!function_exists('shell_exec')) {
            $lastError = 'Fallback curl.exe indisponivel: shell_exec desabilitado.';
            return 0.0;
        }

        $command = self::curlBinaryCommand()
            . ' -4 -L -sS --max-time 20'
            . ' -H ' . escapeshellarg('Accept: application/json')
            . ' -H ' . escapeshellarg('User-Agent: MaxxWhatsAppPricing/1.0')
            . ' ' . escapeshellarg($url)
            . ' 2>&1';

        $output = shell_exec($command);
        if (!is_string($output) || trim($output) === '') {
            $lastError = 'Fallback curl.exe nao retornou saida para ' . $url;
            return 0.0;
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            $lastError = 'Fallback curl.exe nao retornou JSON valido em ' . $url . ': ' . mb_substr(trim($output), 0, 300);
            return 0.0;
        }

        $rate = self::extractRateFromPayload($decoded);
        if ($rate <= 0) {
            $lastError = 'Fallback curl.exe sem campo de cotacao reconhecido em ' . $url;
        }

        return $rate;
    }

    private static function forceIpv4Exchange(): bool
    {
        $value = strtolower((string)TelephonyConfig::env('WHATSAPP_EXCHANGE_FORCE_IPV4', 'true'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private static function curlBinaryFallbackEnabled(): bool
    {
        $value = strtolower((string)TelephonyConfig::env('WHATSAPP_EXCHANGE_USE_CURL_BINARY', 'true'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private static function curlBinaryCommand(): string
    {
        $binary = trim((string)TelephonyConfig::env('WHATSAPP_EXCHANGE_CURL_BINARY', 'curl.exe'));
        return $binary !== '' ? escapeshellcmd($binary) : 'curl.exe';
    }

    private static function exchangeProxy(): string
    {
        return trim((string)(
            TelephonyConfig::env('WHATSAPP_EXCHANGE_PROXY', '')
            ?: getenv('HTTPS_PROXY')
            ?: getenv('HTTP_PROXY')
            ?: ''
        ));
    }

    private static function exchangeCaInfo(): string
    {
        $path = trim((string)TelephonyConfig::env('WHATSAPP_EXCHANGE_CAINFO', ''));
        return $path !== '' && is_file($path) ? $path : '';
    }

    private static function diagnoseExchangeUrl(string $url): array
    {
        $base = [
            'url' => $url,
            'host' => parse_url($url, PHP_URL_HOST),
        ];

        if (!extension_loaded('curl')) {
            return $base + [
                'ok' => false,
                'error' => 'Extensão PHP cURL não carregada.',
            ];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: MaxxWhatsAppPricing/1.0',
            ],
        ]);

        if (self::forceIpv4Exchange() && defined('CURL_IPRESOLVE_V4')) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }

        $proxy = self::exchangeProxy();
        if ($proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
        $caInfo = self::exchangeCaInfo();
        if ($caInfo !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $caInfo);
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        $headerSize = (int)($info['header_size'] ?? 0);
        $headers = is_string($raw) ? substr($raw, 0, $headerSize) : '';
        $body = is_string($raw) ? substr($raw, $headerSize) : '';
        $decoded = json_decode($body, true);
        $rate = is_array($decoded) ? self::extractRateFromPayload($decoded) : 0.0;
        $binaryRate = null;
        $binaryError = null;
        if ($rate <= 0 && self::curlBinaryFallbackEnabled()) {
            $binaryRate = self::fetchUsdBrlRateFromCurlBinary($url, $binaryError);
        }

        return $base + [
            'ok' => (
                $errno === 0
                && (int)($info['http_code'] ?? 0) >= 200
                && (int)($info['http_code'] ?? 0) < 300
                && $rate > 0
            ) || ($binaryRate && $binaryRate > 0),
            'curl_errno' => $errno,
            'curl_error' => $error,
            'http_code' => (int)($info['http_code'] ?? 0),
            'primary_ip' => $info['primary_ip'] ?? null,
            'local_ip' => $info['local_ip'] ?? null,
            'namelookup_time' => $info['namelookup_time'] ?? null,
            'connect_time' => $info['connect_time'] ?? null,
            'total_time' => $info['total_time'] ?? null,
            'rate_detected' => $rate > 0 ? $rate : null,
            'curl_binary_rate_detected' => $binaryRate && $binaryRate > 0 ? $binaryRate : null,
            'curl_binary_error' => $binaryError,
            'headers' => self::compactHeaders($headers),
            'body_sample' => mb_substr($body, 0, 500),
        ];
    }

    private static function extractRateFromPayload(array $data): float
    {
        foreach (['USDBRL.bid', 'rates.BRL', 'conversion_rates.BRL', 'value.0.cotacaoVenda', 'brl', 'rate', 'bid'] as $path) {
            $value = self::valueByPath($data, $path);
            if (is_numeric($value) && (float)$value > 0) {
                return (float)$value;
            }
        }

        return 0.0;
    }

    private static function compactHeaders(string $headers): array
    {
        $lines = preg_split('/\r?\n/', trim($headers)) ?: [];
        return array_values(array_filter(array_slice($lines, -12)));
    }

    private static function maskProxy(string $proxy): string
    {
        return preg_replace('#//([^:@/]+):([^@/]+)@#', '//***:***@', $proxy) ?? 'configured';
    }

    private static function valueByPath(array $data, string $path): mixed
    {
        $current = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }

    private static function effectiveRate(float $rate, float $safetyMarginPercent): float
    {
        return round($rate * (1 + ($safetyMarginPercent / 100)), 6);
    }

    private static function automaticExtraMargin(array $exchange): float
    {
        $dailyChange = (float)($exchange['daily_change_percent'] ?? 0);
        $threshold = self::extraMarginThresholdPercent();
        if ($dailyChange <= $threshold) {
            return 0.0;
        }

        return (float)TelephonyConfig::env('WHATSAPP_EXCHANGE_EXTRA_MARGIN_PERCENT', 5.0);
    }

    private static function commercialRound(float $value): float
    {
        $step = max(0.0001, (float)TelephonyConfig::env('WHATSAPP_PRICE_ROUND_STEP_BRL', 0.01));
        $minimum = max(0.0, (float)TelephonyConfig::env('WHATSAPP_MIN_PRICE_BRL', 0.05));
        $rounded = ceil(max($value, $minimum) / $step) * $step;

        return round($rounded, 4);
    }

    private static function applyPriceFloor(string $countryCode, string $messageType, ?int $customerId, float $finalPrice, float $dailyChangePercent): float
    {
        if (!self::priceFloorEnabled()) {
            return $finalPrice;
        }

        $customerScope = (int)($customerId ?? 0);
        $holdHours = max(1, (int)TelephonyConfig::env('WHATSAPP_PRICE_FLOOR_HOURS', 24));

        try {
            $row = (new Database('whatsapp_pricing_price_floors'))
                ->select(
                    'country_code = :country_code AND message_type = :message_type AND customer_id = :customer_id',
                    [
                        ':country_code' => $countryCode,
                        ':message_type' => $messageType,
                        ':customer_id' => $customerScope,
                    ],
                    '',
                    '1'
                )
                ->fetch(\PDO::FETCH_ASSOC);

            $floor = $row && (!($row['locked_until'] ?? null) || strtotime((string)$row['locked_until']) >= time())
                ? (float)$row['final_price_brl']
                : 0.0;

            if ($finalPrice > $floor) {
                (new Database())->execute(
                    "INSERT INTO whatsapp_pricing_price_floors
                        (country_code, message_type, customer_id, final_price_brl, locked_until, updated_at)
                     VALUES (:country_code, :message_type, :customer_id, :final_price, DATE_ADD(NOW(), INTERVAL {$holdHours} HOUR), NOW())
                     ON DUPLICATE KEY UPDATE
                        final_price_brl = VALUES(final_price_brl),
                        locked_until = VALUES(locked_until),
                        updated_at = NOW()",
                    [
                        ':country_code' => $countryCode,
                        ':message_type' => $messageType,
                        ':customer_id' => $customerScope,
                        ':final_price' => $finalPrice,
                    ]
                );
                return $finalPrice;
            }

            if ($dailyChangePercent < 0 && $floor > $finalPrice) {
                return round($floor, 4);
            }
        } catch (\Throwable $e) {
            error_log('[whatsapp_price_floor] ' . $e->getMessage());
        }

        return $finalPrice;
    }

    private static function priceFloorEnabled(): bool
    {
        $value = strtolower((string)TelephonyConfig::env('WHATSAPP_PRICE_FLOOR_ON_RATE_DROP', 'true'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private static function seedDefaults(): void
    {
        static $seeded = false;
        if ($seeded) {
            return;
        }

        self::ensureVoicePricingSchema();

        foreach (self::DEFAULT_USD_PRICES as $messageType => $priceUsd) {
            (new Database())->execute(
                "INSERT INTO whatsapp_meta_message_prices
                    (country_code, message_type, price_usd, valid_from, active, created_at, updated_at)
                 VALUES ('BR', :message_type, :price_usd, CURDATE(), 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE price_usd = price_usd",
                [
                    ':message_type' => $messageType,
                    ':price_usd' => $priceUsd,
                ]
            );
        }

        foreach (self::DEFAULT_MARGINS as $messageType => $margin) {
            (new Database())->execute(
                "INSERT INTO whatsapp_pricing_margins
                    (customer_id, message_type, margin_percent, active, created_at, updated_at)
                 VALUES (0, :message_type, :margin_percent, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE margin_percent = margin_percent",
                [
                    ':message_type' => $messageType,
                    ':margin_percent' => $margin,
                ]
            );
        }

        $seeded = true;
    }

    private static function storedExchangeRate(): ?array
    {
        $row = (new Database('currency_exchange_rates'))
            ->select('currency = :currency', [':currency' => self::DEFAULT_CURRENCY], 'updated_at DESC, id DESC', '1')
            ->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function syncMxxPlanWhatsappColumns(array $prices): void
    {
        $marketing = round((float)($prices['marketing']['final_price_brl'] ?? 0), 4);
        $utility = round((float)($prices['utility']['final_price_brl'] ?? 0), 4);
        $authentication = round((float)($prices['authentication']['final_price_brl'] ?? 0), 4);
        $voice = round((float)($prices['voice']['final_price_brl'] ?? 0), 4);

        if ($marketing <= 0 || $utility <= 0 || $authentication <= 0) {
            return;
        }

        $columns = self::mxxPlanColumns();
        $fields = [
            'value_whatsapp' => $utility,
        ];

        if (isset($columns['value_whatsapp_marketing'])) {
            $fields['value_whatsapp_marketing'] = $marketing;
        }

        if (isset($columns['value_whatsapp_utility'])) {
            $fields['value_whatsapp_utility'] = $utility;
        }

        if (isset($columns['value_whatsapp_authentication'])) {
            $fields['value_whatsapp_authentication'] = $authentication;
        }

        if ($voice > 0 && isset($columns['whatsapp_voice_price_per_minute'])) {
            $fields['whatsapp_voice_price_per_minute'] = $voice;
            if (isset($columns['whatsapp_voice_enabled'])) {
                $fields['whatsapp_voice_enabled'] = 1;
            }
        }

        if (!$fields) {
            return;
        }

        $set = [];
        $params = [];
        foreach ($fields as $field => $value) {
            $placeholder = ':' . $field;
            $set[] = "{$field} = {$placeholder}";
            $params[$placeholder] = $value;
        }

        if (isset($columns['updated_at'])) {
            $set[] = 'updated_at = NOW()';
        }

        (new Database())->execute(
            'UPDATE mxx_plans SET ' . implode(', ', $set) . " WHERE status = 'active'",
            $params
        );
    }

    private static function ensureVoicePricingSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $schemaUpdates = [
            'whatsapp_meta_message_prices' => 'ALTER TABLE whatsapp_meta_message_prices MODIFY COLUMN message_type VARCHAR(32) NOT NULL',
            'whatsapp_pricing_margins' => 'ALTER TABLE whatsapp_pricing_margins MODIFY COLUMN message_type VARCHAR(32) NOT NULL',
            'whatsapp_pricing_price_floors' => 'ALTER TABLE whatsapp_pricing_price_floors MODIFY COLUMN message_type VARCHAR(32) NOT NULL',
            'plan_whatsapp_pricing' => 'ALTER TABLE plan_whatsapp_pricing MODIFY COLUMN category VARCHAR(32) NOT NULL',
        ];

        foreach ($schemaUpdates as $table => $sql) {
            try {
                $columnName = $table === 'plan_whatsapp_pricing' ? 'category' : 'message_type';
                $type = (string)((new Database())->execute(
                    "SELECT COLUMN_TYPE
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = :table
                       AND COLUMN_NAME = :column
                     LIMIT 1",
                    [
                        ':table' => $table,
                        ':column' => $columnName,
                    ]
                )->fetchColumn() ?: '');

                if (stripos($type, 'enum(') === 0) {
                    (new Database())->execute($sql);
                }
            } catch (\Throwable $e) {
                error_log('[whatsapp_voice_pricing_schema] ' . $table . ' ' . $e->getMessage());
            }
        }

        try {
            (new Database())->execute(
                "UPDATE whatsapp_pricing_margins
                 SET message_type = 'voice', updated_at = NOW()
                 WHERE message_type = ''
                   AND customer_id = 0
                   AND margin_percent = 80.0000"
            );
        } catch (\Throwable $e) {
            error_log('[whatsapp_voice_margin_repair] ' . $e->getMessage());
        }
    }

    private static function mxxPlanColumns(): array
    {
        static $columns = null;
        if ($columns === null) {
            try {
                $rows = (new Database())->execute('SHOW COLUMNS FROM mxx_plans')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                $columns = array_fill_keys(array_map(static fn (array $row): string => (string)$row['Field'], $rows), true);
            } catch (\Throwable $e) {
                $columns = [];
            }
        }

        return $columns;
    }

    private static function exchangeSafetyMarginPercent(): float
    {
        return (float)TelephonyConfig::env('WHATSAPP_EXCHANGE_SAFETY_MARGIN_PERCENT', 5.0);
    }

    private static function exchangeAlertThresholdPercent(): float
    {
        return (float)TelephonyConfig::env('WHATSAPP_EXCHANGE_ALERT_THRESHOLD_PERCENT', 2.0);
    }

    private static function extraMarginThresholdPercent(): float
    {
        return (float)TelephonyConfig::env('WHATSAPP_EXCHANGE_EXTRA_MARGIN_THRESHOLD_PERCENT', 2.0);
    }

    private static function cacheTtl(): int
    {
        return max(60, min(900, (int)TelephonyConfig::env('WHATSAPP_PRICING_CACHE_TTL_SECONDS', self::CACHE_TTL_SECONDS)));
    }

    private static function cacheGet(string $key): ?array
    {
        try {
            $raw = RedisConn::app()->get($key);
            $decoded = $raw ? json_decode((string)$raw, true) : null;
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function cacheSet(string $key, array $value, int $ttl): void
    {
        try {
            RedisConn::app()->setex($key, $ttl, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
        }
    }

    private static function cacheDelete(string $key): void
    {
        try {
            RedisConn::app()->del([$key]);
        } catch (\Throwable $e) {
        }
    }
}
