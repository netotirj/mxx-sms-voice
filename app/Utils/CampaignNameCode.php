<?php

namespace App\Utils;

class CampaignNameCode
{
    public static function generate(string $baseName, ?callable $exists = null, string $fallback = 'campanha'): string
    {
        $normalized = preg_replace('/\s+/', '-', trim($baseName)) ?: $fallback;
        $normalized = preg_replace('/-+/', '-', $normalized) ?: $fallback;
        $normalized = trim($normalized, "-_ \t\n\r\0\x0B");

        if ($normalized === '') {
            $normalized = $fallback;
        }

        $normalized = mb_substr($normalized, 0, 90);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $suffix = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $candidate = $normalized . '-' . $suffix;

            if (!$exists || !$exists($candidate)) {
                return $candidate;
            }
        }

        return $normalized . '-' . substr((string) time(), -6);
    }
}
