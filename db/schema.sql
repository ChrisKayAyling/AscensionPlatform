-- AscensionPlatform core schema (etc/db.db)
--
-- Replaces the two divergent, half-used schemas from the source projects
-- (a `sqlite/core.sqlite` path Core::__loadSettings() looked for but which
-- never existed, and the `etc/db.db` the CMS actually shipped/seeded). This
-- is now the single source of truth, applied via `./build.sh db:init`.

CREATE TABLE IF NOT EXISTS users (
    ID INTEGER PRIMARY KEY AUTOINCREMENT,
    Username TEXT NOT NULL UNIQUE,
    PasswordHash TEXT NOT NULL,
    CreatedAt TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS settings (
    ID INTEGER PRIMARY KEY AUTOINCREMENT,
    Name TEXT NOT NULL,
    Value TEXT,
    "Group" TEXT NOT NULL,
    Environment TEXT NOT NULL DEFAULT 'Development'
);

CREATE TABLE IF NOT EXISTS DataConnectors (
    ID INTEGER PRIMARY KEY AUTOINCREMENT,
    Alias TEXT NOT NULL,
    Resource TEXT NOT NULL,
    RequiresParameters INTEGER NOT NULL DEFAULT 0,
    Hostname TEXT,
    Database TEXT,
    Username TEXT,
    Password TEXT,
    Environment TEXT NOT NULL DEFAULT 'Development'
);

CREATE TABLE IF NOT EXISTS MessageQueue_Settings (
    ID INTEGER PRIMARY KEY AUTOINCREMENT,
    Exchange TEXT NOT NULL,
    Queue TEXT NOT NULL,
    Key TEXT NOT NULL,
    Value TEXT
);

-- Custom routes manageable from the admin Routes screen. Core's
-- RoutingConfiguration is still populated at boot time (routes are compiled
-- once per request, not re-read from SQL on every match), but the admin UI
-- reads/writes this table and regenerates the registration file.
CREATE TABLE IF NOT EXISTS routes (
    ID INTEGER PRIMARY KEY AUTOINCREMENT,
    Name TEXT NOT NULL UNIQUE,
    Path TEXT NOT NULL,
    Controller TEXT NOT NULL,
    RepositoryClass TEXT NOT NULL,
    Method TEXT NOT NULL,
    Verbs TEXT NOT NULL DEFAULT 'GET',
    Enabled INTEGER NOT NULL DEFAULT 1
);

-- API keys: only a salted hash of the key is ever stored. The Prefix column
-- (first 8 chars of the key) is shown in the admin UI so an administrator can
-- identify a key without the full value ever being persisted or displayed
-- again after creation.
CREATE TABLE IF NOT EXISTS api_keys (
    ID INTEGER PRIMARY KEY AUTOINCREMENT,
    Label TEXT NOT NULL,
    Prefix TEXT NOT NULL,
    KeyHash TEXT NOT NULL,
    CreatedAt TEXT NOT NULL DEFAULT (datetime('now')),
    LastUsedAt TEXT,
    RevokedAt TEXT
);

-- Example table used by the Home controller/DataStorageObjects\SQLiteConnector
-- demonstration query.
CREATE TABLE IF NOT EXISTS main (
    ID INTEGER PRIMARY KEY AUTOINCREMENT,
    Value TEXT
);

INSERT INTO main (Value) SELECT 'First record' WHERE NOT EXISTS (SELECT 1 FROM main);

INSERT INTO settings (Name, Value, "Group", Environment)
SELECT 'SiteName', 'AscensionPlatform', 'General', 'Development'
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE Name = 'SiteName' AND "Group" = 'General');

INSERT INTO DataConnectors (Alias, Resource, RequiresParameters, Environment)
SELECT 'db', 'DataStorageObjects\SQLiteConnector', 0, 'Development'
WHERE NOT EXISTS (SELECT 1 FROM DataConnectors WHERE Alias = 'db' AND Environment = 'Development');
