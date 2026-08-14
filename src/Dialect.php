<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder;

use Amp\Mysql\MysqlLink;
use Amp\Mysql\MysqlResult;
use Amp\Postgres\PostgresLink;
use Amp\Postgres\PostgresResult;

/**
 * The only two things MySQL and Postgres genuinely differ on for what this
 * package does — everything else (parameterized "?" placeholders, LIMIT n
 * OFFSET m, getRowCount()) is identical between them at the Amp\Sql level,
 * checked directly against both drivers' source rather than assumed:
 *
 * 1. Identifier quoting (backtick vs double-quote).
 * 2. Getting a generated primary key back after an INSERT — MySQL exposes
 *    Amp\Mysql\MysqlResult::getLastInsertId(); Postgres has no equivalent,
 *    the idiomatic mechanism there is INSERT ... RETURNING plus reading the
 *    value back out of the result row.
 */
interface Dialect
{
    public function quoteIdentifier(string $identifier): string;

    /**
     * @param array<string, mixed> $data
     * @return CompiledQuery an INSERT that also arranges to retrieve $primaryKey's generated value —
     *         via a RETURNING clause on Postgres, unmodified on MySQL (see extractInsertedId()).
     */
    public function insertGetIdQuery(string $table, array $data, string $primaryKey): CompiledQuery;

    /**
     * Reads the value insertGetIdQuery() arranged to retrieve, from the SqlResult produced by
     * actually executing it.
     */
    public function extractInsertedId(MysqlResult|PostgresResult $result, string $primaryKey): int|string|null;

    /**
     * A ready-to-inline SQL literal for $value — already quoted/escaped
     * where needed — or null when $value can't be safely inlined this way,
     * meaning the caller should bind it as a real "?" parameter instead.
     * $link is the live connection $value would otherwise be bound
     * against, needed here because whether a string can be safely inlined
     * depends on the connection's own state (MySQL: its configured
     * charset; Postgres: nothing beyond quoteLiteral() itself).
     */
    public function literalFor(mixed $value, MysqlLink|PostgresLink $link): ?string;
}
