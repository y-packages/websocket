<?php

require_once __DIR__ . '/../vendor/autoload.php';

use YakNet\WebSocket\Server;
use YakNet\WebSocket\Tests\EchoHandler;

$port = 18080;
$address = '127.0.0.1';

$handler = new EchoHandler();
$server = new Server($address, $port, $handler);

echo "Server starting on {$address}:{$port}...\n";
flush();

try {
    $server->start();
} catch (\Throwable $e) {
    fwrite(STDERR, "Server error: " . $e->getMessage() . "\n");
    exit(1);
}
