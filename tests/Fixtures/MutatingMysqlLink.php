<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Kinetis\Persistence\Contract\MysqlLink;

/** MySQL half of {@see MutatesAfterFirstQuery} — the marker Query detects the dialect from. */
final class MutatingMysqlLink implements MysqlLink
{
    use MutatesAfterFirstQuery;
}
