<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

/** Mapping target for the qualified-cursor and projection tests. */
final readonly class CursorReviewItem
{
    public function __construct(
        public int $id,
        public string $name,
        public string $label,
    ) {}
}
