<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Dialect;

use Kinetis\QueryBuilder\CompiledQuery;
use Kinetis\QueryBuilder\Dialect;
use Amp\Mysql\MysqlResult;
use Amp\Postgres\PostgresResult;

final class PostgresDialect implements Dialect
{
    /**
     * Splits on "." and quotes each segment separately — see
     * MySqlDialect::quoteIdentifier() for why this matters: a qualified
     * "orders.total" must become "orders"."total", not one literal column
     * named "orders.total".
     */
    #[\Override]
    public function quoteIdentifier(string $identifier): string
    {
        return implode('.', array_map(
            static fn (string $part): string => '"' . str_replace('"', '""', $part) . '"',
            explode('.', $identifier),
        ));
    }

    #[\Override]
    public function insertGetIdQuery(string $table, array $data, string $primaryKey): CompiledQuery
    {
        $columns = array_keys($data);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) RETURNING %s',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            implode(', ', array_fill(0, count($columns), '?')),
            $this->quoteIdentifier($primaryKey),
        );

        return new CompiledQuery($sql, array_values($data));
    }

    #[\Override]
    public function extractInsertedId(MysqlResult|PostgresResult $result, string $primaryKey): int|string|null
    {
        $value = $result->fetchRow()[$primaryKey] ?? null;

        return is_int($value) || is_string($value) ? $value : null;
    }
}
