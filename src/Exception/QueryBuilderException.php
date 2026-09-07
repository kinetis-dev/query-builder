<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Exception;

use RuntimeException;

final class QueryBuilderException extends RuntimeException
{
    /**
     * update()/delete() compile the table and the WHERE clause only.
     * $clauses names the state the caller accumulated that they would
     * drop — dropping it would widen the statement to every row the
     * WHERE clause alone matches.
     *
     * @param list<string> $clauses
     */
    public static function unsupportedMutationClauses(string $method, array $clauses): self
    {
        return new self(
            "{$method} compiles the table and the WHERE clause only, so " . implode(', ', $clauses)
            . ' would be dropped from the statement and it would affect every row the WHERE clause '
            . 'matches. Build the mutation on a Query carrying only table() and '
            . 'where()/whereIn()/whereRaw() calls.',
        );
    }

    public static function mutationNeedsAPredicate(string $method): self
    {
        return new self(
            "{$method} needs a where()/whereIn()/whereRaw() predicate: with none it affects every row in "
            . 'the table. Run a deliberate whole-table statement as raw SQL through the connection itself.',
        );
    }

    /**
     * Without an order the server may return rows in any order it likes,
     * so two pages of the same query can repeat or skip rows. Which
     * column makes a page stable is the caller's own choice, so
     * paginate() requires one rather than inventing it.
     */
    public static function paginationNeedsAnOrder(): self
    {
        return new self(
            'paginate() needs an orderBy()/orderByRaw() on the query: without one the server may return '
            . 'rows in any order, so a later page can repeat or skip rows from an earlier one. Order by a '
            . 'key unique across the result set.',
        );
    }

    public static function cursorColumnMissingFromRow(string $cursorRowKey): self
    {
        return new self(
            "cursorPaginate() could not read \"{$cursorRowKey}\" back off the row it just returned. "
            . 'The cursor column has to reach the result under exactly that name for its value to be '
            . 'readable.',
        );
    }
}
