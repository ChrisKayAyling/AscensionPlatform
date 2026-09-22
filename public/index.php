<?php

use Ascension\Core;

require_once __DIR__ . '/../vendor/autoload.php';

Core::$Debug = false;
Core::$TemplateDevelopmentMode = true;

try {
    session_start();

    Core::addCustomTemplate('Header', 'components/header.twig');
    Core::addCustomTemplate('Navigation', 'components/navigation.twig');
    Core::addCustomTemplate('Footer', 'components/footer.twig');

    // Support CLI-based invocation: `php public/index.php Home main`.
    if (PHP_SAPI === 'cli' && isset($argv[1], $argv[2])) {
        Core::$Route['controller'] = $argv[1];
        Core::$Route['method'] = $argv[2];
    }

    // Requests routed to the API controller are always JSON, regardless of the
    // inbound Content-Type header - this used to only force JSON when the
    // *request* itself was JSON, which meant a plain GET to an API endpoint
    // rendered an HTML page instead of a JSON response.
    $requestPath = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', '/');
    $pathSegments = $requestPath === '' ? [] : explode('/', $requestPath);
    $firstSegment = $pathSegments[0] ?? '';
    $isVersionSegment = (bool)preg_match('/^v\d+$/i', $firstSegment);
    $controllerSegment = $isVersionSegment ? ($pathSegments[1] ?? '') : $firstSegment;
    $forceJson = strcasecmp($controllerSegment, 'API') === 0;

    Core::ascend(forceJSONResponseType: $forceJson);
} catch (\Throwable $e) {
    $statusCode = $e->getCode();
    if ($statusCode < 400 || $statusCode > 599) {
        $statusCode = 500;
    }
    new Ascension\ExceptionPrinter($e->getMessage(), $statusCode);
}
