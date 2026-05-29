<?php

namespace YakNet\WebSocket\Frame;

class Frame
{
    public const OPCODE_CONTINUATION = 0x0;
    public const OPCODE_TEXT = 0x1;
    public const OPCODE_BINARY = 0x2;
    public const OPCODE_CLOSE = 0x8;
    public const OPCODE_PING = 0x9;
    public const OPCODE_PONG = 0xA;

    private int $opcode;
    private string $payload;
    private bool $fin;
    private int $rsv1;
    private int $rsv2;
    private int $rsv3;

    public function __construct(
        int $opcode,
        string $payload = '',
        bool $fin = true,
        int $rsv1 = 0,
        int $rsv2 = 0,
        int $rsv3 = 0
    ) {
        $this->opcode = $opcode;
        $this->payload = $payload;
        $this->fin = $fin;
        $this->rsv1 = $rsv1;
        $this->rsv2 = $rsv2;
        $this->rsv3 = $rsv3;
    }

    public function getOpcode(): int
    {
        return $this->opcode;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function isFin(): bool
    {
        return $this->fin;
    }

    public function getRsv1(): int
    {
        return $this->rsv1;
    }

    public function getRsv2(): int
    {
        return $this->rsv2;
    }

    public function getRsv3(): int
    {
        return $this->rsv3;
    }

    public function isControlFrame(): bool
    {
        return $this->opcode >= 0x8;
    }
}
