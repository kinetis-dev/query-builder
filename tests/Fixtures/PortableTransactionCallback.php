<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\QueryBuilder\Query;

/**
 * A TransactionGuard::transaction() callback typed against the shared
 * SqlTransaction contract. phpstan.neon and psalm.xml analyse this file,
 * so both analyzers hold new Query() to accepting that parameter type.
 */
final class PortableTransactionCallback
{
    public static function query(SqlTransaction $tx): Query
    {
        return new Query($tx);
    }
}
