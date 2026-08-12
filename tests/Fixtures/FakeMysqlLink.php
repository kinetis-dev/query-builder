<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Amp\Mysql\MysqlLink;
use Amp\Mysql\MysqlResult;
use Amp\Mysql\MysqlStatement;
use Amp\Mysql\MysqlTransaction;
use LogicException;

/**
 * Satisfies Query's MysqlLink|PostgresLink constructor type for pure
 * query-*building* tests, which never actually call execute()/query() —
 * only Query::toSelectSql()/toInsertSql()/etc. and the compiled SQL/params
 * they produce are under test, no live database involved. Every method
 * that would need a real connection throws, so a test accidentally
 * exercising execution fails loudly instead of hanging.
 */
final class FakeMysqlLink implements MysqlLink
{
    public function query(string $sql): MysqlResult
    {
        throw new LogicException('FakeMysqlLink does not execute queries.');
    }

    public function prepare(string $sql): MysqlStatement
    {
        throw new LogicException('FakeMysqlLink does not execute queries.');
    }

    public function execute(string $sql, array $params = []): MysqlResult
    {
        throw new LogicException('FakeMysqlLink does not execute queries.');
    }

    public function beginTransaction(): MysqlTransaction
    {
        throw new LogicException('FakeMysqlLink does not support transactions.');
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
