<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder;

/** One page of Query::paginate(), JSON-encoding to its public fields. */
final readonly class Paginator
{
    /**
     * @param list<mixed> $data
     */
    public function __construct(
        public array $data,
        public int $currentPage,
        public int $perPage,
        public int $total,
        public int $lastPage,
    ) {}
}
