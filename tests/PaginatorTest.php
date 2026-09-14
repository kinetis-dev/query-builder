<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests;

use Kinetis\QueryBuilder\Paginator;
use PHPUnit\Framework\TestCase;

final class PaginatorTest extends TestCase
{
    public function test_encodes_to_the_flat_shape(): void
    {
        $paginator = new Paginator(
            data: [['id' => 1], ['id' => 2]],
            currentPage: 2,
            perPage: 20,
            total: 145,
            lastPage: 8,
        );

        self::assertSame(
            '{"data":[{"id":1},{"id":2}],"currentPage":2,"perPage":20,"total":145,"lastPage":8}',
            json_encode($paginator, JSON_THROW_ON_ERROR),
        );
    }
}
