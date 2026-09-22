<?php

namespace API\Controller;

use API\Repository\Repository;
use Ascension\HTTP;
use OpenApi\Attributes as OA;

/**
 * Class Controller
 * @package API\Controller
 *
 * (The original of this file imported CMA\DatabaseConnector\MSSQLConnector and
 * Logging\LOG_CATEGORY/LogObject - classes that don't exist anywhere in either
 * source project. Dropped as dead/incorrect imports left over from another
 * codebase.)
 */
#[OA\Info(title: 'AscensionPlatform API', version: '1.0.0')]
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

    #[OA\Get(
        path: '/API',
        summary: 'API root - lists available routes.',
        tags: ['API'],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
        ]
    )]
    public function main(): void
    {
        $this->data = array(
            'Information' => 'AscensionPlatform API',
            'Route' => 'Default',
            'AvailableRoutes' => array(
                'You are here' => '/',
            ),
        );
    }
}
