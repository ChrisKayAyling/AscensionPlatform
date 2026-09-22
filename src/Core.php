<?php

namespace Ascension;

use Ascension\Components\RoutingConfiguration;
use Ascension\Exceptions\ControllerNotFound;
use Ascension\Exceptions\DataStorageFailure;
use Ascension\Exceptions\EnvironmentSanityCheckFailure;
use Ascension\Exceptions\FrameworkFailure;
use Ascension\Exceptions\FrameworkSettingsFailure;
use Ascension\Exceptions\HttpException;
use Ascension\Exceptions\TemplateEngineFailure;
use Ascension\RabbitMQ\BaseFactory;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;
use Spatie\Ignition\Ignition;

/**
 * Ascension\Core - the request lifecycle, routing, templating and resource
 * registry used by every controller/repository pair in the platform.
 *
 * @package Ascension
 */
class Core
{
    /** @var Environment Internal framework templating (layout/exception shells). */
    private static Environment $TwigEnvironment;

    /** @var Environment Application/user-space templating (templates/). */
    private static Environment $UserTwigEnvironment;

    /**
     * @var array|null[] Header/Navigation/Footer overrides, set via addCustomTemplate().
     */
    private static array $TwigCustomTemplating = array(
        'Header' => null,
        'Navigation' => null,
        'Footer' => null
    );

    /**
     * @var array Every resource injected into the framework: Settings, DataStorage
     * connectors, AppSettings, DataConnectors, MessageQueues, Declared-Middleware.
     */
    public static array $Resources = array();

    /** @var array Twig templates queued for rendering by the active controller. */
    private static array $TwigTemplates = array();

    /** @var bool True once a version-prefixed URL (e.g. /v1/Home) has been detected. */
    private static bool $VersionedCodebase = false;

    /** @var array Data handed to Twig for rendering, populated by the controller. */
    private static array $ViewData;

    /**
     * @var bool Enable/disable the Kint debug dump of Core::$Resources on every
     * request. Defaults to FALSE: the previous TRUE default meant every request -
     * including production ones - dumped DataStorage handles and settings.
     */
    public static bool $Debug = false;

    /** @var bool Force 'application/json' response type regardless of routing. */
    private static bool $ForceJSONResponse = false;

    /** @var bool Enable/disable the Common (server/session/date) helper block on XHR calls. */
    public static bool $EnableCommonHelpers = false;

    /** @var bool Enable/disable Twig's development (uncached) mode. */
    public static bool $TemplateDevelopmentMode = true;

    /** @var RoutingConfiguration */
    public static RoutingConfiguration $Routes;

    /** @var array Parsed request body / query data. */
    public static array $UserData = array();

    /** @var HTTP|null Compatibility layer - built once per request in __buildRequest(). */
    private static ?HTTP $HTTP = null;

    /** @var array Default route, overwritten during request matching. */
    public static array $Route = array(
        'version' => 'v1',
        'controller' => 'Home',
        'method' => 'main',
        'id' => 0,
        'content' => 'plain'
    );

    /** @var array Accessor for the instantiated Controller/Repository pair. */
    public static array $Accessor = [];

    public static $RestClient;

    /**
     * Boots the framework: sanity checks, settings, data connectors, middleware,
     * routing/dispatch, and output.
     *
     * @throws \Exception
     */
    public static function ascend(
        bool $DisableDataConnectors = false,
        bool $DisableIgnitionDebug = false,
        bool $forceJSONResponseType = false
    ): void {
        try {
            if (!$DisableIgnitionDebug) {
                Ignition::make()->register();
            }

            if ($forceJSONResponseType) {
                self::$ForceJSONResponse = true;
            }

            self::__saneSys();
            self::__stage('__loadSettings', fn () => self::__loadSettings());

            // The request must exist before data connectors/middleware run, since
            // middleware (e.g. API key auth) needs to inspect it.
            self::__buildRequest();

            if (!$DisableDataConnectors) {
                self::__stage('addDataConnectors', fn () => self::addDataConnectors());
            }

            if (!empty(self::$Resources['Declared-Middleware'])) {
                self::__stage('executeMiddlewareChain', fn () => self::executeMiddlewareChain());
            }

            self::__stage('requestHandler', fn () => self::requestHandler());

            self::$RestClient = new RestClient();

            self::__loader();
            self::__output();
        } catch (HttpException $e) {
            // Thrown from anywhere in the pipeline above - routing, middleware
            // (e.g. a rejected API key), or the controller action itself.
            // Rendered centrally here so it behaves identically regardless of
            // which stage raised it.
            self::__renderHttpException($e);
        }
    }

