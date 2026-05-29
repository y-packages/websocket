<?php

namespace YakNet\WebSocket\Tests;

use YakNet\WebSocket\Connection;
use YakNet\WebSocket\Contract\ConnectionHandlerInterface;

class EchoHandler implements ConnectionHandlerInterface
{
    public array $events = [];

    public function onOpen(Connection $connection): void
    {
        $this->events[] = ['event' => 'open', 'id' => $connection->getId()];
    }

    public function onMessage(Connection $connection, string $message): void
    {
        $this->events[] = ['event' => 'message', 'id' => $connection->getId(), 'data' => $message];
        // Echo back the message
        $connection->send("Echo: {$message}");
    }

    public function onClose(Connection $connection, int $code, string $reason): void
    {
        $this->events[] = ['event' => 'close', 'id' => $connection->getId(), 'code' => $code, 'reason' => $reason];
    }

    public function onError(Connection $connection, \Throwable $exception): void
    {
        $this->events[] = ['event' => 'error', 'id' => $connection->getId(), 'message' => $exception->getMessage()];
    }
}
