<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Kinetis\Persistence\Contract\MysqlLink;

/**
 * Records every query()/execute() call it receives, returning a
 * caller-supplied queue of results instead of RecordsCalls' own fixed
 * EmptySqlResult — see RecordsCallsWithQueuedResults for why.
 */
final class QueuedRowsMysqlLink implements MysqlLink
{
    use RecordsCallsWithQueuedResults;
}
