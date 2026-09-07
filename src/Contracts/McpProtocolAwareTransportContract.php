<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts;

use Padosoft\AskMyDocsMcpPack\Support\McpProtocolEra;

interface McpProtocolAwareTransportContract extends McpTransportContract
{
    public function useProtocol(McpProtocolEra $era, string $version): void;

    public function protocolEra(): ?McpProtocolEra;

    public function protocolVersion(): ?string;

    public function sessionId(): ?string;

    public function clearSession(): void;

    public function lastStatusCode(): ?int;

    /** @return array<string,list<string>> */
    public function lastResponseHeaders(): array;
}
