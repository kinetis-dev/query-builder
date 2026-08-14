<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Amp\Postgres\PostgresResult;

/**
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class EmptyPostgresResult implements PostgresResult, \IteratorAggregate
{
    public function getIterator(): \Generator
    {
        return;
        yield;
    }

    public function fetchRow(): ?array
    {
        return null;
    }

    public function getNextResult(): ?self
    {
        return null;
    }

    public function getRowCount(): ?int
    {
        return 0;
    }

    public function getColumnCount(): ?int
    {
        return 0;
    }
}
