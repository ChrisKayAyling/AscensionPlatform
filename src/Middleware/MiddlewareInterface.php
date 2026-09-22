<?php

namespace Ascension\Middleware;

use Ascension\HTTP;

/**
 * A single link in Core's middleware chain.
 *
 * Implementations should call $next() to continue the chain, or throw an
 * \Ascension\Exceptions\HttpException (with the desired HTTP status code) to
 * halt it - e.g. a missing/invalid API key. The exception is rendered by
 * Core::__loader()'s HttpException handler as either JSON or the exception
 * template, matching the route's content type.
 */
interface MiddlewareInterface
{
    public function handle(?HTTP $request, callable $next): void;
}
