<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder;

use Closure;
use InvalidArgumentException;
use Kinetis\QueryBuilder\Exception\QueryBuilderException;

/**
 * The predicate vocabulary shared by a Query's WHERE and HAVING clauses,
 * a joinOn()/joinSub() ON clause and a whereGroup() callback. A callback
 * receives a fresh instance that can add predicates and nothing else: it
 * has no table, projection, ordering, execution or mutation. It runs
 * synchronously and the instance is not kept once it returns; its
 * compiled predicates are.
 *
 * Every call renders its fragment and that fragment's bindings together,
 * and compile() joins the fragments in the order they were added, so a
 * "?" and its value cannot drift apart. A Query operand is compiled into
 * a Snapshot when it is passed, never kept as the mutable Query.
 */
final class Conditions
{
    /**
     * An operator and a boolean are interpolated into SQL, unlike a value
     * (bound) or an identifier (quoted), and a filterable API
     * (`?op=gte`) is exactly the shape that passes a request value into
     * one, so both are allow-listed where they are set.
     */
    private const array OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];

    private const array BOOLEANS = ['AND', 'OR'];

    /** @var list<array{boolean: 'AND'|'OR', sql: string, params: list<mixed>}> */
    private array $predicates = [];

    private bool $hasRawFragment = false;

    /**
     * @internal Query creates every instance.
     *
     * @param Closure(Query, string): Snapshot $capture compiles an operand
     *        in this instance's dialect, refusing one it cannot embed
     */
    public function __construct(
        private readonly Dialect $dialect,
        private readonly Closure $capture,
    ) {}

    /**
     * A null $value follows SQL's own null semantics instead of binding:
     * `=` compiles to IS NULL, `!=` and `<>` to IS NOT NULL, neither with a
     * placeholder. Every other operator against null is rejected —
     * `column > NULL` is never true for any row, so it can only be a
     * mistake.
     *
     * @param 'AND'|'OR' $boolean
     */
    public function where(string $column, string $operator, mixed $value, string $boolean = 'AND'): static
    {
        $normalizedOperator = self::assertAllowedOperator($operator);
        $normalizedBoolean = self::assertAllowedBoolean($boolean);
        $quoted = $this->dialect->quoteIdentifier($column);

        if ($value === null) {
            return $this->add($normalizedBoolean, $quoted . ' ' . self::nullOperatorFor($normalizedOperator), []);
        }

        return $this->add($normalizedBoolean, "{$quoted} {$normalizedOperator} ?", [$value]);
    }

    public function orWhere(string $column, string $operator, mixed $value): static
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    /** Compares two columns: `whereColumn('comments.article_id', '=', 'articles.id')`. */
    public function whereColumn(string $first, string $operator, string $second): static
    {
        return $this->column($first, $operator, $second, 'AND');
    }

    public function orWhereColumn(string $first, string $operator, string $second): static
    {
        return $this->column($first, $operator, $second, 'OR');
    }

    /**
     * $values is a list of values or a subquery. An empty list compiles to
     * the constant-false `1 = 0`, since `IN ()` is invalid SQL and no row
     * is in an empty set. A null member is rejected: SQL never matches a
     * row against NULL, so it narrows the set without changing how the
     * call reads. Use where($column, '=', null) for IS NULL.
     *
     * @param list<mixed>|Query $values
     * @param 'AND'|'OR' $boolean
     */
    public function whereIn(string $column, array|Query $values, string $boolean = 'AND'): static
    {
        return $this->in('whereIn()', $column, $values, false, $boolean);
    }

    /**
     * The negation of whereIn(). An empty list compiles to the
     * constant-true `1 = 1`: every row is outside an empty set.
     *
     * @param list<mixed>|Query $values
     * @param 'AND'|'OR' $boolean
     */
    public function whereNotIn(string $column, array|Query $values, string $boolean = 'AND'): static
    {
        return $this->in('whereNotIn()', $column, $values, true, $boolean);
    }

    /** `column BETWEEN low AND high`, both bounds inclusive and bound as parameters. */
    public function whereBetween(string $column, mixed $low, mixed $high): static
    {
        return $this->between('whereBetween()', $column, $low, $high, false, 'AND');
    }

    public function orWhereBetween(string $column, mixed $low, mixed $high): static
    {
        return $this->between('orWhereBetween()', $column, $low, $high, false, 'OR');
    }

    public function whereNotBetween(string $column, mixed $low, mixed $high): static
    {
        return $this->between('whereNotBetween()', $column, $low, $high, true, 'AND');
    }

    public function orWhereNotBetween(string $column, mixed $low, mixed $high): static
    {
        return $this->between('orWhereNotBetween()', $column, $low, $high, true, 'OR');
    }

    public function whereExists(Query $query): static
    {
        return $this->exists('whereExists()', $query, false, 'AND');
    }

    public function orWhereExists(Query $query): static
    {
        return $this->exists('orWhereExists()', $query, false, 'OR');
    }

    public function whereNotExists(Query $query): static
    {
        return $this->exists('whereNotExists()', $query, true, 'AND');
    }

    public function orWhereNotExists(Query $query): static
    {
        return $this->exists('orWhereNotExists()', $query, true, 'OR');
    }

    /**
     * A raw predicate for anything the structured forms cannot express.
     * Its own "?" placeholders bind $params in the position the fragment
     * takes in the SQL; "raw" means raw SQL syntax, never raw
     * unparameterized input.
     *
     * $sql must carry an actual fragment. An empty or whitespace-only one
     * is a predicate the caller believes they added and the compiler emits
     * nothing for, which would let update()/delete() past their predicate
     * requirement and affect every row.
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

        $this->hasRawFragment = true;

        return $this->add($normalizedBoolean, $sql, $params);
    }

    /**
     * Parenthesizes the predicates $group adds, joined to the rest with
     * AND. A group that adds nothing compiles to nothing, and so narrows
     * nothing either.
     *
     * @param Closure(Conditions): mixed $group
     */
    public function whereGroup(Closure $group): static
    {
        return $this->group($group, 'AND');
    }

    /**
     * @param Closure(Conditions): mixed $group
     */
    public function orWhereGroup(Closure $group): static
    {
        return $this->group($group, 'OR');
    }

    /**
     * The predicates joined by their booleans, without a WHERE/HAVING/ON
     * keyword. '' when none were added.
     *
     * @internal
     */
    public function compile(): CompiledQuery
    {
        $sql = '';
        $params = [];

        foreach ($this->predicates as $i => $predicate) {
            $sql .= ($i === 0 ? '' : " {$predicate['boolean']} ") . $predicate['sql'];
            array_push($params, ...$predicate['params']);
        }

        return new CompiledQuery($sql, $params);
    }

    /** @internal */
    public function isEmpty(): bool
    {
        return $this->predicates === [];
    }

    /**
     * Whether any predicate carries caller-written SQL text, directly or
     * inside a group or subquery.
     *
     * @internal
     */
    public function hasRawFragment(): bool
    {
        return $this->hasRawFragment;
    }

    /**
     * @param 'AND'|'OR' $boolean
     * @param list<mixed> $params
     */
    private function add(string $boolean, string $sql, array $params): static
    {
        $this->predicates[] = ['boolean' => $boolean, 'sql' => $sql, 'params' => $params];

        return $this;
    }

    /**
     * @param 'AND'|'OR' $boolean
     */
    private function column(string $first, string $operator, string $second, string $boolean): static
    {
        return $this->add(
            $boolean,
            $this->dialect->quoteIdentifier($first) . ' ' . self::assertAllowedOperator($operator) . ' '
            . $this->dialect->quoteIdentifier($second),
            [],
        );
    }

    /**
     * @param list<mixed>|Query $values
     */
    private function in(string $method, string $column, array|Query $values, bool $not, string $boolean): static
    {
        $normalizedBoolean = self::assertAllowedBoolean($boolean);
        $keyword = $not ? 'NOT IN' : 'IN';
        $quoted = $this->dialect->quoteIdentifier($column);

        if ($values instanceof Query) {
            $snapshot = $this->snapshotOf($values, $method);

            if ($snapshot->isLimited && !$this->dialect->admitsLimitedInSubquery()) {
                throw QueryBuilderException::limitedInSubquery($method);
            }

            return $this->add($normalizedBoolean, "{$quoted} {$keyword} ({$snapshot->sql})", $snapshot->params);
        }

        if (in_array(null, $values, true)) {
            throw new InvalidArgumentException(
                "{$method} cannot take null in the value list for \"{$column}\": SQL never matches a row "
                . "against NULL, so the null narrows the set silently. Use where('{$column}', '=', null) "
                . 'for IS NULL.',
            );
        }

        if ($values === []) {
            return $this->add($normalizedBoolean, $not ? '1 = 1' : '1 = 0', []);
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return $this->add($normalizedBoolean, "{$quoted} {$keyword} ({$placeholders})", $values);
    }

    /**
     * @param 'AND'|'OR' $boolean
     */
    private function between(string $method, string $column, mixed $low, mixed $high, bool $not, string $boolean): static
    {
        if ($low === null || $high === null) {
            throw new InvalidArgumentException(
                "{$method} cannot take null as a bound for \"{$column}\": a comparison against NULL is never "
                . 'true, so the predicate would match no row.',
            );
        }

        $keyword = $not ? 'NOT BETWEEN' : 'BETWEEN';

        return $this->add($boolean, $this->dialect->quoteIdentifier($column) . " {$keyword} ? AND ?", [$low, $high]);
    }

    /**
     * @param 'AND'|'OR' $boolean
     */
    private function exists(string $method, Query $query, bool $not, string $boolean): static
    {
        $snapshot = $this->snapshotOf($query, $method);

        return $this->add($boolean, ($not ? 'NOT EXISTS' : 'EXISTS') . " ({$snapshot->sql})", $snapshot->params);
    }

    /**
     * @param Closure(Conditions): mixed $group
     * @param 'AND'|'OR' $boolean
     */
    private function group(Closure $group, string $boolean): static
    {
        $conditions = new self($this->dialect, $this->capture);
        $group($conditions);

        if ($conditions->predicates === []) {
            return $this;
        }

        $compiled = $conditions->compile();
        $this->hasRawFragment = $this->hasRawFragment || $conditions->hasRawFragment;

        return $this->add($boolean, "({$compiled->sql})", $compiled->params);
    }

    private function snapshotOf(Query $query, string $method): Snapshot
    {
        $snapshot = ($this->capture)($query, $method);
        $this->hasRawFragment = $this->hasRawFragment || $snapshot->hasRawFragment;

        return $snapshot;
    }

    private static function assertAllowedOperator(string $operator): string
    {
        $normalized = strtoupper(trim($operator));

        if (!in_array($normalized, self::OPERATORS, true)) {
            throw new InvalidArgumentException(
                "Operator \"{$operator}\" is not allowed. Use one of: " . implode(', ', self::OPERATORS) . '.',
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

        if (!in_array($normalized, self::BOOLEANS, true)) {
            throw new InvalidArgumentException(
                "Where boolean \"{$boolean}\" is not allowed. Use one of: " . implode(', ', self::BOOLEANS) . '.',
            );
        }

        /** @var 'AND'|'OR' $normalized */
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
}
