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
use Kinetis\QueryBuilder\Exception\InvalidPaginationException;
use Kinetis\QueryBuilder\Exception\QueryBuilderException;
use InvalidArgumentException;

/**
 * A thin, parameterized SQL query builder — not an ORM. No relationships,
 * no migrations, no change-tracking, no save()-on-a-model. One class
 * serves both backends through the shared
 * Kinetis\Persistence\Contract\SqlLink family, exactly as
 * Kinetis\Persistence\TransactionGuard does: MySQL and Postgres differ
 * only on identifier quoting and on how a generated primary key comes
 * back after an INSERT, both isolated in Dialect.
 *
 * $link accepts a plain driver client *or* an in-flight SqlTransaction —
 * both implement SqlLink — so a Query composes directly inside
 * TransactionGuard::transaction()'s callback with no transaction concept
 * of its own.
 *
 * Every compile*() method below builds its SQL string and bound-parameter
 * list together, in a single pass. That is what keeps each "?" and its
 * own value in the same position once structured where() calls mix with
 * whereRaw() fragments — by construction, not by bookkeeping.
 *
 * One instance is one query: table()/select()/where()/... accumulate on
 * $this and nothing resets between calls, so reusing one instance across
 * logically separate queries merges them into a single query. Always
 * `new Query($link)` for each distinct query. first(), paginate() and
 * cursorPaginate() are the exception — each applies the limit, offset,
 * order, cursor filter and projection it needs to a shallow clone, so
 * the caller's own builder is unchanged when they return.
 */
