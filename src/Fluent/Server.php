<?php

namespace Padosoft\AskMyDocsMcpPack\Fluent;

use Closure;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\DefinitionContract;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\AppDefinition;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\PromptDefinition;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\ResourceDefinition;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\ResourceTemplateDefinition;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\ServerDefinition;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\ToolDefinition;
use Padosoft\AskMyDocsMcpPack\Http\V2\McpStreamableHttpController;
use Padosoft\AskMyDocsMcpPack\Protocol\CacheScope;

final class Server
{
    private string $displayName;

    private string $serverVersion = '2.0.0';

    private ?string $serverInstructions = null;

    private int $ttlMs = 300_000;

    private CacheScope $cacheScope = CacheScope::Private;

    /** @var list<ToolDefinition> */
    private array $tools = [];

    /** @var list<ResourceDefinition> */
    private array $resources = [];

    /** @var list<ResourceTemplateDefinition> */
    private array $resourceTemplates = [];

    /** @var list<PromptDefinition> */
    private array $prompts = [];

    /** @var list<AppDefinition> */
    private array $apps = [];

    private function __construct(private readonly string $id, private readonly McpManager $manager)
    {
        $this->displayName = $id;
    }

    public static function make(string $id, McpManager $manager): self
    {
        return new self($id, $manager);
    }

    public function name(string $value): self
    {
        $this->displayName = $value;

        return $this;
    }

    public function version(string $value): self
    {
        $this->serverVersion = $value;

        return $this;
    }

    public function instructions(string $value): self
    {
        $this->serverInstructions = $value;

        return $this;
    }

    public function cache(int $ttlMs, CacheScope $scope = CacheScope::Private): self
    {
        $this->ttlMs = max(0, $ttlMs);
        $this->cacheScope = $scope;

        return $this;
    }

    public function tool(Tool|string|Closure $tool, ?string $name = null): self
    {
        $this->tools[] = $this->normaliseTool($tool, $name);

        return $this;
    }

    public function resource(Resource|string|Closure $resource, ?string $uri = null): self
    {
        $this->resources[] = $this->normaliseResource($resource, $uri);

        return $this;
    }

    public function resourceTemplate(ResourceTemplate|string|Closure $resource, ?string $uriTemplate = null): self
    {
        $this->resourceTemplates[] = $resource instanceof ResourceTemplate ? $resource->compile() : ResourceTemplate::make($uriTemplate ?? throw new \InvalidArgumentException('A URI template is required for class/closure handlers.'))->handle($resource)->compile();

        return $this;
    }

    public function prompt(Prompt|string|Closure $prompt, ?string $name = null): self
    {
        $this->prompts[] = $prompt instanceof Prompt ? $prompt->compile() : Prompt::make($name ?? $this->classKey($prompt))->handle($prompt)->compile();

        return $this;
    }

    public function app(App|AppDefinition|string|Closure $app): self
    {
        $this->apps[] = $this->normaliseApp($app);

        return $this;
    }

    /** @param list<string> $middleware */
    public function web(string $path = '/mcp', array $middleware = []): self
    {
        $this->manager->register($this->compile());
        Route::middleware($middleware)->post($path, McpStreamableHttpController::class)
            ->defaults('mcp_server', $this->id)
            ->name('mcp-pack.v2.'.Str::slug($this->id).'.http');

        return $this;
    }

    public function local(string $alias): self
    {
        $this->manager->register($this->compile());
        $this->manager->local($alias, $this->id);

        return $this;
    }

    public function register(): self
    {
        $this->manager->register($this->compile());

        return $this;
    }

    public function compile(): ServerDefinition
    {
        return new ServerDefinition($this->id, $this->displayName, $this->serverVersion, $this->serverInstructions, $this->ttlMs, $this->cacheScope, $this->unique($this->tools), $this->unique($this->resources), $this->unique($this->resourceTemplates), $this->unique($this->prompts), $this->unique($this->apps));
    }

    private function normaliseTool(Tool|string|Closure $tool, ?string $name): ToolDefinition
    {
        if ($tool instanceof Tool) {
            return $tool->compile();
        }

        return Tool::make($name ?? $this->classKey($tool))->handle($tool)->compile();
    }

    private function normaliseResource(Resource|string|Closure $resource, ?string $uri): ResourceDefinition
    {
        if ($resource instanceof Resource) {
            return $resource->compile();
        }

        return Resource::make($uri ?? throw new \InvalidArgumentException('A URI is required for class/closure resource handlers.'))->handle($resource)->compile();
    }

    private function normaliseApp(App|AppDefinition|string|Closure $app): AppDefinition
    {
        if ($app instanceof AppDefinition) {
            return $app;
        }
        if ($app instanceof App) {
            return $app->compile();
        }

        $factory = $app instanceof Closure ? $app : app()->make($app);
        $resolved = is_callable($factory) ? $factory() : $factory;
        if ($resolved instanceof App) {
            return $resolved->compile();
        }
        if ($resolved instanceof AppDefinition) {
            return $resolved;
        }

        $label = is_string($app) ? "class [{$app}]" : 'closure';
        throw new \InvalidArgumentException("App {$label} must return a Fluent App builder or AppDefinition.");
    }

    private function classKey(string|Closure $handler): string
    {
        if ($handler instanceof Closure) {
            throw new \InvalidArgumentException('A stable name is required when registering a closure directly.');
        }

        return Str::kebab(class_basename($handler));
    }

    /** @param list<DefinitionContract> $definitions @return list<\Padosoft\AskMyDocsMcpPack\Contracts\V2\DefinitionContract> */
    private function unique(array $definitions): array
    {
        $byKey = [];
        foreach ($definitions as $definition) {
            $byKey[$definition->key()] = $definition;
        }
        ksort($byKey, SORT_STRING);

        return array_values($byKey);
    }
}
