<?php

require_once __DIR__ . '/../vendor/autoload.php';

use YakNet\WebSocket\Connection;
use YakNet\WebSocket\Contract\ConnectionHandlerInterface;
use YakNet\WebSocket\Server;

class LoggingEchoHandler implements ConnectionHandlerInterface
{
    private function log(string $message, string $color = '37'): void
    {
        $time = date('Y-m-d H:i:s');
        echo "\033[{$color}m[{$time}] {$message}\033[0m\n";
    }

    public function onOpen(Connection $connection): void
    {
        $this->log("✔ Connection OPENED: {$connection->getId()} from {$connection->getRemoteAddress()}", '32');
        $this->log("  Headers: " . json_encode($connection->getHeaders(), JSON_PRETTY_PRINT), '36');
        
        // Welcome the client
        $connection->send("Welcome to YakNet WebSocket Server! Connection ID: " . $connection->getId());
    }

    public function onMessage(Connection $connection, string $message): void
    {
        $this->log("✉ Received message from {$connection->getId()}: \"{$message}\"", '35');
        
        // Echo the message back
        $response = "Echo: " . $message;
        $connection->send($response);
        $this->log("✈ Sent echo response to {$connection->getId()}", '34');
    }

    public function onClose(Connection $connection, int $code, string $reason): void
    {
        $this->log("✖ Connection CLOSED: {$connection->getId()} (Code: {$code}, Reason: \"{$reason}\")", '31');
    }

    public function onError(Connection $connection, \Throwable $exception): void
    {
        $this->log("⚠ Connection ERROR on {$connection->getId()}: " . $exception->getMessage(), '33');
    }
}

$port = 8090;
$address = '0.0.0.0';

$server = new Server($address, $port, new LoggingEchoHandler());

echo "\033[1;36m==================================================\033[0m\n";
echo "\033[1;36m         YAKNET WEBSOCKET DEMO ECHO SERVER        \033[0m\n";
echo "\033[1;36m==================================================\033[0m\n";
echo "Server binding to \033[1;33m{$address}:{$port}\033[0m...\n";
echo "Press Ctrl+C to stop.\n\n";

try {
    $server->start();
} catch (\Throwable $e) {
    echo "\033[1;31mServer Error: " . $e->getMessage() . "\033[0m\n";
    exit(1);
}
