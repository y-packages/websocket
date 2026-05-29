<?php

namespace YakNet\WebSocket\Contract;

use YakNet\WebSocket\Connection;

interface ConnectionHandlerInterface
{
    /**
     * Triggered when a new WebSocket connection is successfully established.
     *
     * @param Connection $connection
     */
    public function onOpen(Connection $connection): void;

    /**
     * Triggered when a message is received from the client.
     *
     * @param Connection $connection
     * @param string $message
     */
    public function onMessage(Connection $connection, string $message): void;

    /**
     * Triggered when a connection is closed.
     *
     * @param Connection $connection
     * @param int $code The WebSocket close status code (e.g. 1000 for normal closure)
     * @param string $reason The reason given by the closing party
     */
    public function onClose(Connection $connection, int $code, string $reason): void;

    /**
     * Triggered when an error or exception occurs on a connection.
     *
     * @param Connection $connection
     * @param \Throwable $exception
     */
    public function onError(Connection $connection, \Throwable $exception): void;
}
