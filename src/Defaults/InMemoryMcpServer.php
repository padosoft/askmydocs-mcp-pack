<?php

namespace Padosoft\AskMyDocsMcpPack\Defaults;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;

/**
 * Plain value object implementing {@see McpServerContract}. Useful in
 * tests and quick-starts that don't want to back the registry with an
 * Eloquent model.
 */
final class InMemoryMcpServer implements McpServerContract
{
    /**
     * @param array<string,mixed> $transportConfig
     * @param array<int,string>   $allowedTools
     */
    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly string $transport,
        private readonly ?string $tenantId,
        private readonly array $transportConfig,
        private readonly array $allowedTools = [],
        private readonly bool $enabled = true,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function transport(): string
    {
        return $this->transport;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function transportConfig(): array
    {
        return $this->transportConfig;
    }

    public function allowedTools(): array
    {
        return $this->allowedTools;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