    /**
     * Runs one boot stage, logging and rethrowing the *original* exception
     * (preserving its class and HTTP status code) rather than wrapping it in
     * a fresh generic \Exception - the previous implementation did the
     * latter at every stage, which meant e.g. a ControllerNotFound's 404
     * code never survived past Core::ascend().
     *
     * @throws \Exception
     */
    private static function __stage(string $label, callable $step): void
    {
        try {
            $step();
        } catch (\Exception $e) {
            error_log(sprintf('Exception raised: Core::%s. %s', $label, $e->getMessage()));
            throw $e;
        }
    }

    /**
     * Renders an HttpException as either a JSON body or the exception.twig
     * template, with the exception's code as the HTTP status.
     */
    private static function __renderHttpException(HttpException $e): void
    {
        http_response_code($e->getCode() ?: 500);

        if ('json' === self::$Route['content']) {
            self::$ViewData = ['message' => $e->getMessage()];
        } else {
            self::$TwigTemplates = isset(self::$TwigEnvironment) ? [self::$TwigEnvironment->load('exception.twig')] : [];
            self::$ViewData = [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ];
        }

        self::__output();
    }

    public static function announce(string $type, $data): void
    {
        if ('routing' === strtolower($type)) {
            self::$Routes = $data;
        }
    }

    public static function addMiddleware(string $middlewareNSClass): void
    {
        self::$Resources['Declared-Middleware'][] = $middlewareNSClass;
    }

    /**
     * Runs the declared middleware chain in order. Each middleware receives the
     * current HTTP request and a $next callback; throwing an HttpException halts
     * the chain and is rendered by __loader()'s HttpException handler (e.g. a 401
     * from an API key middleware).
     *
     * @throws \Exception
     */
    private static function executeMiddlewareChain(): void
    {
        $middlewareStack = self::$Resources['Declared-Middleware'];
        $index = 0;
        $middlewareCount = count($middlewareStack);

        $next = function () use (&$index, &$next, $middlewareStack, $middlewareCount) {
            if ($index < $middlewareCount) {
                $middlewareClass = $middlewareStack[$index];
                $middlewareInstance = new $middlewareClass();
                $index++;
                $middlewareInstance->handle(self::$HTTP, $next);
            }
        };

        $next();
    }

    /**
     * Instantiates every configured DataConnector (as loaded from the core
     * SQLite database's DataConnectors table by __loadSettings()) into
     * self::$Resources['DataStorage'][Alias].
     *
     * @throws \ReflectionException
     */
    public static function addDataConnectors(): void
    {
        if (empty(self::$Resources['DataConnectors'])) {
            return;
        }

        foreach (self::$Resources['DataConnectors'] as $configSection) {
            $configSection = (array)$configSection;

            if (!array_key_exists('Resource', $configSection)) {
                continue;
            }

            try {
                if ($configSection['RequiresParameters']) {
                    self::$Resources['DataStorage'][$configSection['Alias']] = new ($configSection['Resource'])((object)$configSection);
                } else {
                    self::$Resources['DataStorage'][$configSection['Alias']] = new ($configSection['Resource'])();
                }
            } catch (\Exception $e) {
                throw new DataStorageFailure(sprintf(
                    "Core::addDataConnectors error connecting to database. Hostname: %s, Database: %s, Username: %s ",
                    $configSection['Hostname'] ?? '',
                    $configSection['Database'] ?? '',
                    $configSection['Username'] ?? ''
                ), 1);
            }
        }
    }

