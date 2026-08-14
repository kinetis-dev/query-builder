<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder;

use Kinetis\Http\Pagination\CursorPaginator;
use Kinetis\Http\Pagination\Paginator;
use Kinetis\QueryBuilder\Dialect\MySqlDialect;
use Kinetis\QueryBuilder\Dialect\PostgresDialect;
use Kinetis\Validation\Hydrator;
use Amp\Mysql\MysqlLink;
use Amp\Mysql\MysqlResult;
use Amp\Postgres\PostgresLink;
use Amp\Postgres\PostgresResult;
use InvalidArgumentException;

/**
 * A thin, parameterized SQL query builder — not an ORM. No relationships,
 * no migrations, no change-tracking, no save()-on-a-model. Works with
 * either MySQL or Postgres through the shared Amp\Sql\SqlLink interface,
 * exactly like Kinetis\Persistence\TransactionGuard already does — one
 * class, not one package per backend (see detectDialect()): the two
 * backends only genuinely differ on identifier quoting and how a
 * generated primary key is retrieved after an INSERT, both isolated in
 * Dialect, not spread through this class.
 *
 * $link accepts a plain connection pool *or* an in-flight
 * Amp\Sql\SqlTransaction — both implement SqlLink — so a Query composes
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
     * where()'s $operator, orderBy()'s $direction, and join()'s $type were
     * previously interpolated into SQL verbatim, unlike every other
     * user-reachable slot in this class — a real SQL injection point an
     * independent security review found, since a sortable/filterable API
     * (`?sort=name&dir=asc&op=gte`) is exactly the shape that passes these
     * through from a request. Allow-listing them here is a construction-
     * time boundary, the same shape as CorsMiddleware/AsGlobalMiddleware's
     * own constructor guards — every other value/identifier in this class
     * was already safe by construction (bound as "?" or identifier-quoted);
     * this closes the one place that wasn't.
     */
    private const array ALLOWED_WHERE_OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];

    private const array ALLOWED_ORDER_DIRECTIONS = ['ASC', 'DESC'];

    private const array ALLOWED_JOIN_TYPES = ['INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS'];

    private readonly Dialect $dialect;

    private string $table = '';

    /** @var list<string> */
    private array $selectColumns = ['*'];

    /** @var list<string> */
    private array $selectRawExpressions = [];

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
     * $link->execute() always goes through MySQL/Postgres's own prepared-
     * statement protocol (a real PREPARE round-trip before EXECUTE), even
     * for a query with nothing to bind — $link->query() skips that
     * entirely, both for the no-params case and, via inlineLiterals()
     * below, for a query whose params are all safe to write directly into
     * the SQL text instead of binding. A prepared statement only pays for
     * itself when reused; a fresh Query is built and executed exactly
     * once, so it never gets the chance.
     *
     * @param list<mixed> $params
     */
    private function run(string $sql, array $params): MysqlResult|PostgresResult
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
     * one; a raw-fragment query always falls back to execute(), unchanged
     * from before this existed.
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
        if ($this->hasRawFragment) {
            return null;
        }

        $literals = [];

        foreach ($params as $param) {
            $literal = $this->dialect->literalFor($param, $this->link);

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
        $this->wheres[] = ['type' => 'basic', 'column' => $column, 'operator' => $normalizedOperator, 'value' => $value, 'boolean' => $boolean];

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
        $this->wheres[] = ['type' => 'in', 'column' => $column, 'values' => $values, 'boolean' => $boolean];

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
        $this->wheres[] = ['type' => 'raw', 'sql' => $sql, 'params' => $params, 'boolean' => $boolean];
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
        $this->limitValue = $limit;

        return $this;
    }

    public function offset(int $offset): static
    {
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
        $total = $this->count();
        $data = $this->limit($perPage)->offset(($page - 1) * $perPage)->get($dtoClass);

        return new Paginator(
            data: $data,
            currentPage: $page,
            perPage: $perPage,
            total: $total,
            lastPage: $perPage > 0 ? (int) ceil($total / $perPage) : 0,
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
     * Fetches rows as plain arrays first, regardless of $dtoClass, so the
     * next cursor is read off the real column name — a hydrated DTO's own
     * property name isn't guaranteed to match it. $dtoClass, when given,
     * only affects what ends up in the returned data.
     *
     * @param class-string|null $dtoClass
     */
    public function cursorPaginate(int $perPage, ?string $cursor, string $cursorColumn = 'id', ?string $dtoClass = null): CursorPaginator
    {
        if ($cursor !== null) {
            $this->where($cursorColumn, '>', $cursor);
        }

        $compiled = $this->orderBy($cursorColumn)->limit($perPage + 1)->toSelectSql();
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

        $nextCursor = null;

        if ($hasMore && $rows !== []) {
            $lastRow = $rows[array_key_last($rows)];
            $nextCursor = (string) $lastRow[$cursorColumn];
        }

        $data = $dtoClass !== null
            ? array_map(static fn (array $row) => Hydrator::hydrate($dtoClass, $row), $rows)
            : $rows;

        return new CursorPaginator($data, $nextCursor, $hasMore);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): void
    {
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
     * The default "*" is dropped once anything explicit — select() or
     * selectRaw() — has actually been specified; only the untouched
     * default ever produces a bare "*".
     */
    private function compileSelectColumns(): string
    {
        $quoted = array_map(
            fn (string $c) => $c === '*' ? $c : $this->dialect->quoteIdentifier($c),
            $this->selectColumns === ['*'] && $this->selectRawExpressions !== [] ? [] : $this->selectColumns,
        );

        return implode(', ', [...$quoted, ...$this->selectRawExpressions]);
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
