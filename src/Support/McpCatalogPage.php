<?php

namespace Padosoft\AskMyDocsMcpPack\Support;

final readonly class McpCatalogPage
{
    /**
     * @param  list<array<string,mixed>>  $items
     * @param  array<string,mixed>  $meta
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor = null,
        public ?int $cacheTtlSeconds = null,
        public ?int $ttlMs = null,
        public ?string $cacheScope = null,
        public array $meta = [],
    ) {}
}
