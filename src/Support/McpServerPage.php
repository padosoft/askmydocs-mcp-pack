<?php

namespace Padosoft\AskMyDocsMcpPack\Support;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;

/**
 * v1.5.0 — paginated registry result.
 *
 * Readonly value object carrying the page of {@see McpServerContract}
 * entries plus the pagination meta the SPA renders in its table
 * footer. The shape mirrors Laravel's standard `LengthAwarePaginator`
 * meta block (`total / per_page / current_page / last_page`) so a
 * host backing the registry with Eloquent can fill it directly from
 * `$paginator->total()` / `$paginator->perPage()` etc.
 *
 * The class is `final readonly` so a host cannot subclass it with
 * mutable state — pagination meta is a snapshot the controller
 * passes verbatim to the JSON response.
 */
final readonly class McpServerPage
{
    /**
     * @param array<int,McpServerContract> $data
     */
    public function __construct(
        public array $data,
        public int $total,
        public int $perPage,
        public int $currentPage,
        public int $lastPage,
    ) {}

    /**
     * Convenience constructor that derives `lastPage` from `total` /
     * `perPage` so the in-memory registry doesn't have to recompute
     * the division.
     *
     * @param array<int,McpServerContract> $data
     */
    public static function fromSlice(array $data, int $total, int $perPage, int $currentPage): self
    {
        $perPage = max(1, $perPage);
        $lastPage = $total === 0 ? 1 : (int) ceil($total / $perPage);

        return new self(
            data: array_values($data),
            total: $total,
            perPage: $perPage,
            currentPage: max(1, $currentPage),
            lastPage: max(1, $lastPage),
        );
    }

    /**
     * The meta block surfaced under `meta.{total,per_page,current_page,last_page}`
     * by the admin controllers.
     *
     * @return array{total:int, per_page:int, current_page:int, last_page:int}
     */
    public function meta(): array
    {
        return [
            'total' => $this->total,
            'per_page' => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage,
        ];
    }
}
