<?php

namespace YakNet\WebSocket\Tests;

use PHPUnit\Framework\TestCase;
use YakNet\WebSocket\Client;
use YakNet\WebSocket\Frame\Frame;

class ServerClientIntegrationTest extends TestCase
{
    private $serverProcess = null;
    private array $serverPipes = [];

    protected function setUp(): void
    {
        // Ephemeral process execution to run integration test server on port 18080
        $serverScript = __DIR__ . '/integration_test_server.php';
        
        // Build running command string
        // We use PHP_BINARY if available, fallback to 'php'
        $phpBinary = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $cmd = sprintf('"%s" "%s"', $phpBinary, $serverScript);

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w']  // stderr
        ];

        $this->serverProcess = proc_open($cmd, $descriptors, $this->serverPipes);
        
        if (!is_resource($this->serverProcess)) {
            $this->fail('Failed to start integration test server.');
        }

        // Wait up to 1 second for the server to spin up and bind
        usleep(500000); // 500ms
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            // Close all pipes
            foreach ($this->serverPipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            
            // Force terminate process
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }
    }

    public function testClientServerCommunication(): void
    {
        $client = new Client('ws://127.0.0.1:18080');
        
        // 1. Handshake & Connect
        $client->connect();
        
        // 2. Send text and verify echo
        $client->send('Hello YakNet');
        $frame = $client->receive(2.0); // Wait up to 2 seconds

        $this->assertNotNull($frame, 'Did not receive message response from server.');
        $this->assertSame(Frame::OPCODE_TEXT, $frame->getOpcode());
        $this->assertSame('Echo: Hello YakNet', $frame->getPayload());

        // 3. Send Ping and verify automatic Pong response
        $pingFrame = new Frame(Frame::OPCODE_PING, 'PingData');
        $client->sendFrame($pingFrame);
        
        $frame2 = $client->receive(2.0);
        $this->assertNotNull($frame2, 'Did not receive pong response from server.');
        $this->assertSame(Frame::OPCODE_PONG, $frame2->getOpcode());
        $this->assertSame('PingData', $frame2->getPayload());

        // 4. Clean Close Handshake
        $client->close(1000, 'All done!');
    }
}
