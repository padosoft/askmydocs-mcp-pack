# Security and OAuth resource-server mode

Tenant separation is structural: every catalog mutation/read enters through `forTenant()`, and the same tenant+principal scope is applied to cache, Tasks, artifacts, subscriptions and cancellation signals. HTTP identity comes only from host auth middleware or the authenticated user model—not MCP headers.

OAuth support is resource-server-only. Enable RFC 9728 metadata with `MCP_PACK_OAUTH_RESOURCE_SERVER=true`, set resource URI, trusted issuers and required scopes, and bind `OAuthAccessTokenValidatorContract` to the host's IdP/JWT implementation. The host validator verifies signature, expiry and token type; package middleware then enforces issuer, resource audience, subject and scopes and returns `WWW-Authenticate` with `resource_metadata` on failure. The package neither implements an authorization server nor requires Passport.

JSON Schema validation uses Draft 2020-12 through `opis/json-schema`. Remote `$ref` is denied unless its exact hostname is in `MCP_PACK_SCHEMA_REF_HOSTS`. Schema/argument byte limits and nesting depth are configurable.

Protocol failures use JSON-RPC errors. Expected validation/business failures return `isError: true`. Unexpected failures are sanitized to a correlation ID and reported through Laravel; no exception message, raw input, access token or trace baggage is written to the wire or audit table. Audit remains hash-only and non-blocking.

Retries apply only to transport failures and only when caller context marks an operation read-only or idempotent. Destructive calls and Tasks are not automatically retried.
