<?php

namespace Padosoft\AskMyDocsMcpPack\Artifacts;

final class Artifact
{
    private string $mimeType = 'application/octet-stream';

    private ?string $contents = null;

    private ?string $sourcePath = null;

    /** @var array<string,mixed> */
    private array $annotations = [];

    /** @var array<string,mixed> */
    private array $metadata = [];

    private ?int $ttlSeconds = null;

    private function __construct(private readonly string $name) {}

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function mimeType(string $value): self
    {
        $mime = strtolower(trim(explode(';', $value)[0]));
        if (! preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~', $mime)) {
            throw new \InvalidArgumentException("Invalid artifact MIME type [{$value}].");
        }
        $this->mimeType = $mime;

        return $this;
    }

    public function contents(string $value): self
    {
        $this->contents = $value;
        $this->sourcePath = null;

        return $this;
    }

    public function file(string $path): self
    {
        $this->sourcePath = $path;
        $this->contents = null;

        return $this;
    }

    /** @param array<string,mixed> $value */
    public function annotations(array $value): self
    {
        $this->annotations = $value;

        return $this;
    }

    /** @param array<string,mixed> $value */
    public function metadata(array $value): self
    {
        $this->metadata = $value;

        return $this;
    }

    public function ttl(int $seconds): self
    {
        $this->ttlSeconds = max(1, $seconds);

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function mime(): string
    {
        return $this->mimeType;
    }

    /** @return array<string,mixed> */
    public function annotationValues(): array
    {
        return $this->annotations;
    }

    /** @return array<string,mixed> */
    public function metadataValues(): array
    {
        return $this->metadata;
    }

    public function ttlSeconds(): ?int
    {
        return $this->ttlSeconds;
    }

    public function bytes(?int $maxBytes = null): string
    {
        if ($this->contents !== null) {
            $this->guardSize(strlen($this->contents), $maxBytes);

            return $this->contents;
        }
        if ($this->sourcePath === null || ! is_file($this->sourcePath) || ! is_readable($this->sourcePath)) {
            throw new \InvalidArgumentException('Artifact requires readable contents or file.');
        }
        $size = filesize($this->sourcePath);
        if ($size !== false) {
            $this->guardSize($size, $maxBytes);
        }
        $value = file_get_contents($this->sourcePath);
        if ($value === false) {
            throw new \RuntimeException('Artifact file could not be read.');
        }

        return $value;
    }

    private function guardSize(int $size, ?int $maxBytes): void
    {
        if ($maxBytes !== null && $size > $maxBytes) {
            throw new \InvalidArgumentException("Artifact exceeds the {$maxBytes}-byte limit.");
        }
    }
}
