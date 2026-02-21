<?php

require __DIR__ . '/bootstrap/app.php';

use App\Service\VoiceWorker;

ini_set('output_buffering', 'off');
ini_set('implicit_flush', 1);
ob_implicit_flush(true);

echo "Initializing Voice Worker...\n";

$worker = new VoiceWorker();

try {
    $worker->run(1);
} catch (Exception $e) {

}