<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Http\Admin;

use Illuminate\Support\Facades\Route;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

/**
 * v1.5.0 W1.D — architecture-style test ensuring the 6 new routes
 * stay registered EVEN WHEN their per-feature flags are flipped off.
 *
 * The package's contract is "routes always exist; flags only change
 * the controller's response". This means a SPA can rely on stable
 * 403 `feature_disabled` envelopes when a section is turned off,
 * never 404. Regressing this (e.g. an `if ($enabled)` wrapping the
 * route registration block) breaks the SPA's section-disabled UX.
 */
class W1DRoutesRegisteredTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('mcp-pack.admin.enabled', true);
        $app['config']->set('mcp-pack.admin.middleware', ['api']);
        // Flip every W1.D feature flag to FALSE — the routes must
        // STILL be registered (the controller answers 403 inside).
        $app['config']->set('mcp-pack.admin.features.resources', false);
        $app['config']->set('mcp-pack.admin.features.prompts', false);
        $app['config']->set('mcp-pack.admin.features.events_sse', false);
        $app['config']->set('mcp-pack.admin.features.openapi', false);
    }

    public function test_w1d_routes_are_registered_even_when_all_flags_are_false(): void
    {
        $routes = Route::getRoutes();
        $names = [];
        foreach ($routes as $route) {
            $name = $route->getName();
            if (is_string($name) && str_starts_with($name, 'mcp-pack.admin.')) {
                $names[] = $name;
            }
        }

        foreach ([
            'mcp-pack.admin.servers.resources.index',
            'mcp-pack.admin.servers.resources.show',
            'mcp-pack.admin.servers.prompts.index',
            'mcp-pack.admin.servers.prompts.show',
            'mcp-pack.admin.events',
            'mcp-pack.admin.openapi',
        ] as $expected) {
            $this->assertContains($expected, $names, "Expected route name {$expected} to be registered.");
        }
    }
}
