<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Integration;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\QueryBuilder\Conditions;
use Kinetis\QueryBuilder\Exception\QueryBuilderException;
use Kinetis\QueryBuilder\LockWait;
use Kinetis\QueryBuilder\Query;
use Kinetis\QueryBuilder\RowValues;
use Kinetis\QueryBuilder\Tests\Fixtures\ArticleRow;
use Kinetis\QueryBuilder\Tests\Fixtures\ArticleStatus;
use Kinetis\QueryBuilder\Tests\Fixtures\ArticleWrite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Real-backend coverage for the SQL whose acceptance or result depends on
 * the server: the MySQL-family lone offset, set-operation grouping,
 * recursive CTEs, insert-select, insert-or-ignore, upsert, the placeholder
 * ceiling, lock wait modes, the limited IN-subquery refusal, and a
 * RowValues write read back into a DTO.
 *
 * Environment-gated like CursorPaginateTest: it skips unless
 * MYSQL_HOST/POSTGRES_HOST is set. CI's integration workflow runs it with
 * MySQL 8.4 and MariaDB 11.4 behind MYSQL_HOST, and PostgreSQL 16.
 */
final class SharedSqlTest extends TestCase
{
    /** @var list<MysqlLink|PostgresLink> */
    private array $links = [];

    protected function tearDown(): void
    {
        foreach ($this->links as $link) {
            $link->close();
        }

        $this->links = [];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function backends(): iterable
    {
        yield 'MySQL family' => ['mysql'];
        yield 'Postgres' => ['postgres'];
    }

    private function link(string $backend): MysqlLink|PostgresLink
    {
        if ($backend === 'mysql') {
            $host = \getenv('MYSQL_HOST');

            if ($host === false) {
                self::markTestSkipped('MYSQL_HOST is not set — real-backend tests are environment-gated.');
            }

            $link = new MysqliAsyncClient(
                (string) $host,
                \getenv('MYSQL_USER') ?: 'testuser',
                \getenv('MYSQL_PASSWORD') ?: 'testpass',
                \getenv('MYSQL_DATABASE') ?: 'testdb',
                (int) (\getenv('MYSQL_PORT') ?: 3306),
            );
        } else {
            $host = \getenv('POSTGRES_HOST');

            if ($host === false) {
                self::markTestSkipped('POSTGRES_HOST is not set — real-backend tests are environment-gated.');
            }

            $link = new PgsqlAsyncClient(
                (string) $host,
                \getenv('POSTGRES_USER') ?: 'testuser',
                \getenv('POSTGRES_PASSWORD') ?: 'testpass',
                \getenv('POSTGRES_DATABASE') ?: 'testdb',
                (int) (\getenv('POSTGRES_PORT') ?: 5432),
            );
        }

        $this->links[] = $link;

        return $link;
    }

    private static function recreate(MysqlLink|PostgresLink $link, string $table, string $columns): void
    {
        $link->execute("DROP TABLE IF EXISTS {$table}");
        $link->execute("CREATE TABLE {$table} ({$columns})");
    }

    private static function createNumbers(MysqlLink|PostgresLink $link): void
    {
        self::recreate($link, 'kin_qb_numbers', 'n INT NOT NULL');
        new Query($link)->table('kin_qb_numbers')->insert(array_map(static fn (int $n): array => ['n' => $n], range(1, 6)));
    }

    private static function createArticles(MysqlLink|PostgresLink $link, string $backend): void
    {
        self::recreate(
            $link,
            'kin_qb_articles',
            'id ' . ($backend === 'mysql' ? 'INT AUTO_INCREMENT' : 'SERIAL') . ' PRIMARY KEY, title VARCHAR(100) NOT NULL, '
            . 'status VARCHAR(20) NOT NULL, summary VARCHAR(200) NULL, author_id INT NULL, '
            . 'slug VARCHAR(100) NOT NULL UNIQUE, views INT NOT NULL DEFAULT 0',
        );
    }

    /**
     * @param list<mixed> $values
     * @return list<int>
     */
    private static function ints(array $values): array
    {
        return array_map(static fn (mixed $value): int => (int) $value, $values);
    }

    #[DataProvider('backends')]
    public function test_a_lone_offset_skips_rows_without_a_limit(string $backend): void
    {
        $link = $this->link($backend);
        self::createNumbers($link);

        self::assertSame([5, 6], self::ints(new Query($link)->table('kin_qb_numbers')->orderBy('n')->offset(4)->pluck('n')));
    }

    #[DataProvider('backends')]
    public function test_row_values_round_trip_a_backed_enum_into_a_dto(string $backend): void
    {
        $link = $this->link($backend);
        self::createArticles($link, $backend);

        $write = new ArticleWrite('Hello', ArticleStatus::Published, summary: null, authorId: 7, slug: 'hello');
        $id = new Query($link)->table('kin_qb_articles')
            ->insertGetId(RowValues::fromObject($write, columns: ['authorId' => 'author_id']));

        $row = new Query($link)->table('kin_qb_articles')->where('id', '=', $id)->first(ArticleRow::class);

        self::assertInstanceOf(ArticleRow::class, $row);
        self::assertSame('Hello', $row->title);
        self::assertSame(ArticleStatus::Published, $row->status);
        self::assertNull($row->summary);
        self::assertSame(7, $row->author_id);
        self::assertSame('hello', $row->slug);
    }

    /**
     * ((A UNION ALL B) INTERSECT ALL C) EXCEPT D is {2, 3}. Left to SQL
     * precedence, INTERSECT would bind first and the result would be
     * {1, 2, 3}.
     */
    #[DataProvider('backends')]
    public function test_set_operations_apply_in_call_order_and_count_their_result(string $backend): void
    {
        $link = $this->link($backend);
        self::createNumbers($link);
        $numbers = static fn (): Query => new Query($link)->table('kin_qb_numbers')->select('n');

        $combined = $numbers()->where('n', '<=', 3)
            ->union($numbers()->where('n', '>=', 3), all: true)
            ->intersect($numbers()->whereBetween('n', 2, 4), all: true)
            ->except($numbers()->where('n', '=', 4));

        self::assertSame([2, 3], self::ints((clone $combined)->orderBy('n')->pluck('n')));
        self::assertSame(2, $combined->count());
        self::assertSame(7, $numbers()->where('n', '<=', 3)->union($numbers()->where('n', '>=', 3), all: true)->count());

        $withLocalLimit = $numbers()->where('n', '=', 1)
            ->union($numbers()->orderBy('n', 'desc')->limit(2), all: true)
            ->orderBy('n')
            ->limit(2);

        self::assertSame([1, 5], self::ints($withLocalLimit->pluck('n')));
    }

    #[DataProvider('backends')]
    public function test_a_recursive_cte_and_an_insert_select_with_a_cte(string $backend): void
    {
        $link = $this->link($backend);
        self::recreate($link, 'kin_qb_categories', 'id INT PRIMARY KEY, parent_id INT NULL');
        new Query($link)->table('kin_qb_categories')->insert([
            ['id' => 1, 'parent_id' => null],
            ['id' => 2, 'parent_id' => 1],
            ['id' => 3, 'parent_id' => 2],
            ['id' => 4, 'parent_id' => 1],
            ['id' => 5, 'parent_id' => null],
        ]);

        $seed = new Query($link)->table('kin_qb_categories')->select('id', 'parent_id')->where('id', '=', 1);
        $children = new Query($link)->table('kin_qb_categories')
            ->select('kin_qb_categories.id', 'kin_qb_categories.parent_id')
            ->join('tree', 'tree.id', '=', 'kin_qb_categories.parent_id');

        $descendants = new Query($link)
            ->withRecursive('tree', $seed->union($children, all: true), ['id', 'parent_id'])
            ->table('tree')
            ->orderBy('id')
            ->pluck('id');

        self::assertSame([1, 2, 3, 4], self::ints($descendants));

        self::recreate($link, 'kin_qb_category_roots', 'id INT PRIMARY KEY');
        $roots = new Query($link)
            ->with('roots', new Query($link)->table('kin_qb_categories')->select('id')->where('parent_id', '=', null))
            ->table('roots')
            ->select('id');

        self::assertSame(2, new Query($link)->table('kin_qb_category_roots')->insertUsing(['id'], $roots));
        self::assertSame([1, 5], self::ints(new Query($link)->table('kin_qb_category_roots')->orderBy('id')->pluck('id')));
    }

    #[DataProvider('backends')]
    public function test_insert_or_ignore_skips_duplicates_only_and_upsert_updates_them(string $backend): void
    {
        $link = $this->link($backend);
        self::recreate($link, 'kin_qb_tags', 'slug VARCHAR(20) PRIMARY KEY, label VARCHAR(50) NOT NULL, uses INT NOT NULL');
        $tags = static fn (): Query => new Query($link)->table('kin_qb_tags');

        self::assertSame(2, $tags()->insertOrIgnore([
            ['slug' => 'php', 'label' => 'PHP', 'uses' => 1],
            ['slug' => 'sql', 'label' => 'SQL', 'uses' => 1],
        ]));
        self::assertSame(1, $tags()->insertOrIgnore([
            ['slug' => 'php', 'label' => 'Changed', 'uses' => 9],
            ['slug' => 'go', 'label' => 'Go', 'uses' => 1],
        ]));
        self::assertSame('PHP', $tags()->where('slug', '=', 'php')->value('label'));

        // A NOT NULL violation is not a duplicate: it still fails, and writes nothing.
        try {
            $tags()->insertOrIgnore(['slug' => 'rust', 'label' => null, 'uses' => 1]);
            self::fail('insertOrIgnore() was expected to fail on a NOT NULL violation.');
        } catch (QueryException) {
        }

        self::assertFalse($tags()->where('slug', '=', 'rust')->exists());

        $affected = $tags()->upsert(
            [['slug' => 'php', 'label' => 'PHP 8', 'uses' => 5], ['slug' => 'zig', 'label' => 'Zig', 'uses' => 1]],
            ['slug'],
            ['label', 'uses'],
        );

        // The MySQL family counts an updated row as 2; PostgreSQL counts every row as 1.
        self::assertSame($backend === 'mysql' ? 3 : 2, $affected);
        self::assertSame('PHP 8', $tags()->where('slug', '=', 'php')->value('label'));
        self::assertSame(5, (int) $tags()->where('slug', '=', 'php')->value('uses'));
        self::assertSame(4, $tags()->count());
    }

    #[DataProvider('backends')]
    public function test_a_batch_at_the_placeholder_ceiling_executes(string $backend): void
    {
        $link = $this->link($backend);
        self::recreate($link, 'kin_qb_batch', 'a INT NOT NULL, b VARCHAR(10) NOT NULL, c INT NOT NULL');

        // 21,845 rows of three values bind exactly 65,535 parameters; the
        // string column keeps every value bound rather than inlined.
        new Query($link)->table('kin_qb_batch')->insert(
            array_map(static fn (int $i): array => ['a' => $i, 'b' => "s{$i}", 'c' => $i], range(1, 21845)),
        );

        self::assertSame(21845, new Query($link)->table('kin_qb_batch')->count());
    }

    #[DataProvider('backends')]
    public function test_lock_wait_modes_under_contention(string $backend): void
    {
        $first = $this->link($backend);
        $second = $this->link($backend);
        self::recreate($first, 'kin_qb_jobs', 'id INT PRIMARY KEY, state VARCHAR(10) NOT NULL');
        new Query($first)->table('kin_qb_jobs')->insert([
            ['id' => 1, 'state' => 'queued'],
            ['id' => 2, 'state' => 'queued'],
            ['id' => 3, 'state' => 'queued'],
        ]);

        try {
            new Query($first)->table('kin_qb_jobs')->where('id', '=', 1)->lockForUpdate()->first();
            self::fail('A lock outside a transaction was expected to be refused.');
        } catch (QueryBuilderException) {
        }

        $holder = $first->beginTransaction();
        $held = new Query($holder)->table('kin_qb_jobs')->where('id', '=', 1)->lockForUpdate()->first();
        self::assertSame(1, (int) ($held['id'] ?? 0));

        $worker = $second->beginTransaction();
        $available = new Query($worker)->table('kin_qb_jobs')->orderBy('id')->lockForUpdate(LockWait::SkipLocked)->pluck('id');
        self::assertSame([2, 3], self::ints($available));

        try {
            new Query($worker)->table('kin_qb_jobs')->where('id', '=', 1)->lockForUpdate(LockWait::NoWait)->get();
            self::fail('NOWAIT on a held row was expected to fail immediately.');
        } catch (QueryException) {
        }

        $worker->rollback();

        $reader = $second->beginTransaction();
        $shared = new Query($reader)->table('kin_qb_jobs')->where('id', '=', 2)->lockForShare()->value('state');
        self::assertSame('queued', $shared);
        $reader->commit();

        $holder->rollback();
    }

    /**
     * MySQL 8.4 and MariaDB 11.4 both reject LIMIT inside IN (...), so the
     * family's dialect refuses it before execution; PostgreSQL runs it.
     * The derived-table form runs everywhere.
     */
    #[DataProvider('backends')]
    public function test_a_limited_in_subquery_is_refused_only_where_the_server_rejects_it(string $backend): void
    {
        $link = $this->link($backend);
        self::createNumbers($link);
        $latest = new Query($link)->table('kin_qb_numbers')->select('n')->orderBy('n', 'desc')->limit(2);

        if ($backend === 'mysql') {
            try {
                new Query($link)->table('kin_qb_numbers')->whereIn('n', $latest);
                self::fail('whereIn() was expected to refuse a limited subquery.');
            } catch (QueryBuilderException) {
            }
        } else {
            self::assertSame(
                [5, 6],
                self::ints(new Query($link)->table('kin_qb_numbers')->whereIn('n', $latest)->orderBy('n')->pluck('n')),
            );
        }

        $wrapped = new Query($link)->fromSub($latest, 'latest')->select('latest.n');

        self::assertSame(
            [5, 6],
            self::ints(new Query($link)->table('kin_qb_numbers')->whereIn('n', $wrapped)->orderBy('n')->pluck('n')),
        );
    }

    #[DataProvider('backends')]
    public function test_correlated_subqueries_groups_joins_and_arithmetic_writes(string $backend): void
    {
        $link = $this->link($backend);
        self::createArticles($link, $backend);
        self::recreate($link, 'kin_qb_favorites', 'user_id INT NOT NULL, article_id INT NOT NULL, PRIMARY KEY (user_id, article_id)');

        new Query($link)->table('kin_qb_articles')->insert([
            ['title' => 'One', 'status' => 'published', 'author_id' => 1, 'slug' => 'a1'],
            ['title' => 'Two', 'status' => 'draft', 'author_id' => 1, 'slug' => 'a2'],
            ['title' => 'Three', 'status' => 'published', 'author_id' => 2, 'slug' => 'a3'],
        ]);
        $ids = self::ints(new Query($link)->table('kin_qb_articles')->orderBy('id')->pluck('id'));
        new Query($link)->table('kin_qb_favorites')->insert([
            ['user_id' => 10, 'article_id' => $ids[0]],
            ['user_id' => 11, 'article_id' => $ids[0]],
            ['user_id' => 10, 'article_id' => $ids[2]],
        ]);

        $articles = static fn (): Query => new Query($link)->table('kin_qb_articles');
        $favoritesOf = static fn (): Query => new Query($link)->table('kin_qb_favorites')
            ->whereColumn('kin_qb_favorites.article_id', '=', 'kin_qb_articles.id');

        $rows = $articles()
            ->select('kin_qb_articles.slug')
            ->selectSub($favoritesOf()->selectRaw('COUNT(*)'), 'favorites')
            ->whereGroup(static fn (Conditions $g) => $g->where('status', '=', 'published')->orWhere('author_id', '=', 2))
            ->orderBy('kin_qb_articles.id')
            ->get();

        self::assertSame(['a1', 'a3'], array_column($rows, 'slug'));
        self::assertSame([2, 1], self::ints(array_column($rows, 'favorites')));

        self::assertSame(3, $articles()->count());
        self::assertSame(2, $articles()->select('author_id')->groupBy('author_id')->havingRaw('COUNT(*) >= ?', [1])->count());
        self::assertTrue($articles()->whereExists($favoritesOf()->where('user_id', '=', 11))->exists());
        self::assertFalse($articles()->whereExists($favoritesOf()->where('user_id', '=', 99))->exists());

        $popular = $articles()
            ->joinSub(
                new Query($link)->table('kin_qb_favorites')->select('article_id')->selectRaw('COUNT(*) AS total')->groupBy('article_id'),
                'c',
                static fn (Conditions $on) => $on->whereColumn('c.article_id', '=', 'kin_qb_articles.id')->where('c.total', '>', 1),
            )
            ->pluck('slug');

        self::assertSame(['a1'], $popular);

        self::assertSame(2, $articles()->whereExists($favoritesOf()->where('user_id', '=', 10))->increment('views', 3, ['summary' => 'hot']));
        self::assertSame(1, $articles()->where('slug', '=', 'a1')->decrement('views'));
        self::assertSame(5, (int) $articles()->sum('views'));
        self::assertSame(1, $articles()->whereNotIn('id', new Query($link)->table('kin_qb_favorites')->select('article_id'))->delete());
        self::assertSame(['a1', 'a3'], $articles()->orderBy('id')->pluck('slug'));
    }
}