final class Query
{
    /**
     * where()'s $operator, orderBy()'s $direction, join()'s $type, and
     * where()/whereIn()/whereRaw()'s $boolean are interpolated into SQL
     * verbatim, unlike every other user-reachable slot in this class. A
     * sortable/filterable API (`?sort=name&dir=asc&op=gte`) is exactly
     * the shape that passes a request value into one of them, so each is
     * allow-listed at the call that sets it. Every other value or
     * identifier here is already safe by construction — bound as "?" or
     * identifier-quoted.
     */
    private const array ALLOWED_WHERE_OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];

    private const array ALLOWED_ORDER_DIRECTIONS = ['ASC', 'DESC'];

    /**
     * INNER, LEFT and RIGHT compile to the same portable syntax on both
     * backends. FULL and CROSS do not — MySQL has no FULL JOIN at all,
     * and CROSS takes no ON clause, which is the only join shape join()
     * builds.
     */
    private const array ALLOWED_JOIN_TYPES = ['INNER', 'LEFT', 'RIGHT'];

    private const array ALLOWED_WHERE_BOOLEANS = ['AND', 'OR'];

    private readonly Dialect $dialect;

    private string $table = '';

    /** @var list<string> */
    private array $selectColumns = ['*'];

    /** @var list<string> */
    private array $selectRawExpressions = [];

    /**
     * An already-quoted `expr AS alias` fragment appended to the compiled
     * SELECT list, set only on cursorPaginate()'s own clone. Separate
     * from $selectRawExpressions above because that one is subject to
     * compileSelectColumns()'s "drop the default wildcard once something
     * explicit was asked for" rule, which is right for a caller's own
     * selectRaw() and wrong here: appending a cursor alias must never
     * silently take `*` away from a projection the caller never touched.
     */
    private ?string $cursorAliasExpression = null;

    /**
     * @var list<
     *     array{type: 'basic', column: string, operator: string, value: mixed, boolean: 'AND'|'OR'}
     *     |array{type: 'null', column: string, operator: 'IS NULL'|'IS NOT NULL', boolean: 'AND'|'OR'}
     *     |array{type: 'raw', sql: string, params: list<mixed>, boolean: 'AND'|'OR'}
     *     |array{type: 'in', column: string, values: list<mixed>, boolean: 'AND'|'OR'}
     * >
     */
    private array $wheres = [];

    /**
     * cursorPaginate()'s own filter, kept apart from $wheres so
     * compileWheres() can emit it as `(existing predicate) AND column > ?`
     * — appended to the flat list instead, an OR in the caller's own
     * predicate would bind tighter than the cursor and leave it applying
     * to the last OR arm alone.
     *
     * @var array{column: string, value: string}|null
     */
    private ?array $cursorPredicate = null;

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

    /**
     * The link's own type is the only dialect authority. MysqlLink|
     * PostgresLink is a closed union, so PHP's argument-type enforcement
     * rejects anything else at the call site and there is no "neither"
     * branch to write here.
     */
    public function __construct(private readonly MysqlLink|PostgresLink $link)
    {
        $this->dialect = $link instanceof MysqlLink ? new MySqlDialect() : new PostgresDialect();
    }

    /**
     * $link->execute() goes through the server's prepared-statement
     * protocol; $link->query() does not. A query with nothing to bind
     * therefore always takes query(), and one whose parameters are all
     * safe to write into the SQL text may — see inlineLiterals(), which
     * decides on the driver rather than on this class.
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
     * Every "?" this class emits itself has exactly one binding pushed at
     * the same time, in the same left-to-right order, which is what makes
     * a positional "replace each ? with its value" substitution safe.
     * Raw SQL text breaks that: whereRaw()/selectRaw()/orderByRaw() carry
     * caller-supplied text this class cannot parse, which may contain a
     * "?" that was never a placeholder, so $hasRawFragment sends the whole
     * query to execute() rather than telling a real placeholder from a
     * decoy one.
     *
     * Returns null — bind instead — for a raw fragment, or for any value
     * Dialect::literalFor() will not write as a literal.
     *
     * @param list<mixed> $params
     */
    private function inlineLiterals(string $sql, array $params): ?string
    {
        // Whether writing a value into the SQL beats binding it is the
        // driver's own answer, and this marker is where it states it.
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
     * A null $value follows SQL's own null semantics instead of binding:
     * `=` compiles to IS NULL, `!=` and `<>` to IS NOT NULL, neither
     * with a placeholder. Every other operator against null is rejected
     * — `column > NULL` is never true for any row, so it can only be a
     * mistake.
     *
     * @param 'AND'|'OR' $boolean
     */
    public function where(string $column, string $operator, mixed $value, string $boolean = 'AND'): static
    {
        $normalizedOperator = self::assertAllowedOperator($operator);
        $normalizedBoolean = self::assertAllowedBoolean($boolean);

        if ($value === null) {
            $this->wheres[] = ['type' => 'null', 'column' => $column, 'operator' => self::nullOperatorFor($normalizedOperator), 'boolean' => $normalizedBoolean];

            return $this;
        }

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
     * A null member is rejected instead: SQL never matches a row against
     * NULL, so one that slipped into the list changes what the query
     * means without changing how it reads. Use where($column, '=', null)
     * for IS NULL.
     *
     * @param list<mixed> $values
     * @param 'AND'|'OR' $boolean
     */
    public function whereIn(string $column, array $values, string $boolean = 'AND'): static
    {
        $normalizedBoolean = self::assertAllowedBoolean($boolean);

        if (in_array(null, $values, true)) {
            throw new InvalidArgumentException(
                "whereIn() cannot take null in the value list for \"{$column}\": SQL never matches a row "
                . "against NULL, so the null narrows the set silently. Use where('{$column}', '=', null) "
                . 'for IS NULL.',
            );
        }

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
     * $sql must carry an actual fragment. An empty or whitespace-only one
     * is a predicate the caller believes they added and the compiler
     * emits nothing for, which would let update()/delete() past their
     * own predicate requirement and affect every row.
     *
     * @param list<mixed> $params
     * @param 'AND'|'OR' $boolean
     */
    public function whereRaw(string $sql, array $params = [], string $boolean = 'AND'): static
    {
        $normalizedBoolean = self::assertAllowedBoolean($boolean);

        if (trim($sql) === '') {
            throw new InvalidArgumentException(
                'whereRaw() needs a SQL fragment: an empty one compiles to no predicate at all, which '
                . 'would widen an update() or delete() to every row in the table.',
            );
        }

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
        $one = clone $this;
        $one->limitValue = 1;

        return $one->get($dtoClass)[0] ?? null;
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
     * page count. The page's own limit and offset are applied to a
     * clone, and count() reads the same predicates and joins without
     * them, so the two executions describe one logical query and the
     * caller's builder is untouched by either.
     *
     * An order is required: without one the server may return rows in
     * any order it likes, and page 2 can then repeat or skip rows from
     * page 1.
     *
     * @template T of object
     * @param class-string<T>|null $dtoClass
     */
    public function paginate(int $perPage, int $page = 1, ?string $dtoClass = null): Paginator
    {
        if ($perPage < 1) {
            throw InvalidPaginationException::nonPositivePerPage('paginate()', $perPage);
        }

        if ($page < 1) {
            throw InvalidPaginationException::nonPositivePage($page);
        }

        if ($this->orders === []) {
            throw QueryBuilderException::paginationNeedsAnOrder();
        }

        $total = $this->count();

        $window = clone $this;
        $window->limitValue = $perPage;
        $window->offsetValue = ($page - 1) * $perPage;

        return new Paginator(
            data: $window->get($dtoClass),
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
     * offset pagination's page N can.
     *
     * The order, limit, cursor filter and projection this method needs
     * are applied to a clone, so the caller's builder comes back
     * unchanged. A pre-existing orderBy()/orderByRaw(), limit(), or
     * offset() above zero is refused: each states an intent this method's
     * own ordering and windowing would contradict, leaving
     * WHERE $cursorColumn > ? describing something other than the rows
     * delivered. A different or composite ordering needs its own cursor
     * design, which this method does not provide.
     *
     * The cursor filter combines with the where() calls already on the
     * query as `(existing predicate) AND $cursorColumn > ?`, so an OR
     * anywhere in that predicate cannot bind tighter than the cursor.
     *
     * $cursorColumn must be unique and strictly monotonic (a primary key
     * or an auto-incrementing/serial column, not e.g. created_at, which
     * two rows can share): `> ?` excludes rows up to and including the
     * value already seen, not "rows already seen", so a page boundary
     * inside a run of equal values skips the rest of that run.
     *
     * Rows are fetched as plain arrays regardless of $dtoClass, so the
     * next cursor is read off the real column name and out of the same
     * result as the delivered rows — never a second query, which a write
     * landing between the two could leave naming a row the caller never
     * received.
     *
     * Both engines report an unaliased qualified column (`orders.id`)
     * under its bare name (`id`), which a join can collide with, and a
     * PHP row cannot hold two values under one key. A qualified
     * $cursorColumn therefore requires $cursorAlias: the column is
     * additionally selected under that name, read back from it, and
     * stripped from every returned row before hydration.
     * {@see assertAliasIsFreeInProjection()} rejects an alias a listed
     * column already answers to; a wildcard's contents stay the caller's
     * own precondition. An unqualified $cursorColumn is already its own
     * row key: it needs no alias, and is added to the projection only
     * when a select() chose columns that omit it.
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
        $this->assertCursorPaginateArguments($perPage, $cursorColumn, $cursorAlias);

        // Only ever true for an *unqualified* column, whose own name is
        // the row key: a qualified one always arrives here aliased.
        $projectionIncludesCursorColumn = $cursorAlias === null
            && ($this->selectColumns === ['*'] || in_array($cursorColumn, $this->selectColumns, true));

        $page = clone $this;

        if ($cursor !== null) {
            $page->cursorPredicate = ['column' => $cursorColumn, 'value' => $cursor];
        }

        if ($cursorAlias !== null) {
            $page->cursorAliasExpression = $this->dialect->quoteIdentifier($cursorColumn)
                . ' AS ' . $this->dialect->quoteIdentifier($cursorAlias);
        } elseif (!$projectionIncludesCursorColumn) {
            $page->selectColumns[] = $cursorColumn;
        }

        $cursorRowKey = $cursorAlias ?? $cursorColumn;
        $compiled = $page->orderBy($cursorColumn)->limit($perPage + 1)->toSelectSql();
        $result = $page->run($compiled->sql, $compiled->params);

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
     * cursorPaginate()'s argument and conflict checks, extracted for
     * cognitive complexity.
     */
    private function assertCursorPaginateArguments(
        int $perPage,
        string $cursorColumn,
        ?string $cursorAlias,
    ): void {
        if ($perPage < 1) {
            throw InvalidPaginationException::nonPositivePerPage('cursorPaginate()', $perPage);
        }

        // Rejected whichever column it names, including $cursorColumn
        // itself: a compiled order fragment gives no reliable way to
        // tell a redundant order from a conflicting one.
        if ($this->orders !== []) {
            throw InvalidPaginationException::cursorClauseConflict('orderBy()/orderByRaw()');
        }

        if ($this->limitValue !== null) {
            throw InvalidPaginationException::cursorClauseConflict("limit({$this->limitValue})");
        }

        // offset(0) compiles to the same "skip nothing" SQL as no
        // offset() call at all. Anything greater is reapplied inside
        // every cursor window instead of once before the sequence
        // starts, skipping rows as soon as the caller advances a page.
        if ($this->offsetValue !== null && $this->offsetValue !== 0) {
            throw InvalidPaginationException::cursorClauseConflict("offset({$this->offsetValue})");
        }

        if (str_contains($cursorColumn, '.') && $cursorAlias === null) {
            throw InvalidPaginationException::missingCursorAlias($cursorColumn);
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

        // Catches a cursor column that never reached the result at all,
        // which would otherwise report a silently null cursor. Not a
        // collision check: a colliding alias takes the key rather than
        // vacating it, so nothing here could see one.
        if (!array_key_exists($cursorRowKey, $lastRow)) {
            throw QueryBuilderException::cursorColumnMissingFromRow($cursorRowKey);
        }

        return (string) $lastRow[$cursorRowKey];
    }

    /**
     * Rejects a $cursorAlias that a column the caller listed themselves
     * already answers to, before any SQL runs. The appended cursor takes
     * that column's key in the row, and the cleanup that removes the
     * alias removes the caller's field with it — silently, since the key
     * is present either way.
     *
     * This sees the explicit projection only: `select('t.row_cursor')`
     * claims the same bare key as `select('row_cursor')`, since both
     * engines report a qualified column under its last segment. A
     * wildcard's contents and an alias buried in a selectRaw() stay the
     * caller's own precondition — knowing either needs column metadata
     * SqlResult does not carry.
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
                throw InvalidPaginationException::cursorAliasCollision($cursorAlias, $column);
            }
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
        $this->assertMutationIsNarrowed('update()');

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
        $this->assertMutationIsNarrowed('delete()');

        $sql = 'DELETE FROM ' . $this->dialect->quoteIdentifier($this->table);
        $where = $this->compileWheres();

        return new CompiledQuery($sql . $where->sql, $where->params);
    }

    /**
     * UPDATE and DELETE compile the table and the WHERE clause, nothing
     * else. A query with no predicate at all matches every row, and any
     * other accumulated state would be dropped from the statement —
     * turning a mutation the caller narrowed with select(), join(),
     * orderBy(), limit() or offset() into one that matches every row the
     * WHERE clause alone allows. Both are refused instead. A deliberate
     * whole-table statement, like a joined, ordered or limited mutation,
     * runs as raw SQL through the link itself.
     */
    private function assertMutationIsNarrowed(string $method): void
    {
        if ($this->wheres === []) {
            throw QueryBuilderException::mutationNeedsAPredicate($method);
        }

        $unsupported = [];

        if ($this->selectColumns !== ['*'] || $this->selectRawExpressions !== []) {
            $unsupported[] = 'select()/selectRaw()';
        }

        if ($this->joins !== []) {
            $unsupported[] = 'join()/leftJoin()';
        }

        if ($this->orders !== []) {
            $unsupported[] = 'orderBy()/orderByRaw()';
        }

        if ($this->limitValue !== null) {
            $unsupported[] = 'limit()';
        }

        if ($this->offsetValue !== null) {
            $unsupported[] = 'offset()';
        }

        if ($unsupported !== []) {
            throw QueryBuilderException::unsupportedMutationClauses($method, $unsupported);
        }
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

    /**
     * @return 'IS NULL'|'IS NOT NULL'
     */
    private static function nullOperatorFor(string $operator): string
    {
        return match ($operator) {
            '=' => 'IS NULL',
            '!=', '<>' => 'IS NOT NULL',
            default => throw new InvalidArgumentException(
                "Operator \"{$operator}\" cannot be used with a null value. SQL compares null through "
                . 'IS NULL (=) and IS NOT NULL (!=, <>) only; every other comparison against null is '
                . 'never true for any row.',
            ),
        };
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
     * selectRaw() — has been specified: a caller reaching only for
     * selectRaw('COUNT(*) AS total') wants exactly that, not also every
     * column. Once select() has been called $selectColumns is no longer
     * literally ['*'], so the explicit columns and the raw expressions
     * combine normally.
     */
    private function compileSelectColumns(): string
    {
        $quoted = array_map(
            $this->compileSelectColumn(...),
            $this->selectColumns === ['*'] && $this->selectRawExpressions !== [] ? [] : $this->selectColumns,
        );

        $expressions = [...$quoted, ...$this->selectRawExpressions];

        // Appended after that rule: a cursor alias is this class's own
        // addition, not something the caller asked to see, so it must
        // never be what turns an untouched `*` into an explicit
        // projection.
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
     * every column, since that is a different thing to ask for.
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
        $sqlParts = [];
        $bindings = [];

        foreach ($this->wheres as $i => $where) {
            $prefix = $i === 0 ? '' : " {$where['boolean']} ";

            if ($where['type'] === 'raw') {
                $sqlParts[] = $prefix . $where['sql'];
                array_push($bindings, ...$where['params']);

                continue;
            }

            if ($where['type'] === 'null') {
                // No placeholder: SQL has no value to compare a null
                // against, only IS NULL / IS NOT NULL to test for one.
                $sqlParts[] = $prefix . $this->dialect->quoteIdentifier($where['column']) . " {$where['operator']}";

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

        $predicate = implode('', $sqlParts);

        if ($this->cursorPredicate !== null) {
            // Parenthesized: an OR anywhere in the caller's own
            // predicate binds tighter than this AND, which would leave
            // the cursor filtering the last OR arm alone.
            $cursor = $this->dialect->quoteIdentifier($this->cursorPredicate['column']) . ' > ?';
            $predicate = $predicate === '' ? $cursor : "({$predicate}) AND {$cursor}";
            $bindings[] = $this->cursorPredicate['value'];
        }

        if ($predicate === '') {
            return new CompiledQuery('', []);
        }

        return new CompiledQuery(' WHERE ' . $predicate, $bindings);
    }
}
