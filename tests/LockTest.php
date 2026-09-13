<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests;

use Kinetis\QueryBuilder\Conditions;
use Kinetis\QueryBuilder\Exception\QueryBuilderException;
use Kinetis\QueryBuilder\LockWait;
use Kinetis\QueryBuilder\Query;
use Kinetis\QueryBuilder\Tests\Fixtures\SpyMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\SpyMysqlTransaction;
use Kinetis\QueryBuilder\Tests\Fixtures\SpyPostgresTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LockTest extends TestCase
{
    /**
     * @param callable(Query): Query $lock
     */
    #[DataProvider('lockSpellings')]
    public function test_each_lock_compiles_after_the_row_window(callable $lock, bool $postgres, string $sql): void
    {
        $link = $postgres ? new SpyPostgresTransaction() : new SpyMysqlTransaction();
        $lock(new Query($link)->table('jobs')->where('status', '=', 'queued')->orderBy('id')->limit(10))->get();

        self::assertCount(1, $link->calls);
        self::assertSame($sql, $link->calls[0]->sql);
    }

    /**
     * @return iterable<string, array{callable(Query): Query, bool, string}>
     */
    public static function lockSpellings(): iterable
    {
        $mysql = 'SELECT * FROM `jobs` WHERE `status` = ? ORDER BY `id` ASC LIMIT 10';
        $postgres = 'SELECT * FROM "jobs" WHERE "status" = ? ORDER BY "id" ASC LIMIT 10';

        yield 'MySQL wait' => [static fn (Query $q) => $q->lockForUpdate(), false, "{$mysql} FOR UPDATE"];
        yield 'MySQL nowait' => [static fn (Query $q) => $q->lockForUpdate(LockWait::NoWait), false, "{$mysql} FOR UPDATE NOWAIT"];
        yield 'MySQL skip locked' => [static fn (Query $q) => $q->lockForUpdate(LockWait::SkipLocked), false, "{$mysql} FOR UPDATE SKIP LOCKED"];
        yield 'MySQL shared' => [static fn (Query $q) => $q->lockForShare(), false, "{$mysql} LOCK IN SHARE MODE"];
        yield 'Postgres wait' => [static fn (Query $q) => $q->lockForUpdate(LockWait::Wait), true, "{$postgres} FOR UPDATE"];
        yield 'Postgres nowait' => [static fn (Query $q) => $q->lockForUpdate(LockWait::NoWait), true, "{$postgres} FOR UPDATE NOWAIT"];
        yield 'Postgres skip locked' => [static fn (Query $q) => $q->lockForUpdate(LockWait::SkipLocked), true, "{$postgres} FOR UPDATE SKIP LOCKED"];
        yield 'Postgres shared' => [static fn (Query $q) => $q->lockForShare(), true, "{$postgres} FOR SHARE"];
    }

    public function test_first_value_and_pluck_lock_through_an_inner_join(): void
    {
        $tx = new SpyMysqlTransaction();
        $build = static fn (): Query => new Query($tx)->table('accounts')
            ->join('users', 'users.id', '=', 'accounts.user_id')
            ->where('users.id', '=', 3)
            ->lockForUpdate();

        $build()->first();
        $build()->value('balance');
        $build()->pluck('balance');

        $expected = 'SELECT * FROM `accounts` INNER JOIN `users` ON `users`.`id` = `accounts`.`user_id` WHERE `users`.`id` = 3';
        self::assertSame("{$expected} LIMIT 1 FOR UPDATE", $tx->calls[0]->sql);
        self::assertSame("{$expected} LIMIT 1 FOR UPDATE", $tx->calls[1]->sql);
        self::assertSame("{$expected} FOR UPDATE", $tx->calls[2]->sql);
    }

    public function test_a_lock_outside_an_active_transaction_is_refused_before_execution(): void
    {
        $pool = new SpyMysqlLink();
        $ended = new SpyMysqlTransaction();
        $ended->active = false;

        foreach ([$pool, $ended] as $link) {
            try {
                new Query($link)->table('accounts')->where('id', '=', 1)->lockForUpdate()->first();
                self::fail('first() was expected to throw.');
            } catch (QueryBuilderException $e) {
                self::assertSame(
                    'lockForUpdate()/lockForShare() needs a Query built on an active SqlTransaction: outside one the '
                    . 'lock ends with the statement and protects nothing. Run the read inside '
                    . 'TransactionGuard::transaction() and pass the transaction to new Query().',
                    $e->getMessage(),
                );
            }

            self::assertCount(0, $link->calls);
        }
    }

    /**
     * @param callable(Query): mixed $terminal
     */
    #[DataProvider('unlockedTerminals')]
    public function test_a_terminal_that_does_not_return_the_locked_rows_is_refused(callable $terminal, string $method): void
    {
        $tx = new SpyMysqlTransaction();

        try {
            $terminal(new Query($tx)->table('accounts')->orderBy('id')->lockForShare());
            self::fail("{$method} was expected to throw.");
        } catch (QueryBuilderException $e) {
            self::assertSame(
                "{$method} cannot run a Query carrying lockForUpdate()/lockForShare(). A lock is admitted on get(), "
                . 'first(), value() and pluck() only.',
                $e->getMessage(),
            );
        }

        self::assertCount(0, $tx->calls);
    }

    /**
     * @return iterable<string, array{callable(Query): mixed, string}>
     */
    public static function unlockedTerminals(): iterable
    {
        yield 'count()' => [static fn (Query $q) => $q->count(), 'count()'];
        yield 'sum()' => [static fn (Query $q) => $q->sum('balance'), 'sum()'];
        yield 'avg()' => [static fn (Query $q) => $q->avg('balance'), 'avg()'];
        yield 'exists()' => [static fn (Query $q) => $q->exists(), 'exists()'];
        yield 'paginate()' => [static fn (Query $q) => $q->paginate(10), 'paginate()'];
        yield 'cursorPaginate()' => [
            static fn (Query $q) => new Query(new SpyMysqlTransaction())->table('accounts')->lockForUpdate()->cursorPaginate(10, null),
            'cursorPaginate()',
        ];
    }

    /**
     * @param callable(Query): Query $shape
     */
    #[DataProvider('unportableShapes')]
    public function test_a_lock_on_an_unportable_shape_is_refused(callable $shape, string $clause): void
    {
        $tx = new SpyMysqlTransaction();

        try {
            $shape(new Query($tx)->table('accounts'))->lockForUpdate()->get();
            self::fail('get() was expected to throw.');
        } catch (QueryBuilderException $e) {
            self::assertSame(
                "lockForUpdate()/lockForShare() cannot combine with {$clause}. A lock is admitted only on a select "
                . 'from one table or inner joins, with where predicates, ordering, limit and offset; run any other '
                . 'locking read as raw SQL.',
                $e->getMessage(),
            );
        }

        self::assertCount(0, $tx->calls);
    }

    /**
     * @return iterable<string, array{callable(Query): Query, string}>
     */
    public static function unportableShapes(): iterable
    {
        $other = static fn (): Query => new Query(new SpyMysqlTransaction())->table('ledger');

        yield 'distinct()' => [static fn (Query $q) => $q->distinct(), 'distinct()'];
        yield 'groupBy()' => [static fn (Query $q) => $q->groupBy('user_id'), 'groupBy()/groupByRaw()'];
        yield 'havingRaw()' => [static fn (Query $q) => $q->havingRaw('COUNT(*) > 1'), 'having()/orHaving()/havingRaw()'];
        yield 'union()' => [static fn (Query $q) => $q->union($other()), 'union()/intersect()/except()'];
        yield 'with()' => [static fn (Query $q) => $q->with('l', $other()), 'with()/withRecursive()'];
        yield 'fromSub()' => [static fn (Query $q) => $q->fromSub($other(), 'l'), 'fromSub()'];
        yield 'leftJoin()' => [static fn (Query $q) => $q->leftJoin('users', 'users.id', '=', 'accounts.user_id'), 'a LEFT or RIGHT join'];
        yield 'RIGHT join()' => [static fn (Query $q) => $q->join('users', 'users.id', '=', 'accounts.user_id', 'RIGHT'), 'a LEFT or RIGHT join'];
        yield 'crossJoin()' => [static fn (Query $q) => $q->crossJoin('users'), 'crossJoin()'];
        yield 'joinSub()' => [
            static fn (Query $q) => $q->joinSub($other(), 'l', static fn (Conditions $on) => $on->whereColumn('l.id', '=', 'accounts.id')),
            'joinSub()',
        ];
        yield 'several at once' => [
            static fn (Query $q) => $q->distinct()->crossJoin('users'),
            'distinct(), crossJoin()',
        ];
    }

    /** A mutation compiles no lock, so a lock on one is refused rather than dropped. */
    public function test_a_lock_on_a_mutation_is_refused(): void
    {
        $tx = new SpyMysqlTransaction();

        try {
            new Query($tx)->table('accounts')->where('id', '=', 1)->lockForUpdate()->decrement('balance', 5);
            self::fail('decrement() was expected to throw.');
        } catch (QueryBuilderException $e) {
            self::assertStringContainsString('lockForUpdate()/lockForShare() would be dropped', $e->getMessage());
        }

        self::assertCount(0, $tx->calls);
    }

    public function test_a_locked_query_is_not_an_insert_source(): void
    {
        $tx = new SpyMysqlTransaction();

        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage('insertUsing() cannot embed a Query carrying lockForUpdate()/lockForShare().');

        new Query($tx)->table('archive')->insertUsing(['id'], new Query($tx)->table('accounts')->select('id')->lockForUpdate());
    }
}
