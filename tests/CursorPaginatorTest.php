<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests;

use Kinetis\QueryBuilder\CursorPaginator;
use PHPUnit\Framework\TestCase;

final class CursorPaginatorTest extends TestCase
{
    public function test_encodes_to_the_flat_shape(): void
    {
        $paginator = new CursorPaginator(
            data: [['id' => 145], ['id' => 146]],
            nextCursor: '146',
            hasMore: true,
        );

        self::assertSame(
            '{"data":[{"id":145},{"id":146}],"nextCursor":"146","hasMore":true}',
            json_encode($paginator, JSON_THROW_ON_ERROR),
        );
    }

    public function test_a_null_cursor_encodes_as_json_null_when_there_is_no_more_data(): void
    {
        $paginator = new CursorPaginator(data: [], nextCursor: null, hasMore: false);

        self::assertSame('{"data":[],"nextCursor":null,"hasMore":false}', json_encode($paginator, JSON_THROW_ON_ERROR));
    }
}
