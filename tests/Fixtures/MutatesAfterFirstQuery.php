<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Closure;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;

/**
 * Delegates to a real link, running a caller-supplied mutation once,
 * immediately after the first query it forwards returns.
 *
 * Makes "a write landed between two reads" deterministic rather than a
 * race to reproduce: with one read there is no between, and the cursor
 * still names the row that was delivered; with two, the second read sees
 * a table the first one never did. Safe to mutate at that point because
 * SqlResult buffering is part of its contract — the rows already
 * returned are materialized, not a live server-side cursor.
 */
trait MutatesAfterFirstQuery
{
    private int $forwarded = 0;

    public function __construct(
        private readonly MysqlLink|PostgresLink $inner,
        private readonly Closure $mutate,
    ) {}

    public function query(string $sql): SqlResult
    {
        $result = $this->inner->query($sql);
        $this->mutateOnce();

        return $result;
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function execute(string $sql, array $params = []): SqlResult
    {
        $result = $this->inner->execute($sql, $params);
        $this->mutateOnce();

        return $result;
    }

    private function mutateOnce(): void
    {
        $this->forwarded++;

        if ($this->forwarded === 1) {
            ($this->mutate)();
        }
    }

    public function beginTransaction(): SqlTransaction
    {
        return $this->inner->beginTransaction();
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function isClosed(): bool
    {
        return $this->inner->isClosed();
    }
}
