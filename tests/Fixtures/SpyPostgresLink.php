<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Kinetis\Persistence\Contract\PostgresLink;

/**
 * Records every query()/execute() call it receives — exists specifically
 * to observe *which* of the two Query::run() actually chose, and with
 * what final SQL, not just to satisfy the constructor's type.
 */
final class SpyPostgresLink implements PostgresLink
{
    use RecordsCalls;
}
