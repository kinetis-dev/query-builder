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

    /**
     * (page - 1) * perPage overflows PHP's native int range before it
     * ever reaches offset(int) — rejected here rather than left to
     * offset()'s own strict-typed TypeError, which count() would already
     * have run a real query ahead of.
     */
    public static function offsetOverflow(int $page, int $perPage): self
    {
        return new self(
            "paginate() cannot serve page {$page} at perPage {$perPage}: (page - 1) * perPage exceeds PHP's "
            . 'native integer range. Request an earlier page or a smaller perPage.',
        );
    }

    /**
     * cursorPaginate() fetches perPage + 1 rows to detect whether another
     * page exists; that look-ahead overflows PHP's native int range
     * before it ever reaches limit(int) — rejected here rather than left
     * to limit()'s own strict-typed TypeError.
     */
    public static function lookaheadOverflow(int $perPage): self
    {
        return new self(
            "cursorPaginate() cannot use a perPage of {$perPage}: it looks ahead by perPage + 1 to detect "
            . "another page, and that would exceed PHP's native integer range. Request a smaller perPage.",
        );
    }

    /**
     * cursorPaginate() owns the whole ordering of the query it runs — a
     * caller-supplied orderBy()/orderByRaw() set before calling it, on
     * the cursor column or any other, leaves WHERE cursorColumn > ? no
     * longer describing the order results actually come back in, which
     * silently skips or repeats rows rather than failing loudly.
     */
    public static function preExistingOrderConflictsWithCursor(): self
    {
        return new self(
            'cursorPaginate() orders the query by its own $cursorColumn and cannot combine that with an '
            . 'orderBy()/orderByRaw() call already made on this Query — even one that only reorders the same '
            . 'column, since the WHERE cursorColumn > ? comparison this method builds only makes sense against '
            . 'the column results are actually ordered by. Pagination by a different or composite ordering '
            . 'needs its own cursor design, which this API does not provide: call cursorPaginate() on a Query '
            . "with no orderBy()/orderByRaw() calls of your own.",
        );
    }

    /**
     * cursorPaginate() computes its own position purely from the cursor
     * value — a pre-existing offset() greater than zero is reapplied
     * inside every cursor window on every call, not applied once before
     * the sequence starts, which silently skips rows the moment a caller
     * advances past the first page. offset(0) is the one value with no
     * such risk — it never skips a row, so it's the only one accepted.
     */
    public static function preExistingOffsetConflictsWithCursor(int $offset): self
    {
        return new self(
            "cursorPaginate() cannot combine with a pre-existing offset({$offset}): the offset is reapplied "
            . 'inside every cursor window rather than applied once before the sequence starts, which silently '
            . 'skips rows as soon as you advance past the first page. Cursor pagination has no offset concept '
            . 'of its own — its cursor value is the only position it tracks. If you need to skip an initial '
            . 'run of rows, obtain a starting cursor for that position instead, or use offset-based paginate() '
            . 'if page-jumping is what you actually need.',
        );
    }

    /**
     * cursorPaginate() computes its own limit from perPage, fetching one
     * extra row to detect whether another page exists — a pre-existing
     * limit() would either be silently overwritten or fought over with
     * that look-ahead, so it's rejected outright rather than left
     * ambiguous either way.
     */
    public static function preExistingLimitConflictsWithCursor(int $limit): self
    {
        return new self(
            "cursorPaginate() cannot combine with a pre-existing limit({$limit}): it computes its own limit "
            . 'from perPage, fetching one extra row to detect whether another page exists. Pass perPage '
            . 'instead of calling limit() yourself.',
        );
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
