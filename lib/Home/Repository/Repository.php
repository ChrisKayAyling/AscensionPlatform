<?php

namespace Home\Repository;

use Home\Interfaces\IRepository;

/**
 * Class Repository
 * @package Home\Repository
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
     * Example query used by the Home page to demonstrate the DataStorageObjects
     * connector. Uses the properly registered 'db' connector (a PDO-backed
     * DataStorageObjects\SQLiteConnector) rather than Core's internal bootstrap
     * handle, and returns plain rows a Twig template can iterate directly.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getExampleRows(): array
    {
        if (!isset($this->dataStorage['db'])) {
            return [];
        }

        return $this->dataStorage['db']->queryAll('SELECT * FROM main');
    }
}
