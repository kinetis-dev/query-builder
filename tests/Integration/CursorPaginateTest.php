<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Integration;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use Kinetis\QueryBuilder\Query;
use Kinetis\QueryBuilder\Tests\Fixtures\CursorReviewItem;
use Kinetis\QueryBuilder\Tests\Fixtures\MutatingMysqlLink;
use Kinetis\QueryBuilder\Tests\Fixtures\MutatingPostgresLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Real-backend regression coverage for cursorPaginate(): the cursor must
 * name the row that was actually delivered, and the caller's own
 * projection must come back exactly as they asked for it — including a
 * column that happens to share a name with anything internal, an alias
 * an ORDER BY depends on, and an offset() they set themselves.
 *
 * Environment-gated (skips unless MYSQL_HOST/POSTGRES_HOST is set), so a
 * plain local `vendor/bin/phpunit` run stays database-free. CI's
 * integration workflow runs it across its full matrix and SonarQube's
 * coverage job runs it under pcov, matching kinetis/persistence's own
 * DriverCase precedent.
 */
final class CursorPaginateTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function backends(): iterable
    {
        yield 'MySQL' => ['mysql'];
        yield 'Postgres' => ['postgres'];
    }

    private static function makeLink(string $backend): MysqlLink|PostgresLink
    {
        if ($backend === 'mysql') {
            $host = \getenv('MYSQL_HOST');

            if ($host === false) {
                self::markTestSkipped('MYSQL_HOST is not set — real-backend tests are environment-gated.');
            }

            return new MysqliAsyncClient(
                (string) $host,
                \getenv('MYSQL_USER') ?: 'testuser',
                \getenv('MYSQL_PASSWORD') ?: 'testpass',
                \getenv('MYSQL_DATABASE') ?: 'testdb',
                (int) (\getenv('MYSQL_PORT') ?: 3306),
            );
        }

        $host = \getenv('POSTGRES_HOST');

        if ($host === false) {
            self::markTestSkipped('POSTGRES_HOST is not set — real-backend tests are environment-gated.');
        }

        return new PgsqlAsyncClient(
            (string) $host,
            \getenv('POSTGRES_USER') ?: 'testuser',
            \getenv('POSTGRES_PASSWORD') ?: 'testpass',
            \getenv('POSTGRES_DATABASE') ?: 'testdb',
            (int) (\getenv('POSTGRES_PORT') ?: 5432),
        );
    }

    private static function idColumn(string $backend): string
    {
        return $backend === 'mysql' ? 'INT AUTO_INCREMENT' : 'SERIAL';
    }

    /**
     * Seeds a table carrying a real, application-owned `__kinetis_cursor`
     * column — a name an internal alias once hardcoded — alongside the
     * ordinary id/name columns, so any reintroduced hidden alias would
     * collide with it visibly instead of silently.
     *
     * @return list<int> the seeded ids, in order
     */
    private static function seed(MysqlLink|PostgresLink $link, string $backend, string $table, int $rows = 2): array
    {
        $link->execute("DROP TABLE IF EXISTS {$table}");
        $link->execute(
            "CREATE TABLE {$table} ("
            . 'id ' . self::idColumn($backend) . ' PRIMARY KEY, '
            . 'name VARCHAR(50) NOT NULL, '
            . '__kinetis_cursor VARCHAR(30) NOT NULL'
            . ')',
        );

        $ids = [];

        for ($i = 1; $i <= $rows; $i++) {
            $ids[] = (int) new Query($link)->table($table)
                ->insertGetId(['name' => "n{$i}", '__kinetis_cursor' => "application-value-{$i}"]);
        }

        return $ids;
    }

    /**
     * The original reproduction: a real application column sharing the
     * name an internal alias once used must come back untouched. Nothing
     * is appended to the projection now unless the caller names the
     * alias, so there is nothing left to collide.
     */
    #[DataProvider('backends')]
    public function test_a_same_named_application_column_survives_under_the_default_wildcard(string $backend): void
    {
        $link = self::makeLink($backend);
        self::seed($link, $backend, 'kin_cursor_wildcard');

        $page = new Query($link)->table('kin_cursor_wildcard')
            ->cursorPaginate(1, null, 'kin_cursor_wildcard.id', cursorAlias: 'row_cursor');

        self::assertCount(1, $page->data);
        self::assertSame('n1', $page->data[0]['name']);
        self::assertSame('application-value-1', $page->data[0]['__kinetis_cursor']);
        self::assertArrayNotHasKey('row_cursor', $page->data[0], 'The alias must be stripped from the rows.');
        self::assertSame('1', $page->nextCursor);
        self::assertTrue($page->hasMore);

        $link->close();
    }

    #[DataProvider('backends')]
    public function test_a_same_named_application_column_survives_under_an_explicit_projection(string $backend): void
    {
        $link = self::makeLink($backend);
        self::seed($link, $backend, 'kin_cursor_explicit');

        $page = new Query($link)->table('kin_cursor_explicit')
            ->select('id', '__kinetis_cursor', 'name')
            ->cursorPaginate(1, null, 'kin_cursor_explicit.id', cursorAlias: 'row_cursor');

        self::assertSame(
            ['id' => 1, 'name' => 'n1', '__kinetis_cursor' => 'application-value-1'],
            [
                'id' => (int) $page->data[0]['id'],
                'name' => $page->data[0]['name'],
                '__kinetis_cursor' => $page->data[0]['__kinetis_cursor'],
            ],
        );
        self::assertSame('1', $page->nextCursor);

        $link->close();
    }

    #[DataProvider('backends')]
    public function test_dto_hydration_sees_the_uncorrupted_application_column(string $backend): void
    {
        $link = self::makeLink($backend);
        self::seed($link, $backend, 'kin_cursor_dto');

        $page = new Query($link)->table('kin_cursor_dto')
            ->cursorPaginate(1, null, 'kin_cursor_dto.id', CursorReviewItem::class, cursorAlias: 'row_cursor');

        self::assertCount(1, $page->data);
        $item = $page->data[0];
        self::assertInstanceOf(CursorReviewItem::class, $item);
        self::assertSame('n1', $item->name);
        self::assertSame('application-value-1', $item->__kinetis_cursor);

        $link->close();
    }

    /**
     * A projection alias its own ORDER BY depends on used to be dropped
     * by a follow-up query that kept the order list but cleared the
     * projection defining it — valid SQL compiled into invalid SQL
     * ("Unknown column 'rank_value' in 'order clause'"). One query cannot
     * lose half of itself.
     */
    #[DataProvider('backends')]
    public function test_an_order_by_on_a_projection_alias_still_runs(string $backend): void
    {
        $link = self::makeLink($backend);
        self::seed($link, $backend, 'kin_cursor_projection_alias');

        $page = new Query($link)->table('kin_cursor_projection_alias')
            ->select('id', 'name')
            ->selectRaw('id * 2 AS rank_value')
            ->orderBy('rank_value')
            ->cursorPaginate(1, null, 'kin_cursor_projection_alias.id', cursorAlias: 'row_cursor');

        self::assertCount(1, $page->data);
        self::assertSame('n1', $page->data[0]['name']);
        self::assertSame(2, (int) $page->data[0]['rank_value']);
        self::assertSame('1', $page->nextCursor);

        $link->close();
    }

    /**
     * A caller's own offset() shifts which rows the page delivers, and
     * the cursor has to follow it. The follow-up query used to overwrite
     * offset with its own, reporting a cursor for a row the caller was
     * never handed: offset(1) delivered id=2 but reported "1".
     */
    #[DataProvider('backends')]
    public function test_a_caller_supplied_offset_is_honoured_by_the_cursor(string $backend): void
    {
        $link = self::makeLink($backend);
        self::seed($link, $backend, 'kin_cursor_offset', rows: 3);

        $page = new Query($link)->table('kin_cursor_offset')
            ->offset(1)
            ->cursorPaginate(1, null, 'kin_cursor_offset.id', cursorAlias: 'row_cursor');

        self::assertCount(1, $page->data);
        self::assertSame(2, (int) $page->data[0]['id']);
        self::assertSame(
            '2',
            $page->nextCursor,
            'The cursor must name the row delivered, not the one an ignored offset would have reached.',
        );

        $link->close();
    }

    /**
     * The consistency guarantee, made deterministic: a row deleted the
     * instant the page's own query returns cannot move the cursor,
     * because there is no second read to see the changed table. Under
     * the previous two-query design this exact sequence delivered id=1
     * and reported "2", permanently skipping id=2 — a row that was never
     * delivered.
     */
    #[DataProvider('backends')]
    public function test_a_write_landing_between_reads_cannot_move_the_cursor_off_the_delivered_row(string $backend): void
    {
        $link = self::makeLink($backend);
        self::seed($link, $backend, 'kin_cursor_race');

        $mutating = $backend === 'mysql'
            ? new MutatingMysqlLink($link, static fn () => $link->execute('DELETE FROM kin_cursor_race WHERE id = 1'))
            : new MutatingPostgresLink($link, static fn () => $link->execute('DELETE FROM kin_cursor_race WHERE id = 1'));

        $page = new Query($mutating)->table('kin_cursor_race')
            ->cursorPaginate(1, null, 'kin_cursor_race.id', cursorAlias: 'row_cursor');

        self::assertCount(1, $page->data);
        self::assertSame(1, (int) $page->data[0]['id']);
        self::assertSame(
            '1',
            $page->nextCursor,
            'The cursor must be the delivered row\'s own value, not a re-read of a table that has since changed.',
        );

        $link->close();
    }

    /**
     * Reusing an instance after pagination sees only the accumulation
     * every builder method already leaves behind — never the cursor
     * alias, whose expression is scoped to the one compile that needs
     * it.
     */
    #[DataProvider('backends')]
    public function test_reusing_the_query_after_pagination_does_not_leak_the_cursor_alias(string $backend): void
    {
        $link = self::makeLink($backend);
        self::seed($link, $backend, 'kin_cursor_reuse');

        $query = new Query($link)->table('kin_cursor_reuse');
        $page = $query->cursorPaginate(1, null, 'kin_cursor_reuse.id', cursorAlias: 'row_cursor');

        self::assertTrue($page->hasMore);

        $rows = $query->get();

        self::assertCount(2, $rows, 'get() after reuse reflects the accumulated limit(2).');
        self::assertSame(['id', 'name', '__kinetis_cursor'], array_keys($rows[0]));
        self::assertSame('application-value-1', $rows[0]['__kinetis_cursor']);

        $link->close();
    }

    /**
     * The documented precondition, pinned against both engines rather
     * than left to prose: an alias colliding with a column only a
     * wildcard brings in *replaces* that column. The appended cursor
     * takes the key, so the value read back is the cursor's — correct —
     * and the caller's own field is gone with the cleanup.
     *
     * There is deliberately no exception here. It cannot be detected
     * without column metadata the result does not carry, and the one
     * check that would fire (distinct keys against the server's column
     * count) also fires on the ordinary duplicate `id` of any `SELECT *`
     * across a join, which is the most common reason to want a cursor
     * alias at all. An alias the caller *listed* is rejected up front
     * instead; see QueryTest. This test exists so the untestable half
     * stays honestly described rather than silently drifting.
     */
    #[DataProvider('backends')]
    public function test_an_alias_colliding_with_a_wildcard_column_replaces_it(string $backend): void
    {
        $link = self::makeLink($backend);
        $link->execute('DROP TABLE IF EXISTS kin_cursor_alias_collision');
        $link->execute(
            'CREATE TABLE kin_cursor_alias_collision ('
            . 'id ' . self::idColumn($backend) . ' PRIMARY KEY, '
            . 'row_cursor VARCHAR(30) NOT NULL, '
            . 'name VARCHAR(50) NOT NULL'
            . ')',
        );
        $link->execute("INSERT INTO kin_cursor_alias_collision (row_cursor, name) VALUES ('owned-one', 'n1')");
        $link->execute("INSERT INTO kin_cursor_alias_collision (row_cursor, name) VALUES ('owned-two', 'n2')");

        $page = new Query($link)->table('kin_cursor_alias_collision')
            ->cursorPaginate(1, null, 'kin_cursor_alias_collision.id', cursorAlias: 'row_cursor');

        self::assertSame('1', $page->nextCursor, 'The cursor itself is still correct.');
        self::assertSame(
            ['id', 'name'],
            array_keys($page->data[0]),
            'The caller\'s own row_cursor column is replaced by the alias and removed with it.',
        );

        $link->close();
    }

    /**
     * An unqualified cursor column needs no alias at all — its own name
     * is already the row key — and keeps working exactly as before.
     */
    #[DataProvider('backends')]
    public function test_an_unqualified_cursor_column_still_needs_no_alias(string $backend): void
    {
        $link = self::makeLink($backend);
        self::seed($link, $backend, 'kin_cursor_unqualified', rows: 3);

        $page = new Query($link)->table('kin_cursor_unqualified')->select('name')->cursorPaginate(2, null, 'id');

        self::assertSame([['name' => 'n1'], ['name' => 'n2']], $page->data);
        self::assertSame('2', $page->nextCursor);
        self::assertTrue($page->hasMore);

        $link->close();
    }
}
