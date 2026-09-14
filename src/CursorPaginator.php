<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder;

/** One page of Query::cursorPaginate(), JSON-encoding to its public fields. */
final readonly class CursorPaginator
{
    /**
     * @param list<mixed> $data
     */
    public function __construct(
        public array $data,
        public ?string $nextCursor,
        public bool $hasMore,
    ) {}
}
