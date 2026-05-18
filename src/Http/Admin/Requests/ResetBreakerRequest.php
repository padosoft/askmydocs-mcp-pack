<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * v1.5.0 W1.C — validation contract for
 * `POST /circuit-breaker/{key}/reset`.
 *
 * Same two-call protocol as {@see ReplayAuditRequest}: optional
 * `confirm_token` field; absent triggers mint, present triggers
 * consume. The token regex is identical (R19 — exact `tok_[a-f0-9]{32}`).
 */
final class ResetBreakerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'confirm_token' => ['nullable', 'string', 'regex:/^tok_[a-f0-9]{32}$/'],
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'confirm_token.regex' => 'Confirm token must match tok_[a-f0-9]{32}.',
        ];
    }

    public function confirmToken(): ?string
    {
        $raw = $this->input('confirm_token');
        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
