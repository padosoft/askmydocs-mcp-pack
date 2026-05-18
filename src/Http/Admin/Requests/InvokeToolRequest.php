<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * v1.5.0 W1.C — validation contract for
 * `POST /servers/{id}/tools/{name}/invoke`.
 *
 * Validated payload shape:
 *
 *  - `arguments` — required array, MUST nest at most 8 levels deep.
 *                  Every STRING leaf is scrubbed for control chars
 *                  (R19: input-escape-complete). The package does NOT
 *                  schema-validate against the tool's declared input
 *                  schema — that's the upstream MCP server's job; the
 *                  point here is to keep log-injection / parser-confusion
 *                  payloads off the wire.
 *  - `confirm`   — optional boolean. Tools that advertise
 *                  `destructive: true` in their handshake metadata
 *                  must be invoked with `confirm: true` or the
 *                  controller answers 422 `confirmation_required`.
 *                  Read-only tools ignore the field.
 */
final class InvokeToolRequest extends FormRequest
{
    /** Hard cap on argument nesting to bound recursion + log size. */
    private const MAX_ARGUMENT_DEPTH = 8;

    public function authorize(): bool
    {
        // Authorisation is the host's job (see `mcp-pack.admin.middleware`).
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            // `present` (not `required`) so an explicit empty
            // `arguments: {}` is accepted — a tool may legitimately
            // be invocable with no arguments. `required` rejects
            // empty arrays in Laravel and would block that path.
            'arguments' => ['present', 'array'],
            'confirm' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $args = $this->input('arguments');
            if (! is_array($args)) {
                return;
            }
            $depth = $this->measureDepth($args);
            if ($depth > self::MAX_ARGUMENT_DEPTH) {
                $v->errors()->add(
                    'arguments',
                    'Arguments may not nest deeper than ' . self::MAX_ARGUMENT_DEPTH . ' levels.',
                );
                return;
            }
            // R19 — control-character scrub on every string leaf. The
            // hot path uses a closure-based DFS so we surface the
            // first offending key instead of just rejecting the whole
            // payload with a generic message.
            $offender = $this->firstControlCharKey($args, 'arguments');
            if ($offender !== null) {
                $v->errors()->add(
                    'arguments',
                    "Argument [{$offender}] must not contain control characters.",
                );
            }
        });
    }

    /**
     * Recursive max-depth probe. Counts the deepest leaf level
     * (a flat array is depth 1, `{a:[b:1]}` is depth 2, etc.).
     *
     * @param array<mixed> $payload
     */
    private function measureDepth(array $payload, int $current = 1): int
    {
        $max = $current;
        foreach ($payload as $value) {
            if (! is_array($value)) {
                continue;
            }
            $next = $this->measureDepth($value, $current + 1);
            if ($next > $max) {
                $max = $next;
            }
        }
        return $max;
    }

    /**
     * Return the dotted path to the first string leaf containing a
     * control char (0x00..0x1F, 0x7F), or `null` if every leaf is
     * clean.
     *
     * @param array<mixed> $payload
     */
    private function firstControlCharKey(array $payload, string $prefix): ?string
    {
        foreach ($payload as $key => $value) {
            $path = $prefix . '.' . (string) $key;
            if (is_array($value)) {
                $nested = $this->firstControlCharKey($value, $path);
                if ($nested !== null) {
                    return $nested;
                }
                continue;
            }
            if (! is_string($value)) {
                continue;
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Normalised payload reaching the controller.
     *
     * @return array{arguments:array<string,mixed>, confirm:bool}
     */
    public function payload(): array
    {
        $validated = $this->validated();
        /** @var array<string,mixed> $args */
        $args = $validated['arguments'] ?? [];
        return [
            'arguments' => $args,
            'confirm' => (bool) ($validated['confirm'] ?? false),
        ];
    }
}
