<?php

namespace API\Repository;

use API\Interfaces\IRepository;

/**
 * Class Repository
 * @package API\Repository
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
