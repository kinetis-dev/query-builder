<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder;

use Kinetis\Persistence\Contract\SqlResult;

/**
 * Every spelling Query needs that differs between the MySQL family
 * (MySQL 8.4, MariaDB 11.4) and PostgreSQL 16. Everything else — "?"
 * placeholders, joins, set operations, CTEs, exclusive lock clauses,
 * affected-row counts — is written once in Query.
 */
interface Dialect
{
    /**
     * Quotes each "."-separated segment separately, so `orders.total`
     * names the column total of orders rather than one column whose name
     * contains a dot.
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * The row-window suffix with its leading space, or '' for neither.
     * Both values are non-negative ints validated before they get here.
     */
    public function limitOffset(?int $limit, ?int $offset): string;

    /** The shared row-lock suffix, with its leading space. */
    public function sharedLock(): string;

    /** Whether an `IN (subquery)` may carry LIMIT or OFFSET. */
    public function admitsLimitedInSubquery(): bool;

    /**
     * The INSERT suffix that skips a conflicting row while every other
     * error still fails the statement. Which conflicts it covers differs
     * per dialect; see each implementation.
     *
     * @param non-empty-list<string> $columns the inserted columns
     */
    public function insertOrIgnoreClause(array $columns): string;

    /**
     * The INSERT suffix that turns a unique-key conflict into an update of
     * $update, each column taking the value the conflicting row tried to
     * insert.
     *
     * @param non-empty-list<string> $uniqueBy
     * @param non-empty-list<string> $update
     */
    public function upsertClause(array $uniqueBy, array $update): string;

    /**
     * The INSERT suffix that arranges for $primaryKey's generated value to
     * be readable by extractInsertedId(): a RETURNING clause on
     * PostgreSQL, nothing on the MySQL family, which reports it
     * out-of-band.
     */
    public function insertGetIdClause(string $primaryKey): string;

    /**
     * Reads the value insertGetIdClause() arranged to retrieve, from the
     * SqlResult produced by executing the INSERT.
     */
    public function extractInsertedId(SqlResult $result, string $primaryKey): int|string|null;

    /**
     * A ready-to-inline SQL literal for $value, or null when $value
     * should be bound as a real "?" parameter instead. Only values whose
     * literal form is charset- and state-independent are ever inlined
     * (ints, bools) — strings always bind, so no connection state is
     * needed here and none is taken.
     */
    public function literalFor(mixed $value): ?string;
}
