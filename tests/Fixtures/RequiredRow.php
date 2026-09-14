<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

/** A nullable parameter without a default is still a required column. */
final readonly class RequiredRow
{
    public function __construct(
        public int $id,
        public ?string $name,
    ) {}
}
