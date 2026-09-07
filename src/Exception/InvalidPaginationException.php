<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Exception;

use Kinetis\Http\Exception\HttpStatusExceptionInterface;
use InvalidArgumentException;

/**
 * paginate()/cursorPaginate() arguments that leave pagination without a
 * meaning — a non-positive page/perPage, an ambiguous cursor alias.
 * These trace back to an unvalidated HTTP query parameter, so this maps
 * to a 400 the same way MalformedRequestBodyException does, rather than
 * the generic 500 an uncaught InvalidArgumentException would reach
 * ExceptionHandlerMiddleware as.
 */
final class InvalidPaginationException extends InvalidArgumentException implements HttpStatusExceptionInterface
{
    #[\Override]
    public function httpStatus(): int
    {
        return 400;
    }

    public static function nonPositivePerPage(string $method, int $perPage): self
    {
        return new self("{$method} needs a perPage of at least 1, got {$perPage}.");
    }

    public static function nonPositivePage(int $page): self
    {
        return new self("paginate() needs a page of at least 1, got {$page}.");
    }

    /**
     * cursorPaginate() owns the ordering, limit and offset of the query
     * it runs. A pre-existing one of any of them leaves
     * WHERE cursorColumn > ? describing something other than the rows
     * actually delivered, so it is rejected rather than kept or dropped.
     */
    public static function cursorClauseConflict(string $clause): self
    {
        return new self(
            "cursorPaginate() cannot combine with a pre-existing {$clause}: it orders by its own "
            . '$cursorColumn, derives its limit from perPage, and tracks position by the cursor alone. '
            . 'Pass perPage and cursor instead of setting those clauses yourself.',
        );
    }

    public static function missingCursorAlias(string $cursorColumn): self
    {
        return new self(
            "cursorPaginate() needs a \$cursorAlias for the qualified cursor column \"{$cursorColumn}\": both "
            . 'MySQL and Postgres report it under its bare name, which another selected column of that name '
            . 'would silently overwrite in the returned row. Pass a name nothing else in the projection uses '
            . "— cursorAlias: '" . str_replace('.', '_', $cursorColumn) . "', say.",
        );
    }

    public static function cursorAliasCollision(string $cursorAlias, string $column): self
    {
        return new self(
            "cursorPaginate()'s \$cursorAlias \"{$cursorAlias}\" is already the name of a column this query "
            . "selects (\"{$column}\"). The cursor is selected under that alias and stripped back out "
            . 'afterwards, so sharing the name would drop the column you asked for. Pick a name nothing '
            . 'else in the projection uses.',
        );
    }
}
