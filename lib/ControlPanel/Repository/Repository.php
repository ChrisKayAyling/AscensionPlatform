<?php

namespace ControlPanel\Repository;

use ControlPanel\Interfaces\IRepository;

/**
 * Class Repository
 * @package ControlPanel\Repository
 */
class Repository implements IRepository
{
    /** @var array */
    protected $dataStorage;

    /** @var object */
    protected $settings;

    public function __construct($dataStorage, $settings)
    {
        $this->dataStorage = $dataStorage;
        $this->settings = $settings;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function findUserByUsername(string $username)
    {
        if (!isset($this->dataStorage['db'])) {
            return false;
        }

        return $this->dataStorage['db']->queryOne(
            'SELECT ID, Username, PasswordHash FROM users WHERE Username = :username',
            [':username' => $username]
        );
    }
}
