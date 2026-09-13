<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder;

/**
 * What Query::lockForUpdate() does when another transaction already holds
 * a lock on a row it selects. All three compile identically on MySQL 8.4,
 * MariaDB 11.4 and PostgreSQL 16.
 */
enum LockWait
{
    /** FOR UPDATE: wait for the other transaction, up to the server's lock timeout. */
    case Wait;

    /** FOR UPDATE NOWAIT: fail the statement immediately. */
    case NoWait;

    /** FOR UPDATE SKIP LOCKED: leave locked rows out of the result. */
    case SkipLocked;
}
