<?php

namespace Ascension\Tests;

use Ascension\Core;
use Ascension\Exceptions\ControllerNotFound;
use Ascension\Exceptions\EnvironmentSanityCheckFailure;
use PHPUnit\Framework\TestCase;

class CoreTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('ROOT')) {
            define('ROOT', __DIR__ . '/Mock');
        }
        if (!defined('DS')) {
            define('DS', '/');
        }

        require_once __DIR__ . '/Mock/lib/v1/Test/Repository/Repository.php';
        require_once __DIR__ . '/Mock/lib/v1/Test/Controller/Controller.php';
    }

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    /** Property defaults match the documented contract other code relies on. */
    public function testRoutePropertyHasExpectedDefaultShape(): void
    {
        $reflection = new \ReflectionClass(Core::class);
        $route = $reflection->getProperty('Route');

        $value = $route->getValue();

        $this->assertTrue($route->isPublic());
        $this->assertTrue($route->isStatic());
        $this->assertSame('Home', $value['controller']);
        $this->assertSame('main', $value['method']);
    }

    /**
     * __saneSys() previously recorded a missing-extension message in a local
     * $error variable but never threw, since nothing inside its try block
     * actually raised an exception - the check was a complete no-op. It now
     * throws EnvironmentSanityCheckFailure when a required extension really
     * is missing; since curl/simplexml/sqlite3 are present in the test
     * environment, it should simply return without error here.
     */
    public function testSaneSysPassesWhenExtensionsPresent(): void
    {
        $reflection = new \ReflectionClass(Core::class);
        $method = $reflection->getMethod('__saneSys');

        $method->invoke(null);
        $this->addToAssertionCount(1);
    }

    /**
     * __buildRequest() previously lived inline at the top of requestHandler(),
     * which meant middleware (executed before requestHandler()) never saw a
     * parsed request. It's now a standalone step Core::ascend() runs first.
     */
    public function testBuildRequestDetectsJsonContentType(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['REQUEST_URI'] = '/test/method';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $reflection = new \ReflectionClass(Core::class);
        $method = $reflection->getMethod('__buildRequest');
        $method->invoke(null);

        $route = $reflection->getProperty('Route')->getValue();
        $this->assertSame('json', $route['content']);
    }

    public function testFallbackRoutingResolvesUnversionedPathToVersionedMock(): void
    {
        $_SERVER['REQUEST_URI'] = '/test/method';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['CONTENT_TYPE'] = 'text/plain';

        $reflection = new \ReflectionClass(Core::class);
        $reflection->getMethod('__buildRequest')->invoke(null);
        $reflection->getMethod('requestHandler')->invoke(null);

        $route = $reflection->getProperty('Route')->getValue();

        $this->assertSame('Test', $route['controller']);
        $this->assertSame('method', $route['method']);
    }

    public function testFallbackRoutingThrowsControllerNotFoundForUnknownController(): void
    {
        $_SERVER['REQUEST_URI'] = '/NoSuchController/main';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['CONTENT_TYPE'] = 'text/plain';

        $this->expectException(ControllerNotFound::class);

        $reflection = new \ReflectionClass(Core::class);
        $reflection->getMethod('__buildRequest')->invoke(null);
        $reflection->getMethod('requestHandler')->invoke(null);
    }

    public function testInjectAndRemoveResource(): void
    {
        $reflection = new \ReflectionClass(Core::class);

        $reflection->getMethod('__injectResource')->invoke(null, 'TestResource', ['key' => 'value']);
        $resources = $reflection->getProperty('Resources')->getValue();
        $this->assertArrayHasKey('TestResource', $resources);

        $reflection->getMethod('__removeResource')->invoke(null, 'TestResource');
        $resources = $reflection->getProperty('Resources')->getValue();
        $this->assertArrayNotHasKey('TestResource', $resources);
    }

    public function testInjectResourceDoesNotOverwriteAnExistingKey(): void
    {
        $reflection = new \ReflectionClass(Core::class);
        $inject = $reflection->getMethod('__injectResource');

        $this->assertTrue($inject->invoke(null, 'DuplicateResource', 'first'));
        $this->assertFalse($inject->invoke(null, 'DuplicateResource', 'second'));

        $resources = $reflection->getProperty('Resources')->getValue();
        $this->assertSame('first', $resources['DuplicateResource']);

        $reflection->getMethod('__removeResource')->invoke(null, 'DuplicateResource');
    }

    public function testAddCustomTemplateRegistersOverride(): void
    {
        Core::addCustomTemplate('Header', 'components/header.twig');

        $reflection = new \ReflectionClass(Core::class);
        $templating = $reflection->getProperty('TwigCustomTemplating')->getValue();

        $this->assertSame('components/header.twig', $templating['Header']);
    }

    public function testGetCommonReturnsServerAndDateBlocks(): void
    {
        $_SERVER['SERVER_ADDR'] = '127.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit';

        $reflection = new \ReflectionClass(Core::class);
        $method = $reflection->getMethod('getCommon');
        $values = $method->invoke(null);

        $this->assertArrayHasKey('Server', $values);
        $this->assertArrayHasKey('SERVER_ADDR', $values['Server']);
        $this->assertArrayHasKey('General', $values);
        $this->assertArrayHasKey('Year', $values['General']);
    }
}