    /**
     * Confirms every PHP extension the framework depends on is loaded. Previously
     * this recorded a message in $error but never threw - a missing extension
     * failed silently here and surfaced later as a confusing fatal elsewhere.
     *
     * @throws EnvironmentSanityCheckFailure
     */
    private static function __saneSys(): void
    {
        $required = ['curl', 'simplexml', 'sqlite3'];
        $missing = array_values(array_filter($required, static fn ($ext) => !extension_loaded($ext)));

        if (!empty($missing)) {
            $message = 'Required PHP extension(s) not enabled: ' . implode(', ', $missing) . '.';
            error_log('Core::__saneSys, ' . $message);
            throw new EnvironmentSanityCheckFailure($message, 0);
        }
    }

    private static function __setupSys(): void
    {
        date_default_timezone_set('Europe/London');

        header("Cache-Control: no-store, no-cache, must-revalidate");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");

        ini_set("display_errors", "0");
        error_reporting(E_ALL);

        if (!defined('DOCUMENT_ROOT')) {
            define('DOCUMENT_ROOT', $_SERVER['DOCUMENT_ROOT'] ?? '');
        }

        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }

        if (isset($_SERVER['SERVER_ADDR']) && !defined('SERVER_ADDR')) {
            define('SERVER_ADDR', $_SERVER['SERVER_ADDR']);
        } else {
            $_SERVER['SERVER_ADDR'] = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
        }

