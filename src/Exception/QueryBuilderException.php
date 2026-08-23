<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Exception;

use RuntimeException;

final class QueryBuilderException extends RuntimeException
{
    public static function cursorColumnMissingFromRow(string $cursorRowKey): self
    {
        return new self(
            "cursorPaginate() could not read \"{$cursorRowKey}\" back off the row it just returned. "
            . 'The cursor column has to reach the result under exactly that name for its value to be '
            . 'readable.',
        );
    }
}
