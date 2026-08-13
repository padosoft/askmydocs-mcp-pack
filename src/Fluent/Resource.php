<?php

namespace Padosoft\AskMyDocsMcpPack\Fluent;

use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\ResourceDefinition;

final class Resource
{
    private ?string $name = null;

    private ?string $description = null;

    private string $mimeType = 'text/plain';

    private mixed $handler = null;

    /** @var array<string,mixed> */
    private array $annotations = [];

    /** @var array<string,mixed> */
    private array $meta = [];

    private function __construct(private readonly string $uri)
    {
        if (filter_var($uri, FILTER_VALIDATE_URL) === false && ! preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $uri)) {
            throw new \InvalidArgumentException('Resource URI must be an absolute URI.');
        }
    }

    public static function make(string $uri): self
    {
        return new self($uri);
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

    public function compile(): ResourceDefinition
    {
        if ($this->handler === null) {
            throw new \LogicException("Resource [{$this->uri}] has no handler.");
        }

        return new ResourceDefinition($this->uri, $this->name ?? $this->uri, $this->description, $this->mimeType, $this->handler, $this->annotations, $this->meta);
    }
}
