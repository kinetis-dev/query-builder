<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Amp\Postgres\PostgresLink;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Postgres\PostgresTransaction;
use LogicException;

/**
 * The Postgres counterpart of FakeMysqlConnection — PostgresDialect's own
 * literalFor() delegates entirely to quoteLiteral() rather than
 * reimplementing escaping itself (see that class's docblock), so what's
 * under test here is the delegation, not this fake's own escaping — a
 * plain, standard single-quote-doubling implementation is enough to prove
 * PostgresDialect::literalFor() calls through and returns the result
 * faithfully. The real amphp/postgres implementation (and its own
 * correctness) is verified separately, against a live server.
 */
final class FakePostgresLinkWithQuoting implements PostgresLink
{
    public function quoteLiteral(string $data): string
    {
        return "'" . str_replace("'", "''", $data) . "'";
    }

    public function query(string $sql): PostgresResult
    {
        throw new LogicException('FakePostgresLinkWithQuoting does not execute queries.');
    }

    public function prepare(string $sql): PostgresStatement
    {
        throw new LogicException('FakePostgresLinkWithQuoting does not execute queries.');
    }

    public function execute(string $sql, array $params = []): PostgresResult
    {
        throw new LogicException('FakePostgresLinkWithQuoting does not execute queries.');
    }

    public function beginTransaction(): PostgresTransaction
    {
        throw new LogicException('FakePostgresLinkWithQuoting does not support transactions.');
    }

    public function notify(string $channel, string $payload = ''): PostgresResult
    {
        throw new LogicException('FakePostgresLinkWithQuoting does not execute queries.');
    }

    public function quoteIdentifier(string $name): string
    {
        throw new LogicException('FakePostgresLinkWithQuoting does not quote identifiers.');
    }

    public function escapeByteA(string $data): string
    {
        throw new LogicException('FakePostgresLinkWithQuoting does not escape byte arrays.');
    }

    public function close(): void
    {
    }

    public function isClosed(): bool
    {
        return false;
    }

    public function onClose(\Closure $onClose): void
    {
    }

    public function getLastUsedAt(): int
    {
        return 0;
    }
}
