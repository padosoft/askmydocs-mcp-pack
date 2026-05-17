<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * v1.5.0 — validation contract for `POST /api-keys`.
 *
 * Validated payload shape (matches `data.js` `API_KEYS[*]`):
 *
 *  - `name` — string 1..150, MUST NOT contain control characters.
 *    The package keeps the field user-defined and free-form on
 *    purpose: hosts that want stricter rules layer their own
 *    `prepareForValidation()` over a subclass.
 *  - `scopes` — non-empty array of `mcp.<area>.<action>`-style
 *    permission strings. Each scope ≤ 100 chars, regex restricted
 *    to `[a-z0-9.-]+` so a malicious actor cannot inject `%` /
 *    `_` / SQL LIKE wildcards into a downstream `whereLike` query
 *    (R19 — input-escape-complete). Note: `_` is EXPLICITLY
 *    excluded even though scope strings in some conventions use
 *    snake_case — the SQL-LIKE single-character wildcard takes
 *    priority over ergonomic flexibility. Duplicates are
 *    deduplicated silently.
 */
final class CreateApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:150'],
            'scopes' => ['required', 'array', 'min:1'],
            // R19: the regex is the load-bearing escape gate — every
            // scope value must be lower-case alphanumerics + `.` + `-`.
            // Underscores `_` are EXCLUDED because they are the
            // single-character SQL LIKE wildcard; admitting them would
            // let an attacker construct a scope that matches arbitrary
            // patterns downstream. No `%`, no spaces, no whitespace.
            'scopes.*' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9.\-]+$/'],
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'scopes.*.regex' => 'Each scope must match [a-z0-9.-]+ — e.g. mcp.tools.invoke.',
            'name.required' => 'API key name is required.',
            'scopes.required' => 'At least one scope is required.',
        ];
    }

    /**
     * After basic rules pass: reject control characters in `name`.
     * Control-character rejection prevents an attacker from sneaking
     * `\n` / `\0` into a log line that the SPA later renders verbatim
     * (defensive against log-injection).
     *
     * Note: scope dedupe happens later in {@see payload()}, not here —
     * the validator stage only checks correctness, the dedupe is a
     * normalisation concern.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            $name = (string) $this->input('name', '');
            if ($name !== '' && preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
                $v->errors()->add('name', 'Name must not contain control characters.');
            }
        });
    }

    /**
     * Return the validated payload shape the host bridge consumes.
     * Scopes are deduplicated + re-indexed so the host gets a clean
     * list.
     *
     * @return array{name:string, scopes:array<int,string>}
     */
    public function payload(): array
    {
        /** @var array<int,string> $rawScopes */
        $rawScopes = (array) $this->input('scopes', []);
        $scopes = array_values(array_unique(array_map('strval', $rawScopes)));

        return [
            'name' => (string) $this->input('name'),
            'scopes' => $scopes,
        ];
    }
}
