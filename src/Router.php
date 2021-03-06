<?php

namespace Hyqo\Router;

use Hyqo\Container\Container;
use Hyqo\Http\Header;
use Hyqo\Http\HttpCode;
use Hyqo\Http\Request;
use Hyqo\Http\Response;
use Hyqo\Router\Exception\NotFoundException;
use Hyqo\Router\Mapper\Mapper;
use Hyqo\Router\Route\Route;
use Hyqo\Router\Service\CallableService;

class Router
{
    /**
     * @var Container
     */
    private $container;

    /**
     * @var CallableService
     */
    private $callableService;

    protected $routerConfiguration;

    /**
     * @var Mapper
     */
    protected $mapper;

    public function __construct(Container $container, RouterConfiguration $routerConfiguration)
    {
        $this->container = $container;
        $this->routerConfiguration = $routerConfiguration;

        $this->callableService = $container->get(CallableService::class);
    }

    public function match(Request $request): ?Route
    {
        if ($route = $this->routerConfiguration->match($request)) {
            foreach ($route->getAttributes() as $name => $value) {
                $request->attributes->set($name, $value);
            }
        }

        return $route;
    }

    public function handle(Request $request): Response
    {
        $pathInfo = $request->getPathInfo();

        if ($pathInfo !== $sanitizedPathInfo = preg_replace(['#/{2,}#', '#(?<!^)/+$#'], ['/', ''], $pathInfo)) {
            return (new Response(HttpCode::MOVED_PERMANENTLY()))
                ->setHeader(Header::LOCATION, $sanitizedPathInfo);
        }

        try {
            if ($route = $this->match($request)) {
                $pipeline = $this->buildRoutePipeline($route);

                return $pipeline($request) ?? new Response();
            }
        } catch (NotFoundException $e) {
            if (null !== $fallback = $e->getController()) {
                $pipeline = $this->buildPipeline($e->getMiddlewares(), $fallback);

                return $pipeline($request) ?? new Response();
            }
        }

        throw new NotFoundException();
    }

    public function getRoute(string $name): Route
    {
        if (null === $this->mapper) {
            $this->mapper = new Mapper($this->routerConfiguration);
        }

        if (null !== $route = $this->mapper->getRoute($name)) {
            return $route;
        }

        throw new \RuntimeException(sprintf('Cannot find route "%s"', $name));
    }

    public function buildPipeline(array $middlewares, $controller, $fallback = null): Pipeline
    {
        $pipeline = new Pipeline($this->container, $this);

        foreach ($middlewares as $middlewareClassname) {
            $pipeline->pipe($this->container->make($middlewareClassname));
        }

        $callable = $this->callableService->makeCallable($controller);

        $pipeline->pipe(function () use ($callable, $fallback) {
            try {
                return $this->container->call($callable);
            } catch (NotFoundException $e) {
                if (null !== $fallback) {
                    $e->setController($fallback);
                }

                throw $e;
            }
        });

        return $pipeline;
    }

    public function buildRoutePipeline(Route $route): Pipeline
    {
        return $this->buildPipeline($route->getMiddlewares(), $route->getController(), $route->getFallback());
    }
}
