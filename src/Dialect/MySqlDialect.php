<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Dialect;

use Kinetis\QueryBuilder\CompiledQuery;
use Kinetis\QueryBuilder\Dialect;
use Kinetis\Persistence\Contract\SqlResult;

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
        // Fast path for the overwhelmingly common shape — a plain,
        // unqualified identifier with nothing to escape. Identifier
        // quoting runs several times per compiled query, so the
        // explode/array_map/implode machinery below is reserved for
        // identifiers that actually need it.
        if (!str_contains($identifier, '.') && !str_contains($identifier, '`')) {
            return "`{$identifier}`";
        }

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
    public function extractInsertedId(SqlResult $result, string $primaryKey): ?int
    {
        return $result->getLastInsertId();
    }

    #[\Override]
    public function literalFor(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        // null, float, string, ... — always bound as a real parameter.
        // Floats are excluded deliberately (PHP's (string) cast can
        // produce "NAN"/"INF", neither a valid SQL literal); strings are
        // excluded because a safe string literal depends on connection
        // charset/SQL-mode state this class deliberately knows nothing
        // about — the drivers' own execute() binding handles them.
        return null;
    }
}
