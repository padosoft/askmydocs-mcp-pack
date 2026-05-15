<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServer;
use Padosoft\AskMyDocsMcpPack\Services\RemoteMcpTool;
use Padosoft\AskMyDocsMcpPack\Services\ToolInvoker;

class RemoteMcpToolTest extends TestCase
{
    public function test_extracts_name_description_and_schema(): void
    {
        $payload = [
            'name' => 'kb_search',
            'description' => 'Search the KB',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['q' => ['type' => 'string']],
                'required' => ['q'],
            ],
        ];

        $tool = new RemoteMcpTool('kb_search', $payload, $this->server(), new ToolInvoker());

        $this->assertSame('kb_search', $tool->name());
        $this->assertSame('Search the KB', $tool->description());
        $this->assertSame('object', $tool->schema()['type']);
        $this->assertSame(['q'], $tool->schema()['required']);
    }

    public function test_defaults_to_empty_object_schema_when_missing(): void
    {
        $tool = new RemoteMcpTool('noop', ['name' => 'noop'], $this->server(), new ToolInvoker());

        $schema = $tool->schema();
        $this->assertSame('object', $schema['type']);
        $this->assertEquals(new \stdClass(), $schema['properties']);
    }

    public function test_idempotent_and_read_only_flags_default_false(): void
    {
        $tool = new RemoteMcpTool('noop', ['name' => 'noop'], $this->server(), new ToolInvoker());

        $this->assertFalse($tool->isIdempotent());
        $this->assertFalse($tool->isReadOnly());
    }

    public function test_idempotent_and_read_only_flags_are_picked_up(): void
    {
        $tool = new RemoteMcpTool(
            'kb_search',
            ['name' => 'kb_search', 'idempotent' => true, 'readOnly' => true],
            $this->server(),
            new ToolInvoker(),
        );

        $this->assertTrue($tool->isIdempotent());
        $this->assertTrue($tool->isReadOnly());
    }

    private function server(): InMemoryMcpServer
    {
        return new InMemoryMcpServer(
            id: 's1',
            name: 'S1',
            transport: 'http',
            tenantId: null,
            transportConfig: ['endpoint' => 'http://stub'],
        );
    }
}
