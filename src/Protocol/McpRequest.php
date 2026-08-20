<?php

namespace Padosoft\AskMyDocsMcpPack\Protocol;

final readonly class McpRequest
{
    /**
     * @param  array<string,mixed>  $arguments
     * @param  array<string,mixed>  $clientInfo
     * @param  array<string,mixed>  $clientCapabilities
     * @param  array<string,mixed>  $meta
     * @param  array<string,mixed>  $inputResponses
     */
    public function __construct(
        public string $method,
        public array $arguments,
        public ?string $tenantId,
        public mixed $actor,
        public ?string $principalId,
        public array $clientInfo,
        public array $clientCapabilities,
        public array $meta,
        public array $inputResponses = [],
        public ?string $requestState = null,
        public ?string $correlationId = null,
        public ?string $traceparent = null,
        public ?string $tracestate = null,
        public ?string $baggage = null,
        public ?RequestSignals $signals = null,
    ) {}

    public function argument(string $key, mixed $default = null): mixed
    {
        return data_get($this->arguments, $key, $default);
    }

    public function supports(string $capability): bool
    {
        return array_key_exists($capability, $this->clientCapabilities)
            || data_get($this->clientCapabilities, $capability) !== null
            || array_key_exists($capability, (array) ($this->clientCapabilities['extensions'] ?? []));
    }

    public function actorId(): ?string
    {
        $id = is_object($this->actor) && method_exists($this->actor, 'getAuthIdentifier')
            ? $this->actor->getAuthIdentifier()
            : data_get($this->actor, 'id', $this->actor); // scalar actors (e.g. 'alice') are kept, mirroring ToolInvoker

        return is_scalar($id) ? (string) $id : $this->principalId;
    }

    public function progress(float|int $current, float|int|null $total = null, ?string $message = null): void
    {
        $this->signals?->progress($current, $total, $message);
    }

    public function isCancelled(): bool
    {
        return $this->signals?->isCancelled() ?? false;
    }
}
