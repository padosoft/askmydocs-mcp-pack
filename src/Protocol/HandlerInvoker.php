<?php

namespace Padosoft\AskMyDocsMcpPack\Protocol;

use Closure;
use Illuminate\Contracts\Container\Container;

final class HandlerInvoker
{
    public function __construct(private readonly Container $container) {}

    public function invoke(mixed $handler, McpRequest $request): mixed
    {
        $callable = $this->resolve($handler);
        $reflection = is_array($callable)
            ? new \ReflectionMethod($callable[0], $callable[1])
            : new \ReflectionFunction(Closure::fromCallable($callable));
        $parameters = $reflection->getParameters();
        if ($parameters === []) {
            return $callable();
        }

        $first = $parameters[0]->getType();
        $firstValue = $first instanceof \ReflectionNamedType && is_a($first->getName(), McpRequest::class, true)
            ? $request
            : $request->arguments;

        return count($parameters) > 1 ? $callable($firstValue, $request) : $callable($firstValue);
    }

    private function resolve(mixed $handler): callable
    {
        if ($handler instanceof Closure) {
            return $handler;
        }
        if (is_string($handler)) {
            $instance = $this->container->make($handler);
            foreach (['handle', 'invoke', '__invoke'] as $method) {
                if (is_callable([$instance, $method])) {
                    return [$instance, $method];
                }
            }
        }
        if (is_callable($handler)) {
            return $handler;
        }

        throw new \LogicException('MCP handler is not callable and exposes no handle(), invoke() or __invoke() method.');
    }
}
