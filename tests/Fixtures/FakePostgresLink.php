<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Amp\Postgres\PostgresLink;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Postgres\PostgresTransaction;
use LogicException;

/**
 * The Postgres counterpart of FakeMysqlLink — see its docblock.
 */
final class FakePostgresLink implements PostgresLink
{
    public function query(string $sql): PostgresResult
    {
        throw new LogicException('FakePostgresLink does not execute queries.');
    }

    public function prepare(string $sql): PostgresStatement
    {
        throw new LogicException('FakePostgresLink does not execute queries.');
    }

    public function execute(string $sql, array $params = []): PostgresResult
    {
        throw new LogicException('FakePostgresLink does not execute queries.');
    }

    public function beginTransaction(): PostgresTransaction
    {
        throw new LogicException('FakePostgresLink does not support transactions.');
    }

    public function notify(string $channel, string $payload = ''): PostgresResult
    {
        throw new LogicException('FakePostgresLink does not execute queries.');
    }

    public function quoteLiteral(string $data): string
    {
        throw new LogicException('FakePostgresLink does not quote literals.');
    }

    public function quoteIdentifier(string $name): string
    {
        throw new LogicException('FakePostgresLink does not quote identifiers.');
    }

    public function escapeByteA(string $data): string
    {
        throw new LogicException('FakePostgresLink does not escape byte arrays.');
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
