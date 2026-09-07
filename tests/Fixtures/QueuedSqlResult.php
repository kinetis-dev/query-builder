<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use IteratorAggregate;
use Kinetis\Persistence\Contract\SqlResult;
use Traversable;

/**
 * A fixed, caller-supplied set of rows — the fast-unit-level counterpart
 * to a real backend returning data, used to reach the branches a spy's
 * own empty result never does (see QueuedRowsMysqlLink). Iteration via
 * foreach and fetchRow() do not share a cursor, matching the real
 * SqlResult contract.
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final class QueuedSqlResult implements SqlResult, IteratorAggregate
{
    private int $fetchIndex = 0;

    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows) {}

    public function getIterator(): Traversable
    {
        yield from $this->rows;
    }

    public function fetchRow(): ?array
    {
        return $this->rows[$this->fetchIndex++] ?? null;
    }

    public function getRowCount(): ?int
    {
        return \count($this->rows);
    }

    public function getColumnCount(): ?int
    {
        return $this->rows === [] ? null : \count($this->rows[0]);
    }

    public function getLastInsertId(): ?int
    {
        return null;
    }
}
