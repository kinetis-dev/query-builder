<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder;

use Closure;
use InvalidArgumentException;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\PrefersPreparedStatements;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\QueryBuilder\Dialect\MySqlDialect;
use Kinetis\QueryBuilder\Dialect\PostgresDialect;
use Kinetis\QueryBuilder\Exception\InvalidPaginationException;
use Kinetis\QueryBuilder\Exception\QueryBuilderException;

/**
 * A thin, parameterized SQL query builder over the SQL MySQL 8.4,
 * MariaDB 11.4 and PostgreSQL 16 share — not an ORM. No relationships,
 * no migrations, no change-tracking, no save()-on-a-model. One class
 * serves every target through the Kinetis\Persistence\Contract\SqlLink
 * family; the spellings that differ are isolated in Dialect.
 *
 * $link accepts a plain driver client *or* an in-flight SqlTransaction —
 * both implement SqlLink — so a Query composes directly inside
 * TransactionGuard::transaction()'s callback with no transaction concept
 * of its own. A row lock is the one feature that requires the latter.
 *
 * Every compile path builds its SQL string and bound-parameter list
 * together, in a single pass, so each "?" keeps its own value in the same
 * position however structured and raw fragments mix. The bindings follow
 * the emitted SQL: CTEs, SELECT expressions, FROM subquery, joins and
 * their ON predicates, WHERE, the cursor predicate, GROUP BY, HAVING, set
 * operands, ORDER BY.
 *
 * One instance is one query: table()/select()/where()/... accumulate on
 * $this and nothing resets between calls, so reusing one instance across
 * logically separate queries merges them into a single query. Always
 * `new Query($link)` for each distinct query. The terminals that add a
 * limit, projection, order or cursor filter apply it to a clone, and
 * __clone() copies the predicate state, so the caller's builder is
 * unchanged when they return. A Query passed as a subquery, CTE or set
 * operand is compiled when it is attached: changing it afterwards does
 * not change this one.
 */
final class Query
{
    /**
     * orderBy()'s $direction and join()'s $type are interpolated into SQL
     * verbatim, as are the operators and booleans Conditions allow-lists.
     * A sortable API (`?sort=name&dir=asc`) is exactly the shape that
     * passes a request value into one of them, so each is allow-listed
     * where it is set.
     */
    private const array ALLOWED_ORDER_DIRECTIONS = ['ASC', 'DESC'];

    /**
     * INNER, LEFT and RIGHT compile to the same syntax on every target.
     * FULL has no MySQL-family form; CROSS takes no ON clause and has its
     * own crossJoin().
     */
    private const array ALLOWED_JOIN_TYPES = ['INNER', 'LEFT', 'RIGHT'];

    /**
     * MySQL, MariaDB and PostgreSQL all cap one prepared statement at
     * 65,535 bound parameters. A larger batch is refused rather than split,
     * which would turn one statement's outcome into several.
     */
    private const int MAX_PLACEHOLDERS = 65535;

    private readonly Dialect $dialect;

    /** @var Closure(Query, string): Snapshot */
    private readonly Closure $capture;

    private string $table = '';

    private ?string $tableAlias = null;

    /** @var array{sql: string, params: list<mixed>}|null */
    private ?array $fromSub = null;

    /** @var list<array{sql: string, params: list<mixed>, recursive: bool}> */
    private array $ctes = [];

    private bool $distinct = false;

    /** @var list<string> */
    private array $selectColumns = ['*'];

    /**
     * selectRaw(), selectSub() and selectExists() expressions, rendered, in
     * call order.
     *
     * @var list<array{sql: string, params: list<mixed>}>
     */
    private array $selectExpressions = [];

    /**
     * An already-quoted `expr AS alias` fragment appended to the compiled
     * SELECT list, set only on cursorPaginate()'s own clone. Separate
     * from $selectExpressions above because those are subject to
     * compileSelectColumns()'s "drop the default wildcard once something
     * explicit was asked for" rule, which is right for a caller's own
     * expression and wrong here: appending a cursor alias must never
     * silently take `*` away from a projection the caller never touched.
     */
    private ?string $cursorAliasExpression = null;

    private Conditions $wheres;

    /**
     * cursorPaginate()'s own filter, kept apart from $wheres so
     * compileWhere() can emit it as `(existing predicate) AND column > ?`
     * — appended to the flat list instead, an OR in the caller's own
     * predicate would bind tighter than the cursor and leave it applying
     * to the last OR arm alone.
     *
     * @var array{column: string, value: string}|null
     */
    private ?array $cursorPredicate = null;

    /** @var list<array{type: 'INNER'|'LEFT'|'RIGHT'|'CROSS', derived: bool, sql: string, params: list<mixed>}> */
    private array $joins = [];

    /** @var list<array{sql: string, params: list<mixed>}> */
    private array $groups = [];

    private Conditions $havings;

    /** @var list<array{sql: string, params: list<mixed>, isLimited: bool}> */
    private array $setOperations = [];

    /** @var list<array{sql: string, params: list<mixed>}> */
    private array $orders = [];

    private ?int $limitValue = null;

    private ?int $offsetValue = null;

    /** The lock suffix lockForUpdate()/lockForShare() set, with its leading space. */
    private ?string $lock = null;

    /**
     * Set by selectRaw()/groupByRaw()/orderByRaw() text containing a "?",
     * and by attaching a join, subquery, CTE or set operand whose raw SQL
     * does; the predicate clauses track their own. See run() for why this
     * disables literal-inlining for the whole query.
     */
    private bool $hasRawQuestionMark = false;

    /**
     * The link's MysqlLink or PostgresLink marker is the only dialect
     * authority. SqlTransaction is admitted so a transaction callback
     * typed against the shared contract composes; every Kinetis
     * transaction carries its link's marker, and one carrying neither is
     * refused here rather than compiled for a guessed dialect.
     */
    public function __construct(private readonly MysqlLink|PostgresLink|SqlTransaction $link)
    {
        $dialect = match (true) {
            $link instanceof MysqlLink => new MySqlDialect(),
            $link instanceof PostgresLink => new PostgresDialect(),
            default => throw QueryBuilderException::linkWithoutDialect($link::class),
        };
        $this->dialect = $dialect;
        // Static, so it keeps no reference to this instance; declared in
        // this class, so it may compile another Query's private state.
        $this->capture = static fn (Query $query, string $method): Snapshot => $query->snapshot($dialect, $method);
        $this->wheres = new Conditions($dialect, $this->capture);
        $this->havings = new Conditions($dialect, $this->capture);
    }

