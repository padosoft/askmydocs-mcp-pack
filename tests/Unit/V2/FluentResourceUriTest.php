<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\V2;

use Padosoft\AskMyDocsMcpPack\Fluent\Resource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FluentResourceUriTest extends TestCase
{
    /** @return iterable<string,array{0:string}> */
    public static function absoluteUris(): iterable
    {
        yield 'https with authority' => ['https://example.com/docs/1'];
        yield 'custom scheme with authority' => ['docs://tenant/guide'];
        yield 'ui scheme (MCP Apps)' => ['ui://apps/main'];
        yield 'file' => ['file:///tmp/report.txt'];
        yield 'urn (no authority)' => ['urn:isbn:0451450523'];
        yield 'mailto (no authority)' => ['mailto:ops@example.com'];
        yield 'data (no authority)' => ['data:text/plain,hello'];
        yield 'tel (no authority)' => ['tel:+15555550100'];
        yield 'scheme with + . -' => ['git+ssh://host/repo'];
    }

    #[DataProvider('absoluteUris')]
    public function test_any_rfc3986_absolute_uri_is_accepted(string $uri): void
    {
        $definition = Resource::make($uri)->handle(static fn (): string => 'x')->compile();

        $this->assertSame($uri, $definition->uri);
    }

    /** @return iterable<string,array{0:string}> */
    public static function relativeOrInvalidUris(): iterable
    {
        yield 'relative path' => ['/relative/path'];
        yield 'bare word' => ['guide'];
        yield 'space inside' => ['docs://tenant/my guide'];
        yield 'scheme starting with digit' => ['1abc:foo'];
        yield 'empty scheme-specific part' => ['docs:'];
        yield 'empty' => [''];
    }

    #[DataProvider('relativeOrInvalidUris')]
    public function test_relative_or_malformed_uris_are_rejected(string $uri): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute URI');

        Resource::make($uri);
    }
}
