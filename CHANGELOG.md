# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org/).

## [2.0.0] - Unreleased

Initial release of AscensionPlatform: merges the Ascension CMS and
Ascension-Core routing projects into a single, versioned codebase, with an
administration system, API key support, and an integrated QA toolchain.

### Added

- Administration system for managing platform routes and settings.
- API key issuance/revocation, enforced via middleware on API routes.
- QA toolchain: PHPUnit, PHP_CodeSniffer (PSR-12, checkstyle report),
  phpDocumentor and Swagger/OpenAPI generation for API endpoints, with
  reports surfaced in the admin area.
- `build.sh`: a single entry point to install dependencies, initialise the
  database, and run every QA tool.
- `bin/build.php` / `build.phar` (`./build.sh phar`): the same toolchain
  runner as a dependency-free, portable PHP CLI - copy the compiled
  `build.phar` to any machine with a PHP binary and run it against any
  checkout via `--root`.
- `db/schema.sql` / `db/setup.php`: reproducible database schema and seeding,
  replacing hand-maintained example data.

### Fixed

Carried over from the two source projects and fixed as part of the merge:

- The web entry point called `Core::addDataStorageObjects()`, a method that
  did not exist on `Core` - every request fataled before reaching routing.
  Data connectors are now loaded automatically as part of `Core::ascend()`.
- `Core::__loadSettings()` looked for a bootstrap database at
  `sqlite/core.sqlite`, a path that never existed anywhere in either project;
  `AppSettings`, `DataConnectors` and `MessageQueues` silently never loaded.
  Unified onto the one database the platform actually ships and seeds,
  `etc/db.db`.
- `Core::__saneSys()` recorded a missing-PHP-extension message in a local
  variable but never threw - a missing `curl`/`simplexml`/`sqlite3` extension
  failed silently and only surfaced later as a confusing, unrelated fatal.
- The ControlPanel login built its SQL query via `sprintf()` string
  interpolation (a SQL injection hole) and compared plaintext passwords.
  Replaced with parameterised queries and `password_hash`/`password_verify`,
  plus a CSRF token on the login form and session ID regeneration on login/logout.
- `DataStorageObjects\SQLiteConnector` wrapped `SQLite3::query()` directly
  with no parameter binding and only ever returned a single row. Rewritten on
  PDO with prepared statements and proper single-row/multi-row/write methods.
- `Route::getController()` / `getInjectedClass()` read `[0]` off values that
  were never stored as arrays - on a string class name this silently returned
  just its first character, breaking every custom route. Routes now store
  and return the class name directly.
- `RoutingConfiguration::list()` contained an unconditional `die()` inside its
  loop, making it unusable; replaced with `describe()`, used by the admin
  Routes screen.
- The middleware chain ran *before* the HTTP request object existed, so
  middleware (e.g. an API key check) only ever saw `null`. Request parsing
  now happens once, before data connectors and middleware run.
- The exception page echoed the raised exception message into HTML
  unescaped; now passed through `htmlspecialchars()`.
- Every custom `AscensionException` subclass duplicated the same constructor
  with an unqualified `Throwable` type-hint (resolving to a non-existent
  `Ascension\Exceptions\Throwable`); consolidated onto one base class.
- `Core::$Debug` defaulted to `true`, which dumped the full `Core::$Resources`
  array (including database handles and settings) via Kint on every request.
  Now defaults to `false`.
- Removed a disabled "telemetry" call that phoned a base64-encoded external
  URL on every boot to check a license status.
- The API controller carried `use` imports for `CMA\DatabaseConnector\MSSQLConnector`
  and `Logging\LOG_CATEGORY`/`LogObject` - classes that exist in neither
  source project. Removed as leftover, unrelated dead imports.

### Changed

- The Ascension-Core routing/templating library is no longer a separate
  composer package fetched from GitHub at install time; it now lives at
  `src/` in this repository.
- Front-end assets (Bootstrap, icons, jQuery) are now loaded from a CDN
  rather than a ~70MB vendored theme bundle committed to the repository.
