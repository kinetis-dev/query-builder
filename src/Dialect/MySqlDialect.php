<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Dialect;

use Kinetis\QueryBuilder\CompiledQuery;
use Kinetis\QueryBuilder\Dialect;
use Amp\Mysql\MysqlResult;
use Amp\Postgres\PostgresResult;
use LogicException;

final class MySqlDialect implements Dialect
{
    /**
     * Splits on "." and quotes each segment separately — a qualified
     * "orders.total" must become `orders`.`total`, not a single literal
     * column named `orders.total` (which MySQL rejects outright: caught
     * against a real MySQL container, not assumed, when this package's
     * own join() verification failed with "Unknown column 'orders.total'").
     */
    #[\Override]
    public function quoteIdentifier(string $identifier): string
    {
        return implode('.', array_map(
            static fn (string $part): string => '`' . str_replace('`', '``', $part) . '`',
            explode('.', $identifier),
        ));
    }

    #[\Override]
    public function insertGetIdQuery(string $table, array $data, string $primaryKey): CompiledQuery
    {
        $columns = array_keys($data);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            implode(', ', array_fill(0, count($columns), '?')),
        );

        return new CompiledQuery($sql, array_values($data));
    }

    #[\Override]
    public function extractInsertedId(MysqlResult|PostgresResult $result, string $primaryKey): ?int
    {
        if (!$result instanceof MysqlResult) {
            throw new LogicException('MySqlDialect::extractInsertedId() requires a MysqlResult.');
        }

        return $result->getLastInsertId();
    }
}
