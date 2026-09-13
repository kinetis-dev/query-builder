<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Kinetis\Persistence\Contract\PostgresTransaction;

/** The PostgreSQL counterpart of SpyMysqlTransaction. */
final class SpyPostgresTransaction implements PostgresTransaction
{
    use RecordsCalls;

    public bool $active = true;

    public function commit(): void
    {
        $this->active = false;
    }

    public function rollback(): void
    {
        $this->active = false;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
