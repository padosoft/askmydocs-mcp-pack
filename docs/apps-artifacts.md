# MCP Apps and artifacts

Register Apps with a `ui://` URI. A tool links to one with `Tool::app()`, which emits `_meta.ui.resourceUri`; `openai/outputTemplate` is emitted only when `mcp-pack.apps.openai_compatibility` is enabled, following the [official OpenAI MCP UI guide](https://developers.openai.com/plugins/build/chatgpt-ui).

App HTML may come from a string, readable file or Blade view. Resources use `text/html;profile=mcp-app`. CSP defaults to empty allowlists (deny by default). Hosts and admin clients must render App HTML only in a sandboxed iframe; never insert it into the admin document DOM. Clients without `io.modelcontextprotocol/ui` do not see App resources or tool UI metadata and receive `-32021` if they request an App URI directly.

App permissions follow the extension object shape (`camera`, `microphone`, `geolocation`, `clipboardWrite`). The builder accepts their kebab-case names, for example `permissions('clipboard-write')`, validates CSP origins, and preserves empty directive lists as deny-by-default declarations.

`ui/download-file` is draft behavior and disabled by default. Enable it deliberately before calling `App::downloadFile()`; normal downloadable output should use stable embedded resources or resource links.

Create artifacts with `Artifact::make()`, then `ArtifactManagerContract::create()`. Defaults are private storage, 25 MiB maximum, 24-hour TTL and five-minute signed download URLs. Names are sanitized, storage paths are generated from UUIDs, SHA-256 is rechecked on every read, and executable MIME/content signatures are rejected. Artifacts are immutable: create a new UUID for every revision.

`McpResult::artifact()` embeds files below 64 KiB and links larger files as `artifact://<uuid>`. `resources/read` rechecks tenant and actor on every artifact read. Download responses are attachments with `nosniff` and private no-store caching.
