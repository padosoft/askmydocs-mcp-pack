<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\Http\Admin\Requests;

use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Requests\InvokeToolRequest;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class InvokeToolRequestTest extends TestCase
{
    private function buildRequest(array $data): InvokeToolRequest
    {
        $request = InvokeToolRequest::create('/x', 'POST', $data);
        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);
        return $request;
    }

    private function fails(array $data): array
    {
        $request = $this->buildRequest($data);
        try {
            $request->validateResolved();
            return [];
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $e->errors();
        }
    }

    public function test_requires_arguments(): void
    {
        $errors = $this->fails([]);
        $this->assertArrayHasKey('arguments', $errors);
    }

    public function test_arguments_must_be_array(): void
    {
        $errors = $this->fails(['arguments' => 'not-an-array']);
        $this->assertArrayHasKey('arguments', $errors);
    }

    public function test_accepts_empty_array_as_arguments(): void
    {
        $request = $this->buildRequest(['arguments' => []]);
        $request->validateResolved();
        $this->assertSame([], $request->payload()['arguments']);
        $this->assertNull($request->payload()['confirm_token']);
    }

    public function test_confirm_token_must_match_tok_regex(): void
    {
        // Iter-1 (W1.C): the destructive-tool path uses an R21
        // single-use `tok_<32hex>` token, not a reusable boolean.
        // Anything else is 422.
        $errors = $this->fails(['arguments' => [], 'confirm_token' => 'maybe']);
        $this->assertArrayHasKey('confirm_token', $errors);
    }

    public function test_accepts_valid_confirm_token(): void
    {
        $token = 'tok_' . str_repeat('a', 32);
        $request = $this->buildRequest(['arguments' => ['q' => 'hi'], 'confirm_token' => $token]);
        $request->validateResolved();
        $this->assertSame($token, $request->payload()['confirm_token']);
    }

    public function test_rejects_control_char_at_top_level_leaf(): void
    {
        $errors = $this->fails(['arguments' => ['q' => "evil\x00null"]]);
        $this->assertArrayHasKey('arguments', $errors);
    }

    public function test_rejects_control_char_at_nested_leaf(): void
    {
        $errors = $this->fails([
            'arguments' => [
                'options' => ['filter' => "\x07bell"],
            ],
        ]);
        $this->assertArrayHasKey('arguments', $errors);
    }

    public function test_rejects_payload_nested_beyond_eight_levels(): void
    {
        // Build 9 levels deep.
        $deep = 'leaf';
        for ($i = 0; $i < 9; $i++) {
            $deep = ['nested' => $deep];
        }
        $errors = $this->fails(['arguments' => $deep]);
        $this->assertArrayHasKey('arguments', $errors);
    }

    public function test_accepts_payload_at_max_allowed_depth(): void
    {
        // 8-level deep payload is OK.
        $deep = 'leaf';
        for ($i = 0; $i < 7; $i++) {
            // 7 wrappings around the leaf string = depth 8 array (the
            // leaf itself adds the +1; the outer-most array is depth 1).
            $deep = ['n' => $deep];
        }
        $request = $this->buildRequest(['arguments' => $deep]);
        $request->validateResolved();
        $this->assertIsArray($request->payload()['arguments']);
    }

    public function test_non_string_leaves_are_not_scrubbed(): void
    {
        // Numbers, booleans, nulls pass through the control-char
        // scrub without false positives.
        $request = $this->buildRequest([
            'arguments' => [
                'n' => 42,
                'b' => true,
                'nope' => null,
                'floaty' => 1.5,
            ],
        ]);
        $request->validateResolved();
        $this->assertSame(42, $request->payload()['arguments']['n']);
    }
}
