<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Support;

use Padosoft\AskMyDocsMcpPack\Contracts\McpToolContract;

/** Closure-driven {@see McpToolContract} for v1.2.0 server-side tests. */
final class AnonymousTool implements McpToolContract
{
    /** @param \Closure(array<string,mixed>):mixed $invoker */
    public function __construct(
        private readonly string $name,
        private readonly \Closure $invoker,
        private readonly string $description = '',
        private readonly array $schemaData = [],
        private readonly bool $idempotent = false,
        private readonly bool $readOnly = false,
    ) {}

    public function name(): string { return $this->name; }
    public function description(): string { return $this->description; }
    public function schema(): array { return $this->schemaData; }
    public function isIdempotent(): bool { return $this->idempotent; }
    public function isReadOnly(): bool { return $this->readOnly; }
    public function invoke(array $arguments): mixed { return ($this->invoker)($arguments); }
}
