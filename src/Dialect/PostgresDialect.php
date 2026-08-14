<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Dialect;

use Kinetis\QueryBuilder\CompiledQuery;
use Kinetis\QueryBuilder\Dialect;
use Kinetis\Persistence\Contract\SqlResult;

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
        // Fast path for the overwhelmingly common shape — a plain,
        // unqualified identifier with nothing to escape. Identifier
        // quoting runs several times per compiled query, so the
        // explode/array_map/implode machinery below is reserved for
        // identifiers that actually need it.
        if (!str_contains($identifier, '.') && !str_contains($identifier, '"')) {
            return '"' . $identifier . '"';
        }

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
    public function extractInsertedId(SqlResult $result, string $primaryKey): int|string|null
    {
        $value = $result->fetchRow()[$primaryKey] ?? null;

        return is_int($value) || is_string($value) ? $value : null;
    }

    #[\Override]
    public function literalFor(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        // Same policy as MySqlDialect: strings (and everything else)
        // always bind — the native pgsql driver's execute() uses real
        // server-side parameters, so inlining buys nothing worth the
        // escaping responsibility.
        return null;
    }
}
