<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

/** Hydration target for the real-backend RowValues round trip. */
final readonly class ArticleRow
{
    public function __construct(
        public int $id,
        public string $title,
        public ArticleStatus $status,
        public ?string $summary,
        public ?int $author_id,
        public string $slug,
    ) {}
}
