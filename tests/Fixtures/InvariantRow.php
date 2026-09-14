<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use DomainException;

/** A DTO whose constructor enforces its own domain invariant. */
final readonly class InvariantRow
{
    public function __construct(public int $quantity)
    {
        if ($quantity < 1) {
            throw new DomainException('An order line needs a quantity of at least 1.');
        }
    }
}
