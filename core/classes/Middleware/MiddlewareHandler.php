<?php

use DI\Container;

class MiddlewareHandler extends Instanceable
{
    /**
     * @var class-string<AbstractMiddleware>[]
     */
    private array $middleware = [];

    /**
     * Register a middleware class.
     *
     * @param class-string<AbstractMiddleware> $class
     */
    public function register(string $class): void
    {
        $this->middleware[] = $class;
    }

    /**
     * Get all registered middleware classes.
     *
     * @return class-string<AbstractMiddleware>[]
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function call(MiddlewareType $type, Container $container)
    {
        $middlewareClasses = $this->getMiddleware();

        foreach ($middlewareClasses as $class) {
            $middleware = $container->get($class);

            if ($middleware->type() === $type) {
                $container->call([$middleware, 'handle']);
            }
        }
    }
}
