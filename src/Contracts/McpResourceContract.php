<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts;

/**
 * A single MCP resource — opaque content addressable by URI that an
 * upstream MCP server exposes for reading.
 *
 * Per the MCP spec, `resources/list` returns a catalog of resource
 * descriptors and `resources/read` fetches the payload by URI. Hosts
 * use resources to surface documents, code, configuration files, or
 * any other readable artefact the model may need context for.
 *
 * The package treats resources as PASSIVE — they are read, not
 * invoked. Hosts that want to MUTATE upstream state should expose
 * tools (`McpToolContract`) instead.
 */
interface McpResourceContract
{
    /**
     * Stable resource identifier — `file://...`, `https://...`,
     * `obsidian://...`, or any other URI scheme the upstream server
     * advertises. The orchestrator hands this verbatim to
     * `resources/read`.
     */
    public function uri(): string;

    /** Human label shown to the model + admin SPA. */
    public function name(): string;

    /**
     * Optional human description — keep concise; the model uses this
     * to decide WHEN to read the resource.
     */
    public function description(): string;

    /**
     * MIME type the resource delivers. Common values:
     * `text/plain`, `text/markdown`, `application/json`,
     * `application/octet-stream`. Hosts that need to render
     * resources in an admin UI dispatch on this string.
     */
    public function mimeType(): string;

    /**
     * Fetch the resource payload. The return shape mirrors the MCP
     * `resources/read` response: a list of content blocks where
     * each block is either `['type'=>'text','text'=>'...']` or
     * `['type'=>'blob','blob'=>'<base64>','mimeType'=>'...']`.
     *
     * Implementations MAY return a single string for the trivial
     * text-only case — the orchestrator normalises both shapes
     * before passing to the host bridge.
     *
     * @return string|array<int,array<string,mixed>>
     */
    public function read(): string|array;
}
