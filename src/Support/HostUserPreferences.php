<?php

namespace Padosoft\AskMyDocsMcpPack\Support;

/**
 * v1.5.0 — provider-agnostic shape of the per-user preference bag
 * returned by `savePreferences()` and surfaced inline on `GET /me`.
 *
 * Hosts decide what they persist (theme, language, dashboard layout,
 * Splunk-style saved filters) — the package is intentionally schema-
 * less here. The only invariant is that the bag round-trips as a
 * JSON object.
 */
final readonly class HostUserPreferences
{
    /**
     * @param  int|string                 $userId  must match the host's user id type
     * @param  array<string,mixed>        $values
     */
    public function __construct(
        public int|string $userId,
        public array $values = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'values' => $this->values,
        ];
    }
}
