<?php
require __DIR__ . '/vendor/autoload.php';

use React\EventLoop\Factory;
use React\Http\HttpServer;
use React\Http\Message\Response;
use Predis\Client as RedisClient;

$loop = Factory::create();
$redis = new RedisClient(['scheme'=>'tcp','host'=>'127.0.0.1','port'=>6379,'password'=>'mxx123']);

$server = new HttpServer(function ($request) use ($redis, $loop) {
    if ($request->getUri()->getPath() !== '/sse') {
        return Response::plaintext("Use /sse\n");
    }

    return new Response(
        200,
        [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'Access-Control-Allow-Origin' => '*'
        ],
        new React\Stream\ThroughStream(function ($stream) use ($redis, $loop) {
            $lastHash = '';

            $loop->addPeriodicTimer(1, function () use ($redis, $stream, &$lastHash) {
                $data = $redis->get('asterisk:active_calls');
                $hash = md5($data ?: '');

                if ($hash !== $lastHash) {
                    $lastHash = $hash;
                    $stream->write("event: calls\n");
                    $stream->write("data: {$data}\n\n");
                    echo "[SSE] Enviado update para clientes.\n";
                } else {
                    $stream->write(": ping\n\n");
                }
            });
        })
    );
});

$socket = new React\Socket\SocketServer('0.0.0.0:8081');
$server->listen($socket);
echo "🔥 SSE ativo em http://localhost:8081/sse\n";
$loop->run();
