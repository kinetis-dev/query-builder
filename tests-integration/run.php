<?php

declare(strict_types=1);

/**
 * Real-backend regression coverage for Kinetis\QueryBuilder\Query — the
 * same MySQL/Postgres round trip originally verified by hand, now run on
 * every CI push instead of once. Query's own compilation logic is unit
 * tested against a fake link that throws on execute() (see QueryTest.php);
 * this exercises the methods that actually execute() something, which no
 * fake can meaningfully stand in for.
 */

require __DIR__ . '/../vendor/autoload.php';

use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use Kinetis\QueryBuilder\Query;

function check(string $label, bool $condition): void
{
    echo ($condition ? "OK   " : "FAIL ") . $label . "\n";

    if (!$condition) {
        exit(1);
    }
}

function run(string $backend, $link): void
{
    echo "=== {$backend} ===\n";

    $link->execute('DROP TABLE IF EXISTS items');
    $idColumn = $backend === 'MySQL' ? 'INT AUTO_INCREMENT' : 'SERIAL';
    $link->execute("CREATE TABLE items (id {$idColumn} PRIMARY KEY, name VARCHAR(50) NOT NULL, category VARCHAR(20) NOT NULL)");

    foreach (range(1, 25) as $i) {
        new Query($link)->table('items')->insert(['name' => "item{$i}", 'category' => $i % 2 === 0 ? 'even' : 'odd']);
    }

    check("{$backend}: count() sees all 25 rows", new Query($link)->table('items')->count() === 25);
    check("{$backend}: where() filters correctly", new Query($link)->table('items')->where('category', '=', 'even')->count() === 12);
    check("{$backend}: whereIn() filters correctly", count(new Query($link)->table('items')->whereIn('name', ['item1', 'item2', 'item3'])->get()) === 3);
    // §6.7 of the independent evaluation report: whereIn([]) used to emit
    // syntactically invalid SQL (`IN ()`) — must now compile to a real,
    // valid, zero-row query against a live database, not just pass a unit
    // test against a fake link.
    check("{$backend}: whereIn() with an empty array returns zero rows, not a SQL error", count(new Query($link)->table('items')->whereIn('name', [])->get()) === 0);

    $id = new Query($link)->table('items')->insertGetId(['name' => 'item26', 'category' => 'even']);
    check("{$backend}: insertGetId() returns a real id", $id !== null);

    $updated = new Query($link)->table('items')->where('id', '=', $id)->update(['category' => 'odd']);
    check("{$backend}: update() reports 1 affected row", $updated === 1);

    /** @var array<string, mixed> $row */
    $row = new Query($link)->table('items')->where('id', '=', $id)->first();
    check("{$backend}: update() actually changed the row", $row['category'] === 'odd');

    $deleted = new Query($link)->table('items')->where('id', '=', $id)->delete();
    check("{$backend}: delete() reports 1 affected row", $deleted === 1);

    // Offset pagination — back to 25 rows now that the extra insert was deleted.
    $page = new Query($link)->table('items')->orderBy('id')->paginate(perPage: 10, page: 2);
    check("{$backend}: paginate() page 2 has 10 rows", count($page->data) === 10);
    check("{$backend}: paginate() total is 25", $page->total === 25);
    check("{$backend}: paginate() lastPage is 3", $page->lastPage === 3);

    // Cursor pagination.
    $cursor1 = new Query($link)->table('items')->cursorPaginate(perPage: 15, cursor: null);
    check("{$backend}: cursorPaginate() page 1 has 15 rows", count($cursor1->data) === 15);
    check("{$backend}: cursorPaginate() hasMore is true", $cursor1->hasMore === true);

    $cursor2 = new Query($link)->table('items')->cursorPaginate(perPage: 15, cursor: $cursor1->nextCursor);
    check("{$backend}: cursorPaginate() page 2 has the remaining 10 rows", count($cursor2->data) === 10);
    check("{$backend}: cursorPaginate() page 2 hasMore is false", $cursor2->hasMore === false);

    // join()
    $link->execute('DROP TABLE IF EXISTS categories');
    $link->execute("CREATE TABLE categories (name VARCHAR(20) PRIMARY KEY, label VARCHAR(50) NOT NULL)");
    new Query($link)->table('categories')->insert(['name' => 'even', 'label' => 'Even numbered']);
    new Query($link)->table('categories')->insert(['name' => 'odd', 'label' => 'Odd numbered']);

    $joined = new Query($link)->table('items')
        ->join('categories', 'items.category', '=', 'categories.name')
        ->where('items.name', '=', 'item2')
        ->select('items.name', 'categories.label')
        ->first();
    check("{$backend}: join() resolves the related row", $joined['label'] === 'Even numbered');

    echo "\n";
}

$mysql = new MysqliAsyncClient(
    getenv('MYSQL_HOST') ?: '127.0.0.1',
    getenv('MYSQL_USER') ?: 'testuser',
    getenv('MYSQL_PASSWORD') ?: 'testpass',
    getenv('MYSQL_DATABASE') ?: 'testdb',
    (int) (getenv('MYSQL_PORT') ?: 3306),
);
$postgres = new PgsqlAsyncClient(
    getenv('POSTGRES_HOST') ?: '127.0.0.1',
    getenv('POSTGRES_USER') ?: 'testuser',
    getenv('POSTGRES_PASSWORD') ?: 'testpass',
    getenv('POSTGRES_DATABASE') ?: 'testdb',
    (int) (getenv('POSTGRES_PORT') ?: 5432),
);

run('MySQL', $mysql);
run('Postgres', $postgres);

echo "ALL CHECKS PASSED\n";
