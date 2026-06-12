<?php

namespace YakNet\WebSocket\Tests;

use PHPUnit\Framework\TestCase;
use YakNet\WebSocket\Client;
use YakNet\WebSocket\Frame\Frame;
use YakNet\WebSocket\Exception\WebSocketException;

class HeartbeatTest extends TestCase
{
    private $serverProcess = null;
    private array $serverPipes = [];

    protected function setUp(): void
    {
        $serverScript = __DIR__ . '/heartbeat_test_server.php';
        
        $phpBinary = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $cmd = sprintf('"%s" "%s"', $phpBinary, $serverScript);

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w']  // stderr
        ];

        $this->serverProcess = proc_open($cmd, $descriptors, $this->serverPipes);
        
        if (!is_resource($this->serverProcess)) {
            $this->fail('Failed to start heartbeat integration test server.');
        }

        // Wait 500ms for server spin-up
        usleep(500000);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            foreach ($this->serverPipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }
    }

    public function testHeartbeatPingSentAndClientTimeout(): void
    {
        $client = new Client('ws://127.0.0.1:18081');
        $client->connect();

        // Server has heartbeatInterval = 1s.
        // Wait up to 4.0 seconds to receive the Ping frame.
        $pingFrame = $client->receive(4.0);

        $this->assertNotNull($pingFrame, 'Server did not send heartbeat ping.');
        $this->assertSame(Frame::OPCODE_PING, $pingFrame->getOpcode());

        // Do not respond. The server should timeout our connection in heartbeatTimeout = 1s.
        // Wait 2.0 seconds and then attempt to receive or verify we got disconnected.
        sleep(2);

        $this->expectException(WebSocketException::class);
        $client->receive(1.0);
    }

    public function testHeartbeatMaintainedOnPong(): void
    {
        $client = new Client('ws://127.0.0.1:18081');
        $client->connect();

        // 1. Wait for first Ping
        $pingFrame = $client->receive(4.0);
        $this->assertNotNull($pingFrame);
        $this->assertSame(Frame::OPCODE_PING, $pingFrame->getOpcode());

        // 2. Send Pong immediately
        $pongFrame = new Frame(Frame::OPCODE_PONG, $pingFrame->getPayload());
        $client->sendFrame($pongFrame);

        // 3. Connection should still be open! We can send a message.
        $client->send('Hello still alive');
        $reply = $client->receive(2.0);
        
        $this->assertNotNull($reply);
        $this->assertSame(Frame::OPCODE_TEXT, $reply->getOpcode());
        $this->assertSame('Echo: Hello still alive', $reply->getPayload());

        $client->close();
    }
}
