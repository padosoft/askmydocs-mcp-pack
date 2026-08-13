<?php

namespace Padosoft\AskMyDocsMcpPack\Fluent;

use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\ResourceTemplateDefinition;

final class ResourceTemplate
{
    private ?string $name = null;

    private ?string $description = null;

    private string $mimeType = 'text/plain';

    private mixed $handler = null;

    /** @var array<string,mixed> */
    private array $annotations = [];

    /** @var array<string,mixed> */
    private array $meta = [];

    private function __construct(private readonly string $uriTemplate)
    {
        if (! str_contains($uriTemplate, '{')) {
            throw new \InvalidArgumentException('A resource template must contain at least one {variable}.');
        }
    }

    public static function make(string $uriTemplate): self
    {
        return new self($uriTemplate);
    }

    public function name(string $value): self
    {
        $this->name = $value;

        return $this;
    }

    public function description(string $value): self
    {
        $this->description = $value;

        return $this;
    }

    public function mimeType(string $value): self
    {
        $this->mimeType = $value;

        return $this;
    }

    /** @param array<string,mixed> $value */
    public function annotations(array $value): self
    {
        $this->annotations = $value;

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

    public function compile(): ResourceTemplateDefinition
    {
        if ($this->handler === null) {
            throw new \LogicException("Resource template [{$this->uriTemplate}] has no handler.");
        }

        return new ResourceTemplateDefinition($this->uriTemplate, $this->name ?? $this->uriTemplate, $this->description, $this->mimeType, $this->handler, $this->annotations, $this->meta);
    }
}
