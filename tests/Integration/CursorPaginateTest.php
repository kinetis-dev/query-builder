<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Integration;

use Kinetis\Http\Pagination\CursorPaginator;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use Kinetis\QueryBuilder\Query;
use Kinetis\QueryBuilder\Tests\Fixtures\CursorReviewItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Real-backend coverage for cursorPaginate() against both engines: the
 * caller's own projection comes back exactly as asked for, the cursor
 * names the row that was delivered, and a cursor after an OR filter does
 * not repeat one.
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

    /**
     * @return list<int> the seeded ids, in order
     */
    private static function seed(MysqlLink|PostgresLink $link, string $backend, string $table, int $rows = 2): array
    {
        $link->execute("DROP TABLE IF EXISTS {$table}");
        $link->execute(
            "CREATE TABLE {$table} ("
            . 'id ' . ($backend === 'mysql' ? 'INT AUTO_INCREMENT' : 'SERIAL') . ' PRIMARY KEY, '
            . 'name VARCHAR(50) NOT NULL, '
            . 'label VARCHAR(30) NOT NULL'
            . ')',
        );

        $ids = [];

        for ($i = 1; $i <= $rows; $i++) {
            $ids[] = (int) new Query($link)->table($table)
                ->insertGetId(['name' => "n{$i}", 'label' => "label-{$i}"]);
        }

        return $ids;
    }

    /**
     * A qualified cursor column is selected under the caller's alias,
     * read back from it, and stripped from the delivered rows — so a
     * wildcard projection comes back holding exactly the table's own
     * columns.
     */
    #[DataProvider('backends')]
    public function test_a_qualified_cursor_alias_is_stripped_from_a_wildcard_projection(string $backend): void
    {
        $link = self::makeLink($backend);
        self::seed($link, $backend, 'kin_cursor_wildcard');

        $page = new Query($link)->table('kin_cursor_wildcard')
            ->cursorPaginate(1, null, 'kin_cursor_wildcard.id', cursorAlias: 'row_cursor');

        self::assertSame(['id', 'name', 'label'], array_keys($page->data[0]));
        self::assertSame('n1', $page->data[0]['name']);
        self::assertSame('1', $page->nextCursor);
        self::assertTrue($page->hasMore);

        $link->close();
    }

    /** The alias never reaches hydration, so the DTO sees only the caller's own columns. */
    #[DataProvider('backends')]
    public function test_dto_hydration_never_sees_the_cursor_alias(string $backend): void
    {
        $link = self::makeLink($backend);
        self::seed($link, $backend, 'kin_cursor_dto');

        $page = new Query($link)->table('kin_cursor_dto')
            ->cursorPaginate(1, null, 'kin_cursor_dto.id', CursorReviewItem::class, cursorAlias: 'row_cursor');

        $item = $page->data[0];

        self::assertInstanceOf(CursorReviewItem::class, $item);
        self::assertSame('n1', $item->name);
        self::assertSame('label-1', $item->label);

        $link->close();
    }

    /**
     * The cursor filter is compiled as `(existing predicate) AND
     * cursorColumn > ?`. Appended flat to a predicate containing an OR,
     * SQL precedence would bind it to the last arm alone, and n1 —
     * matching the first arm — would come back on every page.
     */
    #[DataProvider('backends')]
    public function test_a_cursor_after_an_or_filter_does_not_repeat_a_delivered_row(string $backend): void
    {
        $link = self::makeLink($backend);
        self::seed($link, $backend, 'kin_cursor_or_filter', rows: 4);

        $first = self::orFilteredPage($link, null);

        self::assertSame(['n1', 'n2'], self::names($first->data));
        self::assertSame('2', $first->nextCursor);

        $second = self::orFilteredPage($link, $first->nextCursor);

        self::assertSame(['n3', 'n4'], self::names($second->data));

        $link->close();
    }

    private static function orFilteredPage(MysqlLink|PostgresLink $link, ?string $cursor): CursorPaginator
    {
        return new Query($link)->table('kin_cursor_or_filter')
            ->where('name', '=', 'n1')
            ->orWhere('name', '!=', 'n1')
            ->cursorPaginate(2, $cursor, 'id');
    }

    /**
     * An unqualified cursor column needs no alias — its own name is
     * already the row key — and is added to a narrowed projection only
     * to be stripped back out.
     */
    #[DataProvider('backends')]
    public function test_an_unqualified_cursor_column_needs_no_alias(string $backend): void
    {
        $link = self::makeLink($backend);
        self::seed($link, $backend, 'kin_cursor_unqualified', rows: 3);

        $page = new Query($link)->table('kin_cursor_unqualified')->select('name')->cursorPaginate(2, null, 'id');

        self::assertSame([['name' => 'n1'], ['name' => 'n2']], $page->data);
        self::assertSame('2', $page->nextCursor);
        self::assertTrue($page->hasMore);

        $link->close();
    }

    /**
     * @param list<mixed> $rows
     * @return list<string>
     */
    private static function names(array $rows): array
    {
        $names = [];

        foreach ($rows as $row) {
            self::assertIsArray($row);
            $names[] = (string) $row['name'];
        }

        return $names;
    }
}
