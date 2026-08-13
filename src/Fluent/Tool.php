<?php

namespace Padosoft\AskMyDocsMcpPack\Fluent;

use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\ToolDefinition;

final class Tool
{
    private ?string $description = null;

    /** @var array<string,mixed> */
    private array $inputSchema = ['type' => 'object', 'properties' => []];

    /** @var array<string,mixed>|null */
    private ?array $outputSchema = null;

    /** @var array<string,mixed> */
    private array $annotations = [];

    /** @var array<string,mixed> */
    private array $meta = [];

    private mixed $handler = null;

    private bool $asynchronous = false;

    private function __construct(private readonly string $name)
    {
        if (! preg_match('/^[A-Za-z0-9._-]{1,128}$/', $name)) {
            throw new \InvalidArgumentException('Tool names may contain only letters, digits, dot, underscore and dash (max 128).');
        }
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function description(string $value): self
    {
        $this->description = $value;

        return $this;
    }

    /** @param array<string,mixed> $schema */
    public function inputSchema(array $schema): self
    {
        $this->inputSchema = $schema;

        return $this;
    }

    /** @param array<string,mixed> $schema */
    public function outputSchema(array $schema): self
    {
        $this->outputSchema = $schema;

        return $this;
    }

    public function readOnly(bool $value = true): self
    {
        $this->annotations['readOnlyHint'] = $value;

        return $this;
    }

    public function destructive(bool $value = true): self
    {
        $this->annotations['destructiveHint'] = $value;

        return $this;
    }

    public function idempotent(bool $value = true): self
    {
        $this->annotations['idempotentHint'] = $value;

        return $this;
    }

    public function openWorld(bool $value = true): self
    {
        $this->annotations['openWorldHint'] = $value;

        return $this;
    }

    /** @param array<string,mixed> $value */
    public function meta(array $value): self
    {
        $this->meta = array_replace_recursive($this->meta, $value);

        return $this;
    }

    /** @param 'model'|'app'|list<'model'|'app'> $visibility */
    public function app(string $resourceUri, string|array $visibility = ['model', 'app']): self
    {
        if (! str_starts_with($resourceUri, 'ui://')) {
            throw new \InvalidArgumentException('MCP App resource URIs must use ui://.');
        }
        $visibility = is_string($visibility) ? [$visibility] : array_values(array_unique($visibility));
        if ($visibility === [] || array_diff($visibility, ['model', 'app']) !== []) {
            throw new \InvalidArgumentException('App visibility may contain only model and app.');
        }
        $this->meta['ui'] = ['resourceUri' => $resourceUri, 'visibility' => $visibility];
        if ((bool) config('mcp-pack.apps.openai_compatibility', true)) {
            $this->meta['openai/outputTemplate'] = $resourceUri;
        }

        return $this;
    }

    public function handle(callable|string $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    public function asTask(bool $value = true): self
    {
        $this->asynchronous = $value;

        return $this;
    }

    public function compile(): ToolDefinition
    {
        if ($this->handler === null) {
            throw new \LogicException("Tool [{$this->name}] has no handler.");
        }
        if ($this->asynchronous && (! is_string($this->handler) || ! class_exists($this->handler))) {
            throw new \LogicException("Asynchronous tool [{$this->name}] must use a serializable class-string handler.");
        }

        $inputSchema = $this->inputSchema;
        if (($inputSchema['type'] ?? null) === 'object' && ($inputSchema['properties'] ?? null) === []) {
            $inputSchema['properties'] = new \stdClass;
        }

        return new ToolDefinition($this->name, $this->description, $inputSchema, $this->outputSchema, $this->handler, $this->annotations, $this->meta, $this->asynchronous);
    }
}
