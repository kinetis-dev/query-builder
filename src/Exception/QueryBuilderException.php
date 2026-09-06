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

    public static function cursorColumnMissingFromRow(string $cursorRowKey): self
    {
        return new self(
            "cursorPaginate() could not read \"{$cursorRowKey}\" back off the row it just returned. "
            . 'The cursor column has to reach the result under exactly that name for its value to be '
            . 'readable.',
        );
    }
}
