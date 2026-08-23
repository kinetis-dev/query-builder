<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PrefersPreparedStatements;

/**
 * A Mysql link that declares it would rather bind a value than read it as a
 * literal, exactly as the PDO drivers do. Identical to SpyMysqlLink but for
 * that marker, so a test comparing the two isolates its effect.
 */
final class PreparingSpyMysqlLink implements MysqlLink, PrefersPreparedStatements
{
    use RecordsCalls;
}
