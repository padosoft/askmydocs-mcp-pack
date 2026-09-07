<?php

namespace Padosoft\AskMyDocsMcpPack\Fluent;

use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\PromptDefinition;

final class Prompt
{
    private ?string $description = null;

    /** @var list<array<string,mixed>> */
    private array $arguments = [];

    /** @var array<string,mixed> */
    private array $meta = [];

    private mixed $handler = null;

    private function __construct(private readonly string $name) {}

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function description(string $value): self
    {
        $this->description = $value;

        return $this;
    }

    /** @param list<array<string,mixed>> $value */
    public function arguments(array $value): self
    {
        $this->arguments = $value;

        return $this;
    }

    /** @param array<string,mixed> $value */
    public function meta(array $value): self
    {
        $this->meta = $value;

        return $this;
    }

    public function handle(callable|string $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    public function compile(): PromptDefinition
    {
        if ($this->handler === null) {
            throw new \LogicException("Prompt [{$this->name}] has no handler.");
        }

        return new PromptDefinition($this->name, $this->description, $this->arguments, $this->handler, $this->meta);
    }
}
