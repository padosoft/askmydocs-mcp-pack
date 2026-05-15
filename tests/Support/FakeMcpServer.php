<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Support;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;

/**
 * Minimal {@see McpServerContract} stub for admin-route feature tests.
 */
final class FakeMcpServer implements McpServerContract
{
    /** @param array<int,string> $allowedTools */
    public function __construct(
        private readonly string $id,
        private readonly string $name = 'Fake',
        private readonly string $transport = 'http',
        private readonly ?string $tenantId = null,
        private readonly array $allowedTools = [],
        private readonly bool $enabled = true,
    ) {}

    public function id(): string { return $this->id; }
    public function name(): string { return $this->name; }
    public function transport(): string { return $this->transport; }
    public function tenantId(): ?string { return $this->tenantId; }

    /** @return array<string,mixed> */
    public function transportConfig(): array { return ['endpoint' => 'http://stub']; }

    /** @return array<int,string> */
    public function allowedTools(): array { return $this->allowedTools; }

    public function isEnabled(): bool { return $this->enabled; }
}
