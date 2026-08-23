<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Exception;

use Kinetis\Http\Exception\HttpStatusExceptionInterface;
use InvalidArgumentException;

/**
 * paginate()/cursorPaginate() arguments genuinely invalid for pagination
 * to mean anything (a non-positive page/perPage, an ambiguous cursor
 * alias) rather than a mistake a controller ever built by hand — these
 * routinely trace straight back to an unvalidated HTTP query parameter,
 * so this maps to a 400 the same way MalformedRequestBodyException does,
 * instead of the generic 500 an uncaught InvalidArgumentException would
 * otherwise reach ExceptionHandlerMiddleware as.
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

    public static function missingCursorAlias(string $cursorColumn): self
    {
        return new self(
            "cursorPaginate() needs a \$cursorAlias for the qualified cursor column \"{$cursorColumn}\": both "
            . 'MySQL and Postgres report it under its bare name, which another selected column of that same '
            . 'name would silently overwrite in the returned row. Pass a name nothing else in the projection '
            . 'uses — cursorAlias: \'' . str_replace('.', '_', $cursorColumn) . '\', say — and the cursor is '
            . 'read from that and stripped back out before the rows are returned.',
        );
    }

    public static function cursorAliasCollision(string $cursorAlias, string $column): self
    {
        return new self(
            "cursorPaginate()'s \$cursorAlias \"{$cursorAlias}\" is already the name of a column this "
            . "query selects (\"{$column}\"). The cursor is selected under that alias and stripped back "
            . 'out afterwards, so sharing the name would drop the column you asked for. Pick a name '
            . 'nothing else in the projection uses.',
        );
    }
}
