<?php

declare(strict_types=1);

namespace Statington\Database;

use PDOStatement;
use Statington\Statington;

final class StatementProxy
{
    private array $boundValues = [];

    public function __construct(private PDOStatement $statement, private string $sql)
    {
    }

    public function statement(): PDOStatement
    {
        return $this->statement;
    }

    public function execute(?array $params = null): bool
    {
        $startedAt = microtime(true);
        try {
            $result = $this->statement->execute($params);
        } catch (\Throwable $error) {
            Statington::recordQueryError($this->sql, $params ?? $this->boundValues, $error, round((microtime(true) - $startedAt) * 1000, 2));
            throw $error;
        }

        Statington::recordQuery(
            $this->sql,
            $params ?? $this->boundValues,
            round((microtime(true) - $startedAt) * 1000, 2),
            $this->statement->rowCount()
        );

        return $result;
    }

    public function bindValue(string|int $param, mixed $value, int $type = 0): bool
    {
        $this->boundValues[$param] = $value;

        return $type === 0
            ? $this->statement->bindValue($param, $value)
            : $this->statement->bindValue($param, $value, $type);
    }

    public function bindParam(string|int $param, mixed &$var, int $type = 0, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        $this->boundValues[$param] = $var;

        return $this->statement->bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->statement->{$method}(...$args);
    }
}
