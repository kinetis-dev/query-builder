<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Kinetis\Persistence\Contract\SqlTransaction;

/** A transaction carrying neither the MysqlLink nor the PostgresLink marker. */
final class UnmarkedSqlTransaction implements SqlTransaction
{
    use RecordsCalls;

    public function commit(): void
    {
    }

    public function rollback(): void
    {
    }

    public function isActive(): bool
    {
        return true;
    }
}
