<?php

require_once __DIR__ . '/../vendor/autoload.php';

use YakNet\WebSocket\Server;
use YakNet\WebSocket\Tests\EchoHandler;

$port = 18081;
$address = '127.0.0.1';

$handler = new EchoHandler();
// Start server with 1s heartbeat interval and 1s timeout
$server = new Server($address, $port, $handler, [], 1, 1);

echo "Heartbeat Server starting on {$address}:{$port}...\n";
flush();

try {
    $server->start();
} catch (\Throwable $e) {
    fwrite(STDERR, "Server error: " . $e->getMessage() . "\n");
    exit(1);
}
