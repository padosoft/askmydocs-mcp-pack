<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Support;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Services\McpHandshakeService;

/**
 * Test-only handshake stub: returns a scripted catalog without
 * touching the real transport stack. The handshake controller test
 * suite rebinds {@see McpHandshakeService} to this class.
 */
final class StubHandshakeService extends McpHandshakeService
{
    /** @var array{capabilities:array<string,mixed>,tools:array<int,array<string,mixed>>} */
    public array $payload = [
        'capabilities' => ['tools' => []],
        'tools' => [],
    ];

    public ?string $throwMessage = null;

    public int $refreshCalls = 0;

    /** @var list<bool> */
    public array $forceCalls = [];

    public function __construct()
    {
        parent::__construct(ttlSeconds: 0);
    }

    public function refresh(McpServerContract $server, bool $force = false): array
    {
        $this->refreshCalls++;
        $this->forceCalls[] = $force;
        if ($this->throwMessage !== null) {
            throw new McpTransportException($this->throwMessage);
        }
        return $this->payload;
    }
}
