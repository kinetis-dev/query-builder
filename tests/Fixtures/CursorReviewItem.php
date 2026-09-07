<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

/** Hydration target for the real-backend qualified-cursor tests. */
final readonly class CursorReviewItem
{
    public function __construct(
        public int $id,
        public string $name,
        public string $label,
    ) {}
}
