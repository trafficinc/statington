<?php

declare(strict_types=1);

namespace Statington\Database;

use PDO;
use Statington\Statington;

final class PdoProxy
{
    public function __construct(private PDO $pdo)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function exec(string $statement): int|false
    {
        $startedAt = microtime(true);
        try {
            $affectedRows = $this->pdo->exec($statement);
        } catch (\Throwable $error) {
            Statington::recordQueryError($statement, [], $error, $this->durationMs($startedAt));
            throw $error;
        }

        Statington::recordQuery($statement, [], $this->durationMs($startedAt), $affectedRows === false ? null : $affectedRows);

        return $affectedRows;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): mixed
    {
        $startedAt = microtime(true);
        try {
            $statement = $fetchMode === null
                ? $this->pdo->query($query)
                : $this->pdo->query($query, $fetchMode, ...$fetchModeArgs);
        } catch (\Throwable $error) {
            Statington::recordQueryError($query, [], $error, $this->durationMs($startedAt));
            throw $error;
        }

        Statington::recordQuery($query, [], $this->durationMs($startedAt), $statement === false ? null : $statement->rowCount());

        return $statement;
    }

    public function prepare(string $query, array $options = []): StatementProxy|false
    {
        $statement = $this->pdo->prepare($query, $options);
        if ($statement === false) {
            return false;
        }

        return new StatementProxy($statement, $query);
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->pdo->{$method}(...$args);
    }

    private function durationMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }
}
