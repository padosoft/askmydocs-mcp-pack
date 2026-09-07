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
        $this->assertValidUriTemplate($uriTemplate);
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

    private function assertValidUriTemplate(string $template): void
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:\S+$/i', $template) !== 1) {
            throw new \InvalidArgumentException('A resource template must be an absolute URI template.');
        }
        preg_match_all('/\{([^{}]*)\}/', $template, $matches);
        if (($matches[0] ?? []) === []) {
            throw new \InvalidArgumentException('A resource template must contain at least one non-empty expression.');
        }
        $literal = preg_replace('/\{[^{}]*\}/', '', $template);
        if (! is_string($literal) || str_contains($literal, '{') || str_contains($literal, '}')) {
            throw new \InvalidArgumentException('A resource template contains unbalanced braces.');
        }
        $varName = '(?:[a-zA-Z0-9_]|%[a-fA-F0-9]{2})+(?:\.(?:[a-zA-Z0-9_]|%[a-fA-F0-9]{2})+)*';
        foreach ($matches[1] as $expression) {
            $body = preg_replace('/^[+#.\/;?&]/', '', (string) $expression);
            if (! is_string($body) || $body === '') {
                throw new \InvalidArgumentException('A resource template contains an empty expression.');
            }
            foreach (explode(',', $body) as $variable) {
                if (preg_match('/^'.$varName.'(?::[1-9][0-9]{0,3}|\*)?$/', $variable) !== 1) {
                    throw new \InvalidArgumentException('A resource template contains an invalid variable expression.');
                }
            }
        }
    }
}
