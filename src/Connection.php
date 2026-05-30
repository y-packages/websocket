<?php

namespace YakNet\WebSocket;

use YakNet\WebSocket\Frame\Frame;
use YakNet\WebSocket\Frame\FrameProcessor;

class Connection
{
    private string $id;
    private $stream;
    private bool $handshaked = false;
    private array $headers = [];
    private string $remoteAddress = '';
    private string $path = '/';
    private array $queryParams = [];

    /**
     * @param resource $stream The PHP socket stream resource
     */
    public function __construct($stream)
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Connection must be initialized with a valid stream resource.');
        }

        $this->stream = $stream;
        $this->id = uniqid('conn_', true);
        
        // Retrieve remote peer name
        $peerName = stream_socket_get_name($stream, true);
        if ($peerName) {
            $this->remoteAddress = $peerName;
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return resource
     */
    public function getStream()
    {
        return $this->stream;
    }

    public function isHandshaked(): bool
    {
        return $this->handshaked;
    }

    public function setHandshaked(bool $handshaked): void
    {
        $this->handshaked = $handshaked;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
        $this->path = $headers['request_path'] ?? '/';
        $this->queryParams = $headers['query_params'] ?? [];
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function getQueryParam(string $name, $default = null): mixed
    {
        return $this->queryParams[$name] ?? $default;
    }

    public function getRemoteAddress(): string
    {
        return $this->remoteAddress;
    }

    /**
     * Sends a text message to the client.
     *
     * @param string $message
     * @return bool True if writing to stream succeeded
     */
    public function send(string $message): bool
    {
        $frame = new Frame(Frame::OPCODE_TEXT, $message);
        return $this->sendFrame($frame);
    }

    /**
     * Sends binary data to the client.
     *
     * @param string $data
     * @return bool True if writing to stream succeeded
     */
    public function sendBinary(string $data): bool
    {
        $frame = new Frame(Frame::OPCODE_BINARY, $data);
        return $this->sendFrame($frame);
    }

    /**
     * Sends a ping frame to the client.
     *
     * @param string $payload
     * @return bool
     */
    public function ping(string $payload = ''): bool
    {
        $frame = new Frame(Frame::OPCODE_PING, $payload);
        return $this->sendFrame($frame);
    }

    /**
     * Sends a pong frame to the client.
     *
     * @param string $payload
     * @return bool
     */
    public function pong(string $payload = ''): bool
    {
        $frame = new Frame(Frame::OPCODE_PONG, $payload);
        return $this->sendFrame($frame);
    }

    /**
     * Closes the connection cleanly by sending a close frame and shutting down the stream.
     *
     * @param int $code WebSocket close status code (RFC 6455 section 7.4)
     * @param string $reason
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        if (!is_resource($this->stream)) {
            return;
        }

        // Pack the status code and reason into the payload
        // Status code is a 16-bit unsigned integer (big-endian)
        $payload = pack('n', $code) . $reason;
        $frame = new Frame(Frame::OPCODE_CLOSE, $payload);
        
        try {
            $this->sendFrame($frame);
        } catch (\Throwable $e) {
            // Ignore write errors during close
        }

        $this->shutdown();
    }

    /**
     * Forcefully shuts down the stream.
     */
    public function shutdown(): void
    {
        if (is_resource($this->stream)) {
            @stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
            @fclose($this->stream);
        }
    }

    /**
     * Encodes and writes a WebSocket frame to the stream.
     *
     * @param Frame $frame
     * @return bool
     */
    public function sendFrame(Frame $frame): bool
    {
        if (!is_resource($this->stream)) {
            return false;
        }

        // Server-to-client frames MUST NOT be masked
        $rawBytes = FrameProcessor::encode($frame, false);
        $length = strlen($rawBytes);
        $written = @fwrite($this->stream, $rawBytes);

        return $written === $length;
    }
}
