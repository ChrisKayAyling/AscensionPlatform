<?php

namespace Home\Controller;

use Home\Repository\Repository;
use Ascension\HTTP;

/**
 * Class Controller
 * @package Home\Controller
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
        $this->data['DatabaseResult'] = $this->Repository->getExampleRows();

        $this->templates = array('default.twig');
    }
}
