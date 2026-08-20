<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder;

use Kinetis\Http\Pagination\CursorPaginator;
use Kinetis\Http\Pagination\Paginator;
use Kinetis\QueryBuilder\Dialect\MySqlDialect;
use Kinetis\QueryBuilder\Dialect\PostgresDialect;
use Kinetis\Validation\Hydrator;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\PrefersPreparedStatements;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\QueryBuilder\Exception\QueryBuilderException;
use InvalidArgumentException;

/**
 * A thin, parameterized SQL query builder — not an ORM. No relationships,
 * no migrations, no change-tracking, no save()-on-a-model. Works with
 * either MySQL or Postgres through the shared
 * Kinetis\Persistence\Contract\SqlLink interface, exactly like
 * Kinetis\Persistence\TransactionGuard already does — one
 * class, not one package per backend (see detectDialect()): the two
 * backends only genuinely differ on identifier quoting and how a
 * generated primary key is retrieved after an INSERT, both isolated in
 * Dialect, not spread through this class.
 *
 * $link accepts a plain driver client *or* an in-flight
 * SqlTransaction — both implement SqlLink — so a Query composes
 * directly inside TransactionGuard::transaction()'s callback with no new
 * transaction concept of its own:
 *
 *   $transactions->transaction($pool, function ($tx) {
 *       new Query($tx)->table('orders')->insert([...]);
 *   });
 *
 * Every compile*() method below builds its SQL string and bound-parameter
 * list together, in a single pass — never the SQL first and the bindings
 * as an afterthought. That's deliberate: once structured where() calls can
 * mix with whereRaw() fragments, tracking which bound value lands in which
 * "?" is the one place a subtle, silent bug could creep in (right value,
 * wrong slot). Building both in lockstep is what makes that impossible by
 * construction rather than by careful bookkeeping.
 *
 * One instance is one query — table()/select()/where()/... all mutate and
 * accumulate on $this, nothing resets between calls. Reusing one instance
 * across logically separate queries silently merges their where()s/
 * orders/etc. into one query instead of running two — a real mistake, not
 * a hypothetical one: this exact bug showed up in this package's own
 * MySQL/Postgres verification script on the first draft (reusing one
 * Query for insert-then-select-then-count accumulated every where() ever
 * called into one WHERE clause, breaking count() silently). Always
 * `new Query($link)` for each distinct query.
 */
