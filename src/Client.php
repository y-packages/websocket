<?php

namespace YakNet\WebSocket;

use YakNet\WebSocket\Exception\WebSocketException;
use YakNet\WebSocket\Frame\Frame;
use YakNet\WebSocket\Frame\FrameProcessor;

class Client
{
    private string $url;
    /** @var array<string, mixed> */
    private array $sslOptions;
    /** @var resource|null */
    private $stream = null;
    private string $buffer = '';
    private bool $connected = false;

    /**
     * @param string $url The WebSocket URL (e.g., ws://localhost:8080/chat or wss://...)
     * @param array<string, mixed> $sslOptions Optional SSL configurations for secure connections (wss://)
     */
    public function __construct(string $url, array $sslOptions = [])
    {
        $this->url = $url;
        $this->sslOptions = $sslOptions;
    }

    /**
     * Connects to the remote WebSocket server and performs the RFC 6455 handshake.
     *
     * @throws WebSocketException
     */
    public function connect(): void
    {
        $parsed = parse_url($this->url);
        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            throw new WebSocketException("Invalid WebSocket URL: {$this->url}");
        }

        $scheme = strtolower($parsed['scheme']);
        if ($scheme !== 'ws' && $scheme !== 'wss') {
            throw new WebSocketException("Unsupported scheme: {$scheme}. Only ws:// and wss:// are supported.");
        }

