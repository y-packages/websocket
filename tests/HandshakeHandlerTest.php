<?php

namespace YakNet\WebSocket\Tests;

use PHPUnit\Framework\TestCase;
use YakNet\WebSocket\Handshake\HandshakeHandler;
use YakNet\WebSocket\Exception\WebSocketException;

class HandshakeHandlerTest extends TestCase
{
    public function testIsCompleteRequest(): void
    {
        $incomplete = "GET /chat HTTP/1.1\r\nHost: localhost\r\n";
        $complete = "GET /chat HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $this->assertFalse(HandshakeHandler::isCompleteRequest($incomplete));
        $this->assertTrue(HandshakeHandler::isCompleteRequest($complete));
    }

    public function testSuccessfulHandshake(): void
    {
        // Example from RFC 6455
        $key = 'dGhlIHNhbXBsZSBub25jZQ==';
        $request = "GET /chat HTTP/1.1\r\n" .
                   "Host: server.example.com\r\n" .
                   "Upgrade: websocket\r\n" .
                   "Connection: Upgrade\r\n" .
                   "Sec-WebSocket-Key: {$key}\r\n" .
                   "Origin: http://example.com\r\n" .
                   "Sec-WebSocket-Version: 13\r\n\r\n";

        $buffer = $request;
        $headers = [];
        $response = HandshakeHandler::handle($buffer, $headers);

        // The remaining buffer should be empty since headers are fully consumed
        $this->assertSame('', $buffer);

        // Check parsed headers
        $this->assertSame('/chat', $headers['Request-URI']);
        $this->assertSame('websocket', $headers['upgrade']);
        $this->assertSame('Upgrade', $headers['connection']);
        $this->assertSame('13', $headers['sec-websocket-version']);
        $this->assertSame($key, $headers['sec-websocket-key']);

        // Check generated response
        $this->assertStringContainsString('HTTP/1.1 101 Switching Protocols', $response);
        $this->assertStringContainsString('Upgrade: websocket', $response);
        $this->assertStringContainsString('Connection: Upgrade', $response);
        
        // Calculated accept signature for this key per RFC 6455: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=
        $this->assertStringContainsString('Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $response);
    }

    public function testInvalidRequestMethodThrows(): void
    {
        $request = "POST /chat HTTP/1.1\r\n" .
                   "Upgrade: websocket\r\n" .
                   "Connection: Upgrade\r\n" .
                   "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n" .
                   "Sec-WebSocket-Version: 13\r\n\r\n";

        $buffer = $request;
        $headers = [];

        $this->expectException(WebSocketException::class);
        $this->expectExceptionMessage('Invalid HTTP request line: Must be GET request.');
        HandshakeHandler::handle($buffer, $headers);
    }

    public function testMissingUpgradeHeaderThrows(): void
    {
        $request = "GET /chat HTTP/1.1\r\n" .
                   "Connection: Upgrade\r\n" .
                   "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n" .
                   "Sec-WebSocket-Version: 13\r\n\r\n";

        $buffer = $request;
        $headers = [];

        $this->expectException(WebSocketException::class);
        $this->expectExceptionMessage('Missing or invalid "Upgrade" header.');
        HandshakeHandler::handle($buffer, $headers);
    }

    public function testInvalidVersionThrows(): void
    {
        $request = "GET /chat HTTP/1.1\r\n" .
                   "Upgrade: websocket\r\n" .
                   "Connection: Upgrade\r\n" .
                   "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n" .
                   "Sec-WebSocket-Version: 8\r\n\r\n";

        $buffer = $request;
        $headers = [];

        $this->expectException(WebSocketException::class);
        $this->expectExceptionMessage('Unsupported WebSocket version: Only version 13 is supported.');
        HandshakeHandler::handle($buffer, $headers);
    }
}