    /**
     * The predicate objects are the only mutable state a Query holds by
     * reference; every other property is a value or an immutable snapshot.
     */
    public function __clone()
    {
        $this->wheres = clone $this->wheres;
        $this->havings = clone $this->havings;
    }

    /**
     * $link->execute() is the shared, dialect-aware path: it resolves
     * this class's own "?" bindings by position and, through
     * persistence's scanner, treats "??" as the escape for a literal
     * "?" — needed for PostgreSQL's own jsonb "?"/"?|"/"?&" operators,
     * lexically identical to a bind placeholder where they appear.
     * $link->query() carries no such shared placeholder or escape
     * handling at all, so what a raw "?" it receives means is left to
     * the driver underneath rather than to any contract Kinetis defines
     * — driver-dependent behavior this class must not rely on. A query
     * with nothing to bind and no raw "?" therefore takes query(), and
     * one whose parameters are all safe to write into the SQL text
     * may — see inlineLiterals(), which decides on the driver rather
     * than on this class.
     *
     * Every "?" this class emits itself has exactly one binding pushed at
     * the same time, which is what makes a positional substitution safe.
     * A "?" in raw SQL text breaks that: it may be a PostgreSQL jsonb
     * operator rather than a placeholder, written "??" so persistence's
     * scanner can tell the two apart, so $hasRawQuestionMark sends the
     * whole statement to execute() rather than guessing — even with zero
     * bound parameters, since only execute() runs that scanner. query()
     * gives no such guarantee: the verified PDO PostgreSQL driver parses
     * a bare "?" as a placeholder itself and turns "??" into a literal
     * "?", while native pgsql sends both strings verbatim, so the same
     * raw fragment would mean two different things depending on which
     * driver received it. Raw text without a "?" cannot shift a
     * substitution, so it leaves inlining alone.
     *
     * @param list<mixed> $params
     */
    private function run(string $sql, array $params, bool $hasRawQuestionMark): SqlResult
    {
        if ($params === [] && !$hasRawQuestionMark) {
            return $this->link->query($sql);
        }

        $inlined = $hasRawQuestionMark ? null : $this->inlineLiterals($sql, $params);

        return $inlined !== null ? $this->link->query($inlined) : $this->link->execute($sql, $params);
    }

    /**
     * Returns null — bind instead — for any value Dialect::literalFor()
     * will not write as a literal.
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

    private function containsRawQuestionMark(): bool
    {
        return $this->hasRawQuestionMark || $this->wheres->hasRawQuestionMark() || $this->havings->hasRawQuestionMark();
    }

    /**
     * $as is quoted as an identifier. update(), increment(), decrement(),
     * delete() and the insert terminals refuse an aliased table.
     */
    public function table(string $table, ?string $as = null): static
    {
        $this->table = $table;
        $this->tableAlias = $as;
        $this->fromSub = null;

        return $this;
    }

    /** Selects from a derived table: `FROM (subquery) AS $as`. Replaces table(). */
    public function fromSub(Query $query, string $as): static
    {
        $snapshot = $this->attach($query, 'fromSub()');
        $this->fromSub = ['sql' => "({$snapshot->sql}) AS " . $this->dialect->quoteIdentifier($as), 'params' => $snapshot->params];
        $this->table = '';
        $this->tableAlias = null;

        return $this;
    }

    /**
     * A common table expression this query can select from by $name.
     * $columns names its result columns; [] leaves them to its projection.
     *
     * @param list<string> $columns
     */
    public function with(string $name, Query $query, array $columns = []): static
    {
        return $this->addCte('with()', $name, $query, $columns, false);
    }

    /**
     * A CTE that may refer to itself by $name, typically a union() of a
     * seed query and a query joining $name. One recursive CTE makes the
     * whole WITH clause `WITH RECURSIVE`.
     *
     * @param list<string> $columns
     */
    public function withRecursive(string $name, Query $query, array $columns = []): static
    {
        return $this->addCte('withRecursive()', $name, $query, $columns, true);
    }

    public function select(string ...$columns): static
    {
        $this->selectColumns = $columns === [] ? ['*'] : array_values($columns);

        return $this;
    }

    /**
     * A raw SELECT expression (an aggregate, a function call). It is
     * appended to the columns an explicit select() named, and replaces an
     * untouched default "*". Its "?" placeholders bind $params where the
     * expression appears in the SQL.
     *
     * @param list<mixed> $params
     */
    public function selectRaw(string $sql, array $params = []): static
    {
        $this->selectExpressions[] = ['sql' => $sql, 'params' => $params];
        $this->hasRawQuestionMark = $this->hasRawQuestionMark || str_contains($sql, '?');

        return $this;
    }

    /** A scalar subquery selected as $as, which may correlate with this query's tables. */
    public function selectSub(Query $query, string $as): static
    {
        $snapshot = $this->attach($query, 'selectSub()');
        $this->selectExpressions[] = [
            'sql' => "({$snapshot->sql}) AS " . $this->dialect->quoteIdentifier($as),
            'params' => $snapshot->params,
        ];

        return $this;
    }

    /**
     * Selects as $as whether $query returns a row: 1 when it does and 0
     * when it does not, on every target. $query may correlate with this
     * query's tables, as with selectSub().
     */
    public function selectExists(Query $query, string $as): static
    {
        $snapshot = $this->attach($query, 'selectExists()');
        $this->selectExpressions[] = [
            'sql' => "CASE WHEN EXISTS ({$snapshot->sql}) THEN 1 ELSE 0 END AS " . $this->dialect->quoteIdentifier($as),
            'params' => $snapshot->params,
        ];

        return $this;
    }

    public function distinct(): static
    {
        $this->distinct = true;

        return $this;
    }

    /**
     * @param 'AND'|'OR' $boolean
     * @see Conditions::where() for null handling
     */
    public function where(string $column, string $operator, mixed $value, string $boolean = 'AND'): static
    {
        $this->wheres->where($column, $operator, $value, $boolean);

        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value): static
    {
        $this->wheres->orWhere($column, $operator, $value);

        return $this;
    }

