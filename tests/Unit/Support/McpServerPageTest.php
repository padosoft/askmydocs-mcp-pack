<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Padosoft\AskMyDocsMcpPack\Support\McpServerPage;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeMcpServer;

class McpServerPageTest extends TestCase
{
    public function test_from_slice_computes_last_page_from_total_and_per_page(): void
    {
        $page = McpServerPage::fromSlice(
            data: [],
            total: 23,
            perPage: 10,
            currentPage: 1,
        );

        $this->assertSame(3, $page->lastPage); // ceil(23/10) = 3
    }

    public function test_from_slice_handles_empty_total_with_min_one_last_page(): void
    {
        $page = McpServerPage::fromSlice([], 0, 50, 1);
        $this->assertSame(1, $page->lastPage);
        $this->assertSame(0, $page->total);
    }

    public function test_from_slice_floors_per_page_at_one(): void
    {
        // perPage=0 would otherwise blow up ceil(0/0) — clamp to 1.
        $page = McpServerPage::fromSlice([], 5, 0, 1);
        $this->assertSame(1, $page->perPage);
        $this->assertSame(5, $page->lastPage);
    }

    public function test_meta_returns_pagination_block(): void
    {
        $page = McpServerPage::fromSlice([new FakeMcpServer(id: 'a')], 1, 50, 1);
        $this->assertSame([
            'total' => 1,
            'per_page' => 50,
            'current_page' => 1,
            'last_page' => 1,
        ], $page->meta());
    }

    public function test_current_page_floors_at_one(): void
    {
        $page = McpServerPage::fromSlice([], 0, 10, 0);
        $this->assertSame(1, $page->currentPage);
    }
}
