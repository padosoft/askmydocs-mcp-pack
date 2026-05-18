<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * v1.5.0 W1.C — validation contract for `POST /audit/{id}/replay`.
 *
 * Two-call protocol:
 *  - First call: `confirm_token` absent → controller mints a token,
 *    returns 202 with `{confirm_token, replays_in_seconds}`.
 *  - Second call: client echoes back the token → controller hands
 *    it to the host bridge, which atomically consumes + executes.
 *
 * The token format is `tok_` + 32 hex chars; the regex below is the
 * R19 escape gate — anything else short-circuits at validation.
 */
final class ReplayAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            // R19: the token is opaque + entropy-bearing — we accept
            // only the exact `tok_` + 32 hex shape the mint side
            // produces. A wider regex would let an attacker fuzz the
            // host's persistence layer.
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