final class Query
{
    /**
     * where()'s $operator, orderBy()'s $direction, join()'s $type, and
     * where()/whereIn()/whereRaw()'s $boolean are interpolated into SQL
     * verbatim, unlike every other user-reachable slot in this class —
     * left unchecked, that is a real SQL injection point, since a
     * sortable/filterable API (`?sort=name&dir=asc&op=gte`) is exactly the
     * shape that passes these through from a request, and a generic
     * "match any/all of these filters" builder is exactly the shape that
     * passes $boolean through the same way. Allow-listing them here is a
     * construction-time boundary, the same shape as
     * CorsMiddleware/AsGlobalMiddleware's own constructor guards — every
     * other value/identifier in this class was already safe by
     * construction (bound as "?" or identifier-quoted); this closes the
     * places that weren't.
     */
    private const array ALLOWED_WHERE_OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];

    private const array ALLOWED_ORDER_DIRECTIONS = ['ASC', 'DESC'];

    private const array ALLOWED_JOIN_TYPES = ['INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS'];

    private const array ALLOWED_WHERE_BOOLEANS = ['AND', 'OR'];

    private readonly Dialect $dialect;

    private string $table = '';

    /** @var list<string> */
    private array $selectColumns = ['*'];

    /** @var list<string> */
    private array $selectRawExpressions = [];

    /**
     * An already-quoted `expr AS alias` fragment appended to the compiled
     * SELECT list, set only for the duration of one
     * {@see toSelectSqlWithCursorAlias()} call and cleared in its own
     * finally — never state a later call on this instance can observe.
     * Separate from $selectRawExpressions above because that one is
     * subject to compileSelectColumns()'s "drop the default wildcard once
     * something explicit was asked for" rule, which is right for a
     * caller's own selectRaw() and wrong here: appending a cursor alias
     * must never silently take `*` away from a projection the caller
     * never touched.
     */
    private ?string $cursorAliasExpression = null;

    /**
     * @var list<
     *     array{type: 'basic', column: string, operator: string, value: mixed, boolean: 'AND'|'OR'}
     *     |array{type: 'raw', sql: string, params: list<mixed>, boolean: 'AND'|'OR'}
     *     |array{type: 'in', column: string, values: list<mixed>, boolean: 'AND'|'OR'}
     * >
     */
    private array $wheres = [];

    /** @var list<array{type: string, table: string, first: string, operator: string, second: string}> */
    private array $joins = [];

    /** @var list<string> */
    private array $orders = [];

    private ?int $limitValue = null;

    private ?int $offsetValue = null;

    /**
     * Set by whereRaw()/selectRaw()/orderByRaw() — see run()'s own
     * docblock for why this disables literal-inlining for the whole query
     * once any raw SQL text is involved.
     */
    private bool $hasRawFragment = false;

    public function __construct(
        private readonly MysqlLink|PostgresLink $link,
        ?Dialect $dialect = null,
    ) {
        $this->dialect = $dialect ?? self::detectDialect($link);
    }

    /**
     * No exception path for "neither" — $link's own parameter type is
     * already the closed union MysqlLink|PostgresLink, so PHP's own
     * argument-type enforcement is what rejects anything else, at the
     * call site, before this method ever runs.
     */
    private static function detectDialect(MysqlLink|PostgresLink $link): Dialect
    {
        return $link instanceof MysqlLink ? new MySqlDialect() : new PostgresDialect();
    }

    /**
     * $link->execute() goes through the server's prepared-statement
     * protocol; $link->query() does not. A query with nothing to bind
     * therefore always takes query(), and one whose parameters are all
     * safe to write into the SQL text may — see inlineLiterals(), which
     * decides on the driver rather than on this class.
     *
     * A fresh Query is built and executed once, so it never reuses a
     * prepared statement itself. Whether one is reused at all is the
     * driver's business: the PDO drivers memoize per connection and get
     * that reuse across Query instances, the native drivers do not.
     *
     * @param list<mixed> $params
     */
    private function run(string $sql, array $params): SqlResult
    {
        if ($params === []) {
            return $this->link->query($sql);
        }

        $inlined = $this->inlineLiterals($sql, $params);

        return $inlined !== null ? $this->link->query($inlined) : $this->link->execute($sql, $params);
    }

    /**
     * Every "?" this class emits itself (where()/whereIn()/insert()/
     * update()) has exactly one corresponding entry pushed onto its
     * bindings at the same time, in the same left-to-right order — see
     * this class's own docblock. That invariant is what makes a plain,
     * positional "replace each ? with its value" substitution safe:
     * there's no other source of a literal "?" character to collide with,
     * *unless* whereRaw()/selectRaw()/orderByRaw() contributed raw SQL
     * text of their own — caller-supplied text this class can't parse,
     * which might contain a "?" that was never meant as a placeholder
     * (inside a quoted string, say). $hasRawFragment rules that out
     * entirely rather than trying to tell a real placeholder from a decoy
     * one; a raw-fragment query always falls back to execute().
     *
     * Returns null (falling back to execute()) when inlining doesn't
     * apply — a raw fragment was used, or any single value in $params
     * isn't safely inlinable as a literal (Dialect::literalFor() already
     * covers per-value type/charset safety; the internal-error case above
     * covers this method's own placeholder-count invariant).
     *
     * @param list<mixed> $params
     */
    private function inlineLiterals(string $sql, array $params): ?string
    {
        // Whether writing a value into the SQL beats binding it is a
        // property of the driver. The native drivers reach the server
        // once for an unparameterized query and twice for a prepared
        // one; the PDO drivers memoize the prepared statement and keep
        // the binary protocol, where the same substitution costs about
        // half again as much per query. They say so themselves.
        if ($this->link instanceof PrefersPreparedStatements) {
            return null;
        }

        if ($this->hasRawFragment) {
            return null;
        }

        $literals = [];

        foreach ($params as $param) {
            $literal = $this->dialect->literalFor($param);

            if ($literal === null) {
                return null;
            }

            $literals[] = $literal;
        }

        $parts = explode('?', $sql);

        if (count($parts) - 1 !== count($literals)) {
            return null;
        }

        $result = $parts[0];

        foreach ($literals as $i => $literal) {
            $result .= $literal . $parts[$i + 1];
        }

        return $result;
    }

    public function table(string $table): static
    {
        $this->table = $table;

        return $this;
    }

    public function select(string ...$columns): static
    {
        $this->selectColumns = $columns === [] ? ['*'] : array_values($columns);

        return $this;
    }

    /**
     * A raw SELECT expression (an aggregate, a function call) alongside
     * whatever select()/the default "*" already contributes. No bound
     * params here — unlike whereRaw(), a SELECT expression is virtually
     * never built from user-controlled values, so this stays minimal;
     * whereRaw() is where parameter binding actually matters.
     */
    public function selectRaw(string $sql): static
    {
        $this->selectRawExpressions[] = $sql;
        $this->hasRawFragment = true;

        return $this;
    }

    /**
     * @param 'AND'|'OR' $boolean
     */
    public function where(string $column, string $operator, mixed $value, string $boolean = 'AND'): static
    {
        $normalizedOperator = self::assertAllowedOperator($operator);
        $normalizedBoolean = self::assertAllowedBoolean($boolean);
        $this->wheres[] = ['type' => 'basic', 'column' => $column, 'operator' => $normalizedOperator, 'value' => $value, 'boolean' => $normalizedBoolean];

        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value): static
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    /**
     * An empty $values compiles to a constant-false predicate (`1 = 0`)
     * rather than the syntactically invalid `IN ()` MySQL/Postgres both
     * reject outright — filtering by an empty result set (e.g. "posts by
     * these users" when the user list came back empty) is a common real
     * case, not an edge case to leave broken.
     *
     * @param list<mixed> $values
     * @param 'AND'|'OR' $boolean
     */
    public function whereIn(string $column, array $values, string $boolean = 'AND'): static
    {
        $normalizedBoolean = self::assertAllowedBoolean($boolean);
        $this->wheres[] = ['type' => 'in', 'column' => $column, 'values' => $values, 'boolean' => $normalizedBoolean];

        return $this;
    }

    /**
     * A raw WHERE fragment for anything the structured form can't express
     * (a function call, a subquery) — $sql's own "?" placeholders are
     * still bound as real parameters via $params, in the position they
     * appear here; "raw" means raw SQL syntax, never raw unparameterized
     * user input.
     *
     * @param list<mixed> $params
     * @param 'AND'|'OR' $boolean
     */
    public function whereRaw(string $sql, array $params = [], string $boolean = 'AND'): static
    {
        $normalizedBoolean = self::assertAllowedBoolean($boolean);
        $this->wheres[] = ['type' => 'raw', 'sql' => $sql, 'params' => $params, 'boolean' => $normalizedBoolean];
        $this->hasRawFragment = true;

        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): static
    {
        $normalizedType = self::assertAllowedJoinType($type);
        $normalizedOperator = self::assertAllowedOperator($operator);
        $this->joins[] = ['type' => $normalizedType, 'table' => $table, 'first' => $first, 'operator' => $normalizedOperator, 'second' => $second];

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->orders[] = $this->dialect->quoteIdentifier($column) . ' ' . self::assertAllowedDirection($direction);

        return $this;
    }

    public function orderByRaw(string $sql): static
    {
        $this->orders[] = $sql;
        $this->hasRawFragment = true;

        return $this;
    }

    public function limit(int $limit): static
    {
        if ($limit < 0) {
            throw new InvalidArgumentException("limit() must be 0 or greater, got {$limit}.");
        }

        $this->limitValue = $limit;

        return $this;
    }

    public function offset(int $offset): static
    {
        if ($offset < 0) {
            throw new InvalidArgumentException("offset() must be 0 or greater, got {$offset}.");
        }

        $this->offsetValue = $offset;

        return $this;
    }

    /**
     * @template T of object
     * @param class-string<T>|null $dtoClass
     * @return list<T>|list<array<string, mixed>>
     */
    public function get(?string $dtoClass = null): array
    {
        $compiled = $this->toSelectSql();
        $result = $this->run($compiled->sql, $compiled->params);

        $rows = [];

        foreach ($result as $row) {
            $rows[] = $dtoClass !== null ? Hydrator::hydrate($dtoClass, $row) : $row;
        }

        return $rows;
    }

    /**
     * @template T of object
     * @param class-string<T>|null $dtoClass
     * @return T|array<string, mixed>|null
     */
    public function first(?string $dtoClass = null): object|array|null
    {
        return $this->limit(1)->get($dtoClass)[0] ?? null;
    }

    public function count(): int
    {
        $compiled = $this->toSelectSql(countOnly: true);
        $result = $this->run($compiled->sql, $compiled->params);

        /** @var array<string, mixed>|null $row */
        $row = $result->fetchRow();

        return (int) ($row['aggregate'] ?? 0);
    }

    /**
     * Offset-based pagination: page()/perPage(), with a total count and
     * page count. count() already ignores order/limit/offset while still
     * respecting where()/join() (see toSelectSql()'s $countOnly branch),
     * so it and the page's own limit()->offset()->get() are two
     * executions of the same logical query, not the "reuse across
     * unrelated queries" mistake this class's own docblock warns against.
     *
     * @template T of object
     * @param class-string<T>|null $dtoClass
     */
    public function paginate(int $perPage, int $page = 1, ?string $dtoClass = null): Paginator
    {
        if ($perPage < 1) {
            throw new InvalidArgumentException("paginate() needs a perPage of at least 1, got {$perPage}.");
        }

        if ($page < 1) {
            throw new InvalidArgumentException("paginate() needs a page of at least 1, got {$page}.");
        }

        $total = $this->count();
        $data = $this->limit($perPage)->offset(($page - 1) * $perPage)->get($dtoClass);

        return new Paginator(
            data: $data,
            currentPage: $page,
            perPage: $perPage,
            total: $total,
            // perPage is validated at least 1 above, so this division is
            // always well-defined.
            lastPage: (int) ceil($total / $perPage),
        );
    }

    /**
     * Cursor-based pagination: no COUNT(*), no page number — advances by
     * the last row's own $cursorColumn value instead of an offset, so
     * rows inserted/deleted between calls can't shift results the way
     * offset pagination's page N can. Always orders by $cursorColumn
     * itself; combining this with an additional orderBy() call on a
     * different column can make pagination skip or repeat rows, since the
     * WHERE $cursorColumn > ? comparison only makes sense against the
     * column results are actually ordered by.
     *
     * $cursorColumn must be unique and strictly monotonic (a primary key
     * or an auto-incrementing/serial column, not e.g. created_at, which
     * two rows can share) — a page boundary landing inside a run of equal
     * values silently skips whatever's left of that run, since
     * `WHERE cursorColumn > ?` only ever excludes rows up to and
     * including the exact value already seen, not "rows already seen."
     *
     * Fetches rows as plain arrays first, regardless of $dtoClass, so the
     * next cursor is read off the real column name — a hydrated DTO's own
     * property name isn't guaranteed to match it. $dtoClass, when given,
     * only affects what ends up in the returned data.
     *
     * The cursor value always comes out of the same result as the
     * delivered rows — never a second query. Two reads of a live table
     * are not one snapshot: a row inserted or deleted between them moves
     * the boundary, and a cursor naming a row the caller was never handed
     * silently skips whatever sits between the two. One query cannot
     * disagree with itself.
     *
     * That makes the cursor column's own *row key* the whole problem, and
     * $cursorAlias is how a caller settles it. Both MySQL and Postgres
     * report an unaliased qualified column (`orders.id`) under its bare
     * name (`id`), which a join can trivially collide with — and a PHP
     * associative row has no way to hold two values under one key, so the
     * colliding pair silently becomes one. There is no alias spelling
     * this class could pick that is *guaranteed* absent from an arbitrary
     * `*` or explicit select(), so it does not guess one: pass
     * $cursorAlias and the column is additionally selected under exactly
     * that name, read back from it, and stripped from every returned row
     * (never reaching $dtoClass hydration) before this returns. A
     * qualified $cursorColumn without one is refused rather than guessed
     * at.
     *
     * Choosing a name nothing else in the projection uses is the
     * caller's, exactly as it is for any `AS` they write themselves —
     * {@see assertAliasIsFreeInProjection()} rejects the half of that
     * mistake which is visible from the builder (a column the caller
     * listed by name), and its own docblock covers why a wildcard's
     * contents cannot be checked the same way.
     *
     * An unqualified $cursorColumn needs no alias, since its own name is
     * already the row key: it is added to the projection only when a
     * caller's own select() chose specific columns that don't include it
     * — never when select() wasn't called at all (the default `*` already
     * covers it) or when the caller already selected it themselves — and
     * stripped back out only when this method is the one that added it.
     * Passing $cursorAlias for one is still allowed, and is the way to
     * disambiguate a projection that already has a *different* column of
     * that name.
     *
     * @param class-string|null $dtoClass
     */
    public function cursorPaginate(
        int $perPage,
        ?string $cursor,
        string $cursorColumn = 'id',
        ?string $dtoClass = null,
        ?string $cursorAlias = null,
    ): CursorPaginator {
        $cursorColumnIsQualified = str_contains($cursorColumn, '.');
        $this->assertCursorPaginateArguments($perPage, $cursorColumn, $cursorAlias, $cursorColumnIsQualified);

        if ($cursor !== null) {
            $this->where($cursorColumn, '>', $cursor);
        }

        // Only ever true for an *unqualified* column, whose own name is
        // the row key: a qualified one always arrives here aliased.
        $projectionIncludesCursorColumn = $cursorAlias === null
            && ($this->selectColumns === ['*'] || in_array($cursorColumn, $this->selectColumns, true));

        if ($cursorAlias === null && !$projectionIncludesCursorColumn) {
            $this->selectColumns[] = $cursorColumn;
        }

        $cursorRowKey = $cursorAlias ?? $cursorColumn;
        $compiled = $this->orderBy($cursorColumn)->limit($perPage + 1)->toSelectSqlWithCursorAlias($cursorColumn, $cursorAlias);
        $result = $this->run($compiled->sql, $compiled->params);

        /** @var list<array<string, mixed>> $rows */
        $rows = [];

        foreach ($result as $row) {
            $rows[] = $row;
        }

        $hasMore = count($rows) > $perPage;

        if ($hasMore) {
            array_pop($rows);
        }

        $nextCursor = self::nextCursorFromRow($rows, $hasMore, $cursorRowKey);

        if (!$projectionIncludesCursorColumn) {
            $rows = array_map(
                static function (array $row) use ($cursorRowKey): array {
                    unset($row[$cursorRowKey]);

                    return $row;
                },
                $rows,
            );
        }

        $data = $dtoClass !== null
            ? array_map(static fn (array $row) => Hydrator::hydrate($dtoClass, $row), $rows)
            : $rows;

        return new CursorPaginator($data, $nextCursor, $hasMore);
    }

    /**
     * cursorPaginate()'s own argument-validation prefix, extracted for
     * cognitive complexity — three independent, unrelated failure modes
     * (an out-of-range perPage, a qualified cursor column with no alias
     * to disambiguate it, an alias that collides with a column the
     * caller already selected), each a guard clause with nothing left
     * to share with the other two.
     */
    private function assertCursorPaginateArguments(
        int $perPage,
        string $cursorColumn,
        ?string $cursorAlias,
        bool $cursorColumnIsQualified,
    ): void {
        if ($perPage < 1) {
            throw new InvalidArgumentException("cursorPaginate() needs a perPage of at least 1, got {$perPage}.");
        }

        if ($cursorColumnIsQualified && $cursorAlias === null) {
            throw new InvalidArgumentException(
                "cursorPaginate() needs a \$cursorAlias for the qualified cursor column \"{$cursorColumn}\": both "
                . 'MySQL and Postgres report it under its bare name, which another selected column of that same '
                . 'name would silently overwrite in the returned row. Pass a name nothing else in the projection '
                . 'uses — cursorAlias: \'' . str_replace('.', '_', $cursorColumn) . '\', say — and the cursor is '
                . 'read from that and stripped back out before the rows are returned.',
            );
        }

        if ($cursorAlias !== null) {
            self::assertAliasIsFreeInProjection($this->selectColumns, $cursorAlias);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function nextCursorFromRow(array $rows, bool $hasMore, string $cursorRowKey): ?string
    {
        if (!$hasMore || $rows === []) {
            return null;
        }

        $lastRow = $rows[array_key_last($rows)];

        // Not a collision check: a colliding alias *takes* the key
        // rather than vacating it, so nothing here could see one. It
        // catches the different failure of a cursor column that never
        // reached the result at all, which would otherwise report a
        // silently null cursor. assertAliasIsFreeInProjection() is what
        // rules out the collisions that are visible at all.
        if (!array_key_exists($cursorRowKey, $lastRow)) {
            throw QueryBuilderException::cursorColumnMissingFromRow($cursorRowKey);
        }

        return (string) $lastRow[$cursorRowKey];
    }

    /**
     * Rejects a $cursorAlias that a column the caller listed themselves
     * already answers to, before any SQL runs.
     *
     * A collision is genuinely destructive rather than merely confusing:
     * a PHP row is an associative array, so the appended cursor column
     * takes the key its namesake would have occupied, and the cleanup
     * that removes the alias afterwards removes the caller's own field
     * with it. Nothing downstream can notice — the appended value is
     * *present* under that key, so a presence check passes, and the row
     * simply comes back one field short.
     *
     * What this can see is the explicit projection: `select('row_cursor')`
     * names its own bare key, and `select('t.row_cursor')` resolves to
     * the same one, since both engines report a qualified column under
     * its last segment. What it cannot see is a wildcard's contents or an
     * alias buried in a selectRaw() expression — neither is knowable
     * without asking the server for column metadata, which SqlResult does
     * not carry. Those stay the caller's own precondition, documented as
     * such rather than promised as a check: a count of distinct keys
     * against the server's column count would catch them, but it also
     * fires on the ordinary duplicate `id` of any `SELECT *` across a
     * join — a false rejection of the single most common reason to reach
     * for a cursor alias at all.
     *
     * @param list<string> $selectColumns
     */
    private static function assertAliasIsFreeInProjection(array $selectColumns, string $cursorAlias): void
    {
        foreach ($selectColumns as $column) {
            if ($column === '*' || str_ends_with($column, '.*')) {
                continue;
            }

            $bareName = str_contains($column, '.') ? substr($column, (int) strrpos($column, '.') + 1) : $column;

            if ($bareName === $cursorAlias) {
                throw new InvalidArgumentException(
                    "cursorPaginate()'s \$cursorAlias \"{$cursorAlias}\" is already the name of a column this "
                    . "query selects (\"{$column}\"). The cursor is selected under that alias and stripped back "
                    . 'out afterwards, so sharing the name would drop the column you asked for. Pick a name '
                    . 'nothing else in the projection uses.',
                );
            }
        }
    }

    /**
     * {@see toSelectSql()}, with $cursorColumn additionally selected
     * under $cursorAlias when one was given — the projection the caller
     * asked for, plus exactly one column this class reads its own cursor
     * back from.
     *
     * Everything else about the query is left alone: an alias an
     * orderBy() depends on stays in the projection that created it, and
     * a caller's own offset() stays exactly as they set it. Appending is
     * the only change, which is what lets the delivered rows and the
     * cursor come out of one result rather than two.
     */
    private function toSelectSqlWithCursorAlias(string $cursorColumn, ?string $cursorAlias): CompiledQuery
    {
        if ($cursorAlias === null) {
            return $this->toSelectSql();
        }

        $this->cursorAliasExpression = $this->dialect->quoteIdentifier($cursorColumn)
            . ' AS ' . $this->dialect->quoteIdentifier($cursorAlias);

        try {
            return $this->toSelectSql();
        } finally {
            $this->cursorAliasExpression = null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): void
    {
        if ($data === []) {
            throw new InvalidArgumentException(
                'insert() needs at least one column — an empty array compiles to invalid SQL '
                . '("INSERT INTO t () VALUES ()"). Pass the columns you want set to their default '
                . 'values explicitly if that\'s the intent; this class has no DEFAULT VALUES shorthand.',
            );
        }

        $columns = array_keys($data);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->dialect->quoteIdentifier($this->table),
            implode(', ', array_map($this->dialect->quoteIdentifier(...), $columns)),
            implode(', ', array_fill(0, count($columns), '?')),
        );

        $this->run($sql, array_values($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insertGetId(array $data, string $primaryKey = 'id'): int|string|null
    {
        if ($data === []) {
            throw new InvalidArgumentException(
                'insertGetId() needs at least one column — an empty array compiles to invalid SQL.',
            );
        }

        $compiled = $this->dialect->insertGetIdQuery($this->table, $data, $primaryKey);
        $result = $this->run($compiled->sql, $compiled->params);

        return $this->dialect->extractInsertedId($result, $primaryKey);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(array $data): int
    {
        $compiled = $this->toUpdateSql($data);
        $result = $this->run($compiled->sql, $compiled->params);

        return $result->getRowCount() ?? 0;
    }

    public function delete(): int
    {
        $compiled = $this->toDeleteSql();
        $result = $this->run($compiled->sql, $compiled->params);

        return $result->getRowCount() ?? 0;
    }

    public function toSelectSql(bool $countOnly = false): CompiledQuery
    {
        $columns = $countOnly ? 'COUNT(*) as aggregate' : $this->compileSelectColumns();

        $sql = "SELECT {$columns} FROM " . $this->dialect->quoteIdentifier($this->table);
        $bindings = [];

        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN " . $this->dialect->quoteIdentifier($join['table'])
                . ' ON ' . $this->dialect->quoteIdentifier($join['first'])
                . " {$join['operator']} " . $this->dialect->quoteIdentifier($join['second']);
        }

        $where = $this->compileWheres();
        $sql .= $where->sql;
        array_push($bindings, ...$where->params);

        if (!$countOnly) {
            if ($this->orders !== []) {
                $sql .= ' ORDER BY ' . implode(', ', $this->orders);
            }

            // Interpolated directly, not bound as "?": both are hard-typed
            // PHP int here, never a raw string, so there's no injection
            // surface to bind against in the first place.
            if ($this->limitValue !== null) {
                $sql .= " LIMIT {$this->limitValue}";
            }

            if ($this->offsetValue !== null) {
                $sql .= " OFFSET {$this->offsetValue}";
            }
        }

        return new CompiledQuery($sql, $bindings);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function toUpdateSql(array $data): CompiledQuery
    {
        if ($data === []) {
            throw new InvalidArgumentException(
                'update() needs at least one column — an empty array compiles to invalid SQL '
                . '("UPDATE t SET  WHERE ...").',
            );
        }

        $sets = implode(', ', array_map(
            fn (string $column) => $this->dialect->quoteIdentifier($column) . ' = ?',
            array_keys($data),
        ));

        $sql = 'UPDATE ' . $this->dialect->quoteIdentifier($this->table) . " SET {$sets}";
        $bindings = array_values($data);

        $where = $this->compileWheres();
        $sql .= $where->sql;
        array_push($bindings, ...$where->params);

        return new CompiledQuery($sql, $bindings);
    }

    public function toDeleteSql(): CompiledQuery
    {
        $sql = 'DELETE FROM ' . $this->dialect->quoteIdentifier($this->table);
        $where = $this->compileWheres();

        return new CompiledQuery($sql . $where->sql, $where->params);
    }

    private static function assertAllowedOperator(string $operator): string
    {
        $normalized = strtoupper(trim($operator));

        if (!in_array($normalized, self::ALLOWED_WHERE_OPERATORS, true)) {
            throw new InvalidArgumentException(
                "Operator \"{$operator}\" is not allowed. Use one of: " . implode(', ', self::ALLOWED_WHERE_OPERATORS) . '.',
            );
        }

        return $normalized;
    }

    private static function assertAllowedDirection(string $direction): string
    {
        $normalized = strtoupper(trim($direction));

        if (!in_array($normalized, self::ALLOWED_ORDER_DIRECTIONS, true)) {
            throw new InvalidArgumentException(
                "Order direction \"{$direction}\" is not allowed. Use one of: " . implode(', ', self::ALLOWED_ORDER_DIRECTIONS) . '.',
            );
        }

        return $normalized;
    }

    private static function assertAllowedJoinType(string $type): string
    {
        $normalized = strtoupper(trim($type));

        if (!in_array($normalized, self::ALLOWED_JOIN_TYPES, true)) {
            throw new InvalidArgumentException(
                "Join type \"{$type}\" is not allowed. Use one of: " . implode(', ', self::ALLOWED_JOIN_TYPES) . '.',
            );
        }

        return $normalized;
    }

    /**
     * @return 'AND'|'OR'
     */
    private static function assertAllowedBoolean(string $boolean): string
    {
        $normalized = strtoupper(trim($boolean));

        if (!in_array($normalized, self::ALLOWED_WHERE_BOOLEANS, true)) {
            throw new InvalidArgumentException(
                "Where boolean \"{$boolean}\" is not allowed. Use one of: " . implode(', ', self::ALLOWED_WHERE_BOOLEANS) . '.',
            );
        }

        /** @var 'AND'|'OR' $normalized */
        return $normalized;
    }

    /**
     * The default "*" is dropped once anything explicit — select() or
     * selectRaw() — has actually been specified; only the untouched
     * default ever produces a bare "*". More precisely: the ternary
     * below drops the default "*" once a real selectRaw() expression
     * exists and select() was never explicitly called — a caller
     * reaching only for selectRaw('COUNT(*) AS total') wants exactly
     * that, not also every column via the untouched default. Once
     * select() has been called, $selectColumns is no longer literally
     * ['*'], so the ternary's condition is false and whatever was
     * explicitly selected is combined with the raw expressions normally.
     */
    private function compileSelectColumns(): string
    {
        $quoted = array_map(
            $this->compileSelectColumn(...),
            $this->selectColumns === ['*'] && $this->selectRawExpressions !== [] ? [] : $this->selectColumns,
        );

        $expressions = [...$quoted, ...$this->selectRawExpressions];

        // Appended after that ternary, deliberately: a cursor alias is
        // this class's own addition, not something the caller asked to
        // see, so it must never be what turns an untouched `*` into an
        // explicit projection.
        if ($this->cursorAliasExpression !== null) {
            $expressions[] = $this->cursorAliasExpression;
        }

        return implode(', ', $expressions);
    }

    /**
     * The bare wildcard and a qualified one ("orders.*") both stay
     * unquoted on their "*" segment — quoteIdentifier() would otherwise
     * quote it as a literal column named "*" (`` `orders`.`*` `` on
     * MySQL), which the server rejects outright rather than expanding to
     * every column, since that's genuinely a different thing to ask for.
     */
    private function compileSelectColumn(string $column): string
    {
        if ($column === '*') {
            return $column;
        }

        if (str_ends_with($column, '.*')) {
            return $this->dialect->quoteIdentifier(substr($column, 0, -2)) . '.*';
        }

        return $this->dialect->quoteIdentifier($column);
    }

    private function compileWheres(): CompiledQuery
    {
        if ($this->wheres === []) {
            return new CompiledQuery('', []);
        }

        $sqlParts = [];
        $bindings = [];

        foreach ($this->wheres as $i => $where) {
            $prefix = $i === 0 ? '' : " {$where['boolean']} ";

            if ($where['type'] === 'raw') {
                $sqlParts[] = $prefix . $where['sql'];
                array_push($bindings, ...$where['params']);

                continue;
            }

            if ($where['type'] === 'in') {
                if ($where['values'] === []) {
                    // IN () is syntactically invalid on both MySQL and
                    // Postgres — a constant-false predicate is the correct
                    // meaning of "column is in this empty set of values"
                    // and needs no bound parameters of its own.
                    $sqlParts[] = $prefix . '1 = 0';

                    continue;
                }

                $placeholders = implode(', ', array_fill(0, count($where['values']), '?'));
                $sqlParts[] = $prefix . $this->dialect->quoteIdentifier($where['column']) . " IN ({$placeholders})";
                array_push($bindings, ...$where['values']);

                continue;
            }

            $sqlParts[] = $prefix . $this->dialect->quoteIdentifier($where['column']) . " {$where['operator']} ?";
            $bindings[] = $where['value'];
        }

        return new CompiledQuery(' WHERE ' . implode('', $sqlParts), $bindings);
    }
}