        if (isset($_SERVER['REMOTE_ADDR']) && !defined('REMOTE_ADDR')) {
            define('REMOTE_ADDR', $_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        }

        if (!defined("COREROOT")) {
            define("COREROOT", __DIR__);
        }

        // Core.php lives at <project root>/src/Core.php in the merged platform
        // layout, so the project root is exactly one level above this file.
        if (!defined('ROOT')) {
            define('ROOT', dirname(__DIR__));
        }

        if (!defined('WEB_ROOT')) {
            define('WEB_ROOT', ROOT . DS . 'public');
        }

        if (!defined('FRAMEWORK_DIR')) {
            define('FRAMEWORK_DIR', ROOT . DS . 'lib');
        }

        $coreCacheDir = ROOT . DS . 'cache' . DS . 'core';
        $userCacheDir = ROOT . DS . 'cache' . DS . 'templates';

        foreach ([$coreCacheDir, $userCacheDir] as $cacheDir) {
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0777, true);
            }
        }

        try {
            $loader = new FilesystemLoader(COREROOT . DS . '..' . DS . 'layout');
            self::$TwigEnvironment = new Environment($loader, array(
                'debug' => self::$TemplateDevelopmentMode,
                'cache' => $coreCacheDir
            ));
            self::$TwigEnvironment->addExtension(new DebugExtension());
        } catch (\Exception $e) {
            error_log(sprintf("Core::__setupSys, throwing exception. Twig templating engine throwing, %s", $e->getMessage()));
            throw new TemplateEngineFailure($e->getMessage(), 0);
        }

        try {
            $loader = new FilesystemLoader(ROOT . DS . 'templates');
            self::$UserTwigEnvironment = new Environment($loader, array(
                'debug' => self::$TemplateDevelopmentMode,
                'cache' => $userCacheDir
            ));
            self::$UserTwigEnvironment->addExtension(new DebugExtension());
        } catch (\Exception $e) {
            error_log(sprintf("Core::__setupSys, throwing exception. Twig templating engine throwing, %s", $e->getMessage()));
            throw new TemplateEngineFailure($e->getMessage(), 0);
        }
    }

    /**
     * @throws FrameworkSettingsFailure
     */
    public static function __loadSettings(): void
    {
        self::__setupSys();

        try {
            $settings = json_decode(file_get_contents(ROOT . DS . 'etc' . DS . 'config.json'));
            self::__injectResource('Settings', $settings);
        } catch (\Exception $e) {
            error_log(sprintf("Core::__loadSettings, throwing exception. issue loading settings file throwing with: %s", $e->getMessage()));
            throw new FrameworkSettingsFailure($e->getMessage(), 0);
        }

        // Bootstrap DB: previously this looked for `sqlite/core.sqlite`, a path
        // that never existed - AppSettings/DataConnectors/MessageQueues silently
        // never loaded. The one database the platform actually ships and seeds
        // is etc/db.db (see db/schema.sql), so that's the single source of truth now.
        try {
            if (extension_loaded('sqlite3')) {
                $dbPath = ROOT . DS . 'etc' . DS . 'db.db';

                if (file_exists($dbPath)) {
                    $core = new \SQLite3($dbPath);
                    self::$Resources['DataStorage']['core'] = $core;

                    $rows = array();
                    $result = $core->query('SELECT * FROM settings');
                    if ($result !== false) {
                        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                            $rows[$row['Environment']][$row['Group'] . '_' . $row['Name']] = (object)array(
                                'Value' => $row['Value'],
                                'Group' => $row['Group']
                            );
                        }
                        self::__injectResource('AppSettings', (object)$rows);
                    }

                    $rows = array();
                    $environment = $settings->Environment ?? 'Development';
                    $statement = $core->prepare('SELECT * FROM DataConnectors WHERE Environment = :environment');
                    $statement->bindValue(':environment', $environment, SQLITE3_TEXT);
                    $result = $statement->execute();
                    if ($result !== false) {
                        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                            $rows[$row['Alias']] = (object)array(
                                'Resource' => $row['Resource'],
                                'RequiresParameters' => $row['RequiresParameters'],
                                'Alias' => $row['Alias'],
                                'Hostname' => $row['Hostname'],
                                'Database' => $row['Database'],
                                'Username' => $row['Username'],
                                'Password' => $row['Password']
                            );
                        }
                        self::__injectResource('DataConnectors', $rows);
                    }

                    $result = $core->query('SELECT * FROM MessageQueue_Settings');
                    if ($result !== false) {
                        $messageQueueSettings = [];
                        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                            $messageQueueSettings[$row['Exchange'] . '_' . $row['Queue'] . '_' . $row['Key']] = $row['Value'];
                        }
                        self::__injectResource('MessageQueues', (object)$messageQueueSettings);
                    }
                }
            }
        } catch (\Exception $e) {
            error_log(sprintf("Core::__loadSettings, throwing exception. issue loading settings from SQLite3 database: %s", $e->getMessage()));
            throw new FrameworkSettingsFailure('Core: Application settings could not be loaded.' . $e->getMessage(), 0);
        }
    }

    /**
     * Parses the inbound request body/content-type once, up front, so both
     * middleware and requestHandler() see a fully-formed HTTP object. Previously
     * this logic lived inline at the top of requestHandler() and self::$HTTP was
     * rebuilt (and briefly left null) at several points during routing.
     */
    private static function __buildRequest(): void
    {
        if (isset($_SERVER['CONTENT_TYPE']) && preg_match('#application/json#i', $_SERVER['CONTENT_TYPE'])) {
            $decodePayload = json_decode(file_get_contents('php://input'), true);
            self::$UserData = $decodePayload ?? array();
            self::$Route['content'] = 'json';
        } else {
            self::$UserData = $_REQUEST;
            self::$Route['content'] = 'plain';
        }

        if (self::$ForceJSONResponse) {
            self::$Route['content'] = 'json';
        }

        self::$HTTP = new HTTP($_SERVER, $_FILES, self::$UserData, self::$Route['id']);
    }

    /**
     * Matches the request against custom routes first, falling back to
     * PSR-0/PSR-4 directory-based routing (/{version}/Controller/method or
     * /Controller/method).
     *
     * @throws ControllerNotFound
     * @throws FrameworkFailure
     */
    public static function requestHandler(): void
    {
        $routeMatch = false;

        if (isset(self::$Routes) && !empty(self::$Routes->getRoutes())) {
            foreach (self::$Routes->getRoutes() as $routeDefinition) {
                preg_match_all('/({\w+})/', $routeDefinition->getPath(), $paramKeyExtraction);
                $paramNames = array_map(
                    static fn ($v) => str_replace(['{', '}'], '', $v),
                    $paramKeyExtraction[0] ?? []
                );

                $regex = preg_replace('/({\w+})+/', '([\w+%]+)', $routeDefinition->getPath());
                $regex = str_replace('/', '\/', $regex);

                preg_match('/^' . $regex . '$/m', $_SERVER['REQUEST_URI'] ?? '', $matches);

                if (empty($matches)) {
                    continue;
                }

                if (!in_array(strtoupper($_SERVER['REQUEST_METHOD']), $routeDefinition->getVerbs(), true)) {
                    http_response_code(405);
                    throw new FrameworkFailure('Request method not allowed for route: ' . $routeDefinition->getName(), 405);
                }

                $routeMatch = true;

                if (in_array(strtolower($_SERVER['REQUEST_METHOD']), ['get', 'delete'], true)) {
                    $paramValues = array_slice($matches, 1);
                    self::$UserData = array_combine($paramNames, $paramValues);
                }

                self::$Route['controller'] = $routeDefinition->getController();
                self::$Route['method'] = $routeDefinition->getMethod();
                self::$HTTP = new HTTP($_SERVER, $_FILES, self::$UserData, self::$UserData);

                $repositoryClass = $routeDefinition->getInjectedClass();
                try {
                    self::$Accessor['Repository'] = new $repositoryClass(self::$Resources['DataStorage'] ?? [], self::$Resources['Settings'] ?? null);
                } catch (\Exception $e) {
                    throw new FrameworkFailure($e->getMessage(), 0);
                }

                $controllerClass = self::$Route['controller'];
                self::$Accessor['Controller'] = new $controllerClass(self::$HTTP, self::$Resources['Settings'] ?? null, self::$Accessor['Repository']);
                break;
            }
        }

        if (!$routeMatch) {
            self::__fallbackRoute();
        }
    }

    /**
     * PSR-0/PSR-4 directory-based fallback routing:
     *   /{version}/Controller/method/filters...   (versioned codebase)
     *   /Controller/method/filters...              (unversioned codebase)
     *
     * @throws ControllerNotFound
     * @throws FrameworkFailure
     */
    private static function __fallbackRoute(): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = array_values(array_filter(explode('/', parse_url($requestUri, PHP_URL_PATH) ?? '/'), static fn ($segment) => $segment !== ''));

        if (empty($path)) {
            $path = ['Home', 'main'];
        }

        $filterPos = 2;

        if (isset($path[0]) && preg_match('/^[a-zA-Z]\d/', $path[0])) {
            self::$VersionedCodebase = true;
            self::$Route['version'] = strtolower($path[0]);
            self::$Route['controller'] = ucfirst(preg_replace('/[^a-zA-z]/', '', $path[1] ?? ''));
            self::$Route['method'] = isset($path[2]) ? preg_replace('/[^a-zA-z]/', '', $path[2]) : 'Home';
            $filterPos = 3;

            if (!is_dir(ROOT . DS . 'lib' . DS . strtolower(self::$Route['version']) . DS . ucfirst(self::$Route['controller']))) {
                throw new ControllerNotFound("Controller '" . ucfirst(self::$Route['controller']) . "' not found.", 404);
            }
        } else {
            $controllerName = ucfirst(preg_replace('/[^a-zA-z]/', '', $path[0] ?? 'Home'));

            if (is_dir(ROOT . DS . 'lib' . DS . $controllerName)) {
                self::$VersionedCodebase = false;
            } elseif (is_dir(ROOT . DS . 'lib' . DS . 'v1' . DS . $controllerName)) {
                self::$VersionedCodebase = false;
                self::$Route['version'] = 'v1';
            } else {
                throw new ControllerNotFound("Controller '" . $controllerName . "' not found in PSR loadable directories.", 404);
            }

            self::$Route['controller'] = $controllerName;
            self::$Route['method'] = isset($path[1]) ? preg_replace('/[^a-zA-z]/', '', $path[1]) : 'main';
        }

        $filters = [];
        if (count($path) > $filterPos) {
            foreach (array_slice($path, $filterPos) as $filterVal) {
                if ($filterVal === '' || str_contains($filterVal, '?')) {
                    continue;
                }

                if (str_contains($filterVal, ':')) {
                    [$filterKey, $filterValue] = explode(':', $filterVal, 2);
                    $filters[$filterKey] = $filterValue;
                    self::$Route['id'] = [$filterKey => $filterValue];
                } else {
                    self::$Route['id'] = (int)$filterVal;
                }
            }
        }

        self::$HTTP = new HTTP($_SERVER, $_FILES, self::$UserData, $filters ?: self::$Route['id']);

        $namespacePrefix = '';
        if (self::$VersionedCodebase) {
            $namespacePrefix = strtolower(self::$Route['version']) . '\\';
        } elseif (is_dir(ROOT . DS . 'lib' . DS . 'v1' . DS . self::$Route['controller'])) {
            $namespacePrefix = 'v1\\';
        }

        $repositoryClass = $namespacePrefix . self::$Route['controller'] . '\\Repository\\Repository';
        if (!class_exists($repositoryClass)) {
            throw new FrameworkFailure($repositoryClass . ' Repository class not found', 0);
        }

        try {
            self::$Accessor['Repository'] = new $repositoryClass(self::$Resources['DataStorage'] ?? [], self::$Resources['Settings'] ?? null);
        } catch (\Exception $e) {
            throw new FrameworkFailure($e->getMessage(), 0);
        }

        $controllerClass = $namespacePrefix . self::$Route['controller'] . '\\Controller\\Controller';
        if (!class_exists($controllerClass)) {
            throw new FrameworkFailure($controllerClass . ' Controller class not found.', 0);
        }

        self::$Accessor['Controller'] = new $controllerClass(self::$HTTP, self::$Resources['Settings'] ?? null, self::$Accessor['Repository']);
    }

    /**
     * @throws FrameworkFailure
     */
    public static function __loader(): void
    {
        try {
            $method = self::$Route['method'];
            self::$Accessor['Controller']->$method();

            self::$TwigTemplates = self::$Accessor['Controller']->templates ?? [];
            self::$ViewData = isset(self::$Accessor['Controller']->data) ? (array)self::$Accessor['Controller']->data : [];

            if (self::$EnableCommonHelpers) {
                self::$ViewData['Common'] = self::getCommon();
            }

            if (self::$Debug) {
                d('Ascension Core Debug Output');
                d(self::$Resources);
            }
        } catch (HttpException $e) {
            // Re-thrown as-is; rendered centrally by Core::ascend()'s
            // __renderHttpException() so a controller action, middleware, or
            // routing failure are all handled the same way.
            throw $e;
        } catch (\Exception $e) {
            throw new FrameworkFailure($e->getMessage(), 0, $e);
        }
    }

    private static function __output(): void
    {
        if (self::$Route['content'] === 'json') {
            header('Content-Type: application/json');
            echo json_encode(self::$ViewData);
            exit();
        }

        self::$ViewData['Session'] = $_SESSION ?? [];

        $customTemplateResource = [];
        foreach (self::$TwigCustomTemplating as $customTemplateKey => $customTemplateValue) {
            $customTemplateResource[$customTemplateKey] = null !== $customTemplateValue
                ? self::$UserTwigEnvironment->load($customTemplateValue)
                : self::$TwigEnvironment->load('empty.twig');
        }

        $contentRendered = '';
        foreach (self::$TwigTemplates as $viewTemplate) {
            $contentTemplate = is_string($viewTemplate) ? self::$UserTwigEnvironment->load($viewTemplate) : $viewTemplate;
            $contentRendered .= $contentTemplate->render(['data' => self::$ViewData]);
        }

        $mainTemplate = self::$TwigEnvironment->load('layout.twig');
        $mainRendered = $mainTemplate->render(array(
            'header' => $customTemplateResource['Header']->render(['data' => self::$ViewData]),
            'navigation' => $customTemplateResource['Navigation']->render(['data' => self::$ViewData]),
            'body' => $contentRendered,
            'footer' => $customTemplateResource['Footer']->render(['data' => self::$ViewData])
        ));

        echo $mainRendered;
        exit();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- kept snake_case; renaming is a public API break.
    public static function create_rmq_worker(
        string $action,
        string $unit,
        string $exchange,
        string $type,
        string $routeKey
    ): void {
        $factory = new BaseFactory();
        $channel = $factory->channel;

        $channel->exchange_declare($exchange, $type, true, true, true);
        $channel->queue_declare($action . '_' . $unit . '_queue', true, true, false, false);
    }

    private static function telemetry(): void
    {
        // Intentionally a no-op: the platform performs no telemetry/phone-home
        // calls. (A previous, already-disabled build called out to an external
        // "license status" endpoint on every boot - removed entirely.)
    }

    private static function getCommon(): array
    {
        $data = array();

        $data['Server']['SERVER_ADDR'] = $_SERVER['SERVER_ADDR'] ?? '';
        $data['Server']['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $data['Server']['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (isset($_SERVER['HTTPS'])) {
            $data['Server']['HTTPS'] = $_SERVER['HTTPS'];
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $data['Session'] = $_SESSION;
            $data['Server']['SESSION_ID'] = session_id();
        }

        $data['General']['DayShort'] = date('D');
        $data['General']['Day'] = date('l');
        $data['General']['DayNumber'] = date('d');
        $data['General']['MonthShort'] = date('M');
        $data['General']['MonthNumber'] = date('m');
        $data['General']['Year'] = date('Y');

        return $data;
    }

    public static function raiseEvent($message, $level = E_USER_NOTICE): bool
    {
        $trace = debug_backtrace();
        $caller = next($trace);

        $msg = $message . ' in ' . ($caller['function'] ?? '?') . ' called from ' . ($caller['file'] ?? '?') . ' on line ' . ($caller['line'] ?? '?') . "\r\n";
        $msg .= 'class:' . ($caller['class'] ?? '') . "\r\n";

        if (isset($caller['object'])) {
            $msg .= 'object: ' . json_encode($caller['object']);
        }

        switch ($level) {
            case E_USER_ERROR:
                syslog(E_ERROR, $msg);
                new ExceptionPrinter($msg);
                exit();

            case E_USER_NOTICE:
            default:
                syslog(E_NOTICE, $msg);
        }

        return true;
    }

    public static function __injectResource($Name, $Resource): bool
    {
        if (!isset(self::$Resources[$Name])) {
            self::$Resources[$Name] = $Resource;
            return true;
        }
        return false;
    }

    public static function __removeResource($Name): bool
    {
        if (isset(self::$Resources[$Name])) {
            unset(self::$Resources[$Name]);
            return true;
        }
        return false;
    }

    /**
     * @param string $Name Header|Navigation|Footer
     * @param string $Path Relative file path within templates/
     */
    public static function addCustomTemplate($Name, $Path): bool
    {
        self::$TwigCustomTemplating[$Name] = $Path;
        return true;
    }
}
