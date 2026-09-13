<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

/** Hydration target for the real-backend selectExists() flag. */
final readonly class ArticleFlagRow
{
    public function __construct(
        public string $slug,
        public bool $favorited,
    ) {}
}
