<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\V2;

use Padosoft\AskMyDocsMcpPack\Validation\JsonSchemaValidator;
use PHPUnit\Framework\TestCase;

final class JsonSchemaValidatorTest extends TestCase
{
    public function test_remote_refs_are_denied_by_default(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new JsonSchemaValidator)->validate(['type' => 'object', 'properties' => ['x' => ['$ref' => 'https://evil.example/schema.json']]], []);
    }

    public function test_file_and_relative_external_refs_are_always_denied(): void
    {
        foreach (['file:///etc/passwd', './schema.json'] as $ref) {
            try {
                (new JsonSchemaValidator(allowedRemoteHosts: ['example.test']))->validate(['$ref' => $ref], []);
                $this->fail("External ref [{$ref}] should be denied.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('not allowed', $e->getMessage());
            }
        }
    }

    public function test_empty_schema_maps_are_encoded_as_objects(): void
    {
        (new JsonSchemaValidator)->validate(['type' => 'object', 'properties' => []], []);
        $this->addToAssertionCount(1);
    }

    public function test_empty_instance_representation_follows_the_schema_type(): void
    {
        $validator = new JsonSchemaValidator;

        // An empty PHP array is ambiguous ({} vs []); pick the shape the schema expects.
        $validator->validate(['type' => 'array'], []);
        $validator->validate(['type' => 'array', 'minItems' => 0], []);
        $validator->validate(['type' => ['array', 'null']], []);
        $validator->validate(['type' => 'object'], []);
        $validator->validate(['type' => ['object', 'array']], []);
        $validator->validate([], []);
        $this->addToAssertionCount(6);

        try {
            $validator->validate(['type' => 'array', 'minItems' => 1], []);
            $this->fail('An empty array must not satisfy minItems: 1.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('JSON Schema validation failed', $e->getMessage());
        }
    }

    public function test_allowlisted_remote_ref_is_not_rejected_by_security_gate(): void
    {
        $validator = new JsonSchemaValidator(allowedRemoteHosts: ['127.0.0.1']);
        try {
            $validator->validate(['type' => 'object', 'properties' => ['x' => ['$ref' => 'http://127.0.0.1:9/schema.json']]], []);
        } catch (\InvalidArgumentException $e) {
            $this->fail('Allowlisted host was rejected by the security gate: '.$e->getMessage());
        } catch (\Throwable) {
            // Opis may fail to resolve the intentionally unavailable document;
            // this assertion covers only the package host allowlist gate.
            $this->addToAssertionCount(1);

            return;
        }
        $this->addToAssertionCount(1);
    }
}
