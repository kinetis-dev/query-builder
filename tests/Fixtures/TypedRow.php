<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

/** One defaulted constructor parameter per type RowMapper admits. */
final class TypedRow
{
    public function __construct(
        public string $string = 'default',
        public int $int = 0,
        public float $float = 0.0,
        public bool $bool = false,
        public ArticleStatus $status = ArticleStatus::Draft,
        public Priority $priority = Priority::Low,
        public ?int $nullableInt = 0,
        public mixed $mixed = 'default',
        public $untyped = 'default',
    ) {}
}
