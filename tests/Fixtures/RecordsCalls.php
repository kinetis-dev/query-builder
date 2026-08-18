<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use LogicException;

/**
 * Records every query()/execute() call — the whole point of the spy
 * links, which exist to observe *which* of the two Query::run() chose and
 * with what final SQL, not just to satisfy a constructor's type.
 *
 * A trait rather than a base class so the links stay final, and so a spy
 * that additionally declares PrefersPreparedStatements differs from its
 * plain counterpart by nothing but that marker.
 */
trait RecordsCalls
{
    /** @var list<RecordedCall> */
    public array $calls = [];

    public function query(string $sql): SqlResult
    {
        $this->calls[] = new RecordedCall('query', $sql, []);

        return new EmptySqlResult();
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function execute(string $sql, array $params = []): SqlResult
    {
        $this->calls[] = new RecordedCall('execute', $sql, \array_values($params));

        return new EmptySqlResult();
    }

    public function beginTransaction(): SqlTransaction
    {
        throw new LogicException(static::class . ' does not support transactions.');
    }

    public function close(): void
    {
    }

    public function isClosed(): bool
    {
        return false;
    }
}
