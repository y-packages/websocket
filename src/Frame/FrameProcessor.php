<?php

namespace YakNet\WebSocket\Frame;

use YakNet\WebSocket\Exception\WebSocketException;

class FrameProcessor
{
    /**
     * Encodes a Frame object into a raw binary string ready to be sent over the socket.
     *
     * @param Frame $frame
     * @param bool $masked Whether to mask the frame (required for client-to-server frames, forbidden for server-to-client)
     * @return string Raw bytes
     */
    public static function encode(Frame $frame, bool $masked = false): string
    {
        $payload = $frame->getPayload();
        $payloadLength = strlen($payload);

        // Byte 1: FIN (1 bit) + RSV1-3 (3 bits) + Opcode (4 bits)
        $b1 = ($frame->isFin() ? 0x80 : 0x00) |
              ($frame->getRsv1() ? 0x40 : 0x00) |
              ($frame->getRsv2() ? 0x20 : 0x00) |
              ($frame->getRsv3() ? 0x10 : 0x00) |
              $frame->getOpcode();

        $header = pack('C', $b1);

        // Byte 2: Mask (1 bit) + Payload Length (7 bits)
        $maskBit = $masked ? 0x80 : 0x00;

        if ($payloadLength <= 125) {
            $header .= pack('C', $maskBit | $payloadLength);
        } elseif ($payloadLength <= 65535) {
            $header .= pack('C', $maskBit | 126);
            $header .= pack('n', $payloadLength); // 16-bit unsigned length
        } else {
            $header .= pack('C', $maskBit | 127);
            // 64-bit unsigned length (big-endian)
            $header .= pack('N', ($payloadLength >> 32) & 0xFFFFFFFF);
            $header .= pack('N', $payloadLength & 0xFFFFFFFF);
        }

        if ($masked) {
            // Generate a random 4-byte mask
            $maskKey = random_bytes(4);
            $header .= $maskKey;

            // Apply XOR masking
            $maskedPayload = '';
            for ($i = 0; $i < $payloadLength; $i++) {
                $maskedPayload .= $payload[$i] ^ $maskKey[$i % 4];
            }
            $payload = $maskedPayload;
        }

        return $header . $payload;
    }

    /**
     * Attempts to decode a Frame from the given binary buffer.
     * If a full frame is decoded, it is removed from the buffer and returned.
     * If the buffer does not contain a full frame, null is returned and the buffer is left untouched.
     *
     * @param string $buffer Reference to the read buffer string
     * @return Frame|null The decoded Frame, or null if incomplete
     * @throws WebSocketException If protocol errors are detected
     */
    public static function decodeFromBuffer(string &$buffer): ?Frame
    {
        $bufferLength = strlen($buffer);
        if ($bufferLength < 2) {
            return null; // Need at least 2 bytes for the base header
        }

        $b1 = ord($buffer[0]);
        $b2 = ord($buffer[1]);

        $fin = ($b1 & 0x80) !== 0;
        $rsv1 = ($b1 & 0x40) !== 0 ? 1 : 0;
        $rsv2 = ($b1 & 0x20) !== 0 ? 1 : 0;
        $rsv3 = ($b1 & 0x10) !== 0 ? 1 : 0;
        $opcode = $b1 & 0x0F;

        $masked = ($b2 & 0x80) !== 0;
        $payloadLen = $b2 & 0x7F;

        // Protocol Validation
        // RSVs must be 0 unless extension is negotiated
        if ($rsv1 || $rsv2 || $rsv3) {
            throw new WebSocketException('Protocol error: RSV bits must be 0.');
        }

        // Control frames must not be fragmented and must have payload <= 125 bytes
        if ($opcode >= 0x8) {
            if (!$fin) {
                throw new WebSocketException('Protocol error: Control frames must not be fragmented.');
            }
            if ($payloadLen > 125) {
                throw new WebSocketException('Protocol error: Control frame payload must be 125 bytes or less.');
            }
        }

        $headerLength = 2;

        if ($payloadLen === 126) {
            if ($bufferLength < 4) {
                return null; // Need 2 more bytes for length
            }
            $payloadLen = unpack('n', substr($buffer, 2, 2))[1];
            $headerLength += 2;
        } elseif ($payloadLen === 127) {
            if ($bufferLength < 10) {
                return null; // Need 8 more bytes for length
            }
            $parts = unpack('Nhigh/Nlow', substr($buffer, 2, 8));
            $payloadLen = ($parts['high'] << 32) | $parts['low'];
            
            // Check for negative length or extreme sizes on 32-bit platforms
            if ($payloadLen < 0) {
                throw new WebSocketException('Protocol error: Unsupported 64-bit payload length (too large).');
            }
            $headerLength += 8;
        }

        $maskKey = '';
        if ($masked) {
            if ($bufferLength < $headerLength + 4) {
                return null; // Need 4 bytes for masking key
            }
            $maskKey = substr($buffer, $headerLength, 4);
            $headerLength += 4;
        }

        // Check if we have the full payload in the buffer
        if ($bufferLength < $headerLength + $payloadLen) {
            return null; // Full payload not yet received
        }

        $rawPayload = substr($buffer, $headerLength, $payloadLen);
        $payload = '';

        if ($masked) {
            // Apply unmasking
            for ($i = 0; $i < $payloadLen; $i++) {
                $payload .= $rawPayload[$i] ^ $maskKey[$i % 4];
            }
        } else {
            $payload = $rawPayload;
        }

        // Consume the parsed frame from the buffer
        $buffer = substr($buffer, $headerLength + $payloadLen);

        return new Frame($opcode, $payload, $fin, $rsv1, $rsv2, $rsv3);
    }
}
