<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use LogicException;

/**
 * RecordsCalls' own "always EmptySqlResult" replaced with a caller-
 * supplied queue, one result per query()/execute() call in the order
 * they're made — the way to drive the branches a zero-row result never
 * reaches, such as cursorPaginate()'s hasMore=true, without a live
 * database.
 */
trait RecordsCallsWithQueuedResults
{
    /** @var list<RecordedCall> */
    public array $calls = [];

    /** @var list<SqlResult> */
    private array $queue;

    /** @param list<SqlResult> $queue */
    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function query(string $sql): SqlResult
    {
        $this->calls[] = new RecordedCall('query', $sql, []);

        return $this->nextResult();
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function execute(string $sql, array $params = []): SqlResult
    {
        $this->calls[] = new RecordedCall('execute', $sql, \array_values($params));

        return $this->nextResult();
    }

    private function nextResult(): SqlResult
    {
        return \array_shift($this->queue) ?? new EmptySqlResult();
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
