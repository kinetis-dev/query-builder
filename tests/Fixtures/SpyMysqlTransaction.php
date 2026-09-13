<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Kinetis\Persistence\Contract\MysqlTransaction;

/**
 * A recording MySQL transaction whose activity a test sets directly, to
 * reach the lock checks that depend on it.
 */
final class SpyMysqlTransaction implements MysqlTransaction
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
