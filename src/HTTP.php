<?php

namespace Ascension;

/**
 * Class HTTP
 *
 * Read-only snapshot of the inbound request, handed to every
 * Controller/Repository pair by Core::requestHandler().
 *
 * @package Ascension
 */
class HTTP
{
    /**
     * @var array|null
     */
    public $Server = null;

    /**
     * @var array|null
     */
    public $Files = null;

    /**
     * @var mixed
     */
    public $data = null;

    /**
     * @var array
     */
    public $filters = array();

    public function __construct($Server, $Files, $data, $filters)
    {
        $this->Server = $Server;
        $this->Files = $Files;
        $this->data = $data;
        $this->filters = $filters;
    }
}
