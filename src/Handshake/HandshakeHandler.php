<?php

namespace YakNet\WebSocket\Handshake;

use YakNet\WebSocket\Exception\WebSocketException;

class HandshakeHandler
{
    private const WEBSOCKET_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * Checks if the buffer contains a complete HTTP header (ends with \r\n\r\n or \n\n).
     *
     * @param string $buffer
     * @return bool
     */
    public static function isCompleteRequest(string $buffer): bool
    {
        return strpos($buffer, "\r\n\r\n") !== false || strpos($buffer, "\n\n") !== false;
    }

    /**
     * Parses the HTTP Handshake request and generates the HTTP 101 response string.
     * Returns null if the request is incomplete.
     *
     * @param string $buffer The raw request buffer (passed by reference, will consume the headers if complete)
     * @param array<string, mixed> $headers Reference to output parsed headers
     * @return string The raw HTTP 101 response bytes
     * @throws WebSocketException If the handshake request is invalid or malformed
     */
    public static function handle(string &$buffer, array &$headers): string
    {
        $endPos = strpos($buffer, "\r\n\r\n");
        $delimiter = "\r\n\r\n";
        
        if ($endPos === false) {
            $endPos = strpos($buffer, "\n\n");
            $delimiter = "\n\n";
        }

        if ($endPos === false) {
            throw new WebSocketException('Incomplete HTTP Handshake request.');
        }

        $rawHeaders = substr($buffer, 0, $endPos);
        // Consume headers from the buffer
        $buffer = substr($buffer, $endPos + strlen($delimiter));

        $lines = explode("\r\n", str_replace("\n", "\r\n", str_replace("\r\n", "\n", $rawHeaders)));
        $requestLine = array_shift($lines);

        // Parse Request Line: "GET /chat HTTP/1.1"
        if (!preg_match('/^GET\s+(.+)\s+HTTP\/1\.[01]$/i', $requestLine, $matches)) {
            throw new WebSocketException('Invalid HTTP request line: Must be GET request.');
        }
        $requestUri = $matches[1];
        $headers['Request-URI'] = $requestUri;

        $uriParts = parse_url($requestUri);
        $headers['request_path'] = $uriParts['path'] ?? '/';
        
        $queryParams = [];
        if (isset($uriParts['query'])) {
            parse_str($uriParts['query'], $queryParams);
        }
        $headers['query_params'] = $queryParams;

        // Parse HTTP Headers
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $key = strtolower(trim($parts[0]));
                $headers[$key] = trim($parts[1]);
            }
        }

        // Validate WebSocket Upgrade headers according to RFC 6455
        if (!isset($headers['upgrade']) || strtolower($headers['upgrade']) !== 'websocket') {
            throw new WebSocketException('Missing or invalid "Upgrade" header.');
        }

        if (!isset($headers['connection']) || strpos(strtolower($headers['connection']), 'upgrade') === false) {
            throw new WebSocketException('Missing or invalid "Connection" header.');
        }

        if (!isset($headers['sec-websocket-key'])) {
            throw new WebSocketException('Missing "Sec-WebSocket-Key" header.');
        }

        if (!isset($headers['sec-websocket-version']) || $headers['sec-websocket-version'] !== '13') {
            throw new WebSocketException('Unsupported WebSocket version: Only version 13 is supported.');
        }

        // Calculate WebSocket Accept Key
        $clientKey = $headers['sec-websocket-key'];
        $acceptKey = base64_encode(sha1($clientKey . self::WEBSOCKET_GUID, true));

        // Format Response
        $responseHeaders = [
            'HTTP/1.1 101 Switching Protocols',
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Accept: ' . $acceptKey,
        ];

        // Echo protocol if requested
        if (isset($headers['sec-websocket-protocol'])) {
            // Echo first requested subprotocol as simple default behavior
            $protocols = explode(',', $headers['sec-websocket-protocol']);
            $responseHeaders[] = 'Sec-WebSocket-Protocol: ' . trim($protocols[0]);
        }

        return implode("\r\n", $responseHeaders) . "\r\n\r\n";
    }
}
