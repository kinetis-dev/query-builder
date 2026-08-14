<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use LogicException;

/**
 * Satisfies Query's MysqlLink|PostgresLink constructor type for pure
 * query-*building* tests, which never actually call execute()/query() —
 * only the compiled SQL/params are under test, no live database
 * involved. Every method that would need a real connection throws, so a
 * test accidentally exercising execution fails loudly instead of
 * hanging.
 */
final class FakeMysqlLink implements MysqlLink
{
    public function query(string $sql): SqlResult
    {
        throw new LogicException('FakeMysqlLink does not execute queries.');
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        throw new LogicException('FakeMysqlLink does not execute queries.');
    }

    public function beginTransaction(): SqlTransaction
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
}
