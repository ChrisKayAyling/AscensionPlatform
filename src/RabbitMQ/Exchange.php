<?php

namespace Ascension\RabbitMQ;

class Exchange
{
    /**
     * @var $action - An action.
     */
    public $action;

    /**
     * @var $unit - A unit.
     */
    public $unit;

    /**
     * @var $exchange - An exchange for the worker to listen on.
     */
    public $exchange;

    /**
     * @var $type - The type of queue in use.
     */
    public $type;

    /**
     * @var $routeKey - The route key.
     */
    public $routeKey;
}
