<?php

namespace Padosoft\AskMyDocsMcpPack\Validation;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\Uri;
use Opis\JsonSchema\Validator;

final class JsonSchemaValidator
{
    /** @param list<string> $allowedRemoteHosts */
    public function __construct(
        private readonly int $maxSchemaBytes = 262_144,
        private readonly int $maxInstanceBytes = 1_048_576,
        private readonly int $maxDepth = 64,
        private readonly array $allowedRemoteHosts = [],
    ) {}

    /** @param array<string,mixed> $schema @param array<string,mixed> $instance */
    public function validate(array $schema, array $instance): void
    {
        $this->assertWithinLimits($schema, $this->maxSchemaBytes, 'schema');
        $this->assertWithinLimits($instance, $this->maxInstanceBytes, 'arguments');
        $this->assertRefsAllowed($schema);

        $schemaObject = json_decode(json_encode($this->normaliseSchemaMaps($schema), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $instanceObject = json_decode(json_encode($instance === [] ? new \stdClass : $instance, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $validator = new Validator;
        if ($this->allowedRemoteHosts !== []) {
            $resolver = new SchemaResolver;
            foreach (['http', 'https'] as $scheme) {
                $resolver->registerProtocol($scheme, fn (Uri $uri): object|bool|null => $this->resolveRemoteSchema((string) $uri));
            }
            $validator->setResolver($resolver);
        }
        $result = $validator->validate($instanceObject, $schemaObject);
        if ($result->isValid()) {
            return;
        }

        $formatted = (new ErrorFormatter)->format($result->error(), false);
        throw new \InvalidArgumentException('JSON Schema validation failed: '.json_encode($formatted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function assertWithinLimits(array $value, int $maxBytes, string $label): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        if (strlen($json) > $maxBytes) {
            throw new \InvalidArgumentException("JSON {$label} exceeds the {$maxBytes}-byte limit.");
        }
        if ($this->depth($value) > $this->maxDepth) {
            throw new \InvalidArgumentException("JSON {$label} exceeds the maximum depth of {$this->maxDepth}.");
        }
    }

    private function depth(mixed $value, int $current = 0): int
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (! is_array($value) || $value === []) {
            return $current;
        }

        return max(array_map(fn (mixed $child): int => $this->depth($child, $current + 1), $value));
    }

    private function assertRefsAllowed(mixed $node): void
    {
        if (is_object($node)) {
            $node = get_object_vars($node);
        }
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && ! str_starts_with($value, '#')) {
                $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
                $host = strtolower((string) parse_url($value, PHP_URL_HOST));
                if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ! in_array($host, $this->allowedRemoteHosts, true)) {
                    throw new \InvalidArgumentException("External JSON Schema ref [{$value}] is not allowed.");
                }
            }
            $this->assertRefsAllowed($value);
        }
    }

    private function normaliseSchemaMaps(mixed $node, ?string $key = null): mixed
    {
        if (is_object($node)) {
            $node = get_object_vars($node);
        }
        if (! is_array($node)) {
            return $node;
        }
        if ($node === [] && in_array($key, ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'], true)) {
            return new \stdClass;
        }

        $normalised = [];
        foreach ($node as $childKey => $value) {
            $normalised[$childKey] = $this->normaliseSchemaMaps($value, is_string($childKey) ? $childKey : null);
        }

        return $normalised;
    }

    private function resolveRemoteSchema(string $uri): object|bool|null
    {
        $parts = parse_url($uri);
        if (! is_array($parts)) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)
            || ! in_array($host, $this->allowedRemoteHosts, true)
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $context = stream_context_create([
            'http' => ['follow_location' => 0, 'max_redirects' => 0, 'timeout' => 3, 'ignore_errors' => false],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $json = @file_get_contents($uri, false, $context, 0, $this->maxSchemaBytes + 1);
        if (! is_string($json) || strlen($json) > $this->maxSchemaBytes) {
            return null;
        }
        $decoded = json_decode($json, false, $this->maxDepth, JSON_THROW_ON_ERROR);

        return is_object($decoded) || is_bool($decoded) ? $decoded : null;
    }
}
