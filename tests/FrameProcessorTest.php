<?php

namespace YakNet\WebSocket\Tests;

use PHPUnit\Framework\TestCase;
use YakNet\WebSocket\Frame\Frame;
use YakNet\WebSocket\Frame\FrameProcessor;
use YakNet\WebSocket\Exception\WebSocketException;

class FrameProcessorTest extends TestCase
{
    public function testEncodeUnmaskedTextFrame(): void
    {
        $frame = new Frame(Frame::OPCODE_TEXT, 'Hello');
        $raw = FrameProcessor::encode($frame, false);

        // Byte 1: 0x81 (FIN=1, opcode=1)
        // Byte 2: 0x05 (MASK=0, length=5)
        // Payload: 'Hello'
        $expected = chr(0x81) . chr(0x05) . 'Hello';
        $this->assertSame($expected, $raw);
    }

    public function testDecodeMaskedTextFrame(): void
    {
        // Setup raw masked frame
        $maskKey = [0x01, 0x02, 0x03, 0x04];
        $payload = 'Hello';
        $maskedPayload = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $maskedPayload .= chr(ord($payload[$i]) ^ $maskKey[$i % 4]);
        }

        $rawFrame = chr(0x81) . chr(0x85) . // FIN=1 opcode=1, MASK=1 length=5
                    chr($maskKey[0]) . chr($maskKey[1]) . chr($maskKey[2]) . chr($maskKey[3]) .
                    $maskedPayload;

        $buffer = $rawFrame;
        $decoded = FrameProcessor::decodeFromBuffer($buffer);

        $this->assertNotNull($decoded);
        $this->assertSame('', $buffer); // Consumed successfully
        $this->assertSame(Frame::OPCODE_TEXT, $decoded->getOpcode());
        $this->assertSame('Hello', $decoded->getPayload());
        $this->assertTrue($decoded->isFin());
    }

    public function testDecodeIncompleteFrameReturnsNull(): void
    {
        $buffer = chr(0x81) . chr(0x05) . 'Hel'; // Incomplete payload
        $decoded = FrameProcessor::decodeFromBuffer($buffer);

        $this->assertNull($decoded);
        $this->assertSame(chr(0x81) . chr(0x05) . 'Hel', $buffer); // Buffer not consumed
    }

    public function testRoundtripMediumPayload(): void
    {
        // 500 bytes payload (uses 16-bit extended length = 126)
        $payload = str_repeat('A', 500);
        $frame = new Frame(Frame::OPCODE_BINARY, $payload);

        // Server encodes (unmasked)
        $encoded = FrameProcessor::encode($frame, false);
        
        // Client decodes (from buffer)
        $buffer = $encoded;
        $decoded = FrameProcessor::decodeFromBuffer($buffer);

        $this->assertNotNull($decoded);
        $this->assertSame(Frame::OPCODE_BINARY, $decoded->getOpcode());
        $this->assertSame(500, strlen($decoded->getPayload()));
        $this->assertSame($payload, $decoded->getPayload());
    }

    public function testRoundtripLargePayload(): void
    {
        // 70,000 bytes payload (uses 64-bit extended length = 127)
        $payload = str_repeat('B', 70000);
        $frame = new Frame(Frame::OPCODE_TEXT, $payload);

        $encoded = FrameProcessor::encode($frame, false);

        $buffer = $encoded;
        $decoded = FrameProcessor::decodeFromBuffer($buffer);

        $this->assertNotNull($decoded);
        $this->assertSame(Frame::OPCODE_TEXT, $decoded->getOpcode());
        $this->assertSame(70000, strlen($decoded->getPayload()));
        $this->assertSame($payload, $decoded->getPayload());
    }

    public function testRsvBitsValidationThrowsException(): void
    {
        // Frame with RSV1 set (0x40 in first byte)
        $rawFrame = chr(0x81 | 0x40) . chr(0x00);
        
        $buffer = $rawFrame;
        $this->expectException(WebSocketException::class);
        $this->expectExceptionMessage('Protocol error: RSV bits must be 0.');
        FrameProcessor::decodeFromBuffer($buffer);
    }
}
