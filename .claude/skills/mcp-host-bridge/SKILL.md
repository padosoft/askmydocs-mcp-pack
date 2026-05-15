---
name: mcp-host-bridge
description: Implement McpHostBridgeContract against an existing chat manager — provider-agnostic, ~30 LOC.
---

# Skill: wire `McpHostBridgeContract`

This is the one piece the consumer host MUST write themselves. The
contract is provider-agnostic by design; the bridge is the
translation layer.

## Step 1 — implement the contract

Create `app/Mcp/MyHostBridge.php`:

```php
<?php

namespace App\Mcp;

use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Support\HostChatResponse;
use Padosoft\AskMyDocsMcpPack\Support\HostChatTurn;

final class MyHostBridge implements McpHostBridgeContract
{
    public function __construct(private readonly /* YourChatManager */ $chat) {}

    public function chat(HostChatTurn $turn): HostChatResponse
    {
        $providerTools = $this->translateTools($turn->tools);
        $rawResponse = $this->chat->chatWithHistory(
            systemPrompt: '',
            messages: $turn->messages,
            options: ['tools' => $providerTools, 'tool_choice' => 'auto'] + $turn->extras,
        );

        return new HostChatResponse(
            content: $rawResponse->content,
            toolCalls: $this->normalizeToolCalls($rawResponse->toolCalls),
            provider: $rawResponse->provider,
            model: $rawResponse->model,
            usage: ['prompt_tokens' => $rawResponse->promptTokens ?? 0],
        );
    }

    public function supportsToolCalling(): bool
    {
        // List of providers in your manager that DO function-calling
        return in_array(
            $this->chat->provider()->name(),
            ['openai', 'openrouter'],
            true,
        );
    }

    private function translateTools(array $tools): array
    {
        return array_map(fn($tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->schema(),
            ],
        ], $tools);
    }

    private function normalizeToolCalls(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_map(
            fn($call) => [
                'id' => (string) ($call['id'] ?? 'tool_' . bin2hex(random_bytes(8))),
                'name' => (string) ($call['function']['name'] ?? $call['name'] ?? ''),
                'arguments' => $this->decodeArguments($call),
            ],
            $raw,
        );
    }

    private function decodeArguments(array $call): array
    {
        $raw = $call['function']['arguments'] ?? $call['arguments'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($raw) ? $raw : [];
    }
}
```

## Step 2 — bind in `AppServiceProvider::register()`

```php
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;

$this->app->singleton(McpHostBridgeContract::class, App\Mcp\MyHostBridge::class);
```

## Step 3 — verify

```bash
php artisan mcp-pack:ping --tenant=default
```

Exit code 0 = bridge is reachable. Anything else: read the table's
`error` column.

## Common pitfalls

- **`supportsToolCalling()` returns false** for the model the user
  selected → the orchestrator silently falls back to a plain
  `$bridge->chat($turn)` with an empty tool catalog. This is by
  design — there is no model-side error, just no tools.
- **Arguments arrive as a JSON-encoded string** (OpenAI shape) but
  your `normalizeToolCalls` expects an array → decode in the bridge,
  the orchestrator does NOT double-decode.
- **`tool_calls` cycle never terminates** → make sure the final
  bridge call after a tool result actually sees the `tool` role
  message you appended. If not, the model thinks the tool never ran
  and re-asks.
