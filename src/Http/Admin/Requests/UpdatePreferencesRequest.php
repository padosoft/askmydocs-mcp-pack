<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * v1.5.0 — validation contract for `POST /me/preferences`.
 *
 * The `preferences` bag is schema-less by design (hosts decide what
 * they persist), but the package enforces some structural invariants:
 *
 *  - `preferences` MUST be present and an associative array
 *  - every key MUST be a non-empty string ≤ 128 chars (matches the
 *    `mcp_user_preferences.key` column when the host adopts the
 *    publishable migration)
 *  - the wire payload MUST round-trip as JSON (no resource handles,
 *    no recursive arrays); the controller persists via `json_encode`
 *    so a non-JSON-safe value would otherwise silently corrupt
 */
final class UpdatePreferencesRequest extends FormRequest
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
            'preferences' => ['required', 'array'],
            'preferences.*' => ['present'],
        ];
    }

    /**
     * Enforce the "key length ≤ 128" + "keys are strings" + JSON-safe
     * value invariants AFTER Laravel's basic rules pass.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            /** @var mixed $preferences */
            $preferences = $this->input('preferences');
            if (! is_array($preferences)) {
                return;
            }

            foreach ($preferences as $key => $_value) {
                if (! is_string($key) || $key === '') {
                    $v->errors()->add('preferences', 'Preference keys must be non-empty strings.');
                    return;
                }
                if (strlen($key) > 128) {
                    $v->errors()->add(
                        "preferences.{$key}",
                        "Preference key [{$key}] exceeds the 128-character limit.",
                    );
                }
            }

            // Round-trip the bag through JSON to guarantee the host
            // can persist it. `json_encode` returns `false` on
            // resources / recursive structures.
            $encoded = json_encode(
                $preferences,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
            );
            if ($encoded === false || json_last_error() !== JSON_ERROR_NONE) {
                $v->errors()->add('preferences', 'Preferences payload is not JSON-serialisable.');
            }
        });
    }

    /** @return array<string,mixed> */
    public function preferences(): array
    {
        /** @var array<string,mixed> $prefs */
        $prefs = (array) $this->input('preferences', []);
        return $prefs;
    }
}
