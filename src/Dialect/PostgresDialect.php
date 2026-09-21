<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Dialect;

use Kinetis\QueryBuilder\Dialect;
use Kinetis\Persistence\Contract\SqlResult;

final class PostgresDialect implements Dialect
{
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
    public function limitOffset(?int $limit, ?int $offset): string
    {
        $sql = $limit === null ? '' : " LIMIT {$limit}";

        return $offset === null ? $sql : "{$sql} OFFSET {$offset}";
    }

    #[\Override]
    public function sharedLock(): string
    {
        return ' FOR SHARE';
    }

    #[\Override]
    public function admitsLimitedInSubquery(): bool
    {
        return true;
    }

    /**
     * No conflict target, so the row is skipped on a conflict with any
     * unique constraint and with an exclusion constraint as well.
     */
    #[\Override]
    public function insertOrIgnoreClause(array $columns): string
    {
        return ' ON CONFLICT DO NOTHING';
    }

    #[\Override]
    public function upsertClause(array $uniqueBy, array $update): string
    {
        return ' ON CONFLICT (' . implode(', ', array_map($this->quoteIdentifier(...), $uniqueBy)) . ') DO UPDATE SET '
            . implode(', ', array_map(
                function (string $column): string {
                    $quoted = $this->quoteIdentifier($column);

                    return "{$quoted} = EXCLUDED.{$quoted}";
                },
                $update,
            ));
    }

    #[\Override]
    public function insertGetIdClause(string $primaryKey): string
    {
        return ' RETURNING ' . $this->quoteIdentifier($primaryKey);
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