    public function whereColumn(string $first, string $operator, string $second): static
    {
        $this->wheres->whereColumn($first, $operator, $second);

        return $this;
    }

    public function orWhereColumn(string $first, string $operator, string $second): static
    {
        $this->wheres->orWhereColumn($first, $operator, $second);

        return $this;
    }

    /**
     * @param list<mixed>|Query $values
     * @param 'AND'|'OR' $boolean
     * @see Conditions::whereIn()
     */
    public function whereIn(string $column, array|Query $values, string $boolean = 'AND'): static
    {
        $this->wheres->whereIn($column, $values, $boolean);

        return $this;
    }

    /**
     * @param list<mixed>|Query $values
     * @param 'AND'|'OR' $boolean
     * @see Conditions::whereNotIn()
     */
    public function whereNotIn(string $column, array|Query $values, string $boolean = 'AND'): static
    {
        $this->wheres->whereNotIn($column, $values, $boolean);

        return $this;
    }

    public function whereBetween(string $column, mixed $low, mixed $high): static
    {
        $this->wheres->whereBetween($column, $low, $high);

        return $this;
    }

    public function orWhereBetween(string $column, mixed $low, mixed $high): static
    {
        $this->wheres->orWhereBetween($column, $low, $high);

        return $this;
    }

    public function whereNotBetween(string $column, mixed $low, mixed $high): static
    {
        $this->wheres->whereNotBetween($column, $low, $high);

        return $this;
    }

    public function orWhereNotBetween(string $column, mixed $low, mixed $high): static
    {
        $this->wheres->orWhereNotBetween($column, $low, $high);

        return $this;
    }

    public function whereExists(Query $query): static
    {
        $this->wheres->whereExists($query);

        return $this;
    }

    public function orWhereExists(Query $query): static
    {
        $this->wheres->orWhereExists($query);

        return $this;
    }

    public function whereNotExists(Query $query): static
    {
        $this->wheres->whereNotExists($query);

        return $this;
    }

    public function orWhereNotExists(Query $query): static
    {
        $this->wheres->orWhereNotExists($query);

        return $this;
    }

    /**
     * @param list<mixed> $params
     * @param 'AND'|'OR' $boolean
     * @see Conditions::whereRaw()
     */
    public function whereRaw(string $sql, array $params = [], string $boolean = 'AND'): static
    {
        $this->wheres->whereRaw($sql, $params, $boolean);

        return $this;
    }

    /**
     * @param Closure(Conditions): mixed $group
     * @see Conditions::whereGroup()
     */
    public function whereGroup(Closure $group): static
    {
        $this->wheres->whereGroup($group);

        return $this;
    }

    /**
     * @param Closure(Conditions): mixed $group
     */
    public function orWhereGroup(Closure $group): static
    {
        $this->wheres->orWhereGroup($group);

        return $this;
    }

    /** A join ON one column comparison: `join('users', 'users.id', '=', 'articles.author_id')`. */
    public function join(
        string $table,
        string $first,
        string $operator,
        string $second,
        string $type = 'INNER',
        ?string $as = null,
    ): static {
        return $this->joinOn(
            $table,
            static fn (Conditions $on): Conditions => $on->whereColumn($first, $operator, $second),
            $type,
            $as,
        );
    }

    public function leftJoin(string $table, string $first, string $operator, string $second, ?string $as = null): static
    {
        return $this->join($table, $first, $operator, $second, 'LEFT', $as);
    }

    /**
     * A join whose ON clause is every predicate $on adds — column
     * comparisons, bound values, groups, subqueries.
     *
     * @param Closure(Conditions): mixed $on
     */
    public function joinOn(string $table, Closure $on, string $type = 'INNER', ?string $as = null): static
    {
        $normalizedType = self::assertAllowedJoinType($type);

        return $this->addJoin($normalizedType, $this->tableReference($table, $as), [], false, $on);
    }

    /**
     * Joins a derived table: `JOIN (subquery) AS $as ON ...`.
     *
     * @param Closure(Conditions): mixed $on
     */
    public function joinSub(Query $query, string $as, Closure $on, string $type = 'INNER'): static
    {
        $normalizedType = self::assertAllowedJoinType($type);
        $snapshot = $this->attach($query, 'joinSub()');

        return $this->addJoin(
            $normalizedType,
            "({$snapshot->sql}) AS " . $this->dialect->quoteIdentifier($as),
            $snapshot->params,
            true,
            $on,
        );
    }

    public function crossJoin(string $table, ?string $as = null): static
    {
        $this->joins[] = [
            'type' => 'CROSS',
            'derived' => false,
            'sql' => ' CROSS JOIN ' . $this->tableReference($table, $as),
            'params' => [],
        ];

        return $this;
    }

    public function groupBy(string ...$columns): static
    {
        foreach ($columns as $column) {
            $this->groups[] = ['sql' => $this->dialect->quoteIdentifier($column), 'params' => []];
        }

        return $this;
    }

    /**
     * @param list<mixed> $params
     */
    public function groupByRaw(string $sql, array $params = []): static
    {
        $this->groups[] = ['sql' => $sql, 'params' => $params];
        $this->hasRawQuestionMark = $this->hasRawQuestionMark || str_contains($sql, '?');

        return $this;
    }

    /**
     * A HAVING comparison on a grouped column, with where()'s operators and
     * null handling. Compare an aggregate through havingRaw().
     *
     * @param 'AND'|'OR' $boolean
     */
    public function having(string $column, string $operator, mixed $value, string $boolean = 'AND'): static
    {
        $this->havings->where($column, $operator, $value, $boolean);

        return $this;
    }

    public function orHaving(string $column, string $operator, mixed $value): static
    {
        $this->havings->orWhere($column, $operator, $value);

        return $this;
    }

    /**
     * @param list<mixed> $params
     * @param 'AND'|'OR' $boolean
     */
    public function havingRaw(string $sql, array $params = [], string $boolean = 'AND'): static
    {
        if (trim($sql) === '') {
            throw new InvalidArgumentException('havingRaw() needs a SQL fragment: an empty one compiles to an invalid HAVING clause.');
        }

        $this->havings->whereRaw($sql, $params, $boolean);

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->orders[] = [
            'sql' => $this->dialect->quoteIdentifier($column) . ' ' . self::assertAllowedDirection($direction),
            'params' => [],
        ];

        return $this;
    }

