<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Amp\Postgres\PostgresLink;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Postgres\PostgresTransaction;
use LogicException;

/**
 * The Postgres counterpart of SpyMysqlConnection — see its docblock.
 * quoteLiteral() uses the same plain doubling as
 * FakePostgresLinkWithQuoting, since Query's dispatch tests only need it
 * to behave consistently, not to be the real amphp implementation.
 */
final class SpyPostgresLink implements PostgresLink
{
    /** @var list<RecordedCall> */
    public array $calls = [];

    public function quoteLiteral(string $data): string
    {
        return "'" . str_replace("'", "''", $data) . "'";
    }

    public function query(string $sql): PostgresResult
    {
        $this->calls[] = new RecordedCall('query', $sql, []);

        return new EmptyPostgresResult();
    }

    /**
     * @param list<mixed> $params
     */
    public function execute(string $sql, array $params = []): PostgresResult
    {
        $this->calls[] = new RecordedCall('execute', $sql, $params);

        return new EmptyPostgresResult();
    }

    public function prepare(string $sql): PostgresStatement
    {
        throw new LogicException('SpyPostgresLink does not support prepare().');
    }

    public function beginTransaction(): PostgresTransaction
    {
        throw new LogicException('SpyPostgresLink does not support transactions.');
    }

    public function notify(string $channel, string $payload = ''): PostgresResult
    {
        throw new LogicException('SpyPostgresLink does not support notify().');
    }

    public function quoteIdentifier(string $name): string
    {
        throw new LogicException('SpyPostgresLink does not quote identifiers.');
    }

    public function escapeByteA(string $data): string
    {
        throw new LogicException('SpyPostgresLink does not escape byte arrays.');
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
