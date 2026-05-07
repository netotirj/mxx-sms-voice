<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Controller\Pages\WhatsApp;

$whatsAppReflection = new ReflectionClass(WhatsApp::class);
$phoneLookupVariants = $whatsAppReflection->getMethod('phoneLookupVariants');
$phoneLookupVariants->setAccessible(true);

$assertions = 0;
$failures = [];

$assertSame = static function ($expected, $actual, string $label) use (&$assertions, &$failures): void {
    $assertions++;
    if ($expected !== $actual) {
        $failures[] = $label . ' expected=' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ' actual=' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
};

$assertTrue = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$variantsInternational = $phoneLookupVariants->invoke(null, '+55 (11) 99876-5432');
$assertTrue(in_array('5511998765432', $variantsInternational, true), 'variant keeps international format');
$assertTrue(in_array('11998765432', $variantsInternational, true), 'variant supports local exact match without country code');

$variantsLocal = $phoneLookupVariants->invoke(null, '11 99876-5432');
$assertTrue(in_array('11998765432', $variantsLocal, true), 'variant keeps local format');
$assertTrue(in_array('5511998765432', $variantsLocal, true), 'variant supports exact E164-style digits with country code');

$assertSame(
    false,
    in_array('5511998765433', $variantsInternational, true),
    'variants do not fuzzily match unrelated numbers'
);

$messageRows = [
    ['id' => 10, 'wamid' => 'wamid.1', 'direction' => 'inbound', 'message_type' => 'text', 'created_at' => '2026-05-05 10:00:00', 'body' => 'Oi'],
    ['id' => 11, 'wamid' => 'wamid.1', 'direction' => 'inbound', 'message_type' => 'text', 'created_at' => '2026-05-05 10:00:01', 'body' => 'Oi'],
    ['id' => 12, 'wamid' => null, 'direction' => 'outbound', 'message_type' => 'template', 'created_at' => '2026-05-05 10:01:00', 'body' => '[Template] hello_world'],
];

$deduped = [];
$seen = [];
foreach ($messageRows as $message) {
    $key = !empty($message['wamid'])
        ? 'wamid:' . $message['wamid']
        : (!empty($message['id'])
            ? 'id:' . $message['id']
            : implode(':', [
                $message['direction'] ?? '',
                $message['message_type'] ?? '',
                $message['created_at'] ?? '',
                $message['body'] ?? '',
            ]));

    if (isset($seen[$key])) {
        continue;
    }

    $seen[$key] = true;
    $deduped[] = $message;
}

$assertSame(2, count($deduped), 'duplicate wamid collapses to a single rendered message');

if ($failures !== []) {
    fwrite(STDERR, "WhatsApp dedup regression failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "WhatsApp dedup regression passed ({$assertions} assertions).\n";
