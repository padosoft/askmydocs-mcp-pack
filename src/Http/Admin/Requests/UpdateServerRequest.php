<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * v1.5.0 — validation contract for `PATCH /servers/{id}`.
 *
 * All fields optional (PATCH semantics); the same R19 escape gates
 * as `StoreServerRequest` apply. Critical difference: `tenant_id`
 * is FORBIDDEN on the wire — accepting it on UPDATE would let an
 * attacker move a server between tenants, defeating R30. The wire
 * shape returns 422 when `tenant_id` is present even if it matches
 * the trusted attribute.
 */
final class UpdateServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:150', 'regex:/^[A-Za-z0-9._\-\s]+$/'],
            'transport' => ['sometimes', 'string', 'in:http,sse,stdio'],
            'url' => ['sometimes', 'string', 'min:1', 'max:2048'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'owner' => ['sometimes', 'nullable', 'string', 'max:150'],
            'enabled' => ['sometimes', 'boolean'],
            'allowed_tools' => ['sometimes', 'array'],
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
     * R30: explicitly reject any wire `tenant_id` field. Accepting it
     * on UPDATE would let an attacker re-parent a server across
     * tenant boundaries. Also reject control characters in
     * free-form fields.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            if ($this->exists('tenant_id')) {
                $v->errors()->add(
                    'tenant_id',
                    'tenant_id cannot be modified through this endpoint. '
                    . 'Tenant assignment is set on create and immutable thereafter.',
                );
            }

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
     * Return the validated patch payload. `tenant_id` is structurally
     * absent (forbidden by `withValidator()`); the controller hands
     * this verbatim to the host's `update()`.
     *
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }
}
