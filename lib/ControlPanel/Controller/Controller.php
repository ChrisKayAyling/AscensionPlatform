<?php

namespace ControlPanel\Controller;

use ControlPanel\Repository\Repository;
use Ascension\HTTP;

/**
 * Class Controller
 * @package ControlPanel\Controller
 *
 * Reengineered from the original: the previous loginSubmit() built a
 * SELECT ... WHERE Username = '%s' AND Password = '%s' query via sprintf()
 * (a SQL injection hole) and compared plaintext passwords directly. This
 * version uses a parameterised lookup + password_verify() against a hash,
 * plus a CSRF token on the login form and session ID regeneration on
 * privilege change (login/logout).
 */
class Controller
{
    /** @var Repository */
    protected $Repository;

    /** @var mixed */
    public $data;

    /** @var array */
    public $templates = array();

    /** @var HTTP */
    protected $Request;

    /** @var object */
    protected $settings;

    public function __construct(HTTP $Request, $settings, Repository $Repository)
    {
        $this->Request = $Request;
        $this->settings = $settings;
        $this->Repository = $Repository;
    }

    public function main(): void
    {
        $this->templates = array();

        if (isset($_SESSION['User'])) {
            $this->templates[] = 'ControlPanel/default.twig';
        } else {
            $this->data['CsrfToken'] = $this->issueCsrfToken();
            $this->templates[] = 'ControlPanel/login.twig';
        }
    }

    public function loginSubmit(): bool
    {
        $username = $this->Request->data['Username'] ?? '';
        $password = $this->Request->data['Password'] ?? '';
        $token = $this->Request->data['csrf_token'] ?? '';

        if ($username === '' || $password === '' || !$this->verifyCsrfToken($token)) {
            return $this->loginFailed();
        }

        $user = $this->Repository->findUserByUsername($username);

        if ($user === false || !password_verify($password, $user['PasswordHash'])) {
            return $this->loginFailed();
        }

        $this->regenerateSession();
        $_SESSION['User'] = $user['Username'];
        $_SESSION['UserID'] = $user['ID'];
        unset($_SESSION['CsrfToken']);

        $this->templates[] = 'ControlPanel/default.twig';
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        $this->regenerateSession();

        $this->data['CsrfToken'] = $this->issueCsrfToken();
        $this->templates[] = 'ControlPanel/login.twig';
    }

    private function regenerateSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private function loginFailed(): bool
    {
        $this->data['CsrfToken'] = $this->issueCsrfToken();
        $this->templates[] = 'ControlPanel/loginfailed.twig';
        return false;
    }

    private function issueCsrfToken(): string
    {
        if (empty($_SESSION['CsrfToken'])) {
            $_SESSION['CsrfToken'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['CsrfToken'];
    }

    private function verifyCsrfToken(string $token): bool
    {
        return !empty($_SESSION['CsrfToken']) && is_string($token) && hash_equals($_SESSION['CsrfToken'], $token);
    }
}