    /**
     * @param list<mixed> $params
     */
    public function orderByRaw(string $sql, array $params = []): static
    {
        $this->orders[] = ['sql' => $sql, 'params' => $params];
        $this->hasRawQuestionMark = $this->hasRawQuestionMark || str_contains($sql, '?');

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
     * Combines this query's rows with $query's. On a query carrying set
     * operations, orderBy()/limit()/offset() apply to the combined result;
     * $query's own stay inside its parentheses. Each further operation
     * groups everything before it, so operations apply in call order.
     */
    public function union(Query $query, bool $all = false): static
    {
        return $this->addSetOperation('union()', 'UNION', $query, $all);
    }

    public function intersect(Query $query, bool $all = false): static
    {
        return $this->addSetOperation('intersect()', 'INTERSECT', $query, $all);
    }

    public function except(Query $query, bool $all = false): static
    {
        return $this->addSetOperation('except()', 'EXCEPT', $query, $all);
    }

    /**
     * An exclusive row lock on the selected rows until the transaction
     * ends. Admitted only on get(), first(), value() and pluck() of a
     * Query built on an active SqlTransaction, over one table or inner
     * joins — see assertLockIsPortable().
     */
    public function lockForUpdate(LockWait $wait = LockWait::Wait): static
    {
        $this->lock = match ($wait) {
            LockWait::Wait => ' FOR UPDATE',
            LockWait::NoWait => ' FOR UPDATE NOWAIT',
            LockWait::SkipLocked => ' FOR UPDATE SKIP LOCKED',
        };

        return $this;
    }

    /**
     * A shared row lock: other transactions may read the rows and take
     * shared locks, but not modify them, until this transaction ends. It
     * always waits. Same admitted domain as lockForUpdate().
     */
    public function lockForShare(): static
    {
        $this->lock = $this->dialect->sharedLock();

        return $this;
    }

    /**
     * @template T of object
     * @param class-string<T>|null $dtoClass
     * @return ($dtoClass is null ? list<array<string, mixed>> : list<T>)
     */
    public function get(?string $dtoClass = null): array
    {
        if ($this->lock !== null && !($this->link instanceof SqlTransaction && $this->link->isActive())) {
            throw QueryBuilderException::lockNeedsATransaction();
        }

        $mapper = $dtoClass !== null ? RowMapper::for($dtoClass) : null;
        $compiled = $this->toSelectSql();
        $result = $this->run($compiled->sql, $compiled->params, $this->containsRawQuestionMark());

        $rows = [];

        foreach ($result as $row) {
            $rows[] = $mapper !== null ? $mapper->map($row) : $row;
        }

        return $rows;
    }

    /**
     * @template T of object
     * @param class-string<T>|null $dtoClass
     * @return ($dtoClass is null ? array<string, mixed>|null : T|null)
     */
    public function first(?string $dtoClass = null): object|array|null
    {
        $one = clone $this;
        $one->limitValue = 1;

        return $one->get($dtoClass)[0] ?? null;
    }

    /**
     * The $column value of the first row, or null when there is none. The
     * column is read from the row by its result name, so select it under
     * that name; the projection is not changed.
     */
    public function value(string $column): mixed
    {
        $row = $this->first();

        return $row === null ? null : self::readColumn($row, $column, 'value()');
    }

    /**
     * The $column value of every row, in result order, read by result
     * name like value().
     *
     * @return list<mixed>
     */
    public function pluck(string $column): array
    {
        $values = [];

        foreach ($this->get() as $row) {
            $values[] = self::readColumn($row, $column, 'pluck()');
        }

        return $values;
    }

    /** Whether the query returns at least one row. */
    public function exists(): bool
    {
        $this->assertUnlocked('exists()');

        $with = $this->compileWith();
        $query = $this->compileQueryExpression(true);
        $result = $this->run(
            $with->sql . "SELECT CASE WHEN EXISTS ({$query->sql}) THEN 1 ELSE 0 END AS aggregate",
            [...$with->params, ...$query->params],
            $this->containsRawQuestionMark(),
        );

        return (int) ($result->fetchRow()['aggregate'] ?? 0) === 1;
    }

    /**
     * The number of rows the query returns, ignoring its order, limit and
     * offset. A distinct, grouped, HAVING or set-operation query is counted
     * by its logical result rows — see compileAggregate().
     */
    public function count(): int
    {
        return (int) ($this->aggregate('count()', 'COUNT(*)') ?? 0);
    }

    /** SUM($column) over the same rows count() counts; null when there are none. */
    public function sum(string $column): int|float|string|null
    {
        return $this->aggregate('sum()', 'SUM(' . $this->dialect->quoteIdentifier($column) . ')');
    }

    public function min(string $column): int|float|string|null
    {
        return $this->aggregate('min()', 'MIN(' . $this->dialect->quoteIdentifier($column) . ')');
    }

    public function max(string $column): int|float|string|null
    {
        return $this->aggregate('max()', 'MAX(' . $this->dialect->quoteIdentifier($column) . ')');
    }

    public function avg(string $column): int|float|string|null
    {
        return $this->aggregate('avg()', 'AVG(' . $this->dialect->quoteIdentifier($column) . ')');
    }

    /**
     * Offset-based pagination: page()/perPage(), with a total count and
     * page count. The page's own limit and offset are applied to a
     * clone, and count() reads the same logical result without them, so
     * the two executions describe one logical query and the caller's
     * builder is untouched by either.
     *
     * An order is required: without one the server may return rows in
     * any order it likes, and page 2 can then repeat or skip rows from
     * page 1.
     *
     * @param class-string|null $dtoClass
     */
    public function paginate(int $perPage, int $page = 1, ?string $dtoClass = null): Paginator
    {
        $this->assertUnlocked('paginate()');

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
     * stripped from every returned row before mapping.
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
        $mapper = $dtoClass !== null ? RowMapper::for($dtoClass) : null;

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
        $result = $page->run($compiled->sql, $compiled->params, $page->containsRawQuestionMark());

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

        return new CursorPaginator($mapper !== null ? array_map($mapper->map(...), $rows) : $rows, $nextCursor, $hasMore);
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
        $this->assertUnlocked('cursorPaginate()');

        if ($this->setOperations !== []) {
            throw QueryBuilderException::cursorPaginationOverSetOperation();
        }

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
     * Inserts one row (a column => value map) or a batch (a list of such
     * maps, every one naming identical columns in identical order) as one
     * statement.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $values
     */
    public function insert(array $values): void
    {
        [$columns, $rows] = $this->rowsFor('insert()', $values);
        $compiled = $this->compileInsert($columns, $rows, '');

        $this->run($compiled->sql, $compiled->params, false);
    }

    /**
     * Inserts one row and returns $primaryKey's generated value.
     *
     * @param array<string, mixed> $values
     */
    public function insertGetId(array $values, string $primaryKey = 'id'): int|string|null
    {
        if (self::isBatch($values)) {
            throw new InvalidArgumentException(
                'insertGetId() inserts one row: pass a single column => value map, and insert() for a batch.',
            );
        }

        [$columns, $rows] = $this->rowsFor('insertGetId()', $values);
        $compiled = $this->compileInsert($columns, $rows, $this->dialect->insertGetIdClause($primaryKey));
        $result = $this->run($compiled->sql, $compiled->params, false);

        return $this->dialect->extractInsertedId($result, $primaryKey);
    }

    /**
     * `INSERT INTO table (columns) <select>` and returns the affected-row
     * count. A WITH clause on $select stays in front of its SELECT, the
     * placement every target accepts inside INSERT.
     *
     * @param list<string> $columns
     */
    public function insertUsing(array $columns, Query $select): int
    {
        $this->assertOnlyATable('insertUsing()');

        if ($columns === []) {
            throw new InvalidArgumentException('insertUsing() needs at least one column to insert into.');
        }

        if ($select->dialect::class !== $this->dialect::class) {
            throw QueryBuilderException::subqueryFromAnotherDialect('insertUsing()');
        }

        if ($select->lock !== null) {
            throw QueryBuilderException::subqueryCarriesLock('insertUsing()');
        }

        $source = $select->toSelectSql();
        $sql = 'INSERT INTO ' . $this->dialect->quoteIdentifier($this->table)
            . ' (' . implode(', ', array_map($this->dialect->quoteIdentifier(...), $columns)) . ') ' . $source->sql;

        return $this->run($sql, $source->params, $select->containsRawQuestionMark())->getRowCount() ?? 0;
    }

    /**
     * Inserts the row or batch, skipping every row the server reports as
     * conflicting, and returns the number of rows inserted. Any other
     * error still fails the whole statement.
     *
     * Which conflicts are skipped differs: the MySQL family skips a
     * unique-key conflict, and PostgreSQL's targetless ON CONFLICT DO
     * NOTHING also skips an exclusion-constraint conflict that insert()
     * would raise as SQLSTATE 23P01. The count is rows inserted and does
     * not say which constraint held a row back.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $values
     */
    public function insertOrIgnore(array $values): int
    {
        [$columns, $rows] = $this->rowsFor('insertOrIgnore()', $values);
        $compiled = $this->compileInsert($columns, $rows, $this->dialect->insertOrIgnoreClause($columns));

        return $this->run($compiled->sql, $compiled->params, false)->getRowCount() ?? 0;
    }

    /**
     * Inserts the row or batch; a row conflicting with a unique key instead
     * updates the $update columns of the existing row to the values it
     * tried to insert. Returns the server's affected-row count.
     *
     * PostgreSQL resolves only a conflict on exactly $uniqueBy's unique
     * constraint. The MySQL family resolves a conflict on any unique key
     * and counts an updated row as 2.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $values
     * @param list<string> $uniqueBy
     * @param list<string> $update
     */
    public function upsert(array $values, array $uniqueBy, array $update): int
    {
        [$columns, $rows] = $this->rowsFor('upsert()', $values);

        if ($uniqueBy === []) {
            throw new InvalidArgumentException('upsert() needs at least one column in $uniqueBy.');
        }

        if ($update === []) {
            throw new InvalidArgumentException('upsert() needs at least one column in $update.');
        }

        foreach (['$uniqueBy' => $uniqueBy, '$update' => $update] as $argument => $names) {
            foreach ($names as $name) {
                if (!in_array($name, $columns, true)) {
                    throw new InvalidArgumentException(
                        "upsert()'s {$argument} names \"{$name}\", which is not one of the inserted columns.",
                    );
                }
            }
        }

        $compiled = $this->compileInsert($columns, $rows, $this->dialect->upsertClause($uniqueBy, $update));

        return $this->run($compiled->sql, $compiled->params, false)->getRowCount() ?? 0;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(array $values): int
    {
        $compiled = $this->toUpdateSql($values);

        return $this->run($compiled->sql, $compiled->params, $this->containsRawQuestionMark())->getRowCount() ?? 0;
    }

    /**
     * `SET column = column + amount`, plus any $extra column => value
     * assignments, under update()'s narrowing rules.
     *
     * @param array<string, mixed> $extra
     */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->arithmetic('increment()', $column, '+', $amount, $extra);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->arithmetic('decrement()', $column, '-', $amount, $extra);
    }

    public function delete(): int
    {
        $compiled = $this->toDeleteSql();

        return $this->run($compiled->sql, $compiled->params, $this->containsRawQuestionMark())->getRowCount() ?? 0;
    }

    public function toSelectSql(): CompiledQuery
    {
        $this->assertLockIsPortable();

        $with = $this->compileWith();
        $query = $this->compileQueryExpression(true);

        return new CompiledQuery($with->sql . $query->sql . ($this->lock ?? ''), [...$with->params, ...$query->params]);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function toUpdateSql(array $values): CompiledQuery
    {
        $this->assertMutationIsNarrowed('update()');

        if ($values === []) {
            throw new InvalidArgumentException(
                'update() needs at least one column — an empty array compiles to invalid SQL '
                . '("UPDATE t SET  WHERE ...").',
            );
        }

        return $this->compileUpdate([], [], $values);
    }

    public function toDeleteSql(): CompiledQuery
    {
        $this->assertMutationIsNarrowed('delete()');

        $where = $this->wheres->compile();

        return new CompiledQuery(
            'DELETE FROM ' . $this->dialect->quoteIdentifier($this->table) . ' WHERE ' . $where->sql,
            $where->params,
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function arithmetic(string $method, string $column, string $operator, int|float $amount, array $extra): int
    {
        $this->assertMutationIsNarrowed($method);

        if (array_key_exists($column, $extra)) {
            throw new InvalidArgumentException(
                "{$method} cannot also assign \"{$column}\" through \$extra: one statement cannot set a column twice.",
            );
        }

        $quoted = $this->dialect->quoteIdentifier($column);
        $compiled = $this->compileUpdate(["{$quoted} = {$quoted} {$operator} ?"], [$amount], $extra);

        return $this->run($compiled->sql, $compiled->params, $this->containsRawQuestionMark())->getRowCount() ?? 0;
    }

    /**
     * @param list<string> $sets already-rendered assignments
     * @param list<mixed> $params their bindings
     * @param array<string, mixed> $values column => value assignments appended after them
     */
    private function compileUpdate(array $sets, array $params, array $values): CompiledQuery
    {
        foreach ($values as $column => $value) {
            $sets[] = $this->dialect->quoteIdentifier((string) $column) . ' = ?';
            $params[] = $value;
        }

        $where = $this->wheres->compile();

        return new CompiledQuery(
            'UPDATE ' . $this->dialect->quoteIdentifier($this->table) . ' SET ' . implode(', ', $sets)
            . ' WHERE ' . $where->sql,
            [...$params, ...$where->params],
        );
    }

    /**
     * Whether $values is shaped as a batch (a non-empty list of rows)
     * rather than one column => value map. Typed for any array, since a
     * caller can pass a batch where a single row is documented.
     *
     * @param array<array-key, mixed> $values
     */
    private static function isBatch(array $values): bool
    {
        return $values !== [] && array_is_list($values);
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array{0: non-empty-list<string>, 1: non-empty-list<list<mixed>>}
     */
    private function rowsFor(string $method, array $values): array
    {
        $this->assertOnlyATable($method);

        if ($values === []) {
            throw new InvalidArgumentException(
                "{$method} needs at least one column — an empty array compiles to invalid SQL "
                . '("INSERT INTO t () VALUES ()"). Pass the columns you want set to their default '
                . 'values explicitly if that\'s the intent; this class has no DEFAULT VALUES shorthand.',
            );
        }

        $rows = array_is_list($values) ? $values : [$values];
        $columns = [];
        $matrix = [];

        foreach ($rows as $i => $row) {
            if (!is_array($row) || $row === []) {
                throw new InvalidArgumentException("{$method} row {$i} must be a non-empty column => value map.");
            }

            $keys = array_map(static fn (int|string $key): string => (string) $key, array_keys($row));

            if ($i === 0) {
                $columns = $keys;
            } elseif ($keys !== $columns) {
                throw new InvalidArgumentException(
                    "{$method} row {$i} does not name the same columns in the same order as row 0. Every row of "
                    . 'a batch shares one column list.',
                );
            }

            $matrix[] = array_values($row);
        }

        $placeholders = count($matrix) * count($columns);

        if ($placeholders > self::MAX_PLACEHOLDERS) {
            throw new InvalidArgumentException(
                "{$method} would bind {$placeholders} values in one statement; MySQL, MariaDB and PostgreSQL "
                . 'accept at most ' . self::MAX_PLACEHOLDERS . '. Insert fewer rows per call.',
            );
        }

        /** @var non-empty-list<string> $columns */
        return [$columns, $matrix];
    }

    /**
     * @param non-empty-list<string> $columns
     * @param non-empty-list<list<mixed>> $rows
     */
    private function compileInsert(array $columns, array $rows, string $suffix): CompiledQuery
    {
        $tuple = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        return new CompiledQuery(
            'INSERT INTO ' . $this->dialect->quoteIdentifier($this->table)
            . ' (' . implode(', ', array_map($this->dialect->quoteIdentifier(...), $columns)) . ') VALUES '
            . implode(', ', array_fill(0, count($rows), $tuple)) . $suffix,
            array_merge(...$rows),
        );
    }

    /**
     * Compiles this query for embedding in $parent's SQL. A CTE belongs
     * to the outermost query and a lock to the outermost select, so a
     * Query carrying either is refused rather than nested.
     */
    private function snapshot(Dialect $parent, string $method): Snapshot
    {
        if ($this->dialect::class !== $parent::class) {
            throw QueryBuilderException::subqueryFromAnotherDialect($method);
        }

        if ($this->ctes !== []) {
            throw QueryBuilderException::subqueryCarriesCte($method);
        }

        if ($this->lock !== null) {
            throw QueryBuilderException::subqueryCarriesLock($method);
        }

        $compiled = $this->compileQueryExpression(true);

        return new Snapshot($compiled->sql, $compiled->params, $this->containsRawQuestionMark(), $this->isLimited());
    }

    private function attach(Query $query, string $method): Snapshot
    {
        $snapshot = ($this->capture)($query, $method);
        $this->hasRawQuestionMark = $this->hasRawQuestionMark || $snapshot->hasRawQuestionMark;

        return $snapshot;
    }

    private function isLimited(): bool
    {
        if ($this->limitValue !== null || $this->offsetValue !== null) {
            return true;
        }

        foreach ($this->setOperations as $operation) {
            if ($operation['isLimited']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $columns
     */
    private function addCte(string $method, string $name, Query $query, array $columns, bool $recursive): static
    {
        $snapshot = $this->attach($query, $method);
        $sql = $this->dialect->quoteIdentifier($name);

        if ($columns !== []) {
            $sql .= ' (' . implode(', ', array_map($this->dialect->quoteIdentifier(...), $columns)) . ')';
        }

        $this->ctes[] = ['sql' => "{$sql} AS ({$snapshot->sql})", 'params' => $snapshot->params, 'recursive' => $recursive];

        return $this;
    }

    /**
     * @param 'INNER'|'LEFT'|'RIGHT' $type
     * @param list<mixed> $params the source's own bindings
     * @param Closure(Conditions): mixed $on
     */
    private function addJoin(string $type, string $source, array $params, bool $derived, Closure $on): static
    {
        $conditions = new Conditions($this->dialect, $this->capture);
        $on($conditions);

        if ($conditions->isEmpty()) {
            throw new InvalidArgumentException(
                "A {$type} JOIN needs at least one ON predicate. Use crossJoin() to join every row with every row.",
            );
        }

        $compiled = $conditions->compile();
        $this->joins[] = [
            'type' => $type,
            'derived' => $derived,
            'sql' => " {$type} JOIN {$source} ON {$compiled->sql}",
            'params' => [...$params, ...$compiled->params],
        ];
        $this->hasRawQuestionMark = $this->hasRawQuestionMark || $conditions->hasRawQuestionMark();

        return $this;
    }

    private function addSetOperation(string $method, string $keyword, Query $query, bool $all): static
    {
        $snapshot = $this->attach($query, $method);
        $this->setOperations[] = [
            'sql' => " {$keyword}" . ($all ? ' ALL' : '') . " ({$snapshot->sql})",
            'params' => $snapshot->params,
            'isLimited' => $snapshot->isLimited,
        ];

        return $this;
    }

    private function tableReference(string $table, ?string $as): string
    {
        $reference = $this->dialect->quoteIdentifier($table);

        return $as === null ? $reference : $reference . ' AS ' . $this->dialect->quoteIdentifier($as);
    }

    private function aggregate(string $method, string $expression): int|float|string|null
    {
        $this->assertUnlocked($method);

        $compiled = $this->compileAggregate($expression);
        $row = $this->run($compiled->sql, $compiled->params, $this->containsRawQuestionMark())->fetchRow();

        /** @var int|float|string|null $value an aggregate column is a scalar or NULL in every driver's row */
        $value = $row['aggregate'] ?? null;

        return $value;
    }

    /**
     * A plain query aggregates its FROM, joins and WHERE directly, with no
     * projection and so none of its bindings. A distinct, grouped, HAVING
     * or set-operation query's logical result is its projected rows, so
     * the aggregate reads them from a derived table that keeps every
     * projection, group, HAVING and operand binding. Either way the outer
     * order, limit and offset play no part.
     */
    private function compileAggregate(string $expression): CompiledQuery
    {
        $with = $this->compileWith();

        if ($this->distinct || $this->groups !== [] || !$this->havings->isEmpty() || $this->setOperations !== []) {
            $inner = $this->compileQueryExpression(false);
            $query = new CompiledQuery(
                "SELECT {$expression} AS aggregate FROM ({$inner->sql}) AS aggregate_source",
                $inner->params,
            );
        } else {
            $query = $this->compileBody(new CompiledQuery("{$expression} AS aggregate", []), false);
        }

        return new CompiledQuery($with->sql . $query->sql, [...$with->params, ...$query->params]);
    }

    private function compileWith(): CompiledQuery
    {
        if ($this->ctes === []) {
            return new CompiledQuery('', []);
        }

        $definitions = [];
        $params = [];
        $recursive = false;

        foreach ($this->ctes as $cte) {
            $definitions[] = $cte['sql'];
            array_push($params, ...$cte['params']);
            $recursive = $recursive || $cte['recursive'];
        }

        return new CompiledQuery('WITH ' . ($recursive ? 'RECURSIVE ' : '') . implode(', ', $definitions) . ' ', $params);
    }

    /**
     * The select as one query expression: its body, its set operations
     * and — when $outer — the ORDER BY, LIMIT and OFFSET of the whole
     * result. Never the WITH clause or the lock, so a snapshot, an
     * aggregate and exists() can embed exactly this.
     */
    private function compileQueryExpression(bool $outer): CompiledQuery
    {
        $body = $this->compileBody($this->compileSelectColumns(), $this->distinct);
        $sql = $body->sql;
        $params = $body->params;

        if ($this->setOperations !== []) {
            $sql = "({$sql})";

            foreach ($this->setOperations as $i => $operation) {
                if ($i > 0) {
                    $sql = "({$sql})";
                }

                $sql .= $operation['sql'];
                array_push($params, ...$operation['params']);
            }
        }

        if (!$outer) {
            return new CompiledQuery($sql, $params);
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', array_column($this->orders, 'sql'));

            foreach ($this->orders as $order) {
                array_push($params, ...$order['params']);
            }
        }

        // Interpolated rather than bound: both are validated PHP ints.
        return new CompiledQuery($sql . $this->dialect->limitOffset($this->limitValue, $this->offsetValue), $params);
    }

    private function compileBody(CompiledQuery $projection, bool $distinct): CompiledQuery
    {
        $sql = 'SELECT ' . ($distinct ? 'DISTINCT ' : '') . $projection->sql . ' FROM ';
        $params = $projection->params;

        if ($this->fromSub !== null) {
            $sql .= $this->fromSub['sql'];
            array_push($params, ...$this->fromSub['params']);
        } else {
            $sql .= $this->tableReference($this->table, $this->tableAlias);
        }

        foreach ($this->joins as $join) {
            $sql .= $join['sql'];
            array_push($params, ...$join['params']);
        }

        $where = $this->compileWhere();
        $sql .= $where->sql;
        array_push($params, ...$where->params);

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_column($this->groups, 'sql'));

            foreach ($this->groups as $group) {
                array_push($params, ...$group['params']);
            }
        }

        if (!$this->havings->isEmpty()) {
            $having = $this->havings->compile();
            $sql .= ' HAVING ' . $having->sql;
            array_push($params, ...$having->params);
        }

        return new CompiledQuery($sql, $params);
    }

    /**
     * The default "*" is dropped once anything explicit — select(),
     * selectRaw(), selectSub() or selectExists() — has been specified: a
     * caller reaching only for selectRaw('COUNT(*) AS total') wants exactly
     * that, not also every column. Once select() has been called
     * $selectColumns is no longer literally ['*'], so the explicit columns
     * and the expressions combine normally.
     */
    private function compileSelectColumns(): CompiledQuery
    {
        $expressions = array_map(
            $this->compileSelectColumn(...),
            $this->selectColumns === ['*'] && $this->selectExpressions !== [] ? [] : $this->selectColumns,
        );
        $params = [];

        foreach ($this->selectExpressions as $expression) {
            $expressions[] = $expression['sql'];
            array_push($params, ...$expression['params']);
        }

        // Appended after that rule: a cursor alias is this class's own
        // addition, not something the caller asked to see, so it must
        // never be what turns an untouched `*` into an explicit
        // projection.
        if ($this->cursorAliasExpression !== null) {
            $expressions[] = $this->cursorAliasExpression;
        }

        return new CompiledQuery(implode(', ', $expressions), $params);
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

    private function compileWhere(): CompiledQuery
    {
        $where = $this->wheres->compile();
        $predicate = $where->sql;
        $params = $where->params;

        if ($this->cursorPredicate !== null) {
            // Parenthesized: an OR anywhere in the caller's own
            // predicate binds tighter than this AND, which would leave
            // the cursor filtering the last OR arm alone.
            $cursor = $this->dialect->quoteIdentifier($this->cursorPredicate['column']) . ' > ?';
            $predicate = $predicate === '' ? $cursor : "({$predicate}) AND {$cursor}";
            $params[] = $this->cursorPredicate['value'];
        }

        return $predicate === '' ? new CompiledQuery('', []) : new CompiledQuery(' WHERE ' . $predicate, $params);
    }

    /**
     * UPDATE and DELETE compile the table and the WHERE clause, nothing
     * else. A query with no predicate at all matches every row, and any
     * other accumulated state would be dropped from the statement —
     * turning a mutation the caller narrowed with a join, limit or lock
     * into one that matches every row the WHERE clause alone allows. Both
     * are refused instead. A deliberate whole-table statement, like a
     * joined, ordered or limited mutation, runs as raw SQL through the
     * link itself.
     */
    private function assertMutationIsNarrowed(string $method): void
    {
        if ($this->wheres->isEmpty()) {
            throw QueryBuilderException::mutationNeedsAPredicate($method);
        }

        $unsupported = $this->clausesBeyondTableAndWhere();

        if ($unsupported !== []) {
            throw QueryBuilderException::unsupportedMutationClauses($method, $unsupported);
        }
    }

    /** An INSERT compiles the table and its rows; every other clause would be dropped. */
    private function assertOnlyATable(string $method): void
    {
        $unsupported = $this->clausesBeyondTableAndWhere();

        if (!$this->wheres->isEmpty()) {
            array_unshift($unsupported, 'where predicates');
        }

        if ($unsupported !== []) {
            throw QueryBuilderException::unsupportedInsertClauses($method, $unsupported);
        }
    }

    /**
     * Every configured clause other than table() and the WHERE predicates,
     * named by the calls that set it.
     *
     * @return list<string>
     */
    private function clausesBeyondTableAndWhere(): array
    {
        return array_keys(array_filter([
            'with()/withRecursive()' => $this->ctes !== [],
            'a table() alias' => $this->tableAlias !== null,
            'fromSub()' => $this->fromSub !== null,
            'distinct()' => $this->distinct,
            'select()/selectRaw()/selectSub()/selectExists()' => $this->selectColumns !== ['*'] || $this->selectExpressions !== [],
            'join()/leftJoin()/joinOn()/joinSub()/crossJoin()' => $this->joins !== [],
            'groupBy()/groupByRaw()' => $this->groups !== [],
            'having()/orHaving()/havingRaw()' => !$this->havings->isEmpty(),
            'union()/intersect()/except()' => $this->setOperations !== [],
            'orderBy()/orderByRaw()' => $this->orders !== [],
            'limit()' => $this->limitValue !== null,
            'offset()' => $this->offsetValue !== null,
            'lockForUpdate()/lockForShare()' => $this->lock !== null,
        ]));
    }

    /**
     * The admitted lock domain is a select from one table or inner joins,
     * with predicates, ordering, limit and offset, kept narrow so every
     * target locks the rows the query reads. PostgreSQL rejects a lock
     * with DISTINCT, GROUP BY, HAVING, a set operation or the nullable
     * side of an outer join; derived tables, CTEs and cross joins stay
     * outside the domain as well.
     */
    private function assertLockIsPortable(): void
    {
        if ($this->lock === null) {
            return;
        }

        $clauses = array_keys(array_filter([
            'with()/withRecursive()' => $this->ctes !== [],
            'fromSub()' => $this->fromSub !== null,
            'distinct()' => $this->distinct,
            'groupBy()/groupByRaw()' => $this->groups !== [],
            'having()/orHaving()/havingRaw()' => !$this->havings->isEmpty(),
            'union()/intersect()/except()' => $this->setOperations !== [],
            'a LEFT or RIGHT join' => $this->hasJoin(static fn (array $join): bool => $join['type'] === 'LEFT' || $join['type'] === 'RIGHT'),
            'crossJoin()' => $this->hasJoin(static fn (array $join): bool => $join['type'] === 'CROSS'),
            'joinSub()' => $this->hasJoin(static fn (array $join): bool => $join['derived']),
        ]));

        if ($clauses !== []) {
            throw QueryBuilderException::lockCannotCombine($clauses);
        }
    }

    /**
     * @param Closure(array{type: 'INNER'|'LEFT'|'RIGHT'|'CROSS', derived: bool, sql: string, params: list<mixed>}): bool $matches
     */
    private function hasJoin(Closure $matches): bool
    {
        foreach ($this->joins as $join) {
            if ($matches($join)) {
                return true;
            }
        }

        return false;
    }

    private function assertUnlocked(string $method): void
    {
        if ($this->lock !== null) {
            throw QueryBuilderException::lockedTerminal($method);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function readColumn(array $row, string $column, string $method): mixed
    {
        if (!array_key_exists($column, $row)) {
            throw QueryBuilderException::columnMissingFromRow($method, $column);
        }

        return $row[$column];
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

    /**
     * @return 'INNER'|'LEFT'|'RIGHT'
     */
    private static function assertAllowedJoinType(string $type): string
    {
        $normalized = strtoupper(trim($type));

        if (!in_array($normalized, self::ALLOWED_JOIN_TYPES, true)) {
            throw new InvalidArgumentException(
                "Join type \"{$type}\" is not allowed. Use one of: " . implode(', ', self::ALLOWED_JOIN_TYPES) . '.',
            );
        }

        /** @var 'INNER'|'LEFT'|'RIGHT' $normalized */
        return $normalized;
    }
}
