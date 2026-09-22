<?php

namespace Ascension\Components;

/**
 * Holds the set of custom routes registered by the application (in addition
 * to the framework's default PSR-0/PSR-4 directory-based routing fallback).
 */
class RoutingConfiguration
{
    /**
     * @var Route[]
     */
    private array $routes = [];

    public function add(string $name, string $path): Route
    {
        $route = new Route($name, $path);
        $this->routes[$name] = $route;
        return $route;
    }

    /**
     * @return Route[]
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Returns a plain-array summary of every registered route, suitable for
     * rendering (e.g. in the admin Routes screen) without exposing the
     * Route objects themselves.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describe(): array
    {
        $summary = [];

        foreach ($this->routes as $route) {
            $summary[] = [
                'name' => $route->getName(),
                'path' => $route->getPath(),
                'controller' => $route->getController(),
                'method' => $route->getMethod(),
                'verbs' => $route->getVerbs(),
            ];
        }

        return $summary;
    }
}
