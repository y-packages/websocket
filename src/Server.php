<?php

namespace YakNet\WebSocket;

use YakNet\WebSocket\Contract\ConnectionHandlerInterface;
use YakNet\WebSocket\Exception\WebSocketException;
use YakNet\WebSocket\Frame\Frame;
use YakNet\WebSocket\Frame\FrameProcessor;
use YakNet\WebSocket\Handshake\HandshakeHandler;

class Server
{
    private string $address;
    private int $port;
    private ConnectionHandlerInterface $handler;
    private array $sslOptions;
    private $serverSocket = null;
    private bool $running = false;

    /** @var array<string, Connection> Active connections mapped by resource ID */
    private array $connections = [];

    /** @var array<string, resource> Active client streams mapped by resource ID */
    private array $streams = [];

    /** @var array<string, string> Connection read buffers mapped by resource ID */
    private array $buffers = [];

    /**
     * @param string $address The IP address to bind to (e.g. '0.0.0.0' for all interfaces)
     * @param int $port The port to listen on
     * @param ConnectionHandlerInterface $handler The event-driven adapter handling connections
     * @param array $sslOptions Optional SSL options to enable wss:// (e.g. ['local_cert' => '...', 'local_pk' => '...'])
     */
    public function __construct(
        string $address,
        int $port,
        ConnectionHandlerInterface $handler,
        array $sslOptions = []
    ) {
        $this->address = $address;
        $this->port = $port;
        $this->handler = $handler;
        $this->sslOptions = $sslOptions;
    }

    /**
     * Starts the WebSocket server loop.
     * This is a blocking loop until stop() is called or the process is interrupted.
     *
     * @throws WebSocketException
     */
    public function start(): void
    {
        $contextOptions = [];
        $protocol = 'tcp';

        if (!empty($this->sslOptions)) {
            $contextOptions['ssl'] = $this->sslOptions;
            $protocol = 'ssl';
        }

        $context = stream_context_create($contextOptions);
        $serverAddress = "{$protocol}://{$this->address}:{$this->port}";

        $errNo = 0;
        $errStr = '';
        $this->serverSocket = @stream_socket_server(
            $serverAddress,
            $errNo,
            $errStr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if (!$this->serverSocket) {
            throw new WebSocketException("Could not start server on {$serverAddress}: {$errStr} ({$errNo})");
        }

        // Set server socket to non-blocking
        stream_set_blocking($this->serverSocket, false);

        $this->running = true;

        while ($this->running) {
            $read = array_merge([$this->serverSocket], $this->streams);
            $write = [];
            $except = [];

            // Block for up to 200ms checking for changes on sockets
            // Keeps CPU usage virtually zero when idle
            $selected = @stream_select($read, $write, $except, 0, 200000);

            if ($selected === false) {
                // If stream_select was interrupted (e.g. by system signal)
                continue;
            }

            if ($selected === 0) {
                // Timeout, tick the loop
                continue;
            }

            // Check for new client connection
            if (in_array($this->serverSocket, $read, true)) {
                $clientSocket = @stream_socket_accept($this->serverSocket);
                if ($clientSocket) {
                    stream_set_blocking($clientSocket, false);
                    
                    $connection = new Connection($clientSocket);
                    $streamId = (string)(int)$clientSocket;

                    $this->connections[$streamId] = $connection;
                    $this->streams[$streamId] = $clientSocket;
                    $this->buffers[$streamId] = '';
                }
                
                // Remove server socket from $read array so it's not processed as client stream
                $serverIndex = array_search($this->serverSocket, $read, true);
                if ($serverIndex !== false) {
                    unset($read[$serverIndex]);
                }
            }

            // Process existing client streams that have readable data
            foreach ($read as $clientSocket) {
                $streamId = (string)(int)$clientSocket;
                if (!isset($this->connections[$streamId])) {
                    continue;
                }

                $connection = $this->connections[$streamId];
                $data = @fread($clientSocket, 8192);

                if ($data === false || strlen($data) === 0 || feof($clientSocket)) {
                    // Client disconnected abruptly
                    $this->handleDisconnect($streamId, 1006, 'Abnormal closure (Connection lost)');
                    continue;
                }

                // Append received bytes to this connection's read buffer
                $this->buffers[$streamId] .= $data;

                // Process connection state
                if (!$connection->isHandshaked()) {
                    if (HandshakeHandler::isCompleteRequest($this->buffers[$streamId])) {
                        try {
                            $headers = [];
                            $response = HandshakeHandler::handle($this->buffers[$streamId], $headers);
                            
                            // Send HTTP 101 Handshake Response
                            @fwrite($clientSocket, $response);

                            $connection->setHeaders($headers);
                            $connection->setHandshaked(true);

                            // Trigger adapter callback
                            $this->handler->onOpen($connection);
                        } catch (\Throwable $e) {
                            // Handshake failed, send bad request response and drop connection
                            @fwrite($clientSocket, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
                            $this->handler->onError($connection, $e);
                            $this->handleDisconnect($streamId, 1002, 'Protocol error during handshake');
                        }
                    }
                }

                // Process frames if handshake is complete
                if ($connection->isHandshaked()) {
                    try {
                        while (($frame = FrameProcessor::decodeFromBuffer($this->buffers[$streamId])) !== null) {
                            $this->handleFrame($connection, $frame);
                        }
                    } catch (\Throwable $e) {
                        $this->handler->onError($connection, $e);
                        $connection->close(1002, 'Protocol error: ' . $e->getMessage());
                        $this->handleDisconnect($streamId, 1002, $e->getMessage());
                    }
                }
            }
        }

        $this->cleanup();
    }

    /**
     * Stops the WebSocket server loop.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    private function handleFrame(Connection $connection, Frame $frame): void
    {
        $streamId = $connection->getId();

        switch ($frame->getOpcode()) {
            case Frame::OPCODE_TEXT:
            case Frame::OPCODE_BINARY:
                $this->handler->onMessage($connection, $frame->getPayload());
                break;

            case Frame::OPCODE_PING:
                // Automatically respond with a Pong frame containing identical payload (RFC 6455 5.5.3)
                $connection->pong($frame->getPayload());
                break;

            case Frame::OPCODE_PONG:
                // Heartbeat response acknowledged, no specific action required
                break;

            case Frame::OPCODE_CLOSE:
                $code = 1000;
                $reason = 'Normal closure';
                $payload = $frame->getPayload();
                
                if (strlen($payload) >= 2) {
                    $parts = unpack('n', substr($payload, 0, 2));
                    $code = $parts[1];
                    $reason = substr($payload, 2);
                }

                // Trigger closure on adapter
                $this->handler->onClose($connection, $code, $reason);
                
                // Echo close back to the client to complete the close handshake and cleanup
                $connection->close($code, $reason);
                $this->handleDisconnect((string)(int)$connection->getStream(), $code, $reason);
                break;

            default:
                throw new WebSocketException("Unknown frame opcode: {$frame->getOpcode()}");
        }
    }

    private function handleDisconnect(string $streamId, int $code, string $reason): void
    {
        if (isset($this->connections[$streamId])) {
            $connection = $this->connections[$streamId];
            $connection->shutdown();

            unset($this->connections[$streamId]);
            unset($this->streams[$streamId]);
            unset($this->buffers[$streamId]);
        }
    }

    private function cleanup(): void
    {
        foreach (array_keys($this->connections) as $streamId) {
            $this->handleDisconnect($streamId, 1001, 'Server shutting down');
        }

        if (is_resource($this->serverSocket)) {
            @fclose($this->serverSocket);
        }

        $this->serverSocket = null;
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
