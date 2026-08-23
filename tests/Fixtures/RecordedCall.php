<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

/**
 * @param 'query'|'execute' $method
 * @param list<mixed> $params
 */
final readonly class RecordedCall
{
    public function __construct(
        public string $method,
        public string $sql,
        public array $params,
    ) {}
}
