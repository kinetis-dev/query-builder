<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Dialect;

use Kinetis\QueryBuilder\Dialect;
use Kinetis\Persistence\Contract\SqlResult;

/**
 * MySQL 8.4 and MariaDB 11.4: every spelling here is one both servers
 * accept.
 */
final class MySqlDialect implements Dialect
{
    /**
     * The largest BIGINT UNSIGNED, which MySQL documents as the LIMIT for
     * "every remaining row": the family has no OFFSET without a LIMIT.
     */
    private const string UNBOUNDED_LIMIT = '18446744073709551615';

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
    public function limitOffset(?int $limit, ?int $offset): string
    {
        if ($offset === null) {
            return $limit === null ? '' : " LIMIT {$limit}";
        }

        return ' LIMIT ' . ($limit ?? self::UNBOUNDED_LIMIT) . " OFFSET {$offset}";
    }

    /**
     * MariaDB rejects FOR SHARE. It accepts NOWAIT after LOCK IN SHARE
     * MODE where MySQL does not, so the shared lock carries no wait
     * modifier.
     */
    #[\Override]
    public function sharedLock(): string
    {
        return ' LOCK IN SHARE MODE';
    }

    /** MySQL 8.4 and MariaDB 11.4 both reject it with error 1235. */
    #[\Override]
    public function admitsLimitedInSubquery(): bool
    {
        return false;
    }

    /**
     * Not INSERT IGNORE, which also downgrades other failures — a NOT NULL
     * or foreign-key violation, a value out of range — to warnings and
     * writes the row anyway. Assigning a column to itself resolves the
     * unique-key conflict and nothing else.
     */
    #[\Override]
    public function insertOrIgnoreClause(array $columns): string
    {
        $column = $this->quoteIdentifier($columns[0]);

        return " ON DUPLICATE KEY UPDATE {$column} = {$column}";
    }

    /**
     * VALUES(col) is the one spelling both servers share: MariaDB has no
     * INSERT ... AS row alias, and MySQL 8.4 accepts VALUES() with
     * deprecation warning 1287. Both match a conflict on any unique key of
     * the table, so $uniqueBy does not reach the SQL.
     */
    #[\Override]
    public function upsertClause(array $uniqueBy, array $update): string
    {
        return ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(
            function (string $column): string {
                $quoted = $this->quoteIdentifier($column);

                return "{$quoted} = VALUES({$quoted})";
            },
            $update,
        ));
    }

    #[\Override]
    public function insertGetIdClause(string $primaryKey): string
    {
        return '';
    }

    #[\Override]
    public function extractInsertedId(SqlResult $result, string $primaryKey): int|string|null
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
        // A float's (string) cast can produce "NAN"/"INF", neither a
        // valid SQL literal, and a safe string literal depends on
        // connection charset/SQL-mode state this class does not know.
        // The drivers' own execute() binding handles both.
        return null;
    }
}
