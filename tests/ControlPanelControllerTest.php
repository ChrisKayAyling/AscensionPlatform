<?php

namespace Ascension\Tests;

use Ascension\HTTP;
use ControlPanel\Controller\Controller;
use ControlPanel\Repository\Repository;
use PHPUnit\Framework\TestCase;

/**
 * The original version of this suite mocked SQLiteConnector::query() to
 * return the integer 1 to simulate "a matching user row" - which is exactly
 * what the SQL-injectable, plaintext-password loginSubmit() it was testing
 * did in production. It's rewritten here against the reengineered
 * Controller: a mocked Repository returning a hashed password, exercised
 * through password_verify() and a CSRF token, the same way the real request
 * path works.
 */
class ControlPanelControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    /**
     * @param array<string, mixed>|false $user
     */
    private function repositoryReturning($user): Repository
    {
        $repository = $this->getMockBuilder(Repository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findUserByUsername'])
            ->getMock();

        $repository->method('findUserByUsername')->willReturn($user);

        return $repository;
    }

    public function testMainShowsLoginFormAndIssuesCsrfTokenWhenLoggedOut(): void
    {
        $controller = new Controller(new HTTP([], [], [], []), null, $this->repositoryReturning(false));

        $controller->main();

        $this->assertSame(['ControlPanel/login.twig'], $controller->templates);
        $this->assertNotEmpty($controller->data['CsrfToken']);
        $this->assertSame($_SESSION['CsrfToken'], $controller->data['CsrfToken']);
    }

    public function testMainShowsDashboardWhenAlreadyLoggedIn(): void
    {
        $_SESSION['User'] = 'Administrator';

        $controller = new Controller(new HTTP([], [], [], []), null, $this->repositoryReturning(false));
        $controller->main();

        $this->assertSame(['ControlPanel/default.twig'], $controller->templates);
    }

    public function testLoginSubmitRejectsMissingOrIncorrectCsrfToken(): void
    {
        $_SESSION['CsrfToken'] = 'known-token';

        $controller = new Controller(
            new HTTP([], [], ['Username' => 'Administrator', 'Password' => 'ChangeMe'], []),
            null,
            $this->repositoryReturning(false)
        );

        $result = $controller->loginSubmit();

        $this->assertFalse($result);
        $this->assertArrayNotHasKey('User', $_SESSION);
        $this->assertSame(['ControlPanel/loginfailed.twig'], $controller->templates);
    }

    public function testLoginSubmitRejectsWrongPassword(): void
    {
        $_SESSION['CsrfToken'] = 'known-token';
        $user = ['ID' => 1, 'Username' => 'Administrator', 'PasswordHash' => password_hash('ChangeMe', PASSWORD_DEFAULT)];

        $controller = new Controller(
            new HTTP([], [], [
                'Username' => 'Administrator',
                'Password' => 'wrong-password',
                'csrf_token' => 'known-token',
            ], []),
            null,
            $this->repositoryReturning($user)
        );

        $this->assertFalse($controller->loginSubmit());
        $this->assertArrayNotHasKey('User', $_SESSION);
    }

    public function testLoginSubmitAcceptsCorrectPasswordAndRotatesCsrfToken(): void
    {
        $_SESSION['CsrfToken'] = 'known-token';
        $user = ['ID' => 1, 'Username' => 'Administrator', 'PasswordHash' => password_hash('ChangeMe', PASSWORD_DEFAULT)];

        $controller = new Controller(
            new HTTP([], [], [
                'Username' => 'Administrator',
                'Password' => 'ChangeMe',
                'csrf_token' => 'known-token',
            ], []),
            null,
            $this->repositoryReturning($user)
        );

        $result = $controller->loginSubmit();

        $this->assertTrue($result);
        $this->assertSame('Administrator', $_SESSION['User']);
        $this->assertSame(1, $_SESSION['UserID']);
        $this->assertArrayNotHasKey('CsrfToken', $_SESSION);
        $this->assertSame(['ControlPanel/default.twig'], $controller->templates);
    }

    public function testLogoutClearsSessionAndReissuesCsrfToken(): void
    {
        $_SESSION['User'] = 'Administrator';

        $controller = new Controller(new HTTP([], [], [], []), null, $this->repositoryReturning(false));
        $controller->logout();

        $this->assertArrayNotHasKey('User', $_SESSION);
        $this->assertNotEmpty($controller->data['CsrfToken']);
        $this->assertSame(['ControlPanel/login.twig'], $controller->templates);
    }
}
