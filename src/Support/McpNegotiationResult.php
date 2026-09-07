<?php

namespace Padosoft\AskMyDocsMcpPack\Support;

final readonly class McpNegotiationResult
{
    /**
     * @param  array<string,mixed>  $capabilities
     * @param  array<string,mixed>  $serverInfo
     * @param  array<string,mixed>  $raw
     */
    public function __construct(
        public McpProtocolEra $era,
        public string $protocolVersion,
        public array $capabilities = [],
        public array $serverInfo = [],
        public array $raw = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'era' => $this->era->value,
            'protocolVersion' => $this->protocolVersion,
            'capabilities' => $this->capabilities,
            'serverInfo' => $this->serverInfo,
        ];
    }
}
