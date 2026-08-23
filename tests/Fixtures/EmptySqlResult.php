<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use IteratorAggregate;
use Kinetis\Persistence\Contract\SqlResult;
use Traversable;

/**
 * A zero-row result for spies to hand back.
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final class EmptySqlResult implements SqlResult, IteratorAggregate
{
    public function getIterator(): Traversable
    {
        yield from [];
    }

    public function fetchRow(): ?array
    {
        return null;
    }

    public function getRowCount(): ?int
    {
        return 0;
    }

    public function getColumnCount(): ?int
    {
        return null;
    }

    public function getLastInsertId(): ?int
    {
        return null;
    }
}
