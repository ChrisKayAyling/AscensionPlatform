<?php

namespace Ascension\Components;

/**
 * Describes a single custom route: the path it matches, the HTTP verbs it
 * accepts, and the Controller/Repository classes that should service it.
 */
class Route
{
    public string $name;
    private string $path;
    private string $controller;
    private string $injected;
    private array $verbs = [];
    private string $method;

    public function __construct(string $name, string $path)
    {
        $this->name = $name;
        $this->path = $path;
    }

    public function controller(string $controller): self
    {
        $this->controller = $controller;
        return $this;
    }

    public function inject(string $injectedClass): self
    {
        $this->injected = $injectedClass;
        return $this;
    }

    public function verbs(array $verbs): self
    {
        $this->verbs = array_map('strtoupper', $verbs);
        return $this;
    }

    public function method(string $method): self
    {
        $this->method = $method;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getInjectedClass(): string
    {
        return $this->injected;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getController(): string
    {
        return $this->controller;
    }

    public function getVerbs(): array
    {
        return $this->verbs;
    }

    public function getMethod(): string
    {
        return $this->method;
    }
}
