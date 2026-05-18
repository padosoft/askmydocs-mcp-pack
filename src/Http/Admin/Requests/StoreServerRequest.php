<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * v1.5.0 — validation contract for `POST /servers`.
 *
 * Validated payload shape (matches `data.js` `SERVERS[*]`):
 *
 *  - `name`        — required string 1..150, R19-escaped via the
 *                    regex `[A-Za-z0-9._\-\s]+`. The trailing `\s`
 *                    is intentional: the SPA shows `openai-mcp`
 *                    AND human labels like `Acme Slack`. Control
 *                    characters are explicitly rejected in
 *                    `withValidator()` (defensive against
 *                    log-injection).
 *  - `transport`   — required, MUST be one of `http`, `sse`, `stdio`.
 *                    R19: in-list validation is the strongest
 *                    possible escape (zero free-form input
 *                    downstream).
 *  - `url`         — required, ≤ 2048 chars. Validation is loose by
 *                    design: stdio "URLs" are local paths or
 *                    process commands, http/sse are real URLs, and
 *                    the package never parses the field as a URL.
 *                    Control-character rejection guards log lines.
 *  - `description` — optional string ≤ 500 chars.
 *  - `owner`       — optional string ≤ 150 chars (the SPA stores
 *                    the host operator's contact / email).
 *  - `tenant_id`   — optional on the wire; the controller IGNORES
 *                    the wire value and replaces it with the
 *                    trusted `mcp_pack.tenant_id` attribute (R30).
 *                    Accepting it in the rules surface so older
 *                    clients can keep posting the field without a
 *                    422 — the validator just throws it away.
 */
final class StoreServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorisation is the host's job (per `mcp-pack.admin.middleware`).
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            // R19: lower+upper alphanumerics + `.` `_` `-` and spaces.
            // `%` is NOT in the allowed set — the host MAY back the
            // name with a downstream LIKE query and we keep that
            // safe by construction.
            'name' => ['required', 'string', 'min:1', 'max:150', 'regex:/^[A-Za-z0-9._\-\s]+$/'],
            // R19: in-list — the most restrictive possible escape.
            'transport' => ['required', 'string', 'in:http,sse,stdio'],
            'url' => ['required', 'string', 'min:1', 'max:2048'],
            'description' => ['nullable', 'string', 'max:500'],
            'owner' => ['nullable', 'string', 'max:150'],
            // Accepted but ignored by the controller — see class
            // docblock + R30.
            'tenant_id' => ['nullable', 'string', 'max:100'],
            'enabled' => ['nullable', 'boolean'],
            'allowed_tools' => ['nullable', 'array'],
            'allowed_tools.*' => ['string', 'max:200', 'regex:/^[A-Za-z0-9._\-]+$/'],
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'name.regex' => 'Name must match [A-Za-z0-9._-] (spaces allowed).',
            'transport.in' => 'Transport must be one of http, sse, stdio.',
            'allowed_tools.*.regex' => 'Each allowed tool name must match [A-Za-z0-9._-].',
        ];
    }

    /**
     * Reject control characters in free-form fields. Mirrors
     * {@see CreateApiKeyRequest::withValidator()} — defensive against
     * log-injection.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            foreach (['name', 'url', 'description', 'owner'] as $field) {
                $value = $this->input($field);
                if (! is_string($value) || $value === '') {
                    continue;
                }
                if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                    $v->errors()->add($field, "Field [{$field}] must not contain control characters.");
                }
            }
        });
    }

    /**
     * Return the validated payload, EXCLUDING `tenant_id` (the
     * controller injects the trusted tenant attribute) so the host
     * cannot be tricked into honouring a wire-supplied tenant.
     *
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        $validated = $this->validated();
        // R30: drop wire `tenant_id` before handing the payload to the
        // host bridge — the controller replaces it with the trusted
        // attribute.
        unset($validated['tenant_id']);
        return $validated;
    }
}
