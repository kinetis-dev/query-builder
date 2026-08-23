<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use IteratorAggregate;
use Kinetis\Persistence\Contract\SqlResult;
use Traversable;

/**
 * A fixed, caller-supplied set of rows — the fast-unit-level counterpart
 * to a real backend actually returning data, used to exercise
 * cursorPaginate()'s hasMore/fetchBoundaryCursor() branches without a
 * live database (see QueuedRowsMysqlLink/QueuedRowsPostgresLink).
 * Iteration via foreach and fetchRow() intentionally don't share a
 * cursor, matching the real SqlResult contract exactly.
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
