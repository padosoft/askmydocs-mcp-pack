<?php

namespace Padosoft\AskMyDocsMcpPack\Apps;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\AppDefinition;

final readonly class AppRenderer
{
    public function __construct(private ViewFactory $views) {}

    public function render(AppDefinition $app): string
    {
        return match ($app->sourceType) {
            'html' => (string) $app->source,
            'file' => $this->readFile((string) $app->source),
            'view' => $this->views->make((string) $app->source[0], (array) ($app->source[1] ?? []))->render(),
            default => throw new \LogicException("Unsupported MCP App source type [{$app->sourceType}]."),
        };
    }

    private function readFile(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \RuntimeException("MCP App file [{$path}] is not readable.");
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("MCP App file [{$path}] could not be read.");
        }

        return $contents;
    }
}
