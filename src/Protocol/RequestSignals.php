<?php

namespace Padosoft\AskMyDocsMcpPack\Protocol;

use Closure;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\CancellationRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\SubscriptionBrokerContract;

final readonly class RequestSignals
{
    public function __construct(
        private SubscriptionBrokerContract $subscriptions,
        private CancellationRegistryContract $cancellations,
        private ?string $tenantId,
        private ?string $principalId,
        private string $requestId,
        private ?Closure $streamEmitter = null,
        private ?Closure $heartbeat = null,
    ) {}

    public function progress(float|int $current, float|int|null $total = null, ?string $message = null): void
    {
        $this->heartbeat();
        $payload = ['progressToken' => $this->requestId, 'progress' => $current];
        if ($total !== null) {
            $payload['total'] = $total;
        }
        if ($message !== null) {
            $payload['message'] = $message;
        }
        if ($this->streamEmitter !== null) {
            ($this->streamEmitter)('notifications/progress', $payload);
        }
        $this->subscriptions->publish($this->tenantId, 'notifications/progress', $payload, $this->principalId);
    }

    public function isCancelled(): bool
    {
        $this->heartbeat();

        return $this->cancellations->isCancelled($this->tenantId, $this->requestId, $this->principalId);
    }

    private function heartbeat(): void
    {
        if ($this->heartbeat !== null) {
            ($this->heartbeat)();
        }
    }
}
