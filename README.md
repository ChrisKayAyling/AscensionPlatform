# AscensionPlatform

A user-friendly CMS with a built-in administration system, API key management
for programmatic access, and an integrated QA toolchain (PHPUnit,
PHP_CodeSniffer, phpDocumentor and Swagger/OpenAPI).

AscensionPlatform merges what were previously two separate projects -
**Ascension** (the CMS) and **Ascension-Core** (its routing/templating core)
- into a single, versioned codebase.

## Requirements

- PHP >= 8.1, with the following extensions: `curl`, `simplexml`, `sqlite3`, `pdo_sqlite`
- Composer

```
apt-get install php php-cli php-curl php-sqlite3 php-simplexml
```

## Getting started

```
./build.sh install   # composer install (bootstraps composer.phar if needed)
./build.sh db:init    # creates etc/db.db and seeds a default admin user
php -S localhost:8080 -t public
```

Then open `http://localhost:8080/` for the site and
`http://localhost:8080/ControlPanel` for the admin area.

The seeded admin account is `Administrator` / `ChangeMe` - **change this
password immediately**, or seed a different one up front:

```
ASCENSION_ADMIN_USER=you ASCENSION_ADMIN_PASSWORD='something-strong' ./build.sh db:init
```

## Project layout

| Path | Purpose |
|------|---------|
| `src/` | The framework core: request lifecycle, routing, templating, middleware, exceptions (namespace `Ascension\`). |
| `lib/` | Application areas (Home, ControlPanel, API, DataStorageObjects), PSR-0 namespaced. |
| `public/` | Web root. |
| `templates/`, `layout/` | Twig templates - `layout/` is the framework shell, `templates/` is application-owned and overridable. |
| `etc/` | `config.json` and the SQLite database (`db.db`, generated - see `db/schema.sql`). |
| `db/` | `schema.sql` and `setup.php`, the source of truth for `etc/db.db`. |
| `tests/` | PHPUnit suite. |
| `tools/` | QA tool configuration (`phpcs.xml`). |
| `build.sh` | Runs the QA toolchain - see below. |

## Developing new areas

Each business area under `lib/` follows the same Controller/Repository
pattern:

```php
public function __construct(HTTP $Request, $settings, Repository $Repository)
{
    $this->Request = $Request;
    $this->settings = $settings;
    $this->Repository = $Repository;
}
```

| Object | Description |
|--------|-------------|
| `Request` | Access to the inbound request (GET/POST/PUT/etc). |
| `settings` | Object/property access to `etc/config.json`. |
| `Repository` | Data access for this area, backed by `Core::$Resources['DataStorage']`. |

Routing falls back to PSR-0 directory conventions: `/Controller/method` (or
`/v1/Controller/method` for versioned areas) maps to
`lib/Controller/Controller.php`'s `method()`. Custom routes can be
registered against `Ascension\Components\RoutingConfiguration` and are
matched first.

## API access

Requests to any `API` controller (e.g. `/API/...`, `/v1/API/...`) always
return JSON. API key issuance and enforcement live in the admin Routes/API
Keys screens.

## QA toolchain

```
./build.sh test       # PHPUnit -> build/reports/junit.xml
./build.sh lint        # PHP_CodeSniffer (PSR-12) -> build/reports/checkstyle.xml
./build.sh docs        # phpDocumentor -> build/reports/docs/
./build.sh swagger      # OpenAPI from lib/ annotations -> build/reports/openapi.json
./build.sh all          # everything above, in order
```

Reports are also surfaced inside the admin Control Panel.

## Versioning

Releases are tagged in git (`vMAJOR.MINOR.PATCH`, semver). See `CHANGELOG.md`.

## Security

If you discover a security vulnerability, please email
chris@chriskayayling.co.uk.

## License

MIT.
