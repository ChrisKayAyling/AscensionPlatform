<?php

namespace {Example}\Repository;

use {Example}\Interfaces\IRepository;

/**
 * Class Repository
 * @package {Example}\Repository
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
}