        $ssl = ($scheme === 'wss');
        $host = $parsed['host'];
        $port = $parsed['port'] ?? ($ssl ? 443 : 80);
        $path = $parsed['path'] ?? '/';
        if (isset($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }

        $protocol = $ssl ? 'ssl' : 'tcp';
        $remoteAddress = "{$protocol}://{$host}:{$port}";

        $contextOptions = [];
        if ($ssl) {
            $contextOptions['ssl'] = $this->sslOptions;
        }
        $context = stream_context_create($contextOptions);

        $errNo = 0;
        $errStr = '';
        // 5-second connection timeout
        $stream = @stream_socket_client(
            $remoteAddress,
            $errNo,
            $errStr,
            5.0,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($stream === false) {
            throw new WebSocketException("Could not connect to {$remoteAddress}: {$errStr} ({$errNo})");
        }
        $this->stream = $stream;

        // Generate client security nonce
        $nonce = base64_encode(random_bytes(16));

        // Format WebSocket upgrade request
        $requestHeaders = [
            "GET {$path} HTTP/1.1",
            "Host: {$host}:{$port}",
            "Upgrade: websocket",
            "Connection: Upgrade",
            "Sec-WebSocket-Key: {$nonce}",
            "Sec-WebSocket-Version: 13",
            "User-Agent: YakNetWebSocketClient/1.0",
        ];

        $request = implode("\r\n", $requestHeaders) . "\r\n\r\n";
        @fwrite($this->stream, $request);

        // Read HTTP response until headers end (\r\n\r\n)
        $response = '';
        while (strpos($response, "\r\n\r\n") === false) {
            $chunk = @fread($this->stream, 1024);
            if ($chunk === false || strlen($chunk) === 0 || feof($this->stream)) {
                throw new WebSocketException('Handshake failed: Server disconnected prematurely.');
            }
            $response .= $chunk;
        }

        // Split response headers and body
        $parts = explode("\r\n\r\n", $response, 2);
        $headerSection = $parts[0];
        $this->buffer = $parts[1] ?? '';

        // Validate response code
        $lines = explode("\r\n", $headerSection);
        $statusLine = array_shift($lines);
        if (!preg_match('/^HTTP\/1\.[01]\s+101\s+/i', $statusLine)) {
            throw new WebSocketException("Handshake failed: Server did not return 101 Switching Protocols. Response: {$statusLine}");
        }

        // Parse headers
        $headers = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $headerParts = explode(':', $line, 2);
            if (count($headerParts) === 2) {
                $headers[strtolower(trim($headerParts[0]))] = trim($headerParts[1]);
            }
        }

        // Verify accept signature
        if (!isset($headers['sec-websocket-accept'])) {
            throw new WebSocketException('Handshake failed: Server response did not include "Sec-WebSocket-Accept" header.');
        }

        $expectedAccept = base64_encode(sha1($nonce . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if ($headers['sec-websocket-accept'] !== $expectedAccept) {
            throw new WebSocketException('Handshake failed: Server returned an invalid "Sec-WebSocket-Accept" signature.');
        }

        // Set to non-blocking for frame operations
        stream_set_blocking($this->stream, false);
        $this->connected = true;
    }

    /**
     * Sends a text message to the server.
     * Clients MUST mask all outgoing frames.
     *
     * @param string $message
     * @return bool
     */
    public function send(string $message): bool
    {
        return $this->sendFrame(new Frame(Frame::OPCODE_TEXT, $message));
    }

    /**
     * Sends binary data to the server.
     *
     * @param string $data
     * @return bool
     */
    public function sendBinary(string $data): bool
    {
        return $this->sendFrame(new Frame(Frame::OPCODE_BINARY, $data));
    }

    /**
     * Encodes and writes a Frame to the stream.
     *
     * @param Frame $frame
     * @return bool
     */
    public function sendFrame(Frame $frame): bool
    {
        if (!$this->connected || !is_resource($this->stream)) {
            return false;
        }

        // Client frames MUST be masked
        $rawBytes = FrameProcessor::encode($frame, true);
        $length = strlen($rawBytes);
        $written = @fwrite($this->stream, $rawBytes);

        return $written === $length;
    }

    /**
     * Receives the next available Frame from the server.
     *
     * @param float $timeout The maximum time to wait in seconds (0 for non-blocking poll)
     * @return Frame|null The decoded Frame, or null if no frame is available/timeout reached
     * @throws WebSocketException
     */
    public function receive(float $timeout = 0): ?Frame
    {
        if (!$this->connected || !is_resource($this->stream)) {
            return null;
        }

        // Try decoding a frame from already buffered data first
        $frame = FrameProcessor::decodeFromBuffer($this->buffer);
        if ($frame !== null) {
            return $frame;
        }

        $startTime = microtime(true);

        do {
            $read = [$this->stream];
            $write = [];
            $except = [];

            $timeoutSec = (int)$timeout;
            $timeoutUsec = (int)(($timeout - $timeoutSec) * 1000000);

            $selected = @stream_select($read, $write, $except, $timeoutSec, $timeoutUsec);

            if ($selected === false) {
                return null;
            }

            if ($selected > 0 && in_array($this->stream, $read, true)) {
                $data = @fread($this->stream, 8192);

                if ($data === false || strlen($data) === 0 || feof($this->stream)) {
                    $this->disconnect();
                    throw new WebSocketException('Connection closed by remote peer.');
                }

                $this->buffer .= $data;

                $frame = FrameProcessor::decodeFromBuffer($this->buffer);
                if ($frame !== null) {
                    return $frame;
                }
            }

            $elapsed = microtime(true) - $startTime;
        } while ($elapsed < $timeout);

        return null;
    }

    /**
     * Closes the connection cleanly.
     *
     * @param int $code
     * @param string $reason
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        if (!$this->connected || !is_resource($this->stream)) {
            return;
        }

        // Send masked close frame
        $payload = pack('n', $code) . $reason;
        $frame = new Frame(Frame::OPCODE_CLOSE, $payload);
        $this->sendFrame($frame);

        // Wait briefly for echo close response or timeout
        try {
            $this->receive(1.0);
        } catch (\Throwable $e) {
            // Ignore socket disconnects during close handshake
        }

        $this->disconnect();
    }

    private function disconnect(): void
    {
        $this->connected = false;
        if (is_resource($this->stream)) {
            @stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
            @fclose($this->stream);
        }
        $this->stream = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
