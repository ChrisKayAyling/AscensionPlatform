<?php

namespace DataStorageObjects;

/**
 * SQLite3 connector for application repositories.
 *
 * Rewritten on top of PDO with prepared statements throughout: the previous
 * implementation wrapped raw SQLite3::query() with no parameter binding,
 * which meant every repository built queries via string interpolation - a
 * SQL injection hole in e.g. the ControlPanel login. It also only ever
 * returned a single row (fetchArray()), so nothing using it could fetch a
 * result set.
 */
class SQLiteConnector
{
    private \PDO $handle;

    public function __construct()
    {
        $this->connect();
    }

    public function connect(): bool
    {
        $this->handle = new \PDO('sqlite:' . ROOT . DS . 'etc' . DS . 'db.db');
        $this->handle->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->handle->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        return true;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|false
     */
    public function queryOne(string $sql, array $params = [])
    {
        $statement = $this->handle->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return $row === false ? false : $row;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function queryAll(string $sql, array $params = []): array
    {
        $statement = $this->handle->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * Runs an INSERT/UPDATE/DELETE and returns the affected row count.
     *
     * @param array<string|int, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->handle->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    public function lastInsertId(): string
    {
        return $this->handle->lastInsertId();
    }

    public function inTransaction(): bool
    {
        return $this->handle->inTransaction();
    }

    public function beginTransaction(): bool
    {
        return $this->handle->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->handle->commit();
    }

    public function rollBack(): bool
    {
        return $this->handle->rollBack();
    }
}
