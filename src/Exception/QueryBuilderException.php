<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Exception;

use RuntimeException;

/**
 * A Query built into a shape it cannot compile truthfully. Every one is
 * thrown before any SQL reaches the link.
 */
final class QueryBuilderException extends RuntimeException
{
    /**
     * update(), increment(), decrement() and delete() compile the table
     * and the WHERE clause only. $clauses names the state the caller
     * accumulated that they would drop — dropping it would widen the
     * statement to every row the WHERE clause alone matches.
     *
     * @param list<string> $clauses
     */
    public static function unsupportedMutationClauses(string $method, array $clauses): self
    {
        return new self(
            "{$method} compiles the table and the WHERE clause only, so " . implode(', ', $clauses)
            . ' would be dropped from the statement and it would affect every row the WHERE clause '
            . 'matches. Build the mutation on a Query carrying only table() and where predicates.',
        );
    }

    /**
     * @param list<string> $clauses
     */
    public static function unsupportedInsertClauses(string $method, array $clauses): self
    {
        return new self(
            "{$method} compiles the table and the inserted rows only, so " . implode(', ', $clauses)
            . ' would be dropped from the statement. Build the insert on a Query carrying only table().',
        );
    }

    public static function mutationNeedsAPredicate(string $method): self
    {
        return new self(
            "{$method} needs a where predicate: with none it affects every row in the table, and an empty "
            . 'whereGroup() adds none. Run a deliberate whole-table statement as raw SQL through the '
            . 'connection itself.',
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

    /** Its WHERE filter would reach the first operand of the compound only. */
    public static function cursorPaginationOverSetOperation(): self
    {
        return new self(
            'cursorPaginate() cannot page a union()/intersect()/except() query: its cursor filter would apply '
            . 'to the first operand only. Select from the combined query with fromSub() and paginate that.',
        );
    }

    public static function columnMissingFromRow(string $method, string $column): self
    {
        return new self(
            "{$method} could not read \"{$column}\" from the returned row. It reads the column by its name in "
            . 'the result, where a qualified column arrives under its last segment: select the column under '
            . 'the name you pass.',
        );
    }

    public static function subqueryFromAnotherDialect(string $method): self
    {
        return new self(
            "{$method} was given a Query built on a link to a different kind of database. A subquery compiles "
            . 'into its parent\'s SQL, so build it on a link of the same kind.',
        );
    }

    public static function subqueryCarriesCte(string $method): self
    {
        return new self(
            "{$method} cannot embed a Query carrying with()/withRecursive(). Register common table expressions "
            . 'on the outermost query and refer to them by name.',
        );
    }

    public static function subqueryCarriesLock(string $method): self
    {
        return new self(
            "{$method} cannot embed a Query carrying lockForUpdate()/lockForShare(). A row lock belongs to the "
            . 'outermost select.',
        );
    }

    /** MySQL 8.4 and MariaDB 11.4 both reject the statement with error 1235. */
    public static function limitedInSubquery(string $method): self
    {
        return new self(
            "{$method} cannot take a subquery carrying limit() or offset() on MySQL or MariaDB, which reject "
            . 'LIMIT inside an IN subquery. Wrap the limited query with fromSub() and select its column from '
            . 'that instead.',
        );
    }

    public static function lockNeedsATransaction(): self
    {
        return new self(
            'lockForUpdate()/lockForShare() needs a Query built on an active SqlTransaction: outside one the '
            . 'lock ends with the statement and protects nothing. Run the read inside '
            . 'TransactionGuard::transaction() and pass the transaction to new Query().',
        );
    }

    /**
     * @param list<string> $clauses
     */
    public static function lockCannotCombine(array $clauses): self
    {
        return new self(
            'lockForUpdate()/lockForShare() cannot combine with ' . implode(', ', $clauses) . '. A lock is admitted '
            . 'only on a select from one table or inner joins, with where predicates, ordering, limit and offset; '
            . 'run any other locking read as raw SQL.',
        );
    }

    public static function lockedTerminal(string $method): self
    {
        return new self(
            "{$method} cannot run a Query carrying lockForUpdate()/lockForShare(). A lock is admitted on get(), "
            . 'first(), value() and pluck() only.',
        );
    }
}
