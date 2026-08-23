<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

/**
 * DTO hydration target for the real-backend qualified-cursor tests —
 * deliberately has its own $__kinetis_cursor property, matching a real
 * application column of that exact name, to prove hydration sees the
 * caller's own column value untouched, never anything this method's
 * internal machinery introduces.
 */
final readonly class CursorReviewItem
{
    public function __construct(
        public int $id,
        public string $name,
        public string $__kinetis_cursor,
    ) {}
}
